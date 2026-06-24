<?php
/**
 * auth.php
 * Authentication API for Baileys Manager.
 *
 * Actions (POST JSON body { "action": "..." }):
 *   login   — { "action":"login",  "username":"...", "password":"..." }
 *   logout  — { "action":"logout" }
 *   check   — { "action":"check"  }
 */

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Database connection (adjust to match your environment)
// ---------------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'baileys_manager');
define('DB_USER', 'root');
define('DB_PASS', '');

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

// ---------------------------------------------------------------------------
// Read action
// ---------------------------------------------------------------------------
$body   = file_get_contents('php://input');
$input  = json_decode($body, true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------
switch ($action) {

    // ── Check ────────────────────────────────────────────────────────────────
    case 'check':
        if (!empty($_SESSION['user_id'])) {
            echo json_encode([
                'authenticated' => true,
                'user' => [
                    'id'   => $_SESSION['user_id'],
                    'name' => $_SESSION['user_name'],
                    'email'=> $_SESSION['user_email'],
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['authenticated' => false]);
        }
        break;

    // ── Login ────────────────────────────────────────────────────────────────
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
            break;
        }

        try {
            $db  = getDB();
            // Support login by email or by name
            $stmt = $db->prepare(
                'SELECT id, name, email, password FROM users
                  WHERE email = :u OR name = :u2
                  LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':u2' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_email'] = $user['email'];

                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id'    => $user['id'],
                        'name'  => $user['name'],
                        'email' => $user['email'],
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    // ── Logout ───────────────────────────────────────────────────────────────
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        break;
}
