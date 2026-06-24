<?php
/**
 * baileys-proxy.php  — DB-aware reverse proxy
 * ============================================
 * Forwards requests to the Baileys Node.js service (http://127.0.0.1:3000)
 * AND keeps the MySQL `whats_app_sessions` table in sync so sessions survive
 * page refreshes.
 *
 * Intercepted routes (with DB sync):
 *   GET  /api/sessions/list          → merge DB list with Node status
 *   POST /api/session/start          → persist to DB, then forward to Node
 *   GET  /api/session/status/{id}    → forward to Node, update DB status/phone
 *   DELETE /api/session/{id}         → forward to Node, delete from DB
 *
 * All other /api/* paths are forwarded transparently.
 */

// ── Config ────────────────────────────────────────────────────────────────────
define('BAILEYS_BASE',    'http://127.0.0.1:3000');
define('REQUEST_TIMEOUT', 15);

require_once __DIR__ . '/db_config.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('Cache-Control: no-store');

function jsonOut(int $code, $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── DB connection (lazy, singleton) ──────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── cURL helper ───────────────────────────────────────────────────────────────
function proxyRequest(string $path, string $method, string $body, array $extraHeaders = []): array {
    $url     = BAILEYS_BASE . $path;
    $headers = array_merge([], $extraHeaders);
    if (!empty($_SERVER['CONTENT_TYPE']) && empty($extraHeaders)) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) && $body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'raw'    => $raw,
        'status' => $status,
        'error'  => $curlErr,
    ];
}

// ── DB helpers ────────────────────────────────────────────────────────────────
function dbUpsertSession(string $name, string $label, string $status = 'connecting'): void {
    try {
        db()->prepare(
            'INSERT INTO whats_app_sessions (name, label, status, created_at, updated_at)
             VALUES (:name, :label, :status, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 label      = VALUES(label),
                 status     = VALUES(status),
                 updated_at = NOW()'
        )->execute([':name' => $name, ':label' => $label, ':status' => $status]);
    } catch (Exception $e) {
        error_log('[baileys-proxy] dbUpsertSession error: ' . $e->getMessage());
    }
}

function dbUpdateStatus(string $name, string $status, ?string $phone = null): void {
    try {
        if ($phone !== null) {
            db()->prepare(
                'UPDATE whats_app_sessions
                 SET status = :status, phone_number = :phone, last_active = NOW(), updated_at = NOW()
                 WHERE name = :name'
            )->execute([':status' => $status, ':phone' => $phone, ':name' => $name]);
        } else {
            db()->prepare(
                'UPDATE whats_app_sessions
                 SET status = :status, last_active = NOW(), updated_at = NOW()
                 WHERE name = :name'
            )->execute([':status' => $status, ':name' => $name]);
        }
    } catch (Exception $e) {
        error_log('[baileys-proxy] dbUpdateStatus error: ' . $e->getMessage());
    }
}

function dbDeleteSession(string $name): void {
    try {
        db()->prepare('DELETE FROM whats_app_sessions WHERE name = :name')
            ->execute([':name' => $name]);
    } catch (Exception $e) {
        error_log('[baileys-proxy] dbDeleteSession error: ' . $e->getMessage());
    }
}

function dbAllSessions(): array {
    try {
        return db()->query(
            'SELECT name, label, phone_number, status FROM whats_app_sessions ORDER BY created_at ASC'
        )->fetchAll();
    } catch (Exception $e) {
        error_log('[baileys-proxy] dbAllSessions error: ' . $e->getMessage());
        return [];
    }
}

// ── Route parsing ─────────────────────────────────────────────────────────────
$path   = isset($_GET['path']) ? trim($_GET['path']) : '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = file_get_contents('php://input');

if (!preg_match('#^/api/#', $path)) {
    jsonOut(400, ['error' => 'Invalid proxy path.']);
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTE 1 — GET /api/sessions/list
//   Read from DB; also try Node for live status.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/api/sessions/list') {

    $dbRows  = dbAllSessions();

    // Try to get live data from Node (may be offline)
    $nodeMap = [];
    $nr = proxyRequest('/api/sessions/list', 'GET', '');
    if ($nr['raw'] !== false && $nr['status'] === 200) {
        $nd = json_decode($nr['raw'], true);
        if (!empty($nd['sessions'])) {
            foreach ($nd['sessions'] as $ns) {
                $nodeMap[$ns['session_id'] ?? $ns['name'] ?? ''] = $ns;
            }
        }
    }

    $sessions = [];
    foreach ($dbRows as $row) {
        $name  = $row['name'];
        $label = $row['label'] ?? '';
        $label = ($label !== '' && $label !== null) ? $label : $name;

        // Prefer live Node status over DB if Node is up
        $nodeInfo = $nodeMap[$name] ?? null;
        $status   = $nodeInfo['status'] ?? strtoupper($row['status']);
        $phone    = $nodeInfo['phone']  ?? $row['phone_number'] ?? null;

        // Sync updated status back to DB
        if ($nodeInfo && strtolower($status) !== strtolower($row['status'])) {
            dbUpdateStatus($name, $status, $phone ?: null);
        }

        $sessions[] = [
            'session_id' => $name,
            'label'      => $label,
            'status'     => $status,
            'phone'      => $phone,
        ];
    }

    jsonOut(200, ['sessions' => $sessions, 'max' => 10]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTE 2 — POST /api/session/start
//   Save to DB first, then forward to Node.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === '/api/session/start') {

    $input     = json_decode($body, true) ?? [];
    $sessionId = trim($input['sessionId'] ?? '');
    $label     = trim($input['label']     ?? $sessionId);

    if ($sessionId === '') {
        jsonOut(400, ['error' => 'sessionId is required.']);
    }

    // Persist to DB immediately (so it survives even if Node is slow)
    dbUpsertSession($sessionId, $label, 'connecting');

    // Forward to Node
    $nr = proxyRequest('/api/session/start', 'POST', $body, ['Content-Type: application/json']);

    if ($nr['raw'] === false || $nr['status'] === 0) {
        // Node is down — we already saved to DB; return success so UI shows the session
        jsonOut(200, ['status' => 'connecting', 'session_id' => $sessionId, 'label' => $label, 'note' => 'saved to DB; Baileys offline']);
    }

    http_response_code($nr['status'] ?: 502);
    echo $nr['raw'];
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTE 3 — GET /api/session/status/{id}
//   Forward to Node, then sync result to DB.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/api/session/status/(.+)$#', $path, $m)) {
    $sessionId = $m[1];

    $nr = proxyRequest('/api/session/status/' . urlencode($sessionId), 'GET', '');

    if ($nr['raw'] !== false && $nr['status'] === 200) {
        $nd = json_decode($nr['raw'], true);
        if (!empty($nd['status'])) {
            $phone = $nd['phone'] ?? null;
            dbUpdateStatus($sessionId, $nd['status'], $phone ?: null);
        }
        echo $nr['raw'];
        exit;
    }

    // Node offline — return DB record as fallback
    $dbError = null;
    try {
        $row = db()->prepare('SELECT * FROM whats_app_sessions WHERE name = :n LIMIT 1');
        $row->execute([':n' => $sessionId]);
        $r = $row->fetch();
        if ($r) {
            jsonOut(200, ['status' => strtoupper($r['status']), 'phone' => $r['phone_number']]);
        }
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }

    http_response_code($nr['status'] ?: 502);
    echo $nr['raw'] ?: json_encode([
        'error'    => 'Baileys offline',
        'detail'   => $nr['error'] ?: 'Unknown cURL error',
        'db_error' => $dbError ?: 'Session not found in database',
        'target'   => BAILEYS_BASE . '/api/session/status/' . $sessionId
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTE 4 — DELETE /api/session/{id}
//   Delete from Node, then delete from DB.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/session/([^/]+)$#', $path, $m)) {
    $sessionId = $m[1];

    $nr = proxyRequest('/api/session/' . urlencode($sessionId), 'DELETE', $body);

    // Always remove from DB regardless of Node response
    dbDeleteSession($sessionId);

    if ($nr['raw'] !== false) {
        http_response_code($nr['status'] ?: 200);
        echo $nr['raw'];
    } else {
        jsonOut(200, ['success' => true, 'note' => 'Removed from DB; Baileys offline']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// PASSTHROUGH — all other /api/* routes
// ─────────────────────────────────────────────────────────────────────────────
$nr = proxyRequest($path, $method, $body);

if ($nr['raw'] === false) {
    jsonOut(502, [
        'error'  => 'Proxy could not reach Baileys service.',
        'detail' => $nr['error'],
        'target' => BAILEYS_BASE . $path,
    ]);
}

http_response_code($nr['status'] ?: 502);
echo $nr['raw'];
