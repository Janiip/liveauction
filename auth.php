<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function redirectPorRol(string $rol): void {
    switch ($rol) {
        case 'administrador':
            header('Location: admin.php');
            break;
        case 'moderador':
            header('Location: moderador.php');
            break;
        default:
            header('Location: c_obras_activas.php');
            break;
    }
    exit;
}

function requireLogin(?string $rolRequerido = null): void {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: index.php');
        exit;
    }

    if ($rolRequerido !== null && $_SESSION['rol'] !== $rolRequerido) {
        header('Location: index.php');
        exit;
    }
}

function cerrarSesion(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}