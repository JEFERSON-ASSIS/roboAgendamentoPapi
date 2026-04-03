<?php

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_name('robo_settings_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Service\EnvFileManager;

function sa_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sa_normalize_bool(string $value): string
{
    return strtolower(trim($value)) === 'true' ? 'true' : 'false';
}

function sa_field(array $field): array
{
    return $field;
}

function sa_group(string $title, string $description, array $fields): array
{
    return [
        'title' => $title,
        'description' => $description,
        'fields' => $fields,
    ];
}

function sa_resolve_profile_value(array $values, string $baseKey, string $profile): string
{
    $baseValue = (string) ($values[$baseKey] ?? '');

    if ($profile === '') {
        return $baseValue;
    }

    $suffix = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $profile) ?? '');
    $suffix = trim($suffix, '_');

    if ($suffix === '') {
        return $baseValue;
    }

    $profileKey = $baseKey . '_' . $suffix;

    return array_key_exists($profileKey, $values) ? (string) ($values[$profileKey] ?? '') : $baseValue;
}

$envManager = new EnvFileManager(base_path('.env'));
$csrfToken = $_SESSION['settings_admin_csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['settings_admin_csrf'] = $csrfToken;

$expectedUsername = (string) env('SETTINGS_ADMIN_USERNAME', 'admin');
$expectedPassword = (string) env('SETTINGS_ADMIN_PASSWORD', (string) env('SESSION_ADMIN_TOKEN', ''));

$groups = [
    sa_group('Aplicacao', 'Identidade e ambiente geral do robo.', [
        sa_field(['key' => 'APP_NAME', 'label' => 'Nome do app']),
        sa_field(['key' => 'APP_ENV', 'label' => 'Ambiente']),
        sa_field(['key' => 'APP_DEBUG', 'label' => 'Debug', 'type' => 'boolean']),
        sa_field(['key' => 'APP_URL', 'label' => 'URL do app']),
        sa_field(['key' => 'CHAT_URL', 'label' => 'URL do chat']),
        sa_field(['key' => 'APP_TIMEZONE', 'label' => 'Timezone']),
    ]),
    sa_group('Logs E Sessao', 'Caminhos e comportamento de sessao.', [
        sa_field(['key' => 'LOG_CHANNEL', 'label' => 'Canal de log']),
        sa_field(['key' => 'LOG_LEVEL', 'label' => 'Nivel de log']),
        sa_field(['key' => 'LOG_PATH', 'label' => 'Arquivo de log']),
        sa_field(['key' => 'SESSION_STORE_PATH', 'label' => 'Arquivo de sessoes']),
        sa_field(['key' => 'SESSION_TTL_MINUTES', 'label' => 'TTL da sessao (min)']),
        sa_field(['key' => 'SESSION_ADMIN_TOKEN', 'label' => 'Token admin de sessoes', 'type' => 'secret']),
    ]),
    sa_group('WhatsApp Geral', 'Selecao de provider, profile e flags compartilhadas.', [
        sa_field(['key' => 'WHATSAPP_PROVIDER', 'label' => 'Provider atual', 'type' => 'select', 'options' => ['evolution' => 'Evolution', 'papi' => 'PAPI']]),
        sa_field(['key' => 'WHATSAPP_DEFAULT_PROVIDER', 'label' => 'Provider padrao', 'type' => 'select', 'options' => ['evolution' => 'Evolution', 'papi' => 'PAPI']]),
        sa_field(['key' => 'WHATSAPP_MIXED_WEBHOOK_MODE', 'label' => 'Mixed webhook mode', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_PROVIDER_QUERY_KEY', 'label' => 'Query key do provider']),
        sa_field(['key' => 'WHATSAPP_PROFILE', 'label' => 'Profile ativo', 'type' => 'select', 'options' => ['' => 'Sem profile', 'psf01' => 'PSF01', 'psf02' => 'PSF02', 'psf03' => 'PSF03']]),
        sa_field(['key' => 'WHATSAPP_SPLIT_MESSAGES', 'label' => 'Dividir mensagens', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_SPLIT_MAX_LENGTH', 'label' => 'Limite por bloco']),
        sa_field(['key' => 'WHATSAPP_SPLIT_DELAY_MS', 'label' => 'Delay entre blocos (ms)']),
        sa_field(['key' => 'WHATSAPP_SEND_ENABLED', 'label' => 'Envio habilitado (legacy)', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_INTERACTIVE_ENABLED', 'label' => 'Interativo Evolution habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_TYPING_ENABLED', 'label' => 'Typing Evolution habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_TYPING_DELAY_MS', 'label' => 'Delay typing Evolution (ms)']),
        sa_field(['key' => 'WHATSAPP_TYPING_EACH_CHUNK', 'label' => 'Typing por chunk Evolution', 'type' => 'boolean']),
    ]),
    sa_group('Evolution Base', 'Configuracao principal do provider Evolution.', [
        sa_field(['key' => 'WHATSAPP_BASE_URL', 'label' => 'Base URL Evolution']),
        sa_field(['key' => 'WHATSAPP_INSTANCE', 'label' => 'Instancia Evolution']),
        sa_field(['key' => 'WHATSAPP_API_KEY', 'label' => 'API key Evolution', 'type' => 'secret']),
    ]),
    sa_group('Evolution Por Perfil', 'Instancias e chaves especificas por unidade.', [
        sa_field(['key' => 'WHATSAPP_INSTANCE_PSF01', 'label' => 'Instancia Evolution PSF01']),
        sa_field(['key' => 'WHATSAPP_API_KEY_PSF01', 'label' => 'API key Evolution PSF01', 'type' => 'secret']),
        sa_field(['key' => 'WHATSAPP_INSTANCE_PSF02', 'label' => 'Instancia Evolution PSF02']),
        sa_field(['key' => 'WHATSAPP_API_KEY_PSF02', 'label' => 'API key Evolution PSF02', 'type' => 'secret']),
        sa_field(['key' => 'WHATSAPP_INSTANCE_PSF03', 'label' => 'Instancia Evolution PSF03']),
        sa_field(['key' => 'WHATSAPP_API_KEY_PSF03', 'label' => 'API key Evolution PSF03', 'type' => 'secret']),
    ]),
    sa_group('PAPI Base', 'Configuracao principal do provider PAPI.', [
        sa_field(['key' => 'WHATSAPP_PAPI_BASE_URL', 'label' => 'Base URL PAPI']),
        sa_field(['key' => 'WHATSAPP_PAPI_INSTANCE', 'label' => 'Instancia PAPI']),
        sa_field(['key' => 'WHATSAPP_PAPI_API_KEY', 'label' => 'API key PAPI', 'type' => 'secret']),
        sa_field(['key' => 'WHATSAPP_PAPI_SEND_ENABLED', 'label' => 'Envio PAPI habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_PAPI_INTERACTIVE_ENABLED', 'label' => 'Interativo PAPI habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_PAPI_TYPING_ENABLED', 'label' => 'Typing PAPI habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_PAPI_TYPING_DELAY_MS', 'label' => 'Delay typing PAPI (ms)']),
        sa_field(['key' => 'WHATSAPP_PAPI_TYPING_EACH_CHUNK', 'label' => 'Typing por chunk PAPI', 'type' => 'boolean']),
        sa_field(['key' => 'WHATSAPP_PAPI_VALIDATE_NUMBER', 'label' => 'Validar numero PAPI', 'type' => 'boolean']),
    ]),
    sa_group('PAPI Por Perfil', 'Instancias e chaves PAPI por unidade.', [
        sa_field(['key' => 'WHATSAPP_PAPI_INSTANCE_PSF01', 'label' => 'Instancia PAPI PSF01']),
        sa_field(['key' => 'WHATSAPP_PAPI_API_KEY_PSF01', 'label' => 'API key PAPI PSF01', 'type' => 'secret']),
        sa_field(['key' => 'WHATSAPP_PAPI_INSTANCE_PSF02', 'label' => 'Instancia PAPI PSF02']),
        sa_field(['key' => 'WHATSAPP_PAPI_API_KEY_PSF02', 'label' => 'API key PAPI PSF02', 'type' => 'secret']),
        sa_field(['key' => 'WHATSAPP_PAPI_INSTANCE_PSF03', 'label' => 'Instancia PAPI PSF03']),
        sa_field(['key' => 'WHATSAPP_PAPI_API_KEY_PSF03', 'label' => 'API key PAPI PSF03', 'type' => 'secret']),
    ]),
    sa_group('IA', 'Modelo, chaves e transcricao.', [
        sa_field(['key' => 'AI_DRIVER', 'label' => 'Driver de IA']),
        sa_field(['key' => 'AI_PROVIDER', 'label' => 'Provider de IA']),
        sa_field(['key' => 'AI_BASE_URL', 'label' => 'Base URL IA']),
        sa_field(['key' => 'AI_API_KEY', 'label' => 'API key IA', 'type' => 'secret']),
        sa_field(['key' => 'AI_MODEL', 'label' => 'Modelo principal']),
        sa_field(['key' => 'AI_TRANSCRIPTION_MODEL', 'label' => 'Modelo de transcricao']),
        sa_field(['key' => 'AI_TRANSCRIPTION_LANGUAGE', 'label' => 'Idioma da transcricao']),
        sa_field(['key' => 'AI_AUDIO_DEBUG_SAVE', 'label' => 'Salvar audio debug', 'type' => 'boolean']),
        sa_field(['key' => 'AI_AUDIO_DEBUG_PATH', 'label' => 'Pasta audio debug']),
    ]),
    sa_group('Agenda', 'Integracao com a agenda.', [
        sa_field(['key' => 'AGENDA_BASE_URL', 'label' => 'Base URL agenda']),
        sa_field(['key' => 'AGENDA_EMPRESA', 'label' => 'Empresa']),
        sa_field(['key' => 'AGENDA_MOCK', 'label' => 'Mock agenda', 'type' => 'boolean']),
    ]),
    sa_group('Banco De Dados', 'Conexao local e servidor.', [
        sa_field(['key' => 'DB_CONNECTION', 'label' => 'Driver']),
        sa_field(['key' => 'DB_PROFILE', 'label' => 'Profile DB', 'type' => 'select', 'options' => ['auto' => 'Auto', 'local' => 'Local', 'server' => 'Servidor']]),
        sa_field(['key' => 'DB_LOCAL_HOST', 'label' => 'DB local host']),
        sa_field(['key' => 'DB_LOCAL_PORT', 'label' => 'DB local porta']),
        sa_field(['key' => 'DB_LOCAL_DATABASE', 'label' => 'DB local database']),
        sa_field(['key' => 'DB_LOCAL_USERNAME', 'label' => 'DB local usuario']),
        sa_field(['key' => 'DB_LOCAL_PASSWORD', 'label' => 'DB local senha', 'type' => 'secret']),
        sa_field(['key' => 'DB_SERVER_HOST', 'label' => 'DB servidor host']),
        sa_field(['key' => 'DB_SERVER_PORT', 'label' => 'DB servidor porta']),
        sa_field(['key' => 'DB_SERVER_DATABASE', 'label' => 'DB servidor database']),
        sa_field(['key' => 'DB_SERVER_USERNAME', 'label' => 'DB servidor usuario']),
        sa_field(['key' => 'DB_SERVER_PASSWORD', 'label' => 'DB servidor senha', 'type' => 'secret']),
        sa_field(['key' => 'DB_HOST', 'label' => 'DB host efetivo']),
        sa_field(['key' => 'DB_PORT', 'label' => 'DB porta efetiva']),
        sa_field(['key' => 'DB_DATABASE', 'label' => 'DB database efetiva']),
        sa_field(['key' => 'DB_USERNAME', 'label' => 'DB usuario efetivo']),
        sa_field(['key' => 'DB_PASSWORD', 'label' => 'DB senha efetiva', 'type' => 'secret']),
        sa_field(['key' => 'DB_CHARSET', 'label' => 'Charset']),
    ]),
    sa_group('Runtime E Seguranca', 'Tokens e parametros operacionais.', [
        sa_field(['key' => 'INTEGRATION_DEBUG_TOKEN', 'label' => 'Token debug integracao', 'type' => 'secret']),
        sa_field(['key' => 'MESSAGE_DEBOUNCE_ENABLED', 'label' => 'Debounce habilitado', 'type' => 'boolean']),
        sa_field(['key' => 'MESSAGE_DEBOUNCE_WINDOW_MS', 'label' => 'Janela debounce (ms)']),
        sa_field(['key' => 'MESSAGE_QUEUE_LOCK_WAIT_SECONDS', 'label' => 'Lock wait fila (s)']),
        sa_field(['key' => 'SETTINGS_ADMIN_USERNAME', 'label' => 'Usuario da tela', 'placeholder' => 'admin']),
        sa_field(['key' => 'SETTINGS_ADMIN_PASSWORD', 'label' => 'Senha da tela', 'type' => 'secret']),
    ]),
];

$allFields = [];
foreach ($groups as $group) {
    foreach ($group['fields'] as $field) {
        $allFields[$field['key']] = $field;
    }
}

$errorMessage = null;
$successMessage = null;

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    unset($_SESSION['settings_admin_authenticated']);
    header('Location: settings_admin.php');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Falha de seguranca. Atualize a pagina e tente novamente.';
    } elseif ($expectedPassword === '') {
        $errorMessage = 'Configure SETTINGS_ADMIN_PASSWORD ou SESSION_ADMIN_TOKEN antes de usar esta tela.';
    } elseif (!hash_equals($expectedUsername, $username) || !hash_equals($expectedPassword, $password)) {
        $errorMessage = 'Usuario ou senha invalidos.';
    } else {
        $_SESSION['settings_admin_authenticated'] = true;
        header('Location: settings_admin.php');
        exit;
    }
}

$isAuthenticated = ($_SESSION['settings_admin_authenticated'] ?? false) === true;
$values = $envManager->read();

if ($isAuthenticated && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'Falha de seguranca. Atualize a pagina e tente novamente.';
    } else {
        try {
            $updates = [];
            $inputValues = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];

            foreach ($allFields as $key => $field) {
                $rawValue = $inputValues[$key] ?? '';

                if (($field['type'] ?? 'text') === 'boolean') {
                    $updates[$key] = sa_normalize_bool((string) $rawValue);
                    continue;
                }

                $updates[$key] = trim((string) $rawValue);
            }

            $envManager->write($updates);
            $values = $envManager->read();
            $successMessage = 'Configuracoes salvas no .env. As proximas requisicoes ja vao carregar os novos valores.';
        } catch (Throwable $exception) {
            $errorMessage = 'Nao foi possivel salvar: ' . $exception->getMessage();
        }
    }
}

$currentProfile = strtolower(trim((string) ($values['WHATSAPP_PROFILE'] ?? '')));
$currentProvider = strtolower(trim((string) ($values['WHATSAPP_PROVIDER'] ?? '')));
$currentDefaultProvider = strtolower(trim((string) ($values['WHATSAPP_DEFAULT_PROVIDER'] ?? '')));
$resolvedEvolutionInstance = sa_resolve_profile_value($values, 'WHATSAPP_INSTANCE', $currentProfile);
$resolvedPapiInstance = sa_resolve_profile_value($values, 'WHATSAPP_PAPI_INSTANCE', $currentProfile);

?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurações do Robô</title>
    <style>
        :root {
            --bg: #f4efe6;
            --panel: #ffffff;
            --panel-soft: #f8faf8;
            --line: rgba(31, 44, 52, 0.12);
            --text: #1f2c34;
            --soft: #5c6b73;
            --accent: #0a7c66;
            --accent-dark: #085c4b;
            --warn: #b26a00;
            --danger: #a52337;
            --shadow: 0 20px 40px rgba(31, 44, 52, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(10, 124, 102, 0.10), transparent 22%),
                radial-gradient(circle at bottom right, rgba(219, 174, 107, 0.16), transparent 18%),
                var(--bg);
        }

        .page {
            max-width: 1380px;
            margin: 0 auto;
            padding: 28px 20px 44px;
        }

        .hero, .login-card, .flash, .summary, .group-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 24px 26px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .hero h1, .login-card h1 {
            margin: 0 0 8px;
            font-size: 1.9rem;
        }

        .hero p, .login-card p, .group-head p, .summary p {
            margin: 0;
            color: var(--soft);
            line-height: 1.5;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .button, button {
            appearance: none;
            border: 0;
            border-radius: 14px;
            padding: 12px 18px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: transform 120ms ease, background 120ms ease;
        }

        .button-primary, .save-button {
            background: var(--accent);
            color: #fff;
        }

        .button-primary:hover, .save-button:hover {
            background: var(--accent-dark);
        }

        .button-secondary {
            background: rgba(10, 124, 102, 0.12);
            color: var(--accent-dark);
        }

        .button-secondary:hover {
            background: rgba(10, 124, 102, 0.18);
        }

        .button-danger {
            background: rgba(165, 35, 55, 0.12);
            color: var(--danger);
        }

        .flash {
            padding: 14px 18px;
            margin-bottom: 16px;
            border-radius: 16px;
        }

        .flash-success {
            border-color: rgba(10, 124, 102, 0.18);
            background: rgba(10, 124, 102, 0.08);
        }

        .flash-error {
            border-color: rgba(165, 35, 55, 0.18);
            background: rgba(165, 35, 55, 0.08);
        }

        .summary {
            padding: 20px 22px;
            margin-bottom: 20px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .summary-item {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 14px 16px;
        }

        .summary-item strong {
            display: block;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }

        .summary-item span {
            font-size: 1rem;
            word-break: break-word;
        }

        .settings-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .group-card {
            padding: 22px;
        }

        .group-head {
            margin-bottom: 18px;
        }

        .group-head h2 {
            margin: 0 0 6px;
            font-size: 1.2rem;
        }

        .fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 0.92rem;
            font-weight: 700;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 46px;
            border-radius: 14px;
            border: 1px solid var(--line);
            padding: 12px 14px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }

        .field small {
            color: var(--soft);
            line-height: 1.45;
        }

        .secret-wrap {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }

        .toggle-secret {
            min-height: 46px;
            padding-inline: 14px;
            background: rgba(10, 124, 102, 0.10);
            color: var(--accent-dark);
        }

        .save-bar {
            position: sticky;
            bottom: 14px;
            z-index: 5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 14px 16px;
            box-shadow: var(--shadow);
        }

        .login-shell {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 22px;
        }

        .login-card {
            width: min(480px, 100%);
            padding: 28px;
        }

        .login-form {
            display: grid;
            gap: 16px;
            margin-top: 20px;
        }

        .login-form input {
            width: 100%;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid var(--line);
            padding: 12px 14px;
            font: inherit;
        }

        .muted {
            color: var(--soft);
            font-size: 0.92rem;
        }

        @media (max-width: 980px) {
            .summary-grid,
            .fields {
                grid-template-columns: 1fr;
            }

            .hero {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php if (!$isAuthenticated): ?>
    <div class="login-shell">
        <div class="login-card">
            <h1>Configurações do Robô</h1>
            <p>Entre com usuário e senha para administrar o arquivo <code>.env</code> do projeto.</p>

            <?php if ($errorMessage !== null): ?>
                <div class="flash flash-error"><?= sa_e($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= sa_e($csrfToken) ?>">

                <div class="field">
                    <label for="username">Usuário</label>
                    <input id="username" name="username" type="text" value="<?= sa_e($expectedUsername) ?>" autocomplete="username">
                </div>

                <div class="field">
                    <label for="password">Senha</label>
                    <input id="password" name="password" type="password" autocomplete="current-password">
                </div>

                <button type="submit" class="button button-primary">Entrar</button>
                <p class="muted">Se quiser credenciais dedicadas para esta tela, configure <code>SETTINGS_ADMIN_USERNAME</code> e <code>SETTINGS_ADMIN_PASSWORD</code> no <code>.env</code>. Se não configurar, a senha usa <code>SESSION_ADMIN_TOKEN</code>.</p>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="page">
        <header class="hero">
            <div>
                <h1>Configurações do Ambiente</h1>
                <p>Edite o <code>.env</code> por seções, troque provider, perfil, instâncias e credenciais com muito mais praticidade.</p>
            </div>
            <div class="hero-actions">
                <a class="button button-secondary" href="message_monitor.php">Abrir Monitor</a>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="button button-danger">Sair</button>
                </form>
            </div>
        </header>

        <?php if ($successMessage !== null): ?>
            <div class="flash flash-success"><?= sa_e($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== null): ?>
            <div class="flash flash-error"><?= sa_e($errorMessage) ?></div>
        <?php endif; ?>

        <section class="summary">
            <p>Resumo atual carregado do arquivo <code>.env</code>. Esse quadro ajuda a ver rapidamente se o projeto está apontando para Evolution ou PAPI e qual profile está efetivamente em uso.</p>
            <div class="summary-grid">
                <div class="summary-item">
                    <strong>Provider atual</strong>
                    <span><?= sa_e($currentProvider !== '' ? $currentProvider : '-') ?></span>
                </div>
                <div class="summary-item">
                    <strong>Provider padrão</strong>
                    <span><?= sa_e($currentDefaultProvider !== '' ? $currentDefaultProvider : '-') ?></span>
                </div>
                <div class="summary-item">
                    <strong>Profile ativo</strong>
                    <span><?= sa_e($currentProfile !== '' ? strtoupper($currentProfile) : '-') ?></span>
                </div>
                <div class="summary-item">
                    <strong>Modo misto</strong>
                    <span><?= sa_e((string) ($values['WHATSAPP_MIXED_WEBHOOK_MODE'] ?? 'false')) ?></span>
                </div>
                <div class="summary-item">
                    <strong>Instância Evolution resolvida</strong>
                    <span><?= sa_e($resolvedEvolutionInstance !== '' ? $resolvedEvolutionInstance : '-') ?></span>
                </div>
                <div class="summary-item">
                    <strong>Instância PAPI resolvida</strong>
                    <span><?= sa_e($resolvedPapiInstance !== '' ? $resolvedPapiInstance : '-') ?></span>
                </div>
                <div class="summary-item">
                    <strong>Envio PAPI</strong>
                    <span><?= sa_e((string) ($values['WHATSAPP_PAPI_SEND_ENABLED'] ?? 'false')) ?></span>
                </div>
                <div class="summary-item">
                    <strong>DB profile</strong>
                    <span><?= sa_e((string) ($values['DB_PROFILE'] ?? '-')) ?></span>
                </div>
            </div>
        </section>

        <form method="post" class="settings-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= sa_e($csrfToken) ?>">

            <?php foreach ($groups as $group): ?>
                <section class="group-card">
                    <div class="group-head">
                        <h2><?= sa_e($group['title']) ?></h2>
                        <p><?= sa_e($group['description']) ?></p>
                    </div>
                    <div class="fields">
                        <?php foreach ($group['fields'] as $field): ?>
                            <?php
                                $key = $field['key'];
                                $value = (string) ($values[$key] ?? '');
                                $type = $field['type'] ?? 'text';
                            ?>
                            <div class="field">
                                <label for="<?= sa_e($key) ?>"><?= sa_e($field['label']) ?></label>
                                <?php if ($type === 'select'): ?>
                                    <select id="<?= sa_e($key) ?>" name="settings[<?= sa_e($key) ?>]">
                                        <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                                            <option value="<?= sa_e((string) $optionValue) ?>"<?= (string) $optionValue === $value ? ' selected' : '' ?>><?= sa_e((string) $optionLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'boolean'): ?>
                                    <select id="<?= sa_e($key) ?>" name="settings[<?= sa_e($key) ?>]">
                                        <option value="true"<?= $value === 'true' ? ' selected' : '' ?>>true</option>
                                        <option value="false"<?= $value === 'false' ? ' selected' : '' ?>>false</option>
                                    </select>
                                <?php elseif ($type === 'secret'): ?>
                                    <div class="secret-wrap">
                                        <input id="<?= sa_e($key) ?>" name="settings[<?= sa_e($key) ?>]" type="password" value="<?= sa_e($value) ?>" autocomplete="off">
                                        <button type="button" class="toggle-secret" data-target="<?= sa_e($key) ?>">Mostrar</button>
                                    </div>
                                <?php else: ?>
                                    <input id="<?= sa_e($key) ?>" name="settings[<?= sa_e($key) ?>]" type="text" value="<?= sa_e($value) ?>" placeholder="<?= sa_e((string) ($field['placeholder'] ?? '')) ?>" autocomplete="off">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="save-bar">
                <div>
                    <strong>Salvar arquivo .env</strong>
                    <div class="muted">As alterações passam a valer nas próximas requisições do sistema.</div>
                </div>
                <button type="submit" class="save-button">Salvar configurações</button>
            </div>
        </form>
    </div>

    <script>
        document.querySelectorAll('.toggle-secret').forEach(function (button) {
            button.addEventListener('click', function () {
                const target = document.getElementById(button.dataset.target);
                if (!target) {
                    return;
                }

                const nextType = target.type === 'password' ? 'text' : 'password';
                target.type = nextType;
                button.textContent = nextType === 'password' ? 'Mostrar' : 'Ocultar';
            });
        });
    </script>
<?php endif; ?>
</body>
</html>
