<?php
/**
 * playground-proxy.php
 *
 * Receives a multipart/form-data POST from the Playground tab,
 * saves the uploaded file to /tmp, then forwards the request
 * (with file as CURLFile) to the local Baileys Node.js service.
 *
 * Compatible with PHP 7.4+
 */

session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// ── Config ────────────────────────────────────────────────────────────────────
define('BAILEYS_BASE', 'http://127.0.0.1:3000');   // adjust if your Node port differs
define('TMP_DIR',      '/tmp/baileys_playground');  // staging folder for uploads
define('MAX_SIZE',     20 * 1024 * 1024);           // 20 MB hard cap

// ── Validate input ────────────────────────────────────────────────────────────
$token    = isset($_POST['token'])    ? trim($_POST['token'])    : '';
$to       = isset($_POST['to'])       ? trim($_POST['to'])       : '';
$type     = isset($_POST['type'])     ? trim($_POST['type'])     : '';   // image | file
$caption  = isset($_POST['caption'])  ? trim($_POST['caption'])  : '';
$filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';

if (!$token || !$to || !in_array($type, ['image', 'file'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: token, to, type']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['file']) ? $_FILES['file']['error'] : -1;
    http_response_code(400);
    echo json_encode(['error' => 'File upload error', 'code' => $errCode]);
    exit;
}

if ($_FILES['file']['size'] > MAX_SIZE) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large (max 20 MB)']);
    exit;
}

// ── Prepare /tmp staging dir ──────────────────────────────────────────────────
if (!is_dir(TMP_DIR)) {
    if (!mkdir(TMP_DIR, 0750, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot create temp directory']);
        exit;
    }
}

// Purge files older than 10 minutes to keep /tmp clean
foreach (glob(TMP_DIR . '/*') as $oldFile) {
    if (is_file($oldFile) && (time() - filemtime($oldFile)) > 600) {
        @unlink($oldFile);
    }
}

// ── Save uploaded file to /tmp ────────────────────────────────────────────────
$origName  = $filename ?: basename($_FILES['file']['name']);
$safeName  = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $origName);
$tmpPath   = TMP_DIR . '/' . uniqid('pg_', true) . '_' . $safeName;
$mimeType  = $_FILES['file']['type'] ?: mime_content_type($_FILES['file']['tmp_name']);

if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save uploaded file to /tmp']);
    exit;
}

// ── Build endpoint URL ────────────────────────────────────────────────────────
$endpoint = ($type === 'image') ? '/api/send/image' : '/api/send/file';
$url      = BAILEYS_BASE . $endpoint;

// ── Forward via cURL multipart ────────────────────────────────────────────────
$postFields = [
    'to'       => $to,
    'caption'  => $caption,
    'filename' => $safeName,
    'file'     => new CURLFile($tmpPath, $mimeType, $safeName),
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        // Let cURL set Content-Type: multipart/form-data with boundary automatically
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Clean up tmp file ─────────────────────────────────────────────────────────
@unlink($tmpPath);

// ── Return response to browser ────────────────────────────────────────────────
if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error: ' . $curlErr]);
    exit;
}

http_response_code($httpCode);
echo $result;