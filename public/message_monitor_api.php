<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Persistence\DatabaseConnectionFactory;
use App\Infrastructure\Persistence\PdoMessageLogRepository;
use App\Service\MessageLogService;
use App\Service\WhatsAppService;

function mm_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function mm_format_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '';

    if ($digits === '') {
        return '-';
    }

    if (strlen($digits) === 13) {
        return sprintf('+%s (%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 2), substr($digits, 4, 5), substr($digits, 9));
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
    }

    return $digits;
}

function mm_format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return (string) $value;
    }
}

function mm_format_time(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('H:i');
    } catch (Throwable) {
        return '';
    }
}

function mm_decode_payload(?string $rawPayload): array
{
    if (!is_string($rawPayload) || trim($rawPayload) === '') {
        return [];
    }

    $decoded = json_decode($rawPayload, true);

    return is_array($decoded) ? $decoded : [];
}

function mm_extract_send_status(array $payload): string
{
    $sendResult = is_array($payload['send_result'] ?? null) ? $payload['send_result'] : null;
    $status = strtolower(trim((string) ($sendResult['status'] ?? '')));

    return match ($status) {
        'sent', 'multi_sent' => 'sent',
        'skipped' => 'skipped',
        'error' => 'failed',
        default => $sendResult === null ? 'logged' : 'failed',
    };
}

function mm_send_status_label(string $status): string
{
    return match ($status) {
        'sent' => 'enviado',
        'skipped' => 'desabilitado',
        'failed' => 'falhou',
        default => 'registrado',
    };
}

function mm_extract_name(array $row): string
{
    $payload = mm_decode_payload($row['last_raw_payload'] ?? $row['raw_payload'] ?? null);
    $candidates = [
        $payload['push_name'] ?? null,
        $payload['pushName'] ?? null,
        $payload['payload']['push_name'] ?? null,
        $payload['payload']['pushName'] ?? null,
        $payload['body']['pushName'] ?? null,
        $payload['body']['data']['pushName'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return mm_format_phone((string) ($row['phone'] ?? ''));
}

function mm_summarize_message(?string $text, string $type): string
{
    $text = trim((string) $text);

    if ($text !== '') {
        return mb_strimwidth($text, 0, 72, '...');
    }

    return match ($type) {
        'audio' => '[audio]',
        'image' => '[imagem]',
        'video' => '[video]',
        'document' => '[documento]',
        'sticker' => '[figurinha]',
        default => '[mensagem sem texto]',
    };
}


function mm_format_message_for_display(?string $text, bool $compact = false): string
{
    $text = trim((string) $text);

    if ($text === '') {
        return '';
    }

    $text = preg_replace("/\r\n?|\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;

    if ($compact) {
        $text = preg_replace("/\n{2,}/u", "\n", $text) ?? $text;
    }

    return $text;
}
function mm_message_badge(string $type): string
{
    return match ($type) {
        'text' => 'texto',
        'audio' => 'audio',
        'image' => 'imagem',
        'video' => 'video',
        'document' => 'documento',
        'sticker' => 'figurinha',
        default => $type !== '' ? $type : 'desconhecido',
    };
}

function mm_ensure_monitor_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS message_monitor_conversations (
    phone VARCHAR(30) NOT NULL PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
SQL);
}

function mm_normalize_view(string $view): string
{
    $allowed = ['ativas', 'nao_lidas', 'lidas', 'inativas', 'arquivadas', 'todas'];
    $view = trim(mb_strtolower($view));

    return in_array($view, $allowed, true) ? $view : 'ativas';
}

function mm_filter_conversations(array $conversations, string $view): array
{
    return array_values(array_filter($conversations, static function (array $conversation) use ($view): bool {
        $status = (string) ($conversation['conversation_status'] ?? 'active');
        $unreadCount = (int) ($conversation['unread_count'] ?? 0);

        return match ($view) {
            'nao_lidas' => $status === 'active' && $unreadCount > 0,
            'lidas' => $status === 'active' && $unreadCount === 0,
            'inativas' => $status === 'inactive',
            'arquivadas' => $status === 'archived',
            'todas' => true,
            default => $status === 'active',
        };
    }));
}

function mm_upsert_conversation_state(PDO $pdo, string $phone, ?string $status = null, ?int $lastReadMessageId = null): void
{
    $fields = ['phone'];
    $values = [':phone'];
    $updates = [];
    $params = ['phone' => $phone];

    if ($status !== null) {
        $fields[] = 'status';
        $values[] = ':status';
        $updates[] = 'status = VALUES(status)';
        $params['status'] = $status;
    }

    if ($lastReadMessageId !== null) {
        $fields[] = 'last_read_message_id';
        $values[] = ':last_read_message_id';
        $updates[] = 'last_read_message_id = VALUES(last_read_message_id)';
        $params['last_read_message_id'] = max(0, $lastReadMessageId);
    }

    if ($updates === []) {
        return;
    }

    $sql = sprintf(
        'INSERT INTO message_monitor_conversations (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        implode(', ', $fields),
        implode(', ', $values),
        implode(', ', $updates)
    );

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
}

function mm_latest_incoming_message_id(PDO $pdo, string $phone): int
{
    $statement = $pdo->prepare("SELECT MAX(id) FROM message_logs WHERE phone = :phone AND direction = 'in'");
    $statement->execute(['phone' => $phone]);

    return max(0, (int) $statement->fetchColumn());
}

function mm_apply_conversation_action(PDO $pdo, string $phone, string $action): string
{
    return match ($action) {
        'mark_read' => (function () use ($pdo, $phone): string {
            mm_upsert_conversation_state($pdo, $phone, null, mm_latest_incoming_message_id($pdo, $phone));
            return 'Conversa marcada como lida.';
        })(),
        'mark_unread' => (function () use ($pdo, $phone): string {
            $latestIncomingId = mm_latest_incoming_message_id($pdo, $phone);
            if ($latestIncomingId < 1) {
                throw new RuntimeException('Nao ha mensagem recebida para marcar como nao lida.');
            }
            mm_upsert_conversation_state($pdo, $phone, null, max(0, $latestIncomingId - 1));
            return 'Conversa marcada como nao lida.';
        })(),
        'archive' => (function () use ($pdo, $phone): string {
            mm_upsert_conversation_state($pdo, $phone, 'archived', null);
            return 'Conversa arquivada.';
        })(),
        'inactivate' => (function () use ($pdo, $phone): string {
            mm_upsert_conversation_state($pdo, $phone, 'inactive', null);
            return 'Conversa movida para inativas.';
        })(),
        'activate' => (function () use ($pdo, $phone): string {
            mm_upsert_conversation_state($pdo, $phone, 'active', null);
            return 'Conversa movida para ativas.';
        })(),
        default => throw new RuntimeException('Acao de conversa invalida.'),
    };
}

function mm_make_whatsapp_service(): WhatsAppService
{
    return new WhatsAppService(
        new HttpClient(),
        (string) config('services.whatsapp.base_url', ''),
        (string) config('services.whatsapp.instance', ''),
        (string) config('services.whatsapp.api_key', ''),
        (bool) config('services.whatsapp.send_enabled', false),
        (bool) config('services.whatsapp.split_messages', true),
        (int) config('services.whatsapp.split_max_length', 700),
        (int) config('services.whatsapp.split_delay_ms', 400),
        (bool) config('services.whatsapp.typing_enabled', false),
        (int) config('services.whatsapp.typing_delay_ms', 1200),
        (bool) config('services.whatsapp.typing_each_chunk', true)
    );
}

function mm_load_data(MessageLogService $messageLogService, string $search, string $selectedPhone, int $conversationLimit, int $messageLimit, string $view): array
{
    $conversations = mm_filter_conversations(
        $messageLogService->findConversations($conversationLimit, $search),
        $view
    );

    if ($selectedPhone === '' && $conversations !== []) {
        $selectedPhone = (string) ($conversations[0]['phone'] ?? '');
    }

    $selectedConversation = null;

    foreach ($conversations as $conversation) {
        if ((string) ($conversation['phone'] ?? '') === $selectedPhone) {
            $selectedConversation = $conversation;
            break;
        }
    }

    if ($selectedPhone !== '' && $selectedConversation === null) {
        if ($conversations !== []) {
            $selectedConversation = $conversations[0];
            $selectedPhone = (string) ($selectedConversation['phone'] ?? '');
        } else {
            $selectedPhone = '';
        }
    }

    $messages = $selectedPhone !== ''
        ? $messageLogService->findMessagesByPhone($selectedPhone, $messageLimit)
        : [];

    if ($selectedConversation === null && $selectedPhone !== '' && $messages !== []) {
        $lastMessage = $messages[count($messages) - 1] ?? [];
        $selectedConversation = [
            'phone' => $selectedPhone,
            'last_message_at' => $lastMessage['created_at'] ?? null,
            'total_messages' => count($messages),
            'incoming_messages' => count(array_filter($messages, static fn (array $item): bool => ($item['direction'] ?? 'in') === 'in')),
            'outgoing_messages' => count(array_filter($messages, static fn (array $item): bool => ($item['direction'] ?? 'in') === 'out')),
            'last_raw_payload' => $lastMessage['raw_payload'] ?? null,
            'last_message_text' => $lastMessage['normalized_text'] ?? null,
            'last_message_type' => $lastMessage['message_type'] ?? 'text',
            'conversation_status' => 'active',
            'unread_count' => 0,
        ];
    }

    return [
        'selected_phone' => $selectedPhone,
        'current_view' => $view,
        'conversations' => $conversations,
        'selected_conversation' => $selectedConversation,
        'messages' => $messages,
    ];
}

function mm_render_conversations(array $conversations, string $selectedPhone): string
{
    ob_start();

    if ($conversations === []) {
        ?>
        <div class="empty-list">
            <strong>Nenhuma conversa encontrada.</strong>
            <span>Ajuste os filtros ou aguarde novas mensagens.</span>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    foreach ($conversations as $conversation) {
        $phone = (string) ($conversation['phone'] ?? '');
        $name = mm_extract_name($conversation);
        $preview = mm_summarize_message($conversation['last_message_text'] ?? null, (string) ($conversation['last_message_type'] ?? 'unknown'));
        $avatar = mb_strtoupper(mb_substr($name, 0, 1));
        $status = (string) ($conversation['conversation_status'] ?? 'active');
        $statusLabel = $status === 'archived' ? 'Arquivada' : ($status === 'inactive' ? 'Inativa' : 'Ativa');
        $statusClass = $status === 'archived' ? ' is-archived' : ($status === 'inactive' ? ' is-inactive' : '');
        $unreadCount = (int) ($conversation['unread_count'] ?? 0);
        ?>
        <a class="conversation-item<?= $phone === $selectedPhone ? ' is-active' : '' ?><?= $unreadCount > 0 ? ' has-unread' : '' ?><?= mm_e($statusClass) ?>" href="#" data-phone="<?= mm_e($phone) ?>">
            <span class="avatar"><?= mm_e($avatar !== '' ? $avatar : '#') ?></span>
            <span class="conversation-main">
                <span class="conversation-top">
                    <strong><?= mm_e($name) ?></strong>
                    <span class="conversation-aside">
                        <time class="conversation-time"><?= mm_e(mm_format_time((string) ($conversation['last_message_at'] ?? ''))) ?></time>
                        <?php if ($unreadCount > 0): ?>
                            <span class="conversation-unread-badge"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="conversation-meta-row">
                    <span class="conversation-meta"><?= mm_e(mm_format_phone($phone)) ?></span>
                    <?php if ($status !== 'active'): ?>
                        <span class="conversation-status-badge<?= mm_e($statusClass) ?>"><?= mm_e($statusLabel) ?></span>
                    <?php endif; ?>
                </span>
                <span class="conversation-preview"><?= mm_e($preview) ?></span>
            </span>
        </a>
        <?php
    }

    return (string) ob_get_clean();
}

function mm_render_header(?array $selectedConversation): string
{
    ob_start();

    if ($selectedConversation === null) {
        ?>
        <div class="empty-thread">
            <strong>Selecione uma conversa.</strong>
            <span>O historico da conversa aparece aqui.</span>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    $selectedName = mm_extract_name($selectedConversation);
    $selectedAvatar = mb_strtoupper(mb_substr($selectedName, 0, 1));
    $status = (string) ($selectedConversation['conversation_status'] ?? 'active');
    $unreadCount = (int) ($selectedConversation['unread_count'] ?? 0);
    $hasIncoming = (int) ($selectedConversation['incoming_messages'] ?? 0) > 0;
    ?>
    <div class="thread-header-card">
        <div class="thread-header-main">
            <span class="thread-avatar"><?= mm_e($selectedAvatar !== '' ? $selectedAvatar : '#') ?></span>
            <div class="thread-header-copy">
                <strong><?= mm_e($selectedName) ?></strong>
                <span><?= mm_e(mm_format_phone((string) ($selectedConversation['phone'] ?? ''))) ?></span>
                <div class="thread-badges">
                    <span class="thread-pill is-status status-<?= mm_e($status) ?>"><?= mm_e($status === 'archived' ? 'Arquivada' : ($status === 'inactive' ? 'Inativa' : 'Ativa')) ?></span>
                    <span class="thread-pill is-read-state<?= $unreadCount > 0 ? ' is-unread' : '' ?>"><?= $unreadCount > 0 ? $unreadCount . ' nao lida' . ($unreadCount > 1 ? 's' : '') : 'Lida' ?></span>
                </div>
                <div class="thread-actions">
                    <?php if ($hasIncoming): ?>
                        <button type="button" class="thread-action-button" data-conversation-action="<?= $unreadCount > 0 ? 'mark_read' : 'mark_unread' ?>">
                            <?= $unreadCount > 0 ? 'Marcar como lida' : 'Marcar como nao lida' ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($status !== 'active'): ?>
                        <button type="button" class="thread-action-button" data-conversation-action="activate">Mover para ativas</button>
                    <?php endif; ?>
                    <?php if ($status !== 'inactive'): ?>
                        <button type="button" class="thread-action-button" data-conversation-action="inactivate">Inativar</button>
                    <?php endif; ?>
                    <?php if ($status !== 'archived'): ?>
                        <button type="button" class="thread-action-button" data-conversation-action="archive">Arquivar</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="thread-stats">
            <span><b><?= (int) ($selectedConversation['total_messages'] ?? 0) ?></b> msgs</span>
            <span><b><?= (int) ($selectedConversation['incoming_messages'] ?? 0) ?></b> recebidas</span>
            <span><b><?= (int) ($selectedConversation['outgoing_messages'] ?? 0) ?></b> enviadas</span>
            <span><b><?= mm_e(mm_format_datetime((string) ($selectedConversation['last_message_at'] ?? ''))) ?></b> ultima atividade</span>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function mm_render_feed(?array $selectedConversation, array $messages): string
{
    ob_start();

    if ($selectedConversation !== null && $messages === []) {
        ?>
        <div class="empty-thread">
            <strong>Sem mensagens nessa conversa.</strong>
            <span>O numero foi localizado, mas ainda nao ha historico dentro do limite selecionado.</span>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    if ($selectedConversation === null) {
        return (string) ob_get_clean();
    }

    foreach ($messages as $message) {
        $isOutgoing = ($message['direction'] ?? 'in') === 'out';
        $messageId = (int) ($message['id'] ?? 0);
        $text = trim((string) ($message['normalized_text'] ?? ''));
        $displayText = mm_format_message_for_display($text, $isOutgoing);
        $payload = mm_decode_payload($message['raw_payload'] ?? null);
        $sendStatus = $isOutgoing ? mm_extract_send_status($payload) : '';
        $payloadJson = $payload === []
            ? trim((string) ($message['raw_payload'] ?? ''))
            : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <article class="message-row<?= $isOutgoing ? ' outgoing' : ' incoming' ?>">
            <div class="message-bubble">
                <div class="message-top">
                    <span><?= $isOutgoing ? 'Robo' : 'Cliente' ?></span>
                    <span>
                        <?= mm_e(mm_message_badge((string) ($message['message_type'] ?? 'unknown'))) ?>
                        <?php if ($isOutgoing): ?>
                            <small class="message-status message-status-<?= mm_e($sendStatus) ?>"><?= mm_e(mm_send_status_label($sendStatus)) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="message-body">
                    <?php if ($displayText !== ''): ?>
                        <p><?= mm_e($displayText) ?></p>
                    <?php else: ?>
                        <p class="message-placeholder">Mensagem sem texto normalizado.</p>
                    <?php endif; ?>
                </div>
                <div class="message-bottom">
                    <time><?= mm_e(mm_format_datetime((string) ($message['created_at'] ?? ''))) ?></time>
                    <?php if ($messageId > 0): ?>
                        <div class="message-actions">
                            <button type="button" class="message-delete-button" data-message-delete="1" data-message-id="<?= $messageId ?>">Excluir</button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (is_string($payloadJson) && trim($payloadJson) !== ''): ?>
                    <details class="message-details">
                        <summary>Detalhes</summary>
                        <pre><?= mm_e($payloadJson) ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    return (string) ob_get_clean();
}

function mm_render_composer(string $selectedPhone, bool $sendEnabled): string
{
    $isDisabled = $selectedPhone === '' || !$sendEnabled;
    $placeholder = $selectedPhone === ''
        ? 'Selecione uma conversa para responder.'
        : 'Digite uma mensagem para enviar manualmente.';
    $hint = !$sendEnabled
        ? 'Envio manual desabilitado. Ative WHATSAPP_SEND_ENABLED no .env para mandar mensagens daqui.'
        : ($selectedPhone === '' ? 'Escolha uma conversa na lista para habilitar o envio.' : 'Mensagem enviada por aqui tambem entra no historico.');

    ob_start();
    ?>
    <form id="send-form" class="composer-form">
        <input type="hidden" name="phone" value="<?= mm_e($selectedPhone) ?>">
        <div class="composer-fields">
            <textarea id="send-message" name="message" rows="3" placeholder="<?= mm_e($placeholder) ?>"<?= $isDisabled ? ' disabled' : '' ?>></textarea>
            <button type="submit" class="send-button"<?= $isDisabled ? ' disabled' : '' ?>>Enviar</button>
        </div>
        <div class="composer-hint" id="composer-hint"><?= mm_e($hint) ?></div>
        <div class="composer-feedback" id="composer-feedback"></div>
    </form>
    <?php

    return (string) ob_get_clean();
}

function mm_build_snapshot(array $monitorData, bool $sendEnabled): array
{
    return [
        'selected_phone' => $monitorData['selected_phone'],
        'conversations_count' => count($monitorData['conversations']),
        'conversation_list_html' => mm_render_conversations($monitorData['conversations'], $monitorData['selected_phone']),
        'thread_header_html' => mm_render_header($monitorData['selected_conversation']),
        'message_feed_html' => mm_render_feed($monitorData['selected_conversation'], $monitorData['messages']),
        'composer_html' => mm_render_composer($monitorData['selected_phone'], $sendEnabled),
        'refreshed_at' => (new DateTimeImmutable())->format('d/m/Y H:i:s'),
        'send_enabled' => $sendEnabled,
        'current_view' => $monitorData['current_view'] ?? 'ativas',
    ];
}$request = Request::capture();
$remoteAddress = (string) $request->server('REMOTE_ADDR', '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$adminToken = (string) config('services.session.admin_token', '');
$providedToken = (string) ($request->query('token', $request->input('token', '')));

if (!$isLocalRequest && ($adminToken === '' || !hash_equals($adminToken, $providedToken))) {
    Response::json([
        'ok' => false,
        'message' => 'Acesso negado.',
    ], 403)->send();
    return;
}

$action = strtolower((string) ($request->query('action', $request->input('action', 'refresh'))));
$search = trim((string) ($request->query('search', $request->input('search', ''))));
$selectedPhone = preg_replace('/\D+/', '', (string) ($request->query('phone', $request->input('phone', '')))) ?: '';
$view = mm_normalize_view((string) ($request->query('view', $request->input('view', 'ativas'))));
$conversationAction = trim((string) $request->input('conversation_action', $request->query('conversation_action', '')));
$conversationLimit = max(1, min((int) $request->query('conversation_limit', 60), 100));
$messageLimit = max(1, min((int) $request->query('message_limit', 200), 500));
$messageId = max(0, (int) $request->input('message_id', $request->query('message_id', 0)));

try {
    $pdo = (new DatabaseConnectionFactory())->make();
    mm_ensure_monitor_tables($pdo);
    $messageLogService = new MessageLogService(new PdoMessageLogRepository($pdo));
    $whatsAppService = mm_make_whatsapp_service();
    $sendEnabled = $whatsAppService->isEnabled();

    if ($action === 'delete' && $request->method() === 'POST') {
        if ($messageId <= 0) {
            Response::json([
                'ok' => false,
                'message' => 'Informe a mensagem que deve ser excluida.',
            ], 422)->send();
            return;
        }

        $deleted = $messageLogService->deleteById($messageId);

        if ($deleted < 1) {
            Response::json([
                'ok' => false,
                'message' => 'A mensagem selecionada nao foi encontrada.',
            ], 404)->send();
            return;
        }

        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $conversationLimit, $messageLimit, $view);

        Response::json([
            'ok' => true,
            'message' => 'Mensagem excluida com sucesso.',
            'deleted_rows' => $deleted,
            'snapshot' => mm_build_snapshot($monitorData, $sendEnabled),
        ])->send();
        return;
    }

    if ($action === 'conversation' && $request->method() === 'POST') {
        if ($selectedPhone === '') {
            Response::json([
                'ok' => false,
                'message' => 'Selecione uma conversa antes de aplicar uma acao.',
            ], 422)->send();
            return;
        }

        if ($conversationAction === '') {
            Response::json([
                'ok' => false,
                'message' => 'Informe a acao que deve ser aplicada na conversa.',
            ], 422)->send();
            return;
        }

        $message = mm_apply_conversation_action($pdo, $selectedPhone, $conversationAction);
        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $conversationLimit, $messageLimit, $view);

        Response::json([
            'ok' => true,
            'message' => $message,
            'snapshot' => mm_build_snapshot($monitorData, $sendEnabled),
        ])->send();
        return;
    }
    if ($action === 'send' && $request->method() === 'POST') {
        $text = trim((string) $request->input('message', ''));

        if ($selectedPhone === '') {
            Response::json([
                'ok' => false,
                'message' => 'Selecione uma conversa antes de enviar.',
            ], 422)->send();
            return;
        }

        if ($text === '') {
            Response::json([
                'ok' => false,
                'message' => 'Digite uma mensagem antes de enviar.',
            ], 422)->send();
            return;
        }

        if (!$sendEnabled) {
            Response::json([
                'ok' => false,
                'message' => 'O envio manual esta desabilitado no ambiente atual.',
            ], 409)->send();
            return;
        }

        try {
            $sendResult = $whatsAppService->sendText($selectedPhone, $text);
        } catch (Throwable $sendException) {
            Response::json([
                'ok' => false,
                'message' => 'Nao foi possivel enviar a mensagem agora.',
                'send_result' => [
                    'status' => 'error',
                    'error' => $sendException->getMessage(),
                ],
            ], 502)->send();
            return;
        }

        $status = (string) ($sendResult['status'] ?? 'unknown');

        if (!in_array($status, ['sent', 'multi_sent'], true)) {
            Response::json([
                'ok' => false,
                'message' => 'Nao foi possivel enviar a mensagem agora.',
                'send_result' => $sendResult,
            ], 502)->send();
            return;
        }

        $messageLogService->logManualOutgoing($selectedPhone, $text, [
            'source' => 'message_monitor_manual_send',
            'send_result' => $sendResult,
        ]);

        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $conversationLimit, $messageLimit, $view);

        Response::json([
            'ok' => true,
            'message' => 'Mensagem enviada com sucesso.',
            'send_result' => $sendResult,
            'snapshot' => mm_build_snapshot($monitorData, $sendEnabled),
        ])->send();
        return;
    }

    $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $conversationLimit, $messageLimit, $view);

    Response::json([
        'ok' => true,
        'snapshot' => mm_build_snapshot($monitorData, $sendEnabled),
    ])->send();
} catch (Throwable $exception) {
    Response::json([
        'ok' => false,
        'message' => 'Nao foi possivel carregar o painel de mensagens.',
        'error' => $exception->getMessage(),
    ], 500)->send();
}








