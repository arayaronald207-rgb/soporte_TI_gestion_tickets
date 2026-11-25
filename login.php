<?php
require_once __DIR__ . "/config/sesion.php";
require_once __DIR__ . '/config/parametros.php';

require_once __DIR__ . "/config/conexion.php";

require_once __DIR__ . "/config/autorizacion.php";
$usuario_actual = usuarioAutenticado($conn, "login.php", false);
if ($usuario_actual) {
    header("Location: admin/index.php");
    exit;
}

$error = "";
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validarParametrosTexto($_POST, ['usuario', 'contrasena']);

    $usuario = trim($_POST["usuario"] ?? "");
    $contrasena = $_POST["contrasena"] ?? "";

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['login_csrf_token'], $token)) {
        http_response_code(403);
        $error = 'La sesión del formulario no es válida. Actualiza la página.';
    } elseif ($usuario === "" || $contrasena === "") {
        $error = "Completa todos los campos.";
    } else {

        // Ahora también consultamos el rol del usuario
        $sql = "SELECT id, usuario, contrasena, rol, activo
                FROM usuarios
                WHERE usuario = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $usuario);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $admin = $resultado->fetch_assoc();

        if (
            $admin &&
            (int) $admin["activo"] === 1 &&
            in_array($admin["rol"], ["admin", "tecnico"], true) &&
            password_verify($contrasena, $admin["contrasena"])
        ) {
            session_regenerate_id(true);
            // Renovar los tokens de autenticacion al cambiar de contexto.
            $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));

            $_SESSION["admin_id"] = $admin["id"];
            $_SESSION["admin_usuario"] = $admin["usuario"];

            // Guardamos el rol para controlar los permisos
            $_SESSION["admin_rol"] = $admin["rol"];

            $stmt->close();

            header("Location: admin/index.php");
            exit;

        } else {
            $error = "Usuario o contraseña incorrectos.";
        }

        $stmt->close();
    }
}
?>

<?php require_once __DIR__ . "/includes/recursos.php"; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Iniciar sesión | Soporte TI</title>
    <?= faviconSenal() ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/publico.css') ?>">
    <script src="<?= recursoVersionado('js/publico.js') ?>" defer></script>
</head>

<body class="s-app s-publico s-pagina-acceso">

<div class="s-acceso">

    <!-- PANEL DE IDENTIDAD -->
    <aside class="s-acceso-identidad" aria-label="Soporte TI">
        <div class="s-acceso-identidad-interior">
            <a href="index.php" class="s-marca">
                <?= logotipoSenal('', 'oscuro', 44) ?>
                <span class="s-marca-texto">
                    <strong>Soporte TI</strong>
                    <small>Gestión de incidencias</small>
                </span>
            </a>

            <div class="s-acceso-mensaje">
                <span class="s-pulso" aria-hidden="true"></span>
                <h2>Centro de operaciones de soporte técnico</h2>
                <p>
                    Sistema de gestión de incidencias para el registro
                    y seguimiento de solicitudes de soporte técnico.
                </p>
            </div>

            <ul class="s-acceso-rasgos">
                <li>
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <strong>Gestión centralizada</strong>
                    <small>Tickets, técnicos e historial en un solo lugar.</small>
                </li>
                <li>
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    <strong>Acceso seguro</strong>
                    <small>Permisos distintos para administración y técnicos.</small>
                </li>
                <li>
                    <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                    <strong>Seguimiento eficiente</strong>
                    <small>Estados, asignaciones e indicadores al día.</small>
                </li>
            </ul>
        </div>

        <p class="s-acceso-pie">&copy; 2026 Soporte TI · Sistema de Gestión de Incidencias</p>
    </aside>

    <!-- FORMULARIO -->
    <main class="s-acceso-principal">
        <div class="s-acceso-tarjeta">

            <span class="s-rotulo">Acceso de personal</span>
            <h1>Iniciar sesión</h1>
            <p class="s-acceso-descripcion">
                Ingresa con tu cuenta de administrador o técnico de soporte.
            </p>

            <?php if ($error !== ""): ?>
                <div class="s-aviso s-aviso-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <div><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="s-acceso-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="s-campo">
                    <label for="usuario" class="s-etiqueta">Usuario</label>
                    <div class="s-campo-icono">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <input
                            type="text"
                            id="usuario"
                            name="usuario"
                            class="s-control"
                            placeholder="Tu nombre de usuario"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="s-campo">
                    <label for="contrasena" class="s-etiqueta">Contraseña</label>
                    <div class="s-campo-icono s-campo-clave">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <input
                            type="password"
                            id="contrasena"
                            name="contrasena"
                            class="s-control"
                            placeholder="Tu contraseña"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="s-mostrar-clave" data-mostrar-clave="contrasena"
                                aria-controls="contrasena" aria-pressed="false" hidden>
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            <span>Mostrar</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="s-btn s-btn-primario s-btn-grande s-acceso-enviar">
                    Iniciar sesión
                    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </button>

            </form>

            <div class="s-acceso-enlaces">
                <a href="index.php">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                    Volver al inicio
                </a>
                <a href="registrar.php">
                    ¿Necesitas reportar un problema? Registra una incidencia
                </a>
            </div>

        </div>
    </main>

</div>

</body>
</html>
