<?php
// Arbitrage Dopamine Machine v1.1
// Single-file PHP page for local XAMPP use.
// PHP 8.2 compatible.

declare(strict_types=1);

// ==============================
// Hardcoded config (edit anytime)
// ==============================
const POLL_INTERVAL_MS = 5000;      // frontend polling interval
const PROFIT_THRESHOLD_PCT = 0.50;  // emoji threshold
const LOCAL_EXCHANGE_NAME = 'Coins.ph';

// Assets to compare. Binance leg is assumed to be quoted in USDT.
// Local symbol is the Coins.ph bookTicker symbol.
const ASSETS = [
    ['key' => 'USDT', 'binance' => 'USDTUSDT', 'local' => 'USDTPHP', 'label' => 'USDT/PHP'],
    ['key' => 'BTC',  'binance' => 'BTCUSDT',  'local' => 'BTCPHP',  'label' => 'BTC/PHP'],
    ['key' => 'XRP',  'binance' => 'XRPUSDT',  'local' => 'XRPPHP',  'label' => 'XRP/PHP'],
    ['key' => 'ETH',  'binance' => 'ETHUSDT',  'local' => 'ETHPHP',  'label' => 'ETH/PHP'],
    // PAXG intentionally omitted for now because user found no PAXGPHP bookTicker on Coins.ph.
];

// ==============================
// AJAX endpoint
// ==============================
if (isset($_GET['action']) && $_GET['action'] === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(buildPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

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

    return [
        'ok' => true,
        'timestamp' => gmdate('c'),
        'config' => [
            'pollIntervalMs' => POLL_INTERVAL_MS,
            'profitThresholdPct' => PROFIT_THRESHOLD_PCT,
            'localExchangeName' => LOCAL_EXCHANGE_NAME,
        ],
        'bridge' => [
            'symbol' => 'USDTPHP',
            'bid' => $bridgeBid,
            'ask' => $bridgeAsk,
        ],
        'assets' => $rows,
    ];
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
<body class="bg-white text-black min-h-screen">
    <main class="max-w-md mx-auto px-4 py-6 text-center">
        <h1 class="text-3xl font-bold tracking-tight">Arbitrage Dopamine Machine</h1>
        <p class="mt-2 text-sm">because inefficiency hits different</p>

        <section class="mt-5 border border-black rounded-xl p-4">
            <div class="text-sm">Polling interval: <span id="poll-interval" class="font-semibold"><?= htmlspecialchars((string)(POLL_INTERVAL_MS / 1000), ENT_QUOTES) ?>s</span></div>
            <div class="text-sm mt-1">Threshold: <span id="threshold" class="font-semibold"><?= htmlspecialchars(number_format(PROFIT_THRESHOLD_PCT, 2), ENT_QUOTES) ?>%</span></div>
            <div class="text-sm mt-1">Local exchange: <span class="font-semibold"><?= htmlspecialchars(LOCAL_EXCHANGE_NAME, ENT_QUOTES) ?></span></div>
            <div class="text-sm mt-1">Bridge: <span class="font-semibold">USDT/PHP</span></div>
            <div class="text-sm mt-2">Last updated: <span id="last-updated">—</span></div>
        </section>

        <div id="status" class="mt-4 text-sm">Loading…</div>
        <div id="bridge-box" class="mt-4"></div>
        <div id="cards" class="mt-4 space-y-4"></div>
    </main>

<script>
const POLL_INTERVAL_MS = <?= json_encode(POLL_INTERVAL_MS) ?>;
const PROFIT_THRESHOLD_PCT = <?= json_encode(PROFIT_THRESHOLD_PCT) ?>;

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

refresh();
setInterval(refresh, POLL_INTERVAL_MS);
</script>
</body>
</html>
