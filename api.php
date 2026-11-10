<?php
declare(strict_types=1);

/*
 * WEB iDEA WebMCP — public, read-only catalog API.
 * Data source: /webmcp/data/catalog.json
 *
 * Analytics:
 * - Logs API tool requests only (no IP address and no request payload).
 * - Stores JSONL events in /webmcp/data/analytics/.
 * - The analytics directory must be denied by the web server.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=60');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

$requestStartedAt = microtime(true);
$action = isset($_GET['action']) && is_string($_GET['action'])
    ? trim($_GET['action'])
    : '';

$toolNames = [
    'company'      => 'get_company_info',
    'services'     => 'get_services',
    'products'     => 'get_products',
    'product_info' => 'get_product_info',
    'pricing'      => 'get_service_pricing',
];

/**
 * Return a same-origin referrer path only.
 * The full URL, query string and fragment are never stored.
 */
function webmcp_source_page(): ?string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($referer) || $referer === '') {
        return null;
    }

    $refererParts = parse_url($referer);
    if (!is_array($refererParts) || empty($refererParts['host'])) {
        return null;
    }

    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $refererHost = strtolower((string)$refererParts['host']);

    // Strip an optional port before comparing host names.
    $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;

    if ($requestHost === '' || $refererHost !== $requestHost) {
        return null;
    }

    $path = (string)($refererParts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') {
        return null;
    }

    return substr($path, 0, 500);
}

/**
 * Append one analytics event. Logging failures must never break the public API.
 * The log is rotated at 5 MB; one previous file is retained.
 */
function webmcp_log_request(int $httpStatus, array $toolNames): void
{
    global $action, $requestStartedAt;

    if (!isset($toolNames[$action])) {
        return;
    }

    $directory = __DIR__ . '/data/analytics';
    $logFile = $directory . '/events.jsonl';
    $previousFile = $directory . '/events-previous.jsonl';
    $lockFile = $directory . '/events.lock';

    try {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Analytics directory cannot be created.');
        }

        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('Analytics lock cannot be opened.');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Analytics lock cannot be acquired.');
            }

            if (is_file($logFile) && filesize($logFile) >= 5 * 1024 * 1024) {
                @unlink($previousFile);
                if (!@rename($logFile, $previousFile)) {
                    throw new RuntimeException('Analytics log rotation failed.');
                }
            }

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (!is_string($userAgent)) {
                $userAgent = '';
            }

            $event = [
                'timestamp' => date(DATE_ATOM),
                'tool' => $toolNames[$action],
                'source_page' => webmcp_source_page(),
                'status' => ($httpStatus >= 200 && $httpStatus < 400) ? 'success' : 'error',
                'http_status' => $httpStatus,
                'duration_ms' => (int)round((microtime(true) - $requestStartedAt) * 1000),
                'user_agent' => substr($userAgent, 0, 300),
            ];

            $json = json_encode(
                $event,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($json === false || @file_put_contents($logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Analytics event cannot be written.');
            }

            @chmod($logFile, 0640);
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    } catch (Throwable $error) {
        error_log('WEBMCP analytics error: ' . $error->getMessage());
    }
}

function respond(int $status, array $payload): never
{
    global $toolNames;

    webmcp_log_request($status, $toolNames);

    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    respond(405, [
        'ok' => false,
        'error' => 'Sono consentite esclusivamente richieste GET in sola lettura.'
    ]);
}

$catalogPath = __DIR__ . '/data/catalog.json';

if (!is_file($catalogPath) || !is_readable($catalogPath)) {
    respond(500, ['ok' => false, 'error' => 'Catalogo dati non disponibile.']);
}

try {
    $catalogJson = file_get_contents($catalogPath);
    if ($catalogJson === false) {
        throw new RuntimeException('Impossibile leggere il catalogo.');
    }
    $catalog = json_decode($catalogJson, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('WEBMCP catalog error: ' . $error->getMessage());
    respond(500, ['ok' => false, 'error' => 'Catalogo dati non valido.']);
}

if (!is_array($catalog)
    || !isset($catalog['catalog_version'], $catalog['last_updated'])
    || !isset($catalog['company'], $catalog['services'], $catalog['products'], $catalog['pricing'])
) {
    respond(500, ['ok' => false, 'error' => 'Struttura del catalogo incompleta.']);
}

$meta = [
    'catalog_version' => (string)$catalog['catalog_version'],
    'last_updated' => (string)$catalog['last_updated'],
    'source' => (string)($catalog['source'] ?? 'WEB iDEA public catalog')
];

switch ($action) {
    case 'company':
        respond(200, ['ok' => true, 'meta' => $meta, 'data' => $catalog['company']]);

    case 'services':
        respond(200, ['ok' => true, 'meta' => $meta, 'data' => $catalog['services']]);

    case 'products':
        respond(200, ['ok' => true, 'meta' => $meta, 'data' => $catalog['products']]);

    case 'product_info':
        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            respond(400, ['ok' => false, 'error' => 'Parametro prodotto mancante o non valido.']);
        }

        foreach ($catalog['products'] as $product) {
            if (isset($product['name']) && strcasecmp((string)$product['name'], $name) === 0) {
                respond(200, ['ok' => true, 'meta' => $meta, 'data' => $product]);
            }
        }

        respond(404, ['ok' => false, 'error' => 'Prodotto non trovato.']);

    case 'pricing':
        $service = trim((string)($_GET['service'] ?? 'all'));
        $validServices = array_values(array_unique(array_map(
            static fn(array $item): string => (string)($item['service'] ?? ''),
            $catalog['pricing']
        )));

        if ($service !== 'all' && !in_array($service, $validServices, true)) {
            respond(400, ['ok' => false, 'error' => 'Servizio non riconosciuto.']);
        }

        $items = array_values(array_filter(
            $catalog['pricing'],
            static fn(array $item): bool => $service === 'all'
                || (($item['service'] ?? null) === $service)
        ));

        respond(200, [
            'ok' => true,
            'meta' => $meta,
            'currency' => (string)($catalog['currency'] ?? 'EUR'),
            'service_filter' => $service,
            'items' => $items,
            'note' => 'I prezzi sono quelli pubblicati nel catalogo. Per i servizi su misura, il costo viene definito con un preventivo personalizzato.'
        ]);

    default:
        respond(404, ['ok' => false, 'error' => 'Azione non riconosciuta.']);
}
