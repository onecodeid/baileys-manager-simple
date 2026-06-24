<?php
/**
 * api.php — Baileys Message REST API
 * ====================================
 * Sends text, image, and PDF via the Baileys Node.js service.
 * Authentication: Bearer token = session_id (the 32-char hex you see on the dashboard).
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * ENDPOINTS
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * POST /api.php/send/text
 *   Headers : Authorization: Bearer <session_token>
 *             Content-Type: application/json
 *   Body    : { "to": "628123456789", "message": "Hello!" }
 *
 * POST /api.php/send/image
 *   Headers : Authorization: Bearer <session_token>
 *             Content-Type: multipart/form-data
 *   Fields  : to (required), caption (optional), file (image file, required)
 *   -- OR --
 *   Headers : Authorization: Bearer <session_token>
 *             Content-Type: application/json
 *   Body    : { "to": "628123456789", "caption": "Look!", "url": "https://..." }
 *
 * POST /api.php/send/file
 *   Headers : Authorization: Bearer <session_token>
 *             Content-Type: multipart/form-data
 *   Fields  : to (required), caption (optional), file (PDF or any file, required)
 *   -- OR --
 *   Headers : Authorization: Bearer <session_token>
 *             Content-Type: application/json
 *   Body    : { "to": "628123456789", "caption": "Invoice.pdf", "url": "https://...", "filename": "invoice.pdf" }
 *
 * GET /api.php/sessions
 *   Headers : Authorization: Bearer <session_token>
 *   Returns : status of the session matching the token
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * RESPONSE (all endpoints)
 * ──────────────────────────────────────────────────────────────────────────────
 *   200 { "success": true,  "message_id": "...", ... }
 *   4xx { "success": false, "error": "..." }
 *   5xx { "success": false, "error": "..." }
 */

// ── Config ────────────────────────────────────────────────────────────────────
define('BAILEYS_BASE',    'http://127.0.0.1:3000');
define('REQUEST_TIMEOUT', 20);
define('UPLOAD_MAX_MB',   16);        // max upload size per file

require_once __DIR__ . '/db_config.php';

// Upload temp dir inside the project (writable by web server)
define('UPLOAD_TMP',  __DIR__ . '/s/uploads');

// ── Bootstrap ─────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Allow CORS from same origin (adjust as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

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

/**
 * Execute a cURL request to the Baileys Node.js service.
 */
function baileysRequest(string $path, string $method = 'POST', array $curlOpts = []): array {
    $url = BAILEYS_BASE . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST  => $method,
    ], $curlOpts));

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['__curl_error' => $err, '__http_status' => 0];
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        $decoded = ['raw' => $raw];
    }
    $decoded['__http_status'] = $status;
    return $decoded;
}

/**
 * Normalise a WhatsApp number: strip leading + and append @s.whatsapp.net
 */
function normaliseJid(string $to): string {
    $to = preg_replace('/\D/', '', $to);   // digits only
    if (!str_ends_with($to, '@s.whatsapp.net')) {
        $to .= '@s.whatsapp.net';
    }
    return $to;
}

/**
 * File to base64 data URI
 */
function fileToDataUri(string $path, string $mime): string {
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
}

// ── Route parsing ─────────────────────────────────────────────────────────────
// Support both PATH_INFO and ?route= fallback
$pathInfo = $_SERVER['PATH_INFO'] ?? ($_GET['route'] ?? '');
$pathInfo = '/' . trim($pathInfo, '/');
$method   = $_SERVER['REQUEST_METHOD'];

// ── Authentication ────────────────────────────────────────────────────────────
$authHeader  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$sessionToken = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $sessionToken = trim($m[1]);
}
// Also accept ?token= for simple GET testing
if ($sessionToken === '' && isset($_GET['token'])) {
    $sessionToken = trim($_GET['token']);
}

if ($sessionToken === '') {
    fail(401, 'Missing Authorization header. Use: Authorization: Bearer <session_token>');
}

// Validate token exists in DB and is active
try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT name, status FROM whats_app_sessions WHERE name = :token LIMIT 1');
    $stmt->execute([':token' => $sessionToken]);
    $session = $stmt->fetch();
} catch (Exception $e) {
    fail(500, 'Database error: ' . $e->getMessage());
}

if (!$session) {
    fail(401, 'Invalid session token.');
}

// ── Routes ────────────────────────────────────────────────────────────────────

// GET /api.php/sessions  — return this session info
if ($method === 'GET' && $pathInfo === '/sessions') {
    $res = baileysRequest('/api/session/status/' . urlencode($sessionToken));
    unset($res['__http_status']);
    respond(200, ['success' => true, 'session' => $res]);
}

// ── Shared: parse body ────────────────────────────────────────────────────────
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$isJson      = str_contains($contentType, 'application/json');
$isMultipart = str_contains($contentType, 'multipart/form-data');

if ($isJson) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = $_POST;
}

// ── POST /api.php/send/text ───────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/text') {

    $to      = trim($body['to']      ?? '');
    $message = trim($body['message'] ?? '');

    if ($to === '')      fail(400, '"to" field is required (phone number, e.g. 628123456789).');
    if ($message === '') fail(400, '"message" field is required.');

    $jid = normaliseJid($to);
    $payload = ['to' => $jid, 'message' => $message];

    $res = baileysRequest('/api/send-message', 'POST', [
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Session-ID: ' . $sessionToken,
        ],
    ]);

    $httpStatus = $res['__http_status']; unset($res['__http_status']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400)          fail($httpStatus >= 500 ? 502 : 400, $res['error'] ?? 'Baileys error.');

    respond(200, ['success' => true, 'type' => 'text', 'to' => $jid] + $res);
}

// ── POST /api.php/send/image ──────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/image') {

    $to      = trim($body['to']      ?? '');
    $caption = trim($body['caption'] ?? '');
    if ($to === '') fail(400, '"to" field is required.');

    $jid = normaliseJid($to);

    // Source: uploaded file  -OR-  URL in JSON body
    if ($isMultipart && isset($_FILES['file'])) {
        // ── File upload ──────────────────────────────────────────────────
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) fail(400, 'Upload error code: ' . $file['error']);
        if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) fail(413, 'File too large (max ' . UPLOAD_MAX_MB . ' MB).');

        $mime = mime_content_type($file['tmp_name']);
        if (!str_starts_with($mime, 'image/')) fail(415, 'File must be an image (got: ' . $mime . ').');

        $dataUri = fileToDataUri($file['tmp_name'], $mime);

        $payload = [
            'to'      => $jid,
            'caption' => $caption,
            'media'   => $dataUri,
            'type'    => 'image',
        ];
    } elseif (isset($body['url'])) {
        // ── Remote URL ───────────────────────────────────────────────────
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

    $httpStatus = $res['__http_status']; unset($res['__http_status']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400)          fail($httpStatus >= 500 ? 502 : 400, $res['error'] ?? 'Baileys error.');

    respond(200, ['success' => true, 'type' => 'image', 'to' => $jid] + $res);
}

// ── POST /api.php/send/file ───────────────────────────────────────────────────
if ($method === 'POST' && $pathInfo === '/send/file') {

    $to       = trim($body['to']       ?? '');
    $caption  = trim($body['caption']  ?? '');
    $filename = trim($body['filename'] ?? '');
    if ($to === '') fail(400, '"to" field is required.');

    $jid = normaliseJid($to);

    if ($isMultipart && isset($_FILES['file'])) {
        // ── File upload ──────────────────────────────────────────────────
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) fail(400, 'Upload error code: ' . $file['error']);
        if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) fail(413, 'File too large (max ' . UPLOAD_MAX_MB . ' MB).');

        $mime            = mime_content_type($file['tmp_name']);
        $originalName    = $file['name'];
        $displayFilename = $filename !== '' ? $filename : $originalName;
        $dataUri         = fileToDataUri($file['tmp_name'], $mime);

        $payload = [
            'to'       => $jid,
            'caption'  => $caption ?: $displayFilename,
            'filename' => $displayFilename,
            'media'    => $dataUri,
            'type'     => 'document',
        ];
    } elseif (isset($body['url'])) {
        // ── Remote URL ───────────────────────────────────────────────────
        $displayFilename = $filename !== '' ? $filename : basename(parse_url($body['url'], PHP_URL_PATH));
        $payload = [
            'to'       => $jid,
            'caption'  => $caption ?: $displayFilename,
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

    $httpStatus = $res['__http_status']; unset($res['__http_status']);
    if (isset($res['__curl_error'])) fail(502, 'Baileys unreachable: ' . $res['__curl_error']);
    if ($httpStatus >= 400)          fail($httpStatus >= 500 ? 502 : 400, $res['error'] ?? 'Baileys error.');

    respond(200, ['success' => true, 'type' => 'document', 'to' => $jid, 'filename' => $payload['filename']] + $res);
}

// ── 404 ───────────────────────────────────────────────────────────────────────
fail(404, "Unknown route: [{$method}] {$pathInfo}. See API docs.");
