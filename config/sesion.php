<?php

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    $cookie = session_get_cookie_params();
    // HTTPS lo establece Apache. No confiar en cabeceras Forwarded del cliente.
    $https = isset($_SERVER['HTTPS']) && in_array(strtolower((string) $_SERVER['HTTPS']), ['on', '1'], true);
    session_set_cookie_params([
        'lifetime' => $cookie['lifetime'],
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tokenCsrfLogout(): string
{
    if (empty($_SESSION['logout_csrf_token'])) {
        $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['logout_csrf_token'];
}

iniciarSesionSegura();
