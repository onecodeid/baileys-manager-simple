
<?php
/**
 * auth.php — Authentication API  (PHP 7.4 compatible)
 *
 * Actions (POST JSON body):
 *   login           — { "action":"login", "username":"...", "password":"..." }
 *   logout          — { "action":"logout" }
 *   check           — { "action":"check" }
 *   update_name     — { "action":"update_name", "name":"..." }          [auth required]
 *   update_password — { "action":"update_password",                     [auth required]
 *                        "current_password":"...", "new_password":"..." }
 */

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/db_config.php';

// ── DB singleton ──────────────────────────────────────────────────────────────
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

function jsonOut(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth(): void {
    if (empty($_SESSION['user_id'])) {
        jsonOut(401, ['error' => 'Not authenticated.']);
    }
}

// ── Parse input ───────────────────────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$action = isset($input['action']) ? trim($input['action']) : (isset($_GET['action']) ? trim($_GET['action']) : '');

// ── Route ─────────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Check ─────────────────────────────────────────────────────────────────
    case 'check':
        if (!empty($_SESSION['user_id'])) {
            jsonOut(200, [
                'authenticated' => true,
                'user' => [
                    'id'    => $_SESSION['user_id'],
                    'name'  => $_SESSION['user_name'],
                    'email' => $_SESSION['user_email'],
                ]
            ]);
        } else {
            jsonOut(401, ['authenticated' => false]);
        }
        break;

    // ── Login ─────────────────────────────────────────────────────────────────
    case 'login':
        $username = trim(isset($input['username']) ? $input['username'] : '');
        $password = isset($input['password']) ? $input['password'] : '';

        if ($username === '' || $password === '') {
            jsonOut(400, ['success' => false, 'message' => 'Username and password are required.']);
        }

        try {
            $stmt = getDB()->prepare(
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

                jsonOut(200, [
                    'success' => true,
                    'user' => [
                        'id'    => $user['id'],
                        'name'  => $user['name'],
                        'email' => $user['email'],
                    ]
                ]);
            } else {
                jsonOut(401, ['success' => false, 'message' => 'Invalid credentials.']);
            }
        } catch (Exception $e) {
            jsonOut(500, ['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    // ── Logout ────────────────────────────────────────────────────────────────
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        jsonOut(200, ['success' => true]);
        break;

    // ── Update Name ───────────────────────────────────────────────────────────
    case 'update_name':
        requireAuth();

        $name = trim(isset($input['name']) ? $input['name'] : '');
        if ($name === '') {
            jsonOut(400, ['error' => 'Name cannot be empty.']);
        }
        if (mb_strlen($name) > 100) {
            jsonOut(400, ['error' => 'Name is too long (max 100 characters).']);
        }

        try {
            // Check if name is already taken by another user
            $stmt = getDB()->prepare(
                'SELECT id FROM users WHERE name = :name AND id != :id LIMIT 1'
            );
            $stmt->execute([':name' => $name, ':id' => $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                jsonOut(409, ['error' => 'That name is already taken by another user.']);
            }

            getDB()->prepare('UPDATE users SET name = :name, updated_at = NOW() WHERE id = :id')
                ->execute([':name' => $name, ':id' => $_SESSION['user_id']]);

            // Update session so the top bar reflects the change immediately
            $_SESSION['user_name'] = $name;

            jsonOut(200, ['success' => true, 'name' => $name]);
        } catch (Exception $e) {
            jsonOut(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    // ── Update Password ───────────────────────────────────────────────────────
    case 'update_password':
        requireAuth();

        $currentPassword = isset($input['current_password']) ? $input['current_password'] : '';
        $newPassword     = isset($input['new_password'])     ? $input['new_password']     : '';

        if ($currentPassword === '' || $newPassword === '') {
            jsonOut(400, ['error' => 'Current password and new password are required.']);
        }
        if (mb_strlen($newPassword) < 6) {
            jsonOut(400, ['error' => 'New password must be at least 6 characters.']);
        }
        if ($currentPassword === $newPassword) {
            jsonOut(400, ['error' => 'New password must be different from the current password.']);
        }

        try {
            $stmt = getDB()->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                jsonOut(401, ['error' => 'Current password is incorrect.']);
            }

            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            getDB()->prepare('UPDATE users SET password = :pw, updated_at = NOW() WHERE id = :id')
                ->execute([':pw' => $hash, ':id' => $_SESSION['user_id']]);

            jsonOut(200, ['success' => true]);
        } catch (Exception $e) {
            jsonOut(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    // ── Unknown ───────────────────────────────────────────────────────────────
    default:
        jsonOut(400, ['error' => 'Unknown action: ' . $action]);
        break;
}