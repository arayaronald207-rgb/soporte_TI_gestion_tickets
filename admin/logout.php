
<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('El cierre de sesión requiere una solicitud POST.');
}
require_once __DIR__ . '/../config/sesion.php';
$token = $_POST['logout_csrf_token'] ?? '';
if (!is_string($token) || empty($_SESSION['logout_csrf_token']) ||
    !hash_equals($_SESSION['logout_csrf_token'], $token)) {
    http_response_code(403);
    exit('Solicitud no válida. Actualiza la página.');
}

// Eliminar todas las variables de sesión
$_SESSION = [];

// Eliminar también la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]
    );
}

// Destruir la sesión
session_destroy();

// Regresar al inicio de sesión
header("Location: ../login.php");
exit;
