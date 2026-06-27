<?php
/**
 * api.php — Baileys Message REST API  (PHP 7.4 compatible)
 *
 * POST /api.php/send/text
 *   Authorization: Bearer <token>   Content-Type: application/json
 *   Body: { "to": "628xxx", "message": "Hello!" }
 *
 * POST /api.php/send/image
 *   multipart : to, caption (opt), file (image)
 *   JSON      : { "to":"...", "caption":"...", "url":"https://..." }
 *
 * POST /api.php/send/file
 *   multipart : to, caption (opt), filename (opt), file
 *   JSON      : { "to":"...", "caption":"...", "filename":"...", "url":"https://..." }
 *
 * GET /api.php/sessions
 *   Authorization: Bearer <token>
 */

// ── Config ────────────────────────────────────────────────────────────────────
define('BAILEYS_BASE',    'http://127.0.0.1:3000');
define('REQUEST_TIMEOUT', 20);
define('UPLOAD_MAX_MB',   16);
define('UPLOAD_TMP_DIR',  __DIR__ . '/tmp');   // ./tmp beside api.php

require_once __DIR__ . '/db_config.php';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Ensure ./tmp exists and is writable
if (!is_dir(UPLOAD_TMP_DIR)) {
    mkdir(UPLOAD_TMP_DIR, 0750, true);
}
// Purge files older than 10 minutes
foreach (glob(UPLOAD_TMP_DIR . '/*') ?: [] as $old) {
    if (is_file($old) && (time() - filemtime($old)) > 600) @unlink($old);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg): void {
    respond($code, ['success' => false, 'error' => $msg]);
}

function getDB(): PDO {
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

function baileysRequest(string $path, string $method = 'POST', array $curlOpts = []): array {
    $ch = curl_init(BAILEYS_BASE . $path);
    curl_setopt_array($ch, array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST  => $method,
    ], $curlOpts));
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['__curl_error' => $err, '__http_status' => 0, '__raw' => ''];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) $decoded = ['raw' => $raw];
    $decoded['__http_status'] = $status;
    $decoded['__raw']         = $raw;  // keep original for error reporting
    return $decoded;
}

/**
 * Build a meaningful error message from a Baileys response.
 * Tries common error keys; falls back to raw body so nothing is hidden.
 */
function baileysErrorMsg(array $res): string {
    foreach (['error', 'message', 'msg', 'detail', 'reason'] as $key) {
        if (!empty($res[$key]) && is_string($res[$key])) {
            return $res[$key];
        }
    }
    $raw = isset($res['__raw']) ? $res['__raw'] : json_encode($res);
    return 'Baileys HTTP ' . $res['__http_status'] . ': ' . mb_substr($raw, 0, 500);
}

function normaliseJid(string $to): string {
    return preg_replace('/\D/', '', $to) . '@s.whatsapp.net';
}

/**
 * Save uploaded file to ./tmp, return its path.
 * Caller must unlink() after use.
 */
function stageTmp(array $fileArr, string $wantedName = ''): string {
    if ($fileArr['error'] !== UPLOAD_ERR_OK) {
        fail(400, 'Upload error code: ' . $fileArr['error']);
    }
    if ($fileArr['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        fail(413, 'File too large (max ' . UPLOAD_MAX_MB . ' MB).');
    }
    $safe = preg_replace('/[^a-zA-Z0-9_.\-]/', '_',
                         $wantedName ?: basename($fileArr['name']));
    $dest = UPLOAD_TMP_DIR . '/' . uniqid('up_', true) . '_' . $safe;
    if (!move_uploaded_file($fileArr['tmp_name'], $dest)) {
        fail(500, 'Failed to stage uploaded file to ./tmp.');
    }
    return $dest;
}

function fileToDataUri(string $path, string $mime): string {
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
}

// ── Route parsing ─────────────────────────────────────────────────────────────
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO']
          : (isset($_GET['route'])       ? $_GET['route'] : '');
$pathInfo = '/' . trim($pathInfo, '/');
$method   = $_SERVER['REQUEST_METHOD'];

// ── Authentication ────────────────────────────────────────────────────────────
$authHeader   = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
// Some Apache configs pass it differently
if ($authHeader === '' && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    $authHeader = isset($hdrs['Authorization']) ? $hdrs['Authorization'] : '';
}
$sessionToken = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $sessionToken = trim($m[1]);
}
if ($sessionToken === '' && isset($_GET['token'])) {
    $sessionToken = trim($_GET['token']);
}
if ($sessionToken === '') {
    fail(401, 'Missing Authorization header. Use: Authorization: Bearer <session_token>');
}

try {
    $stmt = getDB()->prepare(
        'SELECT name, status FROM whats_app_sessions WHERE name = :t LIMIT 1'
    );
    $stmt->execute([':t' => $sessionToken]);
    $session = $stmt->fetch();
} catch (Exception $e) {
    fail(500, 'Database error: ' . $e->getMessage());
}
if (!$session) fail(401, 'Invalid session token.');

// ── GET /api.php/sessions ─────────────────────────────────────────────────────
if ($method === 'GET' && $pathInfo === '/sessions') {
    $res = baileysRequest('/api/session/status/' . urlencode($sessionToken));
    unset($res['__http_status'], $res['__raw']);
    respond(200, ['success' => true, 'session' => $res]);
}

// ── Detect body type ─────────────────────────────────────────────────────────
// Check $_FILES directly — more reliable than parsing CONTENT_TYPE with PATH_INFO
$hasFileUpload = !empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

$contentType = strtolower(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '');
$isJson      = strpos($contentType, 'application/json') !== false;

if ($isJson) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
} else {
    // multipart or urlencoded — $_POST is already populated by PHP
    $body = $_POST;
}

// ── POST /api.php/send/text ───────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/text') {
    $to      = trim(isset($body['to'])      ? $body['to']      : '');
    $message = trim(isset($body['message']) ? $body['message'] : '');
    if ($to === '')      fail(400, '"to" is required.');
    if ($message === '') fail(400, '"message" is required.');

    $jid = normaliseJid($to);
    $res = baileysRequest('/api/send-message', 'POST', [
        CURLOPT_POSTFIELDS => json_encode(['to' => $jid, 'message' => $message]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Session-ID: ' . $sessionToken,
        ],
    ]);
    $httpStatus = $res['__http_status']; unset($res['__http_status'], $res['__raw']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400) fail($httpStatus >= 500 ? 502 : 400,
        baileysErrorMsg($res));
    respond(200, array_merge(['success' => true, 'type' => 'text', 'to' => $jid], $res));
}

// ── POST /api.php/send/image ──────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/image') {
    $to      = trim(isset($body['to'])      ? $body['to']      : '');
    $caption = trim(isset($body['caption']) ? $body['caption'] : '');
    if ($to === '') fail(400, '"to" is required.');
    $jid = normaliseJid($to);

    if ($hasFileUpload) {
        // ── File upload path ─────────────────────────────────────────────────
        $tmpPath = stageTmp($_FILES['file']);
        $mime    = mime_content_type($tmpPath);
        if (strpos($mime, 'image/') !== 0) {
            @unlink($tmpPath);
            fail(415, 'File must be an image (detected: ' . $mime . ').');
        }
        $payload = [
            'to'      => $jid,
            'caption' => $caption,
            'media'   => fileToDataUri($tmpPath, $mime),
            'type'    => 'image',
        ];
        @unlink($tmpPath);

    } elseif (isset($body['url']) && $body['url'] !== '') {
        // ── URL path ─────────────────────────────────────────────────────────
        $payload = [
            'to'      => $jid,
            'caption' => $caption,
            'url'     => $body['url'],
            'type'    => 'image',
        ];
    } else {
        fail(400, 'Provide either a "file" upload (multipart) or a "url" (JSON).');
    }

    $res = baileysRequest('/api/send-message', 'POST', [
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Session-ID: ' . $sessionToken,
        ],
    ]);
    $httpStatus = $res['__http_status']; unset($res['__http_status'], $res['__raw']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400) fail($httpStatus >= 500 ? 502 : 400,
        baileysErrorMsg($res));
    respond(200, array_merge(['success' => true, 'type' => 'image', 'to' => $jid], $res));
}

// ── POST /api.php/send/file ───────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/file') {
    $to       = trim(isset($body['to'])       ? $body['to']       : '');
    $caption  = trim(isset($body['caption'])  ? $body['caption']  : '');
    $filename = trim(isset($body['filename']) ? $body['filename'] : '');
    if ($to === '') fail(400, '"to" is required.');
    $jid = normaliseJid($to);

    if ($hasFileUpload) {
        // ── File upload path ─────────────────────────────────────────────────
        $origName        = $filename !== '' ? $filename : $_FILES['file']['name'];
        $tmpPath         = stageTmp($_FILES['file'], $origName);
        $mime            = mime_content_type($tmpPath);
        $displayFilename = $origName;
        $payload = [
            'to'       => $jid,
            'caption'  => $caption !== '' ? $caption : $displayFilename,
            'filename' => $displayFilename,
            'media'    => fileToDataUri($tmpPath, $mime),
            'type'     => 'document',
        ];
        @unlink($tmpPath);

    } elseif (isset($body['url']) && $body['url'] !== '') {
        // ── URL path ─────────────────────────────────────────────────────────
        $displayFilename = $filename !== '' ? $filename
            : basename(parse_url($body['url'], PHP_URL_PATH));
        $payload = [
            'to'       => $jid,
            'caption'  => $caption !== '' ? $caption : $displayFilename,
            'filename' => $displayFilename,
            'url'      => $body['url'],
            'type'     => 'document',
        ];
    } else {
        fail(400, 'Provide either a "file" upload (multipart) or a "url" (JSON).');
    }

    $res = baileysRequest('/api/send-message', 'POST', [
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Session-ID: ' . $sessionToken,
        ],
    ]);
    $httpStatus = $res['__http_status']; unset($res['__http_status'], $res['__raw']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400) fail($httpStatus >= 500 ? 502 : 400,
        baileysErrorMsg($res));
    respond(200, array_merge([
        'success'  => true,
        'type'     => 'document',
        'to'       => $jid,
        'filename' => $payload['filename'],
    ], $res));
}

// ── 404 ───────────────────────────────────────────────────────────────────────
fail(404, 'Unknown route: [' . $method . '] ' . $pathInfo
    . '. Available: /send/text  /send/image  /send/file  /sessions');