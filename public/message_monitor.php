<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Persistence\DatabaseConnectionFactory;
use App\Infrastructure\Persistence\PdoMessageLogRepository;
use App\Service\MessageLogService;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatPhoneNumber(string $phone): string
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

function formatDateTimeLabel(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function formatTimeLabel(?string $value): string
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

function decodeRawPayload(?string $rawPayload): array
{
    if (!is_string($rawPayload) || trim($rawPayload) === '') {
        return [];
    }

    $decoded = json_decode($rawPayload, true);

    return is_array($decoded) ? $decoded : [];
}

function extractSendStatus(array $payload): string
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

function sendStatusLabel(string $status): string
{
    return match ($status) {
        'sent' => 'enviado',
        'skipped' => 'desabilitado',
        'failed' => 'falhou',
        default => 'registrado',
    };
}

function extractDisplayName(array $row): string
{
    $payload = decodeRawPayload($row['last_raw_payload'] ?? $row['raw_payload'] ?? null);
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

    return formatPhoneNumber((string) ($row['phone'] ?? ''));
}

function summarizeMessage(?string $text, string $type): string
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


function formatMessageForDisplay(?string $text, bool $compact = false): string
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
function messageTypeBadge(string $type): string
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

function ensureMonitorConversationStateTable(PDO $pdo): void
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

function normalizeMonitorView(string $view): string
{
    $allowed = ['ativas', 'nao_lidas', 'lidas', 'inativas', 'arquivadas', 'todas'];
    $view = trim(mb_strtolower($view));

    return in_array($view, $allowed, true) ? $view : 'ativas';
}

function filterMonitorConversations(array $conversations, string $view): array
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

$request = Request::capture();
$remoteAddress = (string) $request->server('REMOTE_ADDR', '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$adminToken = (string) config('services.session.admin_token', '');
$providedToken = (string) $request->query('token', '');

if (!$isLocalRequest && ($adminToken === '' || !hash_equals($adminToken, $providedToken))) {
    Response::json([
        'ok' => false,
        'message' => 'Acesso negado.',
    ], 403)->send();
    return;
}

$search = trim((string) $request->query('search', ''));
$selectedPhone = preg_replace('/\D+/', '', (string) $request->query('phone', '')) ?: '';
$selectedProvider = strtolower(trim((string) $request->query('provider', '')));
$view = normalizeMonitorView((string) $request->query('view', 'ativas'));
$conversationLimit = max(1, min((int) $request->query('conversation_limit', 60), 100));
$messageLimit = max(1, min((int) $request->query('message_limit', 200), 500));
$refreshSeconds = 15;
$sendEnabled = (bool) config('services.whatsapp.send_enabled', false)
    && trim((string) config('services.whatsapp.base_url', '')) !== ''
    && trim((string) config('services.whatsapp.instance', '')) !== ''
    && trim((string) config('services.whatsapp.api_key', '')) !== '';
$errorMessage = null;
$conversations = [];
$messages = [];
$selectedConversation = null;

try {
    $pdo = (new DatabaseConnectionFactory())->make();
    ensureMonitorConversationStateTable($pdo);
    $messageLogService = new MessageLogService(new PdoMessageLogRepository($pdo));
    $conversations = filterMonitorConversations($messageLogService->findConversations($conversationLimit, $search), $view);

    if ($selectedPhone === '' && $conversations !== []) {
        $selectedPhone = (string) ($conversations[0]['phone'] ?? '');
    }

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

    if ($selectedPhone !== '') {
        $messages = $messageLogService->findMessagesByPhone($selectedPhone, $messageLimit);
    }
} catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
}

$query = $_GET;
$query['search'] = $search;
if ($selectedPhone !== '') {
    $query['phone'] = $selectedPhone;
}
if ($selectedProvider !== '') {
    $query['provider'] = $selectedProvider;
}
$query['view'] = $view;
if ($providedToken !== '') {
    $query['token'] = $providedToken;
}
$refreshUrl = '?' . http_build_query($query);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Painel de Mensagens</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon-i7ai.svg">
        <style>
        :root {
            --bg-main: #efe7dd;
            --bg-panel: rgba(248, 251, 248, 0.88);
            --bg-sidebar: rgba(255, 255, 255, 0.82);
            --bg-thread: rgba(241, 234, 224, 0.74);
            --bg-outgoing: #d9fdd3;
            --bg-incoming: #ffffff;
            --bg-accent: #0a7c66;
            --text-main: #1f2c34;
            --text-soft: #5f6b72;
            --border-soft: rgba(54, 71, 79, 0.12);
            --shadow-main: 0 28px 60px rgba(15, 28, 36, 0.16);
            --shadow-soft: 0 18px 36px rgba(15, 28, 36, 0.08);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            height: 100vh;
            height: 100dvh;
            min-height: 100vh;
            min-height: 100dvh;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at top left, rgba(7, 148, 126, 0.24), transparent 28%),
                radial-gradient(circle at bottom right, rgba(183, 215, 196, 0.35), transparent 34%),
                linear-gradient(135deg, #efe7dd 0%, #e5ddd5 45%, #f5f2eb 100%);
            padding: 24px;
            overflow: hidden;
        }

        .conversation-list,
        .thread-feed,
        .message-details pre {
            scrollbar-width: thin;
            scrollbar-color: rgba(31, 44, 52, 0.18) transparent;
        }

        .shell {
            width: min(1480px, 100%);
            height: calc(100vh - 48px);
            height: calc(100dvh - 48px);
            margin: 0 auto;
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            border-radius: 28px;
            overflow: hidden;
            background: var(--bg-panel);
            box-shadow: var(--shadow-main);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.34);
            isolation: isolate;
        }

        .app-header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: center;
            padding: 22px 28px;
            background: linear-gradient(135deg, rgba(11, 98, 83, 0.96), rgba(20, 125, 104, 0.94));
            color: #f7fffb;
        }

        .app-header h1 {
            margin: 0;
            font-size: 1.55rem;
            line-height: 1.1;
        }

        .app-header p {
            margin: 6px 0 0;
            color: rgba(247, 255, 251, 0.78);
        }

        .header-meta {
            text-align: right;
            font-size: 0.92rem;
        }

        .header-links {
            margin-top: 12px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #f7fffb;
            text-decoration: none;
            font-weight: 700;
            transition: background 120ms ease, transform 120ms ease;
        }

        .header-link:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .layout {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            min-height: 0;
            height: 100%;
            overflow: hidden;
        }

        .sidebar {
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-soft);
            padding: 20px 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-height: 0;
            overflow: hidden;
        }

        .search-box {
            display: grid;
            gap: 8px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(237, 242, 237, 0.92);
            border: 1px solid rgba(91, 112, 118, 0.12);
            box-shadow: var(--shadow-soft);
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: none;
            background: transparent;
            color: var(--text-main);
            font-size: 0.98rem;
            font-family: inherit;
        }

        .search-actions {
            display: flex;
            gap: 10px;
        }

        .search-actions button,
        .search-actions a {
            border: 0;
            border-radius: 999px;
            padding: 10px 14px;
            font-family: inherit;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .search-actions button {
            background: #0a7c66;
            color: #f8fffb;
        }

        .search-actions a {
            background: rgba(31, 44, 52, 0.08);
            color: var(--text-main);
        }

        .conversation-count {
            display: inline-flex;
            align-items: center;
            align-self: flex-start;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(31, 44, 52, 0.08);
            font-size: 0.9rem;
            color: var(--text-soft);
            box-shadow: 0 10px 20px rgba(31, 44, 52, 0.05);
        }

        .view-switcher {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .view-chip {
            border: 0;
            border-radius: 999px;
            padding: 9px 12px;
            background: rgba(31, 44, 52, 0.08);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.82rem;
            cursor: pointer;
            transition: background 120ms ease, color 120ms ease, transform 120ms ease;
        }

        .view-chip:hover {
            transform: translateY(-1px);
            background: rgba(10, 124, 102, 0.12);
        }

        .view-chip.is-active {
            background: #0a7c66;
            color: #f8fffb;
            box-shadow: 0 12px 22px rgba(10, 124, 102, 0.18);
        }


        .conversation-pill,
        .thread-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .conversation-pill.is-unread,
        .thread-pill.is-unread {
            background: rgba(10, 124, 102, 0.14);
            color: #0a7c66;
        }

        .conversation-pill.is-status,
        .thread-pill.is-status {
            background: rgba(31, 44, 52, 0.08);
            color: var(--text-main);
        }

        .thread-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .thread-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .thread-action-button {
            border: 0;
            border-radius: 999px;
            padding: 10px 14px;
            background: rgba(10, 124, 102, 0.10);
            color: #0a7c66;
            font-family: inherit;
            font-size: 0.84rem;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
        }

        .thread-action-button:hover {
            background: rgba(10, 124, 102, 0.16);
        }

        .conversation-list {
            display: flex;
            flex: 1;
            min-height: 0;
            flex-direction: column;
            gap: 6px;
            overflow: auto;
            padding-right: 4px;
        }

        .conversation-item {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            padding: 14px 14px 14px 12px;
            border-radius: 18px;
            text-decoration: none;
            color: inherit;
            background: rgba(255, 255, 255, 0.46);
            border: 1px solid rgba(31, 44, 52, 0.04);
            box-shadow: 0 4px 16px rgba(31, 44, 52, 0.03);
            position: relative;
            transition: transform 120ms ease, background 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
        }

        .conversation-item:hover,
        .conversation-item.is-active {
            background: rgba(233, 248, 241, 0.96);
            border-color: rgba(10, 124, 102, 0.14);
            box-shadow: 0 12px 24px rgba(10, 124, 102, 0.10);
            transform: translateY(-1px);
        }

        .conversation-item.is-active::before {
            content: "";
            position: absolute;
            left: 0;
            top: 12px;
            bottom: 12px;
            width: 4px;
            border-radius: 999px;
            background: linear-gradient(180deg, #0a7c66, #25d366);
        }

        .avatar {
            width: 54px;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: linear-gradient(135deg, #0a7c66, #21a184);
            color: #f8fffb;
            font-size: 1.15rem;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 8px 18px rgba(10, 124, 102, 0.18);
        }

        .conversation-main {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .conversation-top,
        .conversation-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            min-width: 0;
        }

        .conversation-top {
            align-items: flex-start;
        }

        .conversation-top strong,
        .conversation-meta,
        .conversation-preview {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conversation-top strong {
            font-size: 0.97rem;
            line-height: 1.2;
            margin-top: 1px;
        }

        .conversation-aside {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            flex-shrink: 0;
        }

        .conversation-time {
            color: var(--text-soft);
            font-size: 0.79rem;
            line-height: 1;
        }

        .conversation-item.has-unread .conversation-time {
            color: #0a7c66;
            font-weight: 700;
        }

        .conversation-unread-badge {
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #25d366;
            color: #f8fffb;
            font-size: 0.74rem;
            font-weight: 700;
            box-shadow: 0 8px 14px rgba(37, 211, 102, 0.24);
        }

        .conversation-meta-row {
            align-items: center;
        }

        .conversation-meta {
            min-width: 0;
            color: var(--text-soft);
            font-size: 0.84rem;
        }

        .conversation-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(31, 44, 52, 0.08);
            color: var(--text-main);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }

        .conversation-status-badge.is-inactive {
            background: rgba(164, 125, 38, 0.12);
            color: #7a5a12;
        }

        .conversation-status-badge.is-archived {
            background: rgba(31, 44, 52, 0.12);
            color: #46545b;
        }

        .conversation-preview {
            display: block;
            color: var(--text-soft);
            line-height: 1.34;
            font-size: 0.88rem;
        }

        .thread {
            display: grid;
            grid-template-rows: auto auto minmax(0, 1fr) auto;
            min-height: 0;
            height: 100%;
            overflow: hidden;
            background: linear-gradient(rgba(255, 255, 255, 0.32), rgba(255, 255, 255, 0.08)), var(--bg-thread);
        }

        .thread-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            padding: 18px 22px 0;
        }

        .thread-toolbar .note {
            color: var(--text-soft);
            font-size: 0.92rem;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mobile-back {
            display: none;
            border: 0;
            border-radius: 999px;
            padding: 12px 16px;
            background: rgba(31, 44, 52, 0.10);
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.92rem;
            cursor: pointer;
            white-space: nowrap;
        }

        .refresh-button {
            border: 0;
            border-radius: 999px;
            padding: 12px 18px;
            background: #0a7c66;
            color: #f8fffb;
            font-family: inherit;
            font-size: 0.94rem;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(10, 124, 102, 0.22);
            white-space: nowrap;
        }

        .live-status {
            color: var(--text-soft);
            font-size: 0.86rem;
        }

        .thread-header {
            padding: 14px 22px 12px;
        }

        .thread-header-card {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            padding: 18px 20px;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.84), rgba(255, 255, 255, 0.62));
            border: 1px solid rgba(31, 44, 52, 0.07);
            box-shadow: 0 16px 35px rgba(31, 44, 52, 0.08);
        }

        .thread-header-main {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
            flex: 1;
        }

        .thread-avatar {
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: linear-gradient(135deg, #0a7c66, #21a184);
            color: #f8fffb;
            font-size: 1.05rem;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 10px 20px rgba(10, 124, 102, 0.18);
        }

        .thread-header-copy {
            min-width: 0;
            flex: 1;
        }

        .thread-header-card strong,
        .thread-header-card span,
        .thread-stats {
            display: block;
        }

        .thread-header-card span {
            margin-top: 4px;
            color: var(--text-soft);
        }

        .thread-stats {
            text-align: right;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .thread-feed {
            overflow: auto;
            padding: 10px 22px 24px;
            display: flex;
            flex: 1;
            min-height: 0;
            flex-direction: column;
            gap: 12px;
            overscroll-behavior: contain;
            background:
                radial-gradient(circle at top right, rgba(10, 124, 102, 0.08), transparent 18%),
                radial-gradient(circle at 22px 22px, rgba(255, 255, 255, 0.22), transparent 20px),
                radial-gradient(circle at 110px 86px, rgba(10, 124, 102, 0.06), transparent 18px),
                linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0));
        }

        .message-row {
            display: flex;
        }

        .message-row.outgoing {
            justify-content: flex-end;
        }

        .message-bubble {
            width: min(720px, 78%);
            border-radius: 16px;
            padding: 9px 12px 7px;
            background: var(--bg-incoming);
            box-shadow: 0 8px 18px rgba(31, 44, 52, 0.08);
            border: 1px solid rgba(31, 44, 52, 0.06);
            position: relative;
        }

        .message-row.outgoing .message-bubble {
            background: var(--bg-outgoing);
        }

        .message-row.incoming .message-bubble {
            border-top-left-radius: 6px;
        }

        .message-row.outgoing .message-bubble {
            border-top-right-radius: 6px;
        }

        .message-row.incoming .message-bubble::after,
        .message-row.outgoing .message-bubble::after {
            content: "";
            position: absolute;
            top: -1px;
            width: 12px;
            height: 14px;
            background: inherit;
            border-top: 1px solid rgba(31, 44, 52, 0.06);
        }

        .message-row.incoming .message-bubble::after {
            left: -6px;
            clip-path: polygon(100% 0, 100% 100%, 0 0);
            border-left: 1px solid rgba(31, 44, 52, 0.06);
        }

        .message-row.outgoing .message-bubble::after {
            right: -6px;
            clip-path: polygon(0 0, 100% 0, 0 100%);
            border-right: 1px solid rgba(31, 44, 52, 0.06);
        }

        .message-top,
        .message-bottom {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }

        .message-top {
            margin-bottom: 8px;
            color: var(--text-soft);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .message-status {
            display: inline-flex;
            align-items: center;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .message-status-sent {
            background: rgba(17, 114, 71, 0.12);
            color: #176e4e;
        }

        .message-status-skipped,
        .message-status-logged {
            background: rgba(150, 115, 12, 0.14);
            color: #8c6a05;
        }

        .message-status-failed {
            background: rgba(201, 43, 65, 0.12);
            color: #a52337;
        }

        .message-body p {
            margin: 0;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.9rem;
        }

        .message-row.outgoing .message-body p {
            font-size: 0.86rem;
            line-height: 1.46;
        }

        .message-placeholder {
            color: var(--text-soft);
            font-style: italic;
        }

        .message-bottom {
            margin-top: 8px;
            color: var(--text-soft);
            font-size: 0.76rem;
        }

        .message-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .message-delete-button {
            border: 0;
            border-radius: 999px;
            padding: 7px 12px;
            background: rgba(201, 43, 65, 0.12);
            color: #a52337;
            font-family: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 120ms ease, color 120ms ease, opacity 120ms ease;
        }

        .message-delete-button:hover {
            background: rgba(201, 43, 65, 0.18);
            color: #8f1427;
        }

        .message-delete-button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .message-details {
            margin-top: 12px;
            border-top: 1px solid rgba(31, 44, 52, 0.08);
            padding-top: 10px;
        }

        .message-details summary {
            cursor: pointer;
            color: #0a7c66;
            font-weight: 700;
        }

        .message-details pre {
            margin: 10px 0 0;
            padding: 12px;
            max-height: 240px;
            overflow: auto;
            border-radius: 14px;
            background: rgba(31, 44, 52, 0.06);
            color: #233238;
            font-size: 0.8rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .composer-wrap {
            padding: 0 22px 18px;
        }

        .composer-form {
            padding: 12px;
            border-radius: 20px;
            background: rgba(244, 246, 247, 0.92);
            border: 1px solid rgba(31, 44, 52, 0.08);
            box-shadow: 0 10px 22px rgba(31, 44, 52, 0.08);
            backdrop-filter: blur(14px);
        }

        .composer-fields {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: end;
        }

        .composer-stack {
            display: grid;
            gap: 8px;
            min-width: 0;
        }

        .composer-provider {
            width: 100%;
            height: 42px;
            border: 1px solid rgba(31, 44, 52, 0.12);
            border-radius: 14px;
            padding: 0 12px;
            font: inherit;
            color: var(--text-main);
            background: #fff;
            outline: none;
        }

        .composer-fields textarea {
            width: 100%;
            min-height: 74px;
            max-height: 180px;
            resize: vertical;
            border: 1px solid rgba(31, 44, 52, 0.12);
            border-radius: 18px;
            padding: 12px 14px;
            font: inherit;
            line-height: 1.5;
            color: var(--text-main);
            background: #fff;
            outline: none;
        }

        .send-button {
            min-width: 120px;
            height: 48px;
            border: 0;
            border-radius: 999px;
            background: linear-gradient(135deg, #0a7c66, #0c8e74);
            color: #f8fffb;
            font-family: inherit;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .composer-hint,
        .composer-feedback {
            margin-top: 10px;
            font-size: 0.86rem;
        }

        .composer-hint {
            color: var(--text-soft);
        }

        .composer-feedback.is-error {
            color: #a4372c;
        }

        .composer-feedback.is-success {
            color: #075e54;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            color: var(--text-soft);
            font-size: 0.88rem;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #19b97e;
            box-shadow: 0 0 0 4px rgba(25, 185, 126, 0.15);
        }

        .empty-list,
        .empty-thread,
        .error-box {
            display: grid;
            place-items: center;
            text-align: center;
            min-height: 180px;
            padding: 28px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.55);
            color: var(--text-soft);
            border: 1px dashed rgba(31, 44, 52, 0.12);
        }

        .empty-list strong,
        .empty-thread strong,
        .error-box strong {
            color: var(--text-main);
            margin-bottom: 6px;
        }

        @media (max-width: 980px) {
            body {
                padding: 12px;
            }

            .shell {
                height: calc(100vh - 24px);
                height: calc(100dvh - 24px);
            }

            .layout {
                grid-template-columns: 1fr;
                grid-template-rows: minmax(0, 1fr);
            }

            .thread {
                display: none;
            }

            .shell.is-thread-open .sidebar {
                display: none;
            }

            .shell.is-thread-open .thread {
                display: grid;
            }

            .sidebar {
                border-right: 0;
                border-bottom: 0;
                max-height: none;
                padding-bottom: calc(18px + var(--safe-bottom));
            }

            .mobile-back {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .shell:not(.is-thread-open) .mobile-back {
                display: none;
            }

            .app-header,
            .thread-header-card,
            .thread-toolbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-meta,
            .thread-stats {
                text-align: left;
            }

            .shell.is-thread-open .app-header {
                display: none;
            }

            .shell.is-thread-open {
                grid-template-rows: minmax(0, 1fr);
            }

            .shell.is-thread-open .thread-toolbar {
                position: sticky;
                top: 0;
                z-index: 3;
                padding: calc(8px + var(--safe-top)) 10px 0;
                gap: 8px;
                background: linear-gradient(180deg, rgba(239, 231, 221, 0.98), rgba(239, 231, 221, 0.82));
                backdrop-filter: blur(8px);
            }

            .shell.is-thread-open .thread-toolbar .note,
            .shell.is-thread-open .live-status {
                display: none;
            }

            .shell.is-thread-open .toolbar-actions {
                width: 100%;
                display: grid;
                grid-template-columns: minmax(92px, 108px) 1fr;
                gap: 10px;
                align-items: center;
            }

            .shell.is-thread-open .refresh-button,
            .shell.is-thread-open .mobile-back {
                width: 100%;
                padding: 10px 12px;
            }

            .shell.is-thread-open .thread-header {
                padding: 6px 10px 4px;
            }

            .shell.is-thread-open .thread-header-card {
                padding: 12px;
                gap: 10px;
                border-radius: 18px;
                background: rgba(255, 255, 255, 0.92);
                box-shadow: 0 10px 22px rgba(31, 44, 52, 0.06);
            }

            .shell.is-thread-open .thread-header-main {
                gap: 10px;
                align-items: flex-start;
            }

            .shell.is-thread-open .thread-avatar {
                width: 42px;
                height: 42px;
                font-size: 0.9rem;
                box-shadow: 0 8px 16px rgba(10, 124, 102, 0.14);
            }

            .shell.is-thread-open .thread-header-copy {
                display: grid;
                gap: 6px;
            }

            .shell.is-thread-open .thread-header-card span {
                margin-top: 0;
                font-size: 0.79rem;
                line-height: 1.3;
            }

            .shell.is-thread-open .thread-header-card strong {
                font-size: 0.98rem;
            }

            .shell.is-thread-open .thread-stats {
                display: none;
            }

            .shell.is-thread-open .thread-badges,
            .shell.is-thread-open .thread-actions {
                flex-wrap: nowrap;
                overflow-x: auto;
                scrollbar-width: none;
                -ms-overflow-style: none;
                padding-bottom: 2px;
            }

            .shell.is-thread-open .thread-badges::-webkit-scrollbar,
            .shell.is-thread-open .thread-actions::-webkit-scrollbar {
                display: none;
            }

            .shell.is-thread-open .thread-badges {
                margin-top: 0;
                gap: 6px;
            }

            .shell.is-thread-open .thread-actions {
                margin-top: 6px;
                gap: 6px;
            }

            .shell.is-thread-open .thread-pill,
            .shell.is-thread-open .thread-action-button {
                flex: 0 0 auto;
            }

            .shell.is-thread-open .thread-action-button {
                padding: 8px 12px;
                font-size: 0.78rem;
            }

            .shell.is-thread-open .thread-feed {
                padding: 6px 12px 18px;
                gap: 10px;
            }

            .shell.is-thread-open .composer-wrap {
                position: sticky;
                bottom: 0;
                z-index: 2;
                padding: 8px 10px calc(10px + var(--safe-bottom));
                background: linear-gradient(180deg, rgba(239, 231, 221, 0), rgba(239, 231, 221, 0.94) 38%, rgba(239, 231, 221, 0.98));
            }

            .shell.is-thread-open .composer-form {
                padding: 10px;
                border-radius: 18px;
            }

            .shell.is-thread-open .composer-hint {
                display: none;
            }

            .shell.is-thread-open .message-row {
                padding: 0 2px;
            }

            .shell.is-thread-open .message-bubble {
                width: min(100%, 96%);
                padding: 12px 13px 10px;
                border-radius: 18px;
                box-shadow: 0 10px 22px rgba(31, 44, 52, 0.06);
            }

            .shell.is-thread-open .message-row.incoming .message-bubble::after,
            .shell.is-thread-open .message-row.outgoing .message-bubble::after {
                display: none;
            }

            .shell.is-thread-open .message-top {
                margin-bottom: 10px;
                font-size: 0.64rem;
                letter-spacing: 0.04em;
            }

            .shell.is-thread-open .message-body p,
            .shell.is-thread-open .message-row.outgoing .message-body p {
                font-size: 0.95rem;
                line-height: 1.62;
            }

            .shell.is-thread-open .message-bottom {
                margin-top: 10px;
                align-items: flex-start;
                flex-wrap: wrap;
                gap: 8px;
                font-size: 0.72rem;
            }

            .shell.is-thread-open .message-delete-button {
                padding: 6px 10px;
                font-size: 0.72rem;
            }

            .shell.is-thread-open .message-details {
                margin-top: 10px;
                padding-top: 8px;
            }

            .shell.is-thread-open .message-details summary {
                font-size: 0.8rem;
            }

            .shell.is-thread-open .message-details pre {
                max-height: 180px;
            }

            .composer-fields {
                grid-template-columns: 1fr;
            }

            .shell.is-thread-open .composer-fields textarea {
                min-height: 66px;
                max-height: 140px;
                padding: 14px;
                line-height: 1.45;
                font-size: 16px;
            }

            .send-button {
                width: 100%;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 0;
                background: #e7ded4;
            }

            .shell {
                width: 100%;
                height: 100vh;
                height: 100dvh;
                border-radius: 0;
                border: 0;
                box-shadow: none;
            }

            .app-header {
                padding: calc(12px + var(--safe-top)) 14px 12px;
                gap: 8px;
            }

            .app-header h1 {
                font-size: 1.02rem;
            }

            .app-header p {
                font-size: 0.76rem;
            }

            .status-pill {
                padding: 8px 10px;
                font-size: 0.72rem;
            }

            .header-meta p {
                font-size: 0.72rem;
            }

            .sidebar {
                padding: calc(10px + var(--safe-top)) 10px calc(12px + var(--safe-bottom));
                gap: 10px;
            }

            .search-box {
                padding: 12px;
                border-radius: 16px;
            }

            .search-box label {
                font-size: 0.76rem;
            }

            .search-box input {
                font-size: 16px;
            }

            .search-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .search-actions button,
            .search-actions a {
                text-align: center;
                padding: 10px 12px;
            }

            .conversation-count {
                font-size: 0.78rem;
                padding: 7px 10px;
            }

            .view-switcher {
                gap: 6px;
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 2px;
                scrollbar-width: none;
            }

            .view-switcher::-webkit-scrollbar {
                display: none;
            }

            .view-chip {
                flex: 0 0 auto;
                padding: 8px 11px;
                font-size: 0.76rem;
            }

            .conversation-list {
                gap: 8px;
            }

            .conversation-item {
                grid-template-columns: 38px minmax(0, 1fr);
                gap: 10px;
                padding: 12px 10px;
                border-radius: 16px;
            }

            .conversation-item.is-active::before {
                top: 8px;
                bottom: 8px;
                width: 3px;
            }

            .avatar {
                width: 38px;
                height: 38px;
                border-radius: 999px;
                font-size: 0.82rem;
            }

            .conversation-top {
                align-items: flex-start;
                gap: 8px;
            }

            .conversation-top strong {
                font-size: 0.84rem;
            }

            .conversation-meta,
            .conversation-preview {
                font-size: 0.7rem;
                line-height: 1.2;
            }

            .conversation-aside {
                gap: 4px;
            }

            .conversation-time {
                font-size: 0.68rem;
            }

            .conversation-unread-badge {
                min-width: 18px;
                height: 18px;
                font-size: 0.66rem;
            }

            .thread-toolbar {
                padding: calc(8px + var(--safe-top)) 10px 0;
                gap: 8px;
            }

            .thread-toolbar .note {
                font-size: 0.74rem;
                line-height: 1.25;
            }

            .mobile-back,
            .refresh-button {
                width: 100%;
                text-align: center;
                padding: 11px 12px;
            }

            .thread-header {
                padding: 8px 10px 6px;
            }

            .thread-header-card {
                padding: 10px 12px;
                border-radius: 16px;
                gap: 8px;
            }

            .thread-header-main {
                gap: 8px;
            }

            .thread-avatar {
                width: 38px;
                height: 38px;
                font-size: 0.82rem;
            }

            .thread-header-card strong {
                font-size: 0.92rem;
            }

            .thread-header-card span,
            .thread-stats {
                font-size: 0.73rem;
                line-height: 1.3;
            }

            .thread-badges {
                gap: 5px;
            }

            .thread-pill,
            .thread-action-button {
                padding: 7px 11px;
                font-size: 0.72rem;
            }

            .thread-feed {
                padding: 6px 10px 14px;
                gap: 10px;
            }

            .message-bubble {
                width: min(100%, 96%);
                padding: 12px 12px 10px;
                border-radius: 14px;
            }

            .message-top {
                font-size: 0.63rem;
                margin-bottom: 8px;
            }

            .message-body p {
                font-size: 0.92rem;
                line-height: 1.58;
            }

            .message-row.outgoing .message-body p {
                font-size: 0.92rem;
                line-height: 1.58;
            }

            .message-bottom {
                margin-top: 8px;
                font-size: 0.71rem;
            }

            .message-details pre {
                font-size: 0.72rem;
                max-height: 140px;
            }

            .composer-wrap {
                padding: 10px 10px calc(10px + var(--safe-bottom));
            }

            .composer-form {
                border-radius: 18px;
                padding: 10px;
            }

            .composer-fields {
                gap: 8px;
            }

            .composer-provider {
                height: 40px;
                border-radius: 12px;
                font-size: 16px;
            }

            .composer-fields textarea {
                min-height: 60px;
                padding: 12px;
                border-radius: 14px;
                font-size: 16px;
            }

            .composer-hint,
            .composer-feedback {
                margin-top: 6px;
                font-size: 0.74rem;
            }

            .empty-list,
            .empty-thread,
            .error-box {
                min-height: 100px;
                padding: 14px 12px;
                border-radius: 14px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="app-header">
            <div>
                <h1>Painel de Mensagens</h1>
                <p>Visual estilo WhatsApp para acompanhar tudo que entra e sai por numero.</p>
            </div>
            <div class="header-meta">
                <div class="status-pill">
                    <span class="status-dot"></span>
                    <span>Atualizacao automatica a cada <?= $refreshSeconds ?> segundos</span>
                </div>
                <p id="last-sync">Ultima sincronizacao: <?= e((new DateTimeImmutable())->format('d/m/Y H:i:s')) ?></p>
                <div class="header-links">
                    <a class="header-link" href="settings_admin.php">Configurações</a>
                </div>
            </div>
        </header>

        <div class="layout">
            <aside class="sidebar">
                <form method="get" id="search-form" class="search-box">
                    <?php if ($providedToken !== ''): ?>
                        <input type="hidden" name="token" value="<?= e($providedToken) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <label for="search">Buscar por numero</label>
                    <input id="search" type="text" name="search" placeholder="Ex.: 55669999" value="<?= e($search) ?>">
                    <div class="search-actions">
                        <button type="submit">Buscar</button>
                        <a href="#" id="clear-search">Limpar</a>
                    </div>
                </form>

                <div class="conversation-count"><span id="conversation-count"><?= count($conversations) ?></span> conversa(s) carregada(s)</div>
                <div class="view-switcher" id="view-switcher">
                    <button type="button" class="view-chip<?= $view === 'ativas' ? ' is-active' : '' ?>" data-view="ativas">Ativas</button>
                    <button type="button" class="view-chip<?= $view === 'nao_lidas' ? ' is-active' : '' ?>" data-view="nao_lidas">Nao lidas</button>
                    <button type="button" class="view-chip<?= $view === 'lidas' ? ' is-active' : '' ?>" data-view="lidas">Lidas</button>
                    <button type="button" class="view-chip<?= $view === 'inativas' ? ' is-active' : '' ?>" data-view="inativas">Inativas</button>
                    <button type="button" class="view-chip<?= $view === 'arquivadas' ? ' is-active' : '' ?>" data-view="arquivadas">Arquivadas</button>
                    <button type="button" class="view-chip<?= $view === 'todas' ? ' is-active' : '' ?>" data-view="todas">Todas</button>
                </div>

                <div class="conversation-list" id="conversation-list">
                    <?php if ($errorMessage !== null): ?>
                        <div class="error-box">
                            <strong>Falha ao carregar.</strong>
                            <span><?= e($errorMessage) ?></span>
                        </div>
                    <?php elseif ($conversations === []): ?>
                        <div class="empty-list">
                            <strong>Nenhuma conversa encontrada.</strong>
                            <span>Assim que chegarem mensagens, elas aparecem aqui.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conversation): ?>
                            <?php
                                $phone = (string) ($conversation['phone'] ?? '');
                                $name = extractDisplayName($conversation);
                                $preview = summarizeMessage($conversation['last_message_text'] ?? null, (string) ($conversation['last_message_type'] ?? 'unknown'));
                                $avatar = mb_strtoupper(mb_substr($name, 0, 1));
                                $status = (string) ($conversation['conversation_status'] ?? 'active');
                                $statusLabel = $status === 'archived' ? 'Arquivada' : ($status === 'inactive' ? 'Inativa' : 'Ativa');
                                $statusClass = $status === 'archived' ? ' is-archived' : ($status === 'inactive' ? ' is-inactive' : '');
                                $unreadCount = (int) ($conversation['unread_count'] ?? 0);
                            ?>
                            <a class="conversation-item<?= $phone === $selectedPhone ? ' is-active' : '' ?><?= $unreadCount > 0 ? ' has-unread' : '' ?><?= e($statusClass) ?>" href="#" data-phone="<?= e($phone) ?>">
                                <span class="avatar"><?= e($avatar !== '' ? $avatar : '#') ?></span>
                                <span class="conversation-main">
                                    <span class="conversation-top">
                                        <strong><?= e($name) ?></strong>
                                        <span class="conversation-aside">
                                            <time class="conversation-time"><?= e(formatTimeLabel((string) ($conversation['last_message_at'] ?? ''))) ?></time>
                                            <?php if ($unreadCount > 0): ?>
                                                <span class="conversation-unread-badge"><?= $unreadCount ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <span class="conversation-meta-row">
                                        <span class="conversation-meta"><?= e(formatPhoneNumber($phone)) ?></span>
                                        <?php if ($status !== 'active'): ?>
                                            <span class="conversation-status-badge<?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="conversation-preview"><?= e($preview) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>
            <main class="thread">
                <div class="thread-toolbar">
                    <div class="note">Historico completo das mensagens registradas na tabela <code>message_logs</code>.</div>
                    <div class="toolbar-actions">
                        <button type="button" class="mobile-back" id="mobile-back">Voltar</button>
                        <button type="button" class="refresh-button" id="refresh-button">Atualizar agora</button>
                        <div class="live-status" id="live-status">Atualizacao em segundo plano ativa.</div>
                    </div>
                </div>
                <section class="thread-header" id="thread-header">
                    <?php if ($selectedConversation === null): ?>
                        <div class="empty-thread">
                            <strong>Selecione uma conversa.</strong>
                            <span>O historico da conversa aparece aqui.</span>
                        </div>
                    <?php else: ?>
                        <?php
                            $selectedName = extractDisplayName($selectedConversation);
                            $selectedAvatar = mb_strtoupper(mb_substr($selectedName, 0, 1));
                            $selectedStatus = (string) ($selectedConversation['conversation_status'] ?? 'active');
                            $selectedUnreadCount = (int) ($selectedConversation['unread_count'] ?? 0);
                            $selectedHasIncoming = (int) ($selectedConversation['incoming_messages'] ?? 0) > 0;
                        ?>
                        <div class="thread-header-card">
                            <div class="thread-header-main">
                                <span class="thread-avatar"><?= e($selectedAvatar !== '' ? $selectedAvatar : '#') ?></span>
                                <div class="thread-header-copy">
                                    <strong><?= e($selectedName) ?></strong>
                                    <span><?= e(formatPhoneNumber((string) ($selectedConversation['phone'] ?? ''))) ?></span>
                                    <div class="thread-badges">
                                        <span class="thread-pill is-status status-<?= e($selectedStatus) ?>"><?= e($selectedStatus === 'archived' ? 'Arquivada' : ($selectedStatus === 'inactive' ? 'Inativa' : 'Ativa')) ?></span>
                                        <span class="thread-pill is-read-state<?= $selectedUnreadCount > 0 ? ' is-unread' : '' ?>"><?= $selectedUnreadCount > 0 ? $selectedUnreadCount . ' nao lida' . ($selectedUnreadCount > 1 ? 's' : '') : 'Lida' ?></span>
                                    </div>
                                    <div class="thread-actions">
                                        <?php if ($selectedHasIncoming): ?>
                                            <button type="button" class="thread-action-button" data-conversation-action="<?= $selectedUnreadCount > 0 ? 'mark_read' : 'mark_unread' ?>">
                                                <?= $selectedUnreadCount > 0 ? 'Marcar como lida' : 'Marcar como nao lida' ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($selectedStatus !== 'active'): ?>
                                            <button type="button" class="thread-action-button" data-conversation-action="activate">Mover para ativas</button>
                                        <?php endif; ?>
                                        <?php if ($selectedStatus !== 'inactive'): ?>
                                            <button type="button" class="thread-action-button" data-conversation-action="inactivate">Inativar</button>
                                        <?php endif; ?>
                                        <?php if ($selectedStatus !== 'archived'): ?>
                                            <button type="button" class="thread-action-button" data-conversation-action="archive">Arquivar</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="thread-stats">
                                <span><b><?= (int) ($selectedConversation['total_messages'] ?? 0) ?></b> msgs</span>
                                <span><b><?= (int) ($selectedConversation['incoming_messages'] ?? 0) ?></b> recebidas</span>
                                <span><b><?= (int) ($selectedConversation['outgoing_messages'] ?? 0) ?></b> enviadas</span>
                                <span><b><?= e(formatDateTimeLabel((string) ($selectedConversation['last_message_at'] ?? ''))) ?></b> ultima atividade</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="thread-feed" id="thread-feed">
                    <?php if ($selectedConversation !== null && $messages === []): ?>
                        <div class="empty-thread">
                            <strong>Sem mensagens nessa conversa.</strong>
                            <span>O numero foi localizado, mas ainda nao ha historico dentro do limite selecionado.</span>
                        </div>
                    <?php elseif ($selectedConversation !== null): ?>
                        <?php foreach ($messages as $message): ?>
                            <?php
                                $isOutgoing = ($message['direction'] ?? 'in') === 'out';
                                $messageId = (int) ($message['id'] ?? 0);
                                $text = trim((string) ($message['normalized_text'] ?? ''));
                                $displayText = formatMessageForDisplay($text, $isOutgoing);
                                $payload = decodeRawPayload($message['raw_payload'] ?? null);
                                $sendStatus = $isOutgoing ? extractSendStatus($payload) : '';
                                $payloadJson = $payload === []
                                    ? trim((string) ($message['raw_payload'] ?? ''))
                                    : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            ?>
                            <article class="message-row<?= $isOutgoing ? ' outgoing' : ' incoming' ?>">
                                <div class="message-bubble">
                                    <div class="message-top">
                                        <span><?= $isOutgoing ? 'Robo' : 'Cliente' ?></span>
                                        <span>
                                            <?= e(messageTypeBadge((string) ($message['message_type'] ?? 'unknown'))) ?>
                                            <?php if ($isOutgoing): ?>
                                                <small class="message-status message-status-<?= e($sendStatus) ?>"><?= e(sendStatusLabel($sendStatus)) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="message-body">
                                        <?php if ($displayText !== ''): ?>
                                            <p><?= e($displayText) ?></p>
                                        <?php else: ?>
                                            <p class="message-placeholder">Mensagem sem texto normalizado.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-bottom">
                                        <time><?= e(formatDateTimeLabel((string) ($message['created_at'] ?? ''))) ?></time>
                                        <?php if ($messageId > 0): ?>
                                            <div class="message-actions">
                                                <button type="button" class="message-delete-button" data-message-delete="1" data-message-id="<?= $messageId ?>">Excluir</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (is_string($payloadJson) && trim($payloadJson) !== ''): ?>
                                        <details class="message-details">
                                            <summary>Detalhes</summary>
                                            <pre><?= e($payloadJson) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
                <section class="composer-wrap" id="composer-area">
                    <?php $composerDisabled = $selectedPhone === '' || !$sendEnabled; ?>
                    <form id="send-form" class="composer-form">
                        <input type="hidden" name="phone" value="<?= e($selectedPhone) ?>">
                        <div class="composer-fields">
                            <textarea id="send-message" name="message" rows="3" placeholder="<?= e($selectedPhone === '' ? 'Selecione uma conversa para responder.' : 'Digite uma mensagem para enviar manualmente.') ?>"<?= $composerDisabled ? ' disabled' : '' ?>></textarea>
                            <button type="submit" class="send-button"<?= $composerDisabled ? ' disabled' : '' ?>>Enviar</button>
                        </div>
                        <div class="composer-hint" id="composer-hint"><?= e(!$sendEnabled ? 'Envio manual desabilitado. Ative WHATSAPP_SEND_ENABLED no .env para mandar mensagens daqui.' : ($selectedPhone === '' ? 'Escolha uma conversa na lista para habilitar o envio.' : 'Mensagem enviada por aqui tambem entra no historico.')) ?></div>
                        <div class="composer-feedback" id="composer-feedback"></div>
                    </form>
                </section>
            </main>
        </div>
    </div>

            <script>
        (() => {
            const state = {
                selectedPhone: <?= json_encode($selectedPhone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '',
                selectedProvider: <?= json_encode($selectedProvider, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '',
                search: <?= json_encode($search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '',
                view: <?= json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || 'ativas',
                token: <?= json_encode($providedToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '',
                refreshMs: <?= (int) ($refreshSeconds * 1000) ?>,
                loading: false,
                sending: false,
                deletingMessageId: null,
                mobileThreadOpen: false,
                userInteractingUntil: 0,
                cache: {
                    conversationList: '',
                    threadHeader: '',
                    messageFeed: '',
                    composer: '',
                },
            };

            const shell = document.querySelector('.shell');
            const mobileQuery = window.matchMedia('(max-width: 980px)');
            const conversationList = document.getElementById('conversation-list');
            const threadHeader = document.getElementById('thread-header');
            const threadFeed = document.getElementById('thread-feed');
            const composerArea = document.getElementById('composer-area');
            const lastSync = document.getElementById('last-sync');
            const liveStatus = document.getElementById('live-status');
            const conversationCount = document.getElementById('conversation-count');
            const refreshButton = document.getElementById('refresh-button');
            const backButton = document.getElementById('mobile-back');
            const searchForm = document.getElementById('search-form');
            const searchInput = document.getElementById('search');
            const clearSearch = document.getElementById('clear-search');
            const viewSwitcher = document.getElementById('view-switcher');

            state.mobileThreadOpen = mobileQuery.matches && Boolean(state.selectedPhone);
            state.cache.conversationList = conversationList ? conversationList.innerHTML : '';
            state.cache.threadHeader = threadHeader ? threadHeader.innerHTML : '';
            state.cache.messageFeed = threadFeed ? threadFeed.innerHTML : '';
            state.cache.composer = composerArea ? composerArea.innerHTML : '';

            function markInteraction(duration = 6000) {
                state.userInteractingUntil = Date.now() + duration;
            }

            function updateMobileView() {
                if (!shell) return;

                if (mobileQuery.matches) {
                    shell.classList.toggle('is-thread-open', state.mobileThreadOpen);
                } else {
                    shell.classList.remove('is-thread-open');
                }
            }

            function buildQuery(action) {
                const params = new URLSearchParams();
                params.set('action', action);
                params.set('conversation_limit', '60');
                params.set('message_limit', '200');
                if (state.selectedPhone) params.set('phone', state.selectedPhone);
                if (state.selectedProvider) params.set('provider', state.selectedProvider);
                if (state.search) params.set('search', state.search);
                if (state.view) params.set('view', state.view);
                if (state.token) params.set('token', state.token);
                return params;
            }

            function syncUrl() {
                const url = new URL(window.location.href);
                if (state.selectedPhone) url.searchParams.set('phone', state.selectedPhone); else url.searchParams.delete('phone');
                if (state.selectedProvider) url.searchParams.set('provider', state.selectedProvider); else url.searchParams.delete('provider');
                if (state.search) url.searchParams.set('search', state.search); else url.searchParams.delete('search');
                if (state.view) url.searchParams.set('view', state.view); else url.searchParams.delete('view');
                if (state.token) url.searchParams.set('token', state.token); else url.searchParams.delete('token');
                history.replaceState({}, '', url.toString());
            }

            function setFeedback(message, mode) {
                const feedback = composerArea ? composerArea.querySelector('#composer-feedback') : null;
                if (!feedback) return;
                feedback.textContent = message || '';
                feedback.className = 'composer-feedback' + (mode ? ' is-' + mode : '');
            }
            function updateViewButtons() {
                if (!viewSwitcher) return;

                viewSwitcher.querySelectorAll('[data-view]').forEach((button) => {
                    button.classList.toggle('is-active', button.getAttribute('data-view') === state.view);
                });
            }

            function applySnapshot(snapshot, options = {}) {
                if (!snapshot) return;

                const distanceFromBottom = threadFeed ? (threadFeed.scrollHeight - threadFeed.scrollTop) : 0;
                const wasNearBottom = threadFeed ? (distanceFromBottom - threadFeed.clientHeight < 80) : false;

                if (conversationList && snapshot.conversation_list_html !== state.cache.conversationList) {
                    conversationList.innerHTML = snapshot.conversation_list_html || '';
                    state.cache.conversationList = snapshot.conversation_list_html || '';
                }

                if (threadHeader && snapshot.thread_header_html !== state.cache.threadHeader) {
                    threadHeader.innerHTML = snapshot.thread_header_html || '';
                    state.cache.threadHeader = snapshot.thread_header_html || '';
                }

                if (threadFeed && snapshot.message_feed_html !== state.cache.messageFeed) {
                    threadFeed.innerHTML = snapshot.message_feed_html || '';
                    state.cache.messageFeed = snapshot.message_feed_html || '';
                }

                if (composerArea && snapshot.composer_html !== state.cache.composer && document.activeElement?.tagName !== 'TEXTAREA') {
                    composerArea.innerHTML = snapshot.composer_html || '';
                    state.cache.composer = snapshot.composer_html || '';
                }

                if (conversationCount) {
                    conversationCount.textContent = snapshot.conversations_count || 0;
                }

                if (lastSync) {
                    lastSync.textContent = 'Ultima sincronizacao: ' + (snapshot.refreshed_at || '-');
                }

                if (typeof snapshot.selected_phone === 'string') {
                    state.selectedPhone = snapshot.selected_phone;
                }

                if (typeof snapshot.selected_provider === 'string') {
                    state.selectedProvider = snapshot.selected_provider;
                }

                if (typeof snapshot.current_view === 'string') {
                    state.view = snapshot.current_view;
                }

                if (mobileQuery.matches && options.openThreadOnMobile) {
                    state.mobileThreadOpen = Boolean(state.selectedPhone);
                }

                if (threadFeed && (options.forceBottom || wasNearBottom)) {
                    threadFeed.scrollTop = threadFeed.scrollHeight;
                }

                syncUrl();
                updateMobileView();
                updateViewButtons();
            }

            async function refreshData(options = {}) {
                if (state.loading || state.sending || document.hidden) return;
                if (Date.now() < state.userInteractingUntil && !options.manual) return;
                if (document.activeElement && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName) && !options.manual) return;

                state.loading = true;
                if (refreshButton) refreshButton.disabled = true;
                if (liveStatus) liveStatus.textContent = options.manual ? 'Atualizando conversa...' : 'Sincronizando em segundo plano...';

                try {
                    const response = await fetch('message_monitor_api.php?' + buildQuery('refresh').toString(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Falha ao atualizar o painel.');
                    applySnapshot(payload.snapshot, options);
                    if (liveStatus) liveStatus.textContent = 'Painel sincronizado sem recarregar a tela.';
                } catch (error) {
                    if (liveStatus) liveStatus.textContent = error.message || 'Falha ao atualizar o painel.';
                } finally {
                    state.loading = false;
                    if (refreshButton) refreshButton.disabled = false;
                }
            }

            async function sendMessage(form) {
                if (state.sending || state.deletingMessageId !== null) return;

                const textarea = form.querySelector('textarea[name="message"]');
                const providerSelect = form.querySelector('select[name="provider"]');
                const button = form.querySelector('button[type="submit"]');
                const message = textarea ? textarea.value.trim() : '';
                const provider = providerSelect ? providerSelect.value.trim() : (state.selectedProvider || '');

                if (!message) {
                    setFeedback('Digite uma mensagem antes de enviar.', 'error');
                    return;
                }

                state.sending = true;
                markInteraction(8000);
                if (button) button.disabled = true;
                if (textarea) textarea.disabled = true;
                setFeedback('Enviando mensagem...', 'success');

                try {
                    const body = new URLSearchParams();
                    body.set('action', 'send');
                    body.set('phone', state.selectedPhone || '');
                    body.set('provider', provider);
                    body.set('search', state.search || '');
                    body.set('view', state.view || 'ativas');
                    body.set('message', message);
                    if (state.token) body.set('token', state.token);

                    const response = await fetch('message_monitor_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Falha ao enviar a mensagem.');
                    if (textarea) textarea.value = '';
                    state.selectedProvider = provider;
                    applySnapshot(payload.snapshot, { forceBottom: true, openThreadOnMobile: true });
                    setFeedback(payload.message || 'Mensagem enviada com sucesso.', 'success');
                    if (liveStatus) liveStatus.textContent = 'Mensagem enviada sem recarregar a pagina.';
                } catch (error) {
                    setFeedback(error.message || 'Falha ao enviar a mensagem.', 'error');
                    if (liveStatus) liveStatus.textContent = 'Falha no envio manual.';
                } finally {
                    state.sending = false;
                    const newForm = composerArea ? composerArea.querySelector('#send-form') : null;
                    const newButton = newForm ? newForm.querySelector('button[type="submit"]') : null;
                    const newTextarea = newForm ? newForm.querySelector('textarea[name="message"]') : null;
                    if (newButton && !newButton.hasAttribute('disabled')) newButton.disabled = false;
                    if (newTextarea && !newTextarea.hasAttribute('disabled')) newTextarea.disabled = false;
                }
            }


            async function deleteMessage(messageId, button) {
                if (state.deletingMessageId !== null || state.sending) return;
                if (!messageId || !window.confirm('Deseja excluir esta mensagem do historico?')) return;

                state.deletingMessageId = messageId;
                markInteraction(8000);
                if (button) button.disabled = true;
                setFeedback('Excluindo mensagem...', 'success');
                if (liveStatus) liveStatus.textContent = 'Excluindo mensagem do historico...';

                try {
                    const body = new URLSearchParams();
                    body.set('action', 'delete');
                    body.set('message_id', String(messageId));
                    body.set('phone', state.selectedPhone || '');
                    body.set('provider', state.selectedProvider || '');
                    body.set('search', state.search || '');
                    body.set('view', state.view || 'ativas');
                    if (state.token) body.set('token', state.token);

                    const response = await fetch('message_monitor_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Falha ao excluir a mensagem.');
                    applySnapshot(payload.snapshot, { openThreadOnMobile: true });
                    setFeedback(payload.message || 'Mensagem excluida com sucesso.', 'success');
                    if (liveStatus) liveStatus.textContent = 'Mensagem excluida sem recarregar a pagina.';
                } catch (error) {
                    setFeedback(error.message || 'Falha ao excluir a mensagem.', 'error');
                    if (liveStatus) liveStatus.textContent = 'Falha ao excluir a mensagem.';
                    if (button) button.disabled = false;
                } finally {
                    state.deletingMessageId = null;
                }
            }


            async function applyConversationAction(action, button) {
                if (!action || state.sending || state.deletingMessageId !== null) return;
                if (!state.selectedPhone) return;

                markInteraction(8000);
                if (button) button.disabled = true;
                setFeedback('Atualizando conversa...', 'success');
                if (liveStatus) liveStatus.textContent = 'Aplicando acao na conversa...';

                try {
                    const body = new URLSearchParams();
                    body.set('action', 'conversation');
                    body.set('conversation_action', action);
                    body.set('phone', state.selectedPhone || '');
                    body.set('provider', state.selectedProvider || '');
                    body.set('search', state.search || '');
                    body.set('view', state.view || 'ativas');
                    if (state.token) body.set('token', state.token);

                    const response = await fetch('message_monitor_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body.toString()
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Falha ao atualizar a conversa.');
                    applySnapshot(payload.snapshot, { openThreadOnMobile: true });
                    setFeedback(payload.message || 'Conversa atualizada.', 'success');
                    if (liveStatus) liveStatus.textContent = 'Conversa atualizada sem recarregar a pagina.';
                } catch (error) {
                    setFeedback(error.message || 'Falha ao atualizar a conversa.', 'error');
                    if (liveStatus) liveStatus.textContent = 'Falha ao atualizar a conversa.';
                    if (button) button.disabled = false;
                }
            }
            if (refreshButton) {
                refreshButton.addEventListener('click', () => {
                    markInteraction(3000);
                    refreshData({ manual: true });
                });
            }

            if (backButton) {
                backButton.addEventListener('click', () => {
                    state.mobileThreadOpen = false;
                    updateMobileView();
                });
            }

            if (searchForm) {
                searchForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    state.search = searchInput ? searchInput.value.trim() : '';
                    state.selectedPhone = '';
                    state.selectedProvider = '';
                    state.mobileThreadOpen = false;
                    markInteraction(3000);
                    refreshData({ manual: true });
                });
            }

            if (clearSearch) {
                clearSearch.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (searchInput) searchInput.value = '';
                    state.search = '';
                    state.selectedPhone = '';
                    state.selectedProvider = '';
                    state.mobileThreadOpen = false;
                    markInteraction(3000);
                    refreshData({ manual: true });
                });
            }

            if (viewSwitcher) {
                viewSwitcher.addEventListener('click', (event) => {
                    const chip = event.target.closest('[data-view]');
                    if (!chip) return;
                    event.preventDefault();
                    state.view = chip.getAttribute('data-view') || 'ativas';
                    state.selectedPhone = '';
                    state.selectedProvider = '';
                    state.mobileThreadOpen = false;
                    updateViewButtons();
                    markInteraction(3000);
                    refreshData({ manual: true });
                });
            }

            if (conversationList) {
                conversationList.addEventListener('click', (event) => {
                    const item = event.target.closest('[data-phone]');
                    if (!item) return;
                    event.preventDefault();
                    state.selectedPhone = item.getAttribute('data-phone') || '';
                    state.selectedProvider = item.getAttribute('data-provider') || '';
                    state.mobileThreadOpen = true;
                    markInteraction(3000);
                    updateMobileView();
                    refreshData({ forceBottom: true, manual: true, openThreadOnMobile: true });
                });
            }

            document.addEventListener('submit', (event) => {
                if (event.target && event.target.id === 'send-form') {
                    event.preventDefault();
                    sendMessage(event.target);
                }
            });

            document.addEventListener('click', (event) => {
                const deleteButton = event.target.closest('[data-message-delete]');
                if (deleteButton) {
                    event.preventDefault();
                    const messageId = Number(deleteButton.getAttribute('data-message-id') || '0');
                    deleteMessage(messageId, deleteButton);
                    return;
                }

                const conversationButton = event.target.closest('[data-conversation-action]');
                if (!conversationButton) return;
                event.preventDefault();
                applyConversationAction(conversationButton.getAttribute('data-conversation-action') || '', conversationButton);
            });

            document.addEventListener('focusin', () => markInteraction(8000));
            if (threadFeed) {
                threadFeed.addEventListener('scroll', () => markInteraction(4000), { passive: true });
                threadFeed.scrollTop = threadFeed.scrollHeight;
            }

            if (searchInput) {
                searchInput.addEventListener('input', () => markInteraction(8000));
            }

            if (mobileQuery.addEventListener) {
                mobileQuery.addEventListener('change', updateMobileView);
            } else if (mobileQuery.addListener) {
                mobileQuery.addListener(updateMobileView);
            }

            updateMobileView();
            updateViewButtons();
            refreshData({ manual: true, openThreadOnMobile: Boolean(state.selectedPhone) });
            window.setInterval(() => refreshData(), state.refreshMs);
        })();
    </script>
</body>
</html>













