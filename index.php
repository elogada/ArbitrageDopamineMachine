<?php
// Arbitrage Dopamine Machine v1.1
// Single-file PHP page for local XAMPP use.
// PHP 8.2 compatible.

declare(strict_types=1);

loadDotEnv(__DIR__ . '/.env');

// ==============================
// Hardcoded config (edit anytime)
// ==============================
const POLL_INTERVAL_MS = 5000;      // frontend polling interval
const PROFIT_THRESHOLD_PCT = 0.50;  // emoji threshold
const LOCAL_EXCHANGE_NAME = 'Coins.ph';
const ALERT_STATE_FILE = __DIR__ . '/.alert_state.json';

// Assets to compare. Binance leg is assumed to be quoted in USDT.
// Local symbol is the Coins.ph bookTicker symbol.
const ASSETS = [
    ['key' => 'USDT', 'binance' => 'USDTUSDT', 'local' => 'USDTPHP', 'label' => 'USDT/PHP'],
    ['key' => 'BTC',  'binance' => 'BTCUSDT',  'local' => 'BTCPHP',  'label' => 'BTC/PHP'],
    ['key' => 'XRP',  'binance' => 'XRPUSDT',  'local' => 'XRPPHP',  'label' => 'XRP/PHP'],
    ['key' => 'ETH',  'binance' => 'ETHUSDT',  'local' => 'ETHPHP',  'label' => 'ETH/PHP'],
    // PAXG intentionally omitted for now because user found no PAXGPHP bookTicker on Coins.ph.
];

define('ALERTS_ENABLED', envBool('ALERTS_ENABLED', false));
define('ALERT_THRESHOLD_PCT', envFloat('ALERT_THRESHOLD_PCT', 1.25));
define('ALERT_COOLDOWN_SECONDS', envInt('ALERT_COOLDOWN_SECONDS', 300));
define('SMTP_HOST', envString('SMTP_HOST', 'smtp.example.com'));
define('SMTP_PORT', envInt('SMTP_PORT', 465));
define('SMTP_USERNAME', envString('SMTP_USERNAME', 'your_username'));
define('SMTP_PASSWORD', envString('SMTP_PASSWORD', 'your_password'));
define('SMTP_FROM_EMAIL', envString('SMTP_FROM_EMAIL', 'alerts@example.com'));
define('SMTP_TO_EMAIL', envString('SMTP_TO_EMAIL', 'recipient@example.com'));
define('APP_PIN', envString('APP_PIN', ''));

session_start();

function loadDotEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        $value = trim($value);
        $value = trim($value, "\"'");
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function envString(string $key, string $default): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (string) $value;
}

function envBool(string $key, bool $default): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function envInt(string $key, int $default): int
{
    $value = getenv($key);
    if ($value === false || !is_numeric($value)) {
        return $default;
    }
    return (int) $value;
}

function envFloat(string $key, float $default): float
{
    $value = getenv($key);
    if ($value === false || !is_numeric($value)) {
        return $default;
    }
    return (float) $value;
}

function isPinConfigured(): bool
{
    return preg_match('/^\d{4}$/', APP_PIN) === 1;
}

function isPinUnlocked(): bool
{
    return ($_SESSION['pin_unlocked'] ?? false) === true;
}

// ==============================
// AJAX endpoint
// ==============================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? false));
    }
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'data') {
    if (isPinConfigured() && !isPinUnlocked()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'PIN lock enabled. Unlock required.',
            'timestamp' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(buildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pinError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $submittedPin = trim((string) $_POST['pin']);
    if (!isPinConfigured()) {
        $pinError = 'PIN auth is not configured in .env.';
    } elseif (preg_match('/^\d{4}$/', $submittedPin) !== 1) {
        $pinError = 'PIN must be exactly 4 digits.';
    } elseif (!hash_equals(APP_PIN, $submittedPin)) {
        $pinError = 'Incorrect PIN.';
    } else {
        $_SESSION['pin_unlocked'] = true;
        header('Location: ' . basename(__FILE__));
        exit;
    }
}

$pinLocked = isPinConfigured() && !isPinUnlocked();

function buildPayload(): array
{
    $coinsBridge = fetchCoinsBookTicker('USDTPHP');
    if (!$coinsBridge['ok']) {
        return [
            'ok' => false,
            'error' => 'Failed to fetch Coins.ph USDT/PHP bridge: ' . $coinsBridge['error'],
            'timestamp' => gmdate('c'),
        ];
    }

    $bridgeBid = safeFloat($coinsBridge['data']['bidPrice'] ?? null);
    $bridgeAsk = safeFloat($coinsBridge['data']['askPrice'] ?? null);

    if ($bridgeBid === null || $bridgeAsk === null || $bridgeBid <= 0 || $bridgeAsk <= 0) {
        return [
            'ok' => false,
            'error' => 'Coins.ph USDT/PHP bridge returned unusable bid/ask.',
            'timestamp' => gmdate('c'),
        ];
    }

    $rows = [];
    foreach (ASSETS as $asset) {
        $rows[] = buildAssetRow($asset, $bridgeBid, $bridgeAsk);
    }

    $alertStatus = processEmailAlerts($rows);

    return [
        'ok' => true,
        'timestamp' => gmdate('c'),
        'config' => [
            'pollIntervalMs' => POLL_INTERVAL_MS,
            'profitThresholdPct' => PROFIT_THRESHOLD_PCT,
            'alertsEnabled' => ALERTS_ENABLED,
            'alertThresholdPct' => ALERT_THRESHOLD_PCT,
            'localExchangeName' => LOCAL_EXCHANGE_NAME,
        ],
        'bridge' => [
            'symbol' => 'USDTPHP',
            'bid' => $bridgeBid,
            'ask' => $bridgeAsk,
        ],
        'assets' => $rows,
        'alerts' => $alertStatus,
    ];
}

function processEmailAlerts(array $rows): array
{
    if (!ALERTS_ENABLED) {
        return [
            'enabled' => false,
            'triggered' => false,
            'sent' => false,
            'message' => 'Alerts are disabled.',
        ];
    }

    $snapshot = buildAlertSnapshot($rows);
    $state = readAlertState();

    if (!$snapshot['triggered']) {
        $state['breachActive'] = false;
        writeAlertState($state);
        return [
            'enabled' => true,
            'triggered' => false,
            'sent' => false,
            'message' => 'No asset crossed the alert threshold.',
        ];
    }

    if (($state['breachActive'] ?? false) === true) {
        return [
            'enabled' => true,
            'triggered' => true,
            'sent' => false,
            'message' => 'Alert already sent for current threshold breach.',
            'candidates' => $snapshot['candidates'],
        ];
    }

    $now = time();
    $lastSentAt = (int) ($state['lastSentAt'] ?? 0);
    if ($lastSentAt > 0 && ($now - $lastSentAt) < ALERT_COOLDOWN_SECONDS) {
        return [
            'enabled' => true,
            'triggered' => true,
            'sent' => false,
            'message' => 'Alert cooldown active.',
            'candidates' => $snapshot['candidates'],
        ];
    }

    $emailBody = renderAlertEmailBody($snapshot['candidates']);
    $subject = 'Arbitrage Alert: ' . count($snapshot['candidates']) . ' threshold hit(s)';
    $sendResult = smtpSendMail($subject, $emailBody);

    if (!$sendResult['ok']) {
        return [
            'enabled' => true,
            'triggered' => true,
            'sent' => false,
            'message' => 'Failed to send email alert: ' . $sendResult['error'],
            'candidates' => $snapshot['candidates'],
        ];
    }

    $state['lastSentAt'] = $now;
    $state['breachActive'] = true;
    writeAlertState($state);

    return [
        'enabled' => true,
        'triggered' => true,
        'sent' => true,
        'message' => 'Email alert sent successfully.',
        'candidates' => $snapshot['candidates'],
    ];
}

function buildAlertSnapshot(array $rows): array
{
    $candidates = [];
    foreach ($rows as $row) {
        if (($row['ok'] ?? false) !== true) {
            continue;
        }

        $d1 = $row['direction1']['spreadPct'] ?? null;
        $d2 = $row['direction2']['spreadPct'] ?? null;
        if (!is_numeric($d1) || !is_numeric($d2)) {
            continue;
        }

        if ((float) $d1 >= ALERT_THRESHOLD_PCT || (float) $d2 >= ALERT_THRESHOLD_PCT) {
            $candidates[] = [
                'label' => (string) $row['label'],
                'direction1' => [
                    'title' => (string) $row['direction1']['title'],
                    'spreadPct' => (float) $d1,
                    'emoji' => (string) $row['direction1']['emoji'],
                ],
                'direction2' => [
                    'title' => (string) $row['direction2']['title'],
                    'spreadPct' => (float) $d2,
                    'emoji' => (string) $row['direction2']['emoji'],
                ],
            ];
        }
    }

    return [
        'triggered' => count($candidates) > 0,
        'candidates' => $candidates,
    ];
}

function renderAlertEmailBody(array $candidates): string
{
    $lines = [];
    $lines[] = 'Arbitrage alert triggered at ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = 'Alert threshold: ' . number_format(ALERT_THRESHOLD_PCT, 2) . '%';
    $lines[] = '';

    foreach ($candidates as $asset) {
        $lines[] = $asset['label'];
        $lines[] = '  - ' . $asset['direction1']['title'] . ': ' . number_format((float) $asset['direction1']['spreadPct'], 2) . '% ' . $asset['direction1']['emoji'];
        $lines[] = '  - ' . $asset['direction2']['title'] . ': ' . number_format((float) $asset['direction2']['spreadPct'], 2) . '% ' . $asset['direction2']['emoji'];
        $lines[] = '';
    }

    return implode("\r\n", $lines);
}

function readAlertState(): array
{
    if (!is_file(ALERT_STATE_FILE)) {
        return ['lastSentAt' => 0, 'breachActive' => false];
    }

    $raw = file_get_contents(ALERT_STATE_FILE);
    if ($raw === false) {
        return ['lastSentAt' => 0, 'breachActive' => false];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['lastSentAt' => 0, 'breachActive' => false];
    }

    return [
        'lastSentAt' => (int) ($decoded['lastSentAt'] ?? 0),
        'breachActive' => (bool) ($decoded['breachActive'] ?? false),
    ];
}

function writeAlertState(array $state): void
{
    $payload = json_encode([
        'lastSentAt' => (int) ($state['lastSentAt'] ?? 0),
        'breachActive' => (bool) ($state['breachActive'] ?? false),
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return;
    }
    file_put_contents(ALERT_STATE_FILE, $payload, LOCK_EX);
}

function smtpSendMail(string $subject, string $body): array
{
    $transport = 'ssl://' . SMTP_HOST;
    $socket = @stream_socket_client($transport . ':' . SMTP_PORT, $errno, $errstr, 20);
    if ($socket === false) {
        return ['ok' => false, 'error' => 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, 20);

    $steps = [
        ['expect' => '220'],
        ['command' => 'EHLO localhost', 'expect' => '250'],
        ['command' => 'AUTH LOGIN', 'expect' => '334'],
        ['command' => base64_encode(SMTP_USERNAME), 'expect' => '334'],
        ['command' => base64_encode(SMTP_PASSWORD), 'expect' => '235'],
        ['command' => 'MAIL FROM:<' . SMTP_FROM_EMAIL . '>', 'expect' => '250'],
        ['command' => 'RCPT TO:<' . SMTP_TO_EMAIL . '>', 'expect' => '250'],
        ['command' => 'DATA', 'expect' => '354'],
    ];

    foreach ($steps as $step) {
        if (isset($step['command'])) {
            fwrite($socket, $step['command'] . "\r\n");
        }

        $response = smtpReadResponse($socket);
        if (!str_starts_with($response, $step['expect'])) {
            fclose($socket);
            return ['ok' => false, 'error' => 'SMTP protocol error. Expected ' . $step['expect'] . ', got: ' . trim($response)];
        }
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From: ' . SMTP_FROM_EMAIL,
        'To: ' . SMTP_TO_EMAIL,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $dotSafeBody = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $dotSafeBody) . "\r\n.\r\n";
    fwrite($socket, $message);
    $dataResponse = smtpReadResponse($socket);
    if (!str_starts_with($dataResponse, '250')) {
        fclose($socket);
        return ['ok' => false, 'error' => 'SMTP DATA failed: ' . trim($dataResponse)];
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return ['ok' => true];
}

function smtpReadResponse($socket): string
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function buildAssetRow(array $asset, float $usdtPhpBid, float $usdtPhpAsk): array
{
    if ($asset['key'] === 'USDT') {
        $local = fetchCoinsBookTicker($asset['local']);
        if (!$local['ok']) {
            return errorAssetRow($asset, 'Coins.ph fetch failed: ' . $local['error']);
        }

        $localBid = safeFloat($local['data']['bidPrice'] ?? null);
        $localAsk = safeFloat($local['data']['askPrice'] ?? null);
        if ($localBid === null || $localAsk === null) {
            return errorAssetRow($asset, 'Coins.ph returned unusable bid/ask.');
        }

        // Binance leg for USDT itself is effectively the bridge market here.
        $binanceSellPhp = $usdtPhpBid;
        $binanceBuyPhp = $usdtPhpAsk;

        return finalizeAssetRow($asset, $localBid, $localAsk, $binanceSellPhp, $binanceBuyPhp, [
            'notes' => 'USDT uses the local USDT/PHP bridge directly.',
        ]);
    }

    $binance = fetchBinanceBookTicker($asset['binance']);
    if (!$binance['ok']) {
        return errorAssetRow($asset, 'Binance fetch failed: ' . $binance['error']);
    }

    $local = fetchCoinsBookTicker($asset['local']);
    if (!$local['ok']) {
        return errorAssetRow($asset, 'Coins.ph fetch failed: ' . $local['error']);
    }

    $binanceBidUsdt = safeFloat($binance['data']['bidPrice'] ?? null);
    $binanceAskUsdt = safeFloat($binance['data']['askPrice'] ?? null);
    $localBidPhp = safeFloat($local['data']['bidPrice'] ?? null);
    $localAskPhp = safeFloat($local['data']['askPrice'] ?? null);

    if ($binanceBidUsdt === null || $binanceAskUsdt === null || $localBidPhp === null || $localAskPhp === null) {
        return errorAssetRow($asset, 'One or more bid/ask values were missing.');
    }

    // Conservative PHP-equivalent pricing for Binance leg using the Coins.ph USDT/PHP bridge.
    // To BUY the asset on Binance, assume you need to acquire USDT at the bridge ask.
    $binanceBuyPhp = $binanceAskUsdt * $usdtPhpAsk;

    // To SELL the asset on Binance, assume you receive USDT and liquidate at the bridge bid.
    $binanceSellPhp = $binanceBidUsdt * $usdtPhpBid;

    return finalizeAssetRow($asset, $localBidPhp, $localAskPhp, $binanceSellPhp, $binanceBuyPhp, [
        'binanceBidUsdt' => $binanceBidUsdt,
        'binanceAskUsdt' => $binanceAskUsdt,
    ]);
}

function finalizeAssetRow(array $asset, float $localBidPhp, float $localAskPhp, float $binanceSellPhp, float $binanceBuyPhp, array $extra = []): array
{
    $direction1SpreadPct = calcSpreadPct($localBidPhp, $binanceBuyPhp);   // Buy Binance, Sell Local
    $direction2SpreadPct = calcSpreadPct($binanceSellPhp, $localAskPhp);  // Buy Local, Sell Binance

    return array_merge([
        'key' => $asset['key'],
        'label' => $asset['label'],
        'binanceSymbol' => $asset['binance'],
        'localSymbol' => $asset['local'],
        'ok' => true,
        'direction1' => [
            'title' => 'Buy Binance → Sell ' . LOCAL_EXCHANGE_NAME,
            'buyVenue' => 'Binance',
            'sellVenue' => LOCAL_EXCHANGE_NAME,
            'buyPhp' => $binanceBuyPhp,
            'sellPhp' => $localBidPhp,
            'spreadPct' => $direction1SpreadPct,
            'emoji' => thresholdEmoji($direction1SpreadPct),
        ],
        'direction2' => [
            'title' => 'Buy ' . LOCAL_EXCHANGE_NAME . ' → Sell Binance',
            'buyVenue' => LOCAL_EXCHANGE_NAME,
            'sellVenue' => 'Binance',
            'buyPhp' => $localAskPhp,
            'sellPhp' => $binanceSellPhp,
            'spreadPct' => $direction2SpreadPct,
            'emoji' => thresholdEmoji($direction2SpreadPct),
        ],
        'localBidPhp' => $localBidPhp,
        'localAskPhp' => $localAskPhp,
        'binanceSellPhp' => $binanceSellPhp,
        'binanceBuyPhp' => $binanceBuyPhp,
    ], $extra);
}

function thresholdEmoji(float $spreadPct): string
{
    return $spreadPct >= PROFIT_THRESHOLD_PCT ? '✅' : '❌';
}

function calcSpreadPct(float $sellPrice, float $buyPrice): float
{
    if ($buyPrice <= 0) {
        return 0.0;
    }
    return (($sellPrice - $buyPrice) / $buyPrice) * 100.0;
}

function errorAssetRow(array $asset, string $message): array
{
    return [
        'key' => $asset['key'],
        'label' => $asset['label'],
        'binanceSymbol' => $asset['binance'],
        'localSymbol' => $asset['local'],
        'ok' => false,
        'error' => $message,
    ];
}

function fetchBinanceBookTicker(string $symbol): array
{
    if ($symbol === 'USDTUSDT') {
        return ['ok' => true, 'data' => ['bidPrice' => '1', 'askPrice' => '1']];
    }

    $url = 'https://api.binance.com/api/v3/ticker/bookTicker?symbol=' . rawurlencode($symbol);
    return httpJsonGet($url, [
        'Accept: application/json',
        'User-Agent: ArbitrageDopamineMachine/1.1',
    ]);
}

function fetchCoinsBookTicker(string $symbol): array
{
    $url = 'https://api.pro.coins.ph/openapi/v1/ticker/bookTicker?symbol=' . rawurlencode($symbol);
    return httpJsonGet($url, [
        'Accept: application/json',
        'User-Agent: ArbitrageDopamineMachine/1.1',
    ]);
}

function httpJsonGet(string $url, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $curlErr !== '' ? $curlErr : 'Unknown cURL error'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Non-JSON response (HTTP ' . $httpCode . ')'];
    }

    if ($httpCode >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $httpCode . ': ' . ($decoded['msg'] ?? $decoded['message'] ?? 'Unknown error')];
    }

    if (array_key_exists('code', $decoded) && (string)$decoded['code'] !== '0' && (int)$decoded['code'] < 0) {
        return ['ok' => false, 'error' => ($decoded['msg'] ?? 'API error code ' . $decoded['code'])];
    }

    return ['ok' => true, 'data' => $decoded];
}

function safeFloat(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arbitrage Dopamine Machine</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body
    class="text-black min-h-screen bg-cover bg-center bg-fixed"
    style="background-image: linear-gradient(rgba(255, 255, 255, 0.90), rgba(255, 255, 255, 0.90)), url('https://avatars.githubusercontent.com/u/15363067');"
>
    <main class="max-w-md mx-auto px-4 py-6 text-center relative">
        <h1 class="text-3xl font-bold tracking-tight">Arbitrage Dopamine Machine</h1>
        <p class="mt-2 text-sm">because inefficiency hits different</p>
        <?php if (isPinConfigured() && isPinUnlocked()): ?>
        <div class="mt-3">
            <a href="<?= htmlspecialchars(basename(__FILE__), ENT_QUOTES) ?>?action=logout" class="inline-block text-xs border border-black rounded px-3 py-1 hover:bg-black hover:text-white transition-colors">Logout</a>
        </div>
        <?php endif; ?>

        <section class="mt-5 border border-black rounded-xl p-4">
            <div class="text-sm">Polling interval: <span id="poll-interval" class="font-semibold"><?= htmlspecialchars((string)(POLL_INTERVAL_MS / 1000), ENT_QUOTES) ?>s</span></div>
            <div class="text-sm mt-1">Threshold: <span id="threshold" class="font-semibold"><?= htmlspecialchars(number_format(PROFIT_THRESHOLD_PCT, 2), ENT_QUOTES) ?>%</span></div>
            <div class="text-sm mt-1">Email alerts: <span id="alerts-enabled" class="font-semibold"><?= ALERTS_ENABLED ? 'Enabled' : 'Disabled' ?></span></div>
            <div class="text-sm mt-1">Local exchange: <span class="font-semibold"><?= htmlspecialchars(LOCAL_EXCHANGE_NAME, ENT_QUOTES) ?></span></div>
            <div class="text-sm mt-1">Bridge: <span class="font-semibold">USDT/PHP</span></div>
            <div class="text-sm mt-2">Last updated: <span id="last-updated">—</span></div>
        </section>

        <div id="status" class="mt-4 text-sm">Loading…</div>
        <div id="bridge-box" class="mt-4"></div>
        <div id="cards" class="mt-4 space-y-4"></div>
    </main>

    <?php if ($pinLocked): ?>
    <div class="fixed inset-0 bg-white/95 backdrop-blur-sm flex items-center justify-center p-4 z-50">
        <section class="w-full max-w-sm border border-black rounded-xl p-4 bg-white text-center">
            <h2 class="text-xl font-bold">Enter 4-digit PIN</h2>
            <p class="mt-1 text-sm">Unlock required to continue.</p>
            <?php if ($pinError !== ''): ?>
                <p class="mt-2 text-sm font-semibold text-red-700"><?= htmlspecialchars($pinError, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <form method="post" class="mt-4">
                <input id="pin-input" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="pin" class="w-full border border-black rounded px-3 py-2 text-center tracking-[0.4em] text-lg" placeholder="••••" autocomplete="one-time-code" required>
                <div class="grid grid-cols-3 gap-2 mt-3" id="keypad">
                    <?php foreach ([1,2,3,4,5,6,7,8,9] as $digit): ?>
                        <button type="button" class="border border-black rounded py-2 font-semibold" data-digit="<?= $digit ?>"><?= $digit ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="border border-black rounded py-2 font-semibold" id="pin-clear">Clear</button>
                    <button type="button" class="border border-black rounded py-2 font-semibold" data-digit="0">0</button>
                    <button type="submit" class="border border-black rounded py-2 font-semibold bg-black text-white">OK</button>
                </div>
            </form>
        </section>
    </div>
    <?php endif; ?>

<script>
const POLL_INTERVAL_MS = <?= json_encode(POLL_INTERVAL_MS) ?>;
const PROFIT_THRESHOLD_PCT = <?= json_encode(PROFIT_THRESHOLD_PCT) ?>;
const PIN_LOCKED = <?= json_encode($pinLocked) ?>;

function xhrGetJson(url, onSuccess, onError) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                onSuccess(JSON.parse(xhr.responseText));
            } catch (err) {
                onError('Invalid JSON response');
            }
        } else {
            onError('HTTP ' + xhr.status);
        }
    };
    xhr.onerror = function () {
        onError('Network error');
    };
    xhr.send();
}

function formatPhp(value) {
    if (typeof value !== 'number' || !isFinite(value)) return '—';
    return '₱' + value.toLocaleString(undefined, {
        minimumFractionDigits: value < 10 ? 4 : 2,
        maximumFractionDigits: value < 10 ? 4 : 2
    });
}

function formatPct(value) {
    if (typeof value !== 'number' || !isFinite(value)) return '—';
    return (value >= 0 ? '+' : '') + value.toFixed(2) + '%';
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderBridge(bridge) {
    const box = document.getElementById('bridge-box');
    if (!bridge) {
        box.innerHTML = '';
        return;
    }

    box.innerHTML = `
        <section class="border border-black rounded-xl p-4">
            <div class="font-bold text-lg">USDT/PHP Bridge</div>
            <div class="mt-3 text-sm">Buy USDT (ask): <span class="font-semibold">${formatPhp(bridge.ask)}</span></div>
            <div class="mt-1 text-sm">Sell USDT (bid): <span class="font-semibold">${formatPhp(bridge.bid)}</span></div>
        </section>
    `;
}

function renderAssetCard(asset) {
    if (!asset.ok) {
        return `
            <section class="border border-black rounded-xl p-4">
                <div class="font-bold text-xl">${escapeHtml(asset.label)}</div>
                <div class="mt-3 text-sm">Error: ${escapeHtml(asset.error || 'Unknown error')}</div>
            </section>
        `;
    }

    const d1 = asset.direction1;
    const d2 = asset.direction2;

    return `
        <section class="border border-black rounded-xl p-4">
            <div class="font-bold text-xl">${escapeHtml(asset.label)}</div>
            <div class="mt-1 text-xs">Binance: ${escapeHtml(asset.binanceSymbol)} · ${escapeHtml(asset.localSymbol)}: ${escapeHtml('<?= LOCAL_EXCHANGE_NAME ?>')}</div>

            <div class="mt-4 border border-black rounded-lg p-3">
                <div class="font-semibold">${escapeHtml(d1.title)}</div>
                <table class="w-full mt-3 text-sm">
                    <tbody>
                        <tr>
                            <td class="py-1">Binance buy</td>
                            <td class="py-1 font-semibold">${formatPhp(d1.buyPhp)}</td>
                        </tr>
                        <tr>
                            <td class="py-1"><?= htmlspecialchars(LOCAL_EXCHANGE_NAME, ENT_QUOTES) ?> sell</td>
                            <td class="py-1 font-semibold">${formatPhp(d1.sellPhp)}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-3 text-base font-bold">Spread: ${formatPct(d1.spreadPct)} ${d1.emoji}</div>
            </div>

            <div class="mt-4 border border-black rounded-lg p-3">
                <div class="font-semibold">${escapeHtml(d2.title)}</div>
                <table class="w-full mt-3 text-sm">
                    <tbody>
                        <tr>
                            <td class="py-1"><?= htmlspecialchars(LOCAL_EXCHANGE_NAME, ENT_QUOTES) ?> buy</td>
                            <td class="py-1 font-semibold">${formatPhp(d2.buyPhp)}</td>
                        </tr>
                        <tr>
                            <td class="py-1">Binance sell</td>
                            <td class="py-1 font-semibold">${formatPhp(d2.sellPhp)}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-3 text-base font-bold">Spread: ${formatPct(d2.spreadPct)} ${d2.emoji}</div>
            </div>
        </section>
    `;
}

function renderPayload(payload) {
    const status = document.getElementById('status');
    const cards = document.getElementById('cards');
    const updated = document.getElementById('last-updated');

    if (!payload.ok) {
        status.textContent = 'Error: ' + (payload.error || 'Unknown error');
        return;
    }

    status.textContent = 'Watching for weekend nonsense at ' + PROFIT_THRESHOLD_PCT.toFixed(2) + '% threshold.';
    updated.textContent = new Date(payload.timestamp).toLocaleString();
    renderBridge(payload.bridge);
    cards.innerHTML = payload.assets.map(renderAssetCard).join('');
}

function refresh() {
    xhrGetJson('<?= basename(__FILE__) ?>?action=data&_=' + Date.now(), renderPayload, function (err) {
        document.getElementById('status').textContent = 'Error: ' + err;
    });
}

if (!PIN_LOCKED) {
    refresh();
    setInterval(refresh, POLL_INTERVAL_MS);
}

if (PIN_LOCKED) {
    const pinInput = document.getElementById('pin-input');
    const keypad = document.getElementById('keypad');
    const clearBtn = document.getElementById('pin-clear');

    if (pinInput && keypad) {
        keypad.addEventListener('click', function (event) {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const digit = target.dataset.digit;
            if (!digit) return;
            if (pinInput.value.length >= 4) return;
            pinInput.value += digit;
        });
    }

    if (clearBtn && pinInput) {
        clearBtn.addEventListener('click', function () {
            pinInput.value = '';
            pinInput.focus();
        });
    }
}
</script>
</body>
</html>
