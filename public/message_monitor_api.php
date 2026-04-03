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

function mm_provider_label(string $provider): string
{
    return match (strtolower(trim($provider))) {
        'papi' => 'PAPI',
        default => 'Evolution',
    };
}

function mm_extract_provider(array $row): string
{
    $provider = strtolower(trim((string) ($row['provider'] ?? '')));

    if ($provider !== '') {
        return $provider;
    }

    $payload = mm_decode_payload($row['last_raw_payload'] ?? $row['raw_payload'] ?? null);
    $candidates = [
        $payload['_meta']['provider'] ?? null,
        $payload['provider'] ?? null,
        $payload['payload']['provider'] ?? null,
        $payload['body']['provider'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return strtolower(trim($candidate));
        }
    }

    return 'evolution';
}

function mm_conversation_key(string $provider, string $phone): string
{
    return strtolower(trim($provider)) . ':' . (preg_replace('/\D+/', '', $phone) ?: '');
}

function mm_parse_conversation_key(string $key): array
{
    $key = trim($key);

    if ($key === '' || !str_contains($key, ':')) {
        return ['provider' => '', 'phone' => ''];
    }

    [$provider, $phone] = explode(':', $key, 2);

    return [
        'provider' => strtolower(trim($provider)),
        'phone' => preg_replace('/\D+/', '', $phone) ?: '',
    ];
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
    provider VARCHAR(20) NOT NULL DEFAULT 'evolution',
    phone VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider, phone)
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

function mm_upsert_conversation_state(PDO $pdo, string $provider, string $phone, ?string $status = null, ?int $lastReadMessageId = null): void
{
    $fields = ['provider', 'phone'];
    $values = [':provider', ':phone'];
    $updates = [];
    $params = [
        'provider' => strtolower(trim($provider)),
        'phone' => $phone,
    ];

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

function mm_latest_incoming_message_id(PDO $pdo, string $provider, string $phone): int
{
    $statement = $pdo->prepare("SELECT MAX(id) FROM message_logs WHERE provider = :provider AND phone = :phone AND direction = 'in'");
    $statement->execute([
        'provider' => strtolower(trim($provider)),
        'phone' => $phone,
    ]);

    return max(0, (int) $statement->fetchColumn());
}

function mm_apply_conversation_action(PDO $pdo, string $provider, string $phone, string $action): string
{
    return match ($action) {
        'mark_read' => (function () use ($pdo, $provider, $phone): string {
            mm_upsert_conversation_state($pdo, $provider, $phone, null, mm_latest_incoming_message_id($pdo, $provider, $phone));
            return 'Conversa marcada como lida.';
        })(),
        'mark_unread' => (function () use ($pdo, $provider, $phone): string {
            $latestIncomingId = mm_latest_incoming_message_id($pdo, $provider, $phone);
            if ($latestIncomingId < 1) {
                throw new RuntimeException('Nao ha mensagem recebida para marcar como nao lida.');
            }
            mm_upsert_conversation_state($pdo, $provider, $phone, null, max(0, $latestIncomingId - 1));
            return 'Conversa marcada como nao lida.';
        })(),
        'archive' => (function () use ($pdo, $provider, $phone): string {
            mm_upsert_conversation_state($pdo, $provider, $phone, 'archived', null);
            return 'Conversa arquivada.';
        })(),
        'inactivate' => (function () use ($pdo, $provider, $phone): string {
            mm_upsert_conversation_state($pdo, $provider, $phone, 'inactive', null);
            return 'Conversa movida para inativas.';
        })(),
        'activate' => (function () use ($pdo, $provider, $phone): string {
            mm_upsert_conversation_state($pdo, $provider, $phone, 'active', null);
            return 'Conversa movida para ativas.';
        })(),
        default => throw new RuntimeException('Acao de conversa invalida.'),
    };
}

function mm_make_whatsapp_service(): WhatsAppService
{
    return WhatsAppService::fromConfig(new HttpClient(), (array) config('services.whatsapp', []));
}

function mm_enabled_providers(WhatsAppService $whatsAppService): array
{
    return [
        'evolution' => $whatsAppService->isEnabled('evolution'),
        'papi' => $whatsAppService->isEnabled('papi'),
    ];
}

function mm_available_provider_options(array $enabledProviders): array
{
    $options = [];

    foreach (['evolution', 'papi'] as $provider) {
        if (($enabledProviders[$provider] ?? false) === true) {
            $options[] = $provider;
        }
    }

    if ($options === []) {
        $options[] = (string) config('services.whatsapp.default_provider', config('services.whatsapp.provider', 'evolution'));
    }

    return array_values(array_unique($options));
}

function mm_load_data(
    MessageLogService $messageLogService,
    string $search,
    string $selectedPhone,
    string $selectedProvider,
    int $conversationLimit,
    int $messageLimit,
    string $view,
    array $enabledProviders
): array
{
    $conversations = mm_filter_conversations(
        $messageLogService->findConversations($conversationLimit, $search),
        $view
    );

    if (($enabledProviders['evolution'] ?? false) !== true || ($enabledProviders['papi'] ?? false) !== true) {
        $conversations = array_values(array_filter($conversations, static function (array $conversation) use ($enabledProviders): bool {
            $provider = mm_extract_provider($conversation);
            return ($enabledProviders[$provider] ?? false) === true;
        }));
    }

    if (($selectedPhone === '' || $selectedProvider === '') && $conversations !== []) {
        $selectedPhone = (string) ($conversations[0]['phone'] ?? '');
        $selectedProvider = mm_extract_provider($conversations[0]);
    }

    $selectedConversation = null;

    foreach ($conversations as $conversation) {
        if ((string) ($conversation['phone'] ?? '') === $selectedPhone && mm_extract_provider($conversation) === $selectedProvider) {
            $selectedConversation = $conversation;
            break;
        }
    }

    if ($selectedPhone !== '' && $selectedProvider !== '' && $selectedConversation === null) {
        if ($conversations !== []) {
            $selectedConversation = $conversations[0];
            $selectedPhone = (string) ($selectedConversation['phone'] ?? '');
            $selectedProvider = mm_extract_provider($selectedConversation);
        } else {
            $selectedPhone = '';
            $selectedProvider = '';
        }
    }

    $messages = $selectedPhone !== '' && $selectedProvider !== ''
        ? $messageLogService->findMessagesByPhone($selectedPhone, $messageLimit, $selectedProvider)
        : [];

    if ($selectedConversation === null && $selectedPhone !== '' && $selectedProvider !== '' && $messages !== []) {
        $lastMessage = $messages[count($messages) - 1] ?? [];
        $selectedConversation = [
            'provider' => $selectedProvider,
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
        'selected_provider' => $selectedProvider,
        'selected_key' => $selectedPhone !== '' && $selectedProvider !== '' ? mm_conversation_key($selectedProvider, $selectedPhone) : '',
        'current_view' => $view,
        'conversations' => $conversations,
        'selected_conversation' => $selectedConversation,
        'messages' => $messages,
    ];
}

function mm_render_conversations(array $conversations, string $selectedKey): string
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
        $provider = mm_extract_provider($conversation);
        $conversationKey = mm_conversation_key($provider, $phone);
        $name = mm_extract_name($conversation);
        $preview = mm_summarize_message($conversation['last_message_text'] ?? null, (string) ($conversation['last_message_type'] ?? 'unknown'));
        $avatar = mb_strtoupper(mb_substr($name, 0, 1));
        $status = (string) ($conversation['conversation_status'] ?? 'active');
        $statusLabel = $status === 'archived' ? 'Arquivada' : ($status === 'inactive' ? 'Inativa' : 'Ativa');
        $statusClass = $status === 'archived' ? ' is-archived' : ($status === 'inactive' ? ' is-inactive' : '');
        $unreadCount = (int) ($conversation['unread_count'] ?? 0);
        ?>
        <a class="conversation-item<?= $conversationKey === $selectedKey ? ' is-active' : '' ?><?= $unreadCount > 0 ? ' has-unread' : '' ?><?= mm_e($statusClass) ?>" href="#" data-phone="<?= mm_e($phone) ?>" data-provider="<?= mm_e($provider) ?>" data-conversation-key="<?= mm_e($conversationKey) ?>">
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
                    <span class="conversation-meta"><?= mm_e(mm_format_phone($phone)) ?> · <?= mm_e(mm_provider_label($provider)) ?></span>
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
    $provider = mm_extract_provider($selectedConversation);
    ?>
    <div class="thread-header-card">
        <div class="thread-header-main">
            <span class="thread-avatar"><?= mm_e($selectedAvatar !== '' ? $selectedAvatar : '#') ?></span>
            <div class="thread-header-copy">
                <strong><?= mm_e($selectedName) ?></strong>
                <span><?= mm_e(mm_format_phone((string) ($selectedConversation['phone'] ?? ''))) ?> · <?= mm_e(mm_provider_label($provider)) ?></span>
                <div class="thread-badges">
                    <span class="thread-pill is-status status-<?= mm_e($status) ?>"><?= mm_e($status === 'archived' ? 'Arquivada' : ($status === 'inactive' ? 'Inativa' : 'Ativa')) ?></span>
                    <span class="thread-pill is-status"><?= mm_e(mm_provider_label($provider)) ?></span>
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
        $provider = mm_extract_provider($message);
        $payloadJson = $payload === []
            ? trim((string) ($message['raw_payload'] ?? ''))
            : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <article class="message-row<?= $isOutgoing ? ' outgoing' : ' incoming' ?>">
            <div class="message-bubble">
                <div class="message-top">
                    <span><?= $isOutgoing ? 'Robo' : 'Cliente' ?> · <?= mm_e(mm_provider_label($provider)) ?></span>
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

function mm_render_composer(string $selectedPhone, string $selectedProvider, array $enabledProviders): string
{
    $availableProviders = mm_available_provider_options($enabledProviders);
    $selectedProvider = $selectedProvider !== '' ? $selectedProvider : ($availableProviders[0] ?? 'evolution');
    $providerEnabled = (bool) ($enabledProviders[$selectedProvider] ?? false);
    $isDisabled = $selectedPhone === '' || !$providerEnabled;
    $placeholder = $selectedPhone === ''
        ? 'Selecione uma conversa para responder.'
        : 'Digite uma mensagem para enviar manualmente.';
    $hint = !$providerEnabled
        ? 'Envio manual desabilitado para o provider selecionado.'
        : ($selectedPhone === '' ? 'Escolha uma conversa na lista para habilitar o envio.' : 'Mensagem enviada por aqui tambem entra no historico.');

    ob_start();
    ?>
    <form id="send-form" class="composer-form">
        <input type="hidden" name="phone" value="<?= mm_e($selectedPhone) ?>">
        <div class="composer-fields">
            <div class="composer-stack">
                <select name="provider" class="composer-provider"<?= $selectedPhone === '' ? ' disabled' : '' ?>>
                    <?php foreach ($availableProviders as $provider): ?>
                        <option value="<?= mm_e($provider) ?>"<?= $provider === $selectedProvider ? ' selected' : '' ?>><?= mm_e(mm_provider_label($provider)) ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea id="send-message" name="message" rows="3" placeholder="<?= mm_e($placeholder) ?>"<?= $isDisabled ? ' disabled' : '' ?>></textarea>
            </div>
            <button type="submit" class="send-button"<?= $isDisabled ? ' disabled' : '' ?>>Enviar</button>
        </div>
        <div class="composer-hint" id="composer-hint"><?= mm_e($hint) ?></div>
        <div class="composer-feedback" id="composer-feedback"></div>
    </form>
    <?php

    return (string) ob_get_clean();
}

function mm_build_snapshot(array $monitorData, array $enabledProviders): array
{
    return [
        'selected_phone' => $monitorData['selected_phone'],
        'selected_provider' => $monitorData['selected_provider'] ?? '',
        'selected_key' => $monitorData['selected_key'] ?? '',
        'conversations_count' => count($monitorData['conversations']),
        'conversation_list_html' => mm_render_conversations($monitorData['conversations'], (string) ($monitorData['selected_key'] ?? '')),
        'thread_header_html' => mm_render_header($monitorData['selected_conversation']),
        'message_feed_html' => mm_render_feed($monitorData['selected_conversation'], $monitorData['messages']),
        'composer_html' => mm_render_composer((string) $monitorData['selected_phone'], (string) ($monitorData['selected_provider'] ?? ''), $enabledProviders),
        'refreshed_at' => (new DateTimeImmutable())->format('d/m/Y H:i:s'),
        'send_enabled' => in_array(true, $enabledProviders, true),
        'enabled_providers' => $enabledProviders,
        'current_view' => $monitorData['current_view'] ?? 'ativas',
    ];
}
$request = Request::capture();
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
$selectedProvider = strtolower(trim((string) ($request->query('provider', $request->input('provider', '')))));
$selectedConversationKey = trim((string) ($request->query('conversation_key', $request->input('conversation_key', ''))));
if ($selectedConversationKey !== '') {
    $parsedConversation = mm_parse_conversation_key($selectedConversationKey);
    if ($parsedConversation['provider'] !== '') {
        $selectedProvider = $parsedConversation['provider'];
    }
    if ($parsedConversation['phone'] !== '') {
        $selectedPhone = $parsedConversation['phone'];
    }
}
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
    $enabledProviders = mm_enabled_providers($whatsAppService);

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

        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $selectedProvider, $conversationLimit, $messageLimit, $view, $enabledProviders);

        Response::json([
            'ok' => true,
            'message' => 'Mensagem excluida com sucesso.',
            'deleted_rows' => $deleted,
            'snapshot' => mm_build_snapshot($monitorData, $enabledProviders),
        ])->send();
        return;
    }

    if ($action === 'conversation' && $request->method() === 'POST') {
        if ($selectedPhone === '' || $selectedProvider === '') {
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

        $message = mm_apply_conversation_action($pdo, $selectedProvider, $selectedPhone, $conversationAction);
        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $selectedProvider, $conversationLimit, $messageLimit, $view, $enabledProviders);

        Response::json([
            'ok' => true,
            'message' => $message,
            'snapshot' => mm_build_snapshot($monitorData, $enabledProviders),
        ])->send();
        return;
    }
    if ($action === 'send' && $request->method() === 'POST') {
        $text = trim((string) $request->input('message', ''));
        $provider = strtolower(trim((string) $request->input('provider', $selectedProvider !== '' ? $selectedProvider : (string) config('services.whatsapp.default_provider', config('services.whatsapp.provider', 'evolution')))));

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

        if (($enabledProviders[$provider] ?? false) !== true) {
            Response::json([
                'ok' => false,
                'message' => 'O envio manual esta desabilitado para o provider selecionado.',
            ], 409)->send();
            return;
        }

        try {
            $sendResult = $whatsAppService->sendText($selectedPhone, $text, $provider);
        } catch (Throwable $sendException) {
            Response::json([
                'ok' => false,
                'message' => 'Nao foi possivel enviar a mensagem agora.',
                'send_result' => [
                    'status' => 'error',
                    'error' => $sendException->getMessage(),
                    'provider' => $provider,
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
            'provider' => $provider,
            'send_result' => $sendResult,
        ]);

        $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $provider, $conversationLimit, $messageLimit, $view, $enabledProviders);

        Response::json([
            'ok' => true,
            'message' => 'Mensagem enviada com sucesso.',
            'send_result' => $sendResult,
            'snapshot' => mm_build_snapshot($monitorData, $enabledProviders),
        ])->send();
        return;
    }

    $monitorData = mm_load_data($messageLogService, $search, $selectedPhone, $selectedProvider, $conversationLimit, $messageLimit, $view, $enabledProviders);

    Response::json([
        'ok' => true,
        'snapshot' => mm_build_snapshot($monitorData, $enabledProviders),
    ])->send();
} catch (Throwable $exception) {
    Response::json([
        'ok' => false,
        'message' => 'Nao foi possivel carregar o painel de mensagens.',
        'error' => $exception->getMessage(),
    ], 500)->send();
}








