<?php

// Credenciales en config/config.local.php (excluido del repositorio).
// Plantilla: config/config.example.php. No existe fallback a otra cuenta.
$conn = (static function (): mysqli {
    $archivoConfiguracion = __DIR__ . '/config.local.php';
    try {
        if (!is_file($archivoConfiguracion)) {
            throw new RuntimeException('Configuracion no disponible.');
        }
        $config = require $archivoConfiguracion;
        if (!is_array($config) ||
            !is_string($config['host'] ?? null) || $config['host'] === '' ||
            !is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535 ||
            !is_string($config['usuario'] ?? null) || $config['usuario'] === '' ||
            !is_string($config['contrasena'] ?? null) ||
            !is_string($config['base_datos'] ?? null) || $config['base_datos'] === '') {
            throw new RuntimeException('Configuracion no valida.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = @new mysqli($config['host'], $config['usuario'],
            $config['contrasena'], $config['base_datos'], $config['port']);
        if (!@$connection->set_charset('utf8mb4')) {
            throw new RuntimeException('Inicializacion no disponible.');
        }
        return $connection;
    } catch (Throwable $error) {
        // No registrar argumentos, credenciales, rutas ni el mensaje del driver.
        error_log('Soporte TI: conexion a base de datos no disponible.');
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Servicio temporalmente no disponible. Intentalo de nuevo mas tarde.');
    }
})();
