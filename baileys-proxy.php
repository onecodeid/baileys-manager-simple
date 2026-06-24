<?php
/**
 * baileys-proxy.php
 * Reverse proxy: forwards requests from the browser (on baileys.example.com)
 * to the Baileys Node.js service running on http://127.0.0.1:3000.
 *
 * Allowed path prefixes:
 *   /api/sessions/list
 *   /api/session/start
 *   /api/session/status/{id}
 *   /api/session/{id}        (DELETE)
 *   /api/send-message
 */

define('BAILEYS_BASE', 'http://127.0.0.1:3000');
define('REQUEST_TIMEOUT', 15);

// ---------------------------------------------------------------------------
// Validate path — must start with /api/
// ---------------------------------------------------------------------------
$path = isset($_GET['path']) ? $_GET['path'] : '';

if (!preg_match('#^/api/#', $path)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid proxy path.']);
    exit;
}

$targetUrl = BAILEYS_BASE . $path;

// ---------------------------------------------------------------------------
// Incoming request
// ---------------------------------------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'];
$body    = file_get_contents('php://input');
$headers = [];

if (!empty($_SERVER['CONTENT_TYPE'])) {
    $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
}

// ---------------------------------------------------------------------------
// cURL
// ---------------------------------------------------------------------------
$ch = curl_init($targetUrl);

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

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
    $lower = strtolower(trim($header));
    if (strpos($lower, 'content-type:') === 0) {
        header(trim($header), true);
    }
    return strlen($header);
});

$response   = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------
if ($response === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error'  => 'Proxy could not reach Baileys service.',
        'detail' => $curlError,
        'target' => $targetUrl,
    ]);
    exit;
}

http_response_code($httpStatus ? $httpStatus : 502);
echo $response;
