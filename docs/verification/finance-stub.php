<?php
/**
 * A stand-in for the finance system, for verification only.
 *
 * The endpoint in the brief is https://example.invalid/orders. `.invalid` is an RFC 2606
 * reserved TLD that never resolves, so the configured default cannot succeed by construction
 * and a stub is the only way to observe a success path at all.
 *
 * It records EVERY attempt together with the idempotency key it arrived with. That is what
 * makes exactly-once demonstrable rather than asserted: after a run you can see the endpoint
 * was hit five times and still holds one logical delivery.
 *
 * Run it inside the phpfpm container:
 *
 *   cp docs/verification/finance-stub.php src/app/code/
 *   docker compose exec -d -e PHP_CLI_SERVER_WORKERS=4 phpfpm \
 *       php -S 0.0.0.0:8099 /var/www/html/app/code/finance-stub.php
 *
 * PHP_CLI_SERVER_WORKERS matters: the built-in server is single threaded without it, so the
 * timeout mode below would block every later request instead of only its own.
 *
 * Then point the module at http://127.0.0.1:8099/orders.
 *
 *   curl -s localhost:8099/_state                 # what it has seen
 *   curl -s -X POST 'localhost:8099/_mode?mode=500'   # ok | 500 | 429 | 409 | timeout | flaky
 *   curl -s -X POST localhost:8099/_reset
 */

const STATE_FILE = '/tmp/goodahead-finance-stub.json';

/** @return array{mode: string, flaky_remaining: int, attempts: array<int, array<string, mixed>>} */
function loadState(): array
{
    $default = ['mode' => 'ok', 'flaky_remaining' => 2, 'attempts' => []];

    if (!is_file(STATE_FILE)) {
        return $default;
    }

    $state = json_decode((string)file_get_contents(STATE_FILE), true);

    return is_array($state) ? $state + $default : $default;
}

function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$state = loadState();

if ($path === '/_reset') {
    saveState(['mode' => 'ok', 'flaky_remaining' => 2, 'attempts' => []]);
    respond(200, ['reset' => true]);
}

if ($path === '/_mode') {
    $mode = (string)($_GET['mode'] ?? 'ok');
    $state['mode'] = $mode;
    $state['flaky_remaining'] = (int)($_GET['times'] ?? 2);
    saveState($state);
    respond(200, ['mode' => $mode, 'flaky_remaining' => $state['flaky_remaining']]);
}

if ($path === '/_state') {
    $keys = array_column($state['attempts'], 'idempotency_key');
    respond(200, [
        'mode' => $state['mode'],
        'attempts' => count($state['attempts']),
        'distinct_deliveries' => count(array_unique(array_filter($keys))),
        'accepted' => count(array_filter($state['attempts'], static fn (array $a): bool => $a['accepted'])),
        'received' => $state['attempts'],
    ]);
}

if ($path !== '/orders' || $method !== 'POST') {
    respond(404, ['error' => 'not found']);
}

$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$key = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($body['idempotency_key'] ?? ''));

$mode = $state['mode'];
$accepted = false;
$status = 201;
$response = [];

switch ($mode) {
    case 'timeout':
        // Longer than any sane client timeout; the client gives up first.
        sleep(30);
        $status = 201;
        $accepted = true;
        break;

    case '500':
        $status = 500;
        $response = ['error' => 'internal error'];
        break;

    case '429':
        $status = 429;
        header('Retry-After: 2');
        $response = ['error' => 'too many requests'];
        break;

    case '409':
        // "Already have it." Treated as success by the client on purpose: a previous attempt
        // landed and we simply never saw the response.
        $status = 409;
        $response = ['error' => 'already recorded', 'idempotency_key' => $key];
        break;

    case 'flaky':
        if ($state['flaky_remaining'] > 0) {
            $state['flaky_remaining']--;
            $status = 500;
            $response = ['error' => 'transient', 'remaining_failures' => $state['flaky_remaining']];
            break;
        }
        $status = 201;
        $accepted = true;
        break;

    case 'ok':
    default:
        $status = 201;
        $accepted = true;
        break;
}

// Deliberately accepts the same order twice without complaint, exactly as the brief warns.
$state['attempts'][] = [
    'at' => gmdate('c'),
    'idempotency_key' => $key,
    'increment_id' => $body['order']['increment_id'] ?? null,
    'event' => $body['event'] ?? null,
    'status_returned' => $status,
    'accepted' => $accepted,
];
saveState($state);

respond($status, $response ?: ['recorded' => true, 'idempotency_key' => $key]);
