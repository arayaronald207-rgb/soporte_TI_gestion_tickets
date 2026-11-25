<?php
require_once __DIR__ . "/../config/sesion.php";
require_once __DIR__ . '/../config/parametros.php';

if (!isset($_SESSION["admin_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/conexion.php";

function escaparUsuario($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

require_once __DIR__ . "/../config/autorizacion.php";
$usuario_actual = usuarioAutenticado($conn, "../login.php");
exigirAdministrador($usuario_actual);
$usuario_id = (int) $usuario_actual["id"];
validarParametrosTexto($_GET, ['editar', 'creado', 'editado', 'eliminado']);
if (isset($_GET['editar']) &&
    (!filter_var($_GET['editar'], FILTER_VALIDATE_INT) || (int) $_GET['editar'] <= 0)) {
    rechazarParametrosMalformados();
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$usuario_ingresado = "";
$accion_actual = "";
$editar_id = 0;
$editar_usuario = "";
$editar_rol = "tecnico";

// Procesar formularios
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validarParametrosTexto($_POST, ['accion', 'id', 'usuario', 'rol', 'contrasena', 'confirmar']);
    // csrf_token conserva la comprobacion de tipo y hash_equals existentes.

    $accion = $_POST["accion"] ?? "crear";
    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)) {

        $error = "Solicitud no válida. Actualiza la página.";

    } elseif ($accion === "crear") {

        $usuario_ingresado = trim($_POST["usuario"] ?? "");
        $contrasena = $_POST["contrasena"] ?? "";
        $confirmar = $_POST["confirmar"] ?? "";

        if (
            $usuario_ingresado === "" ||
            $contrasena === "" ||
            $confirmar === ""
        ) {
            $error = "Completa todos los campos.";

        } elseif (
            strlen($usuario_ingresado) < 3 ||
            strlen($usuario_ingresado) > 50
        ) {
            $error = "El usuario debe tener entre 3 y 50 caracteres.";

        } elseif (
            !preg_match('/^[a-zA-Z0-9._-]+$/', $usuario_ingresado)
        ) {
            $error = "El usuario solo puede contener letras, números, puntos, guiones y guiones bajos.";

        } elseif (strlen($contrasena) < 8) {
            $error = "La contraseña debe tener al menos 8 caracteres.";

        } elseif ($contrasena !== $confirmar) {
            $error = "Las contraseñas no coinciden.";

        } else {

            $consulta = $conn->prepare(
                "SELECT id FROM usuarios WHERE usuario = ? LIMIT 1"
            );
            $consulta->bind_param("s", $usuario_ingresado);
            $consulta->execute();
            $existe = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if ($existe) {
                $error = "Ese nombre de usuario ya está registrado.";
            } else {

                $hash = password_hash($contrasena, PASSWORD_DEFAULT);

                $insertar = $conn->prepare(
                    "INSERT INTO usuarios (usuario, contrasena, rol)
                     VALUES (?, ?, 'tecnico')"
                );
                $insertar->bind_param("ss", $usuario_ingresado, $hash);

                if ($insertar->execute()) {
                    $insertar->close();
                    header("Location: usuarios.php?creado=1");
                    exit;
                }

                $error = "No se pudo registrar el usuario.";
                $insertar->close();
            }
        }

    } elseif ($accion === "editar") {

        $accion_actual = "editar";
        $editar_id = filter_var(
            $_POST["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $editar_usuario = trim($_POST["usuario"] ?? "");
        $editar_rol = $_POST["rol"] ?? "";
        $contrasena = $_POST["contrasena"] ?? "";
        $confirmar = $_POST["confirmar"] ?? "";

        if (!$editar_id || $editar_id <= 0) {
            $error = "Selecciona un usuario válido.";

        } elseif (
            strlen($editar_usuario) < 3 ||
            strlen($editar_usuario) > 50 ||
            !preg_match('/^[a-zA-Z0-9._-]+$/', $editar_usuario)
        ) {
            $error = "El nombre de usuario debe tener entre 3 y 50 caracteres y solo utilizar letras, números, puntos o guiones.";

        } elseif (!in_array($editar_rol, ["admin", "tecnico"], true)) {
            $error = "Selecciona un rol válido.";

        } elseif ($editar_id === $usuario_id &&
                  $editar_rol !== "admin") {
            $error = "No puedes quitarte tu propio rol de administrador.";

        } elseif ($contrasena !== "" && strlen($contrasena) < 8) {
            $error = "La nueva contraseña debe tener al menos 8 caracteres.";

        } elseif ($contrasena !== $confirmar) {
            $error = "Las contraseñas no coinciden.";

        } else {

            $consulta = $conn->prepare(
                "SELECT id FROM usuarios WHERE id = ? LIMIT 1"
            );
            $consulta->bind_param("i", $editar_id);
            $consulta->execute();
            $existe_id = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$existe_id) {
                $error = "El usuario que deseas editar ya no existe.";
            } else {

                $consulta = $conn->prepare(
                    "SELECT id FROM usuarios
                     WHERE usuario = ? AND id <> ? LIMIT 1"
                );
                $consulta->bind_param(
                    "si",
                    $editar_usuario,
                    $editar_id
                );
                $consulta->execute();
                $duplicado = $consulta->get_result()->fetch_assoc();
                $consulta->close();

                if ($duplicado) {
                    $error = "Ese nombre de usuario ya está en uso.";
                } else {

                    if ($contrasena !== "") {

                        $hash = password_hash(
                            $contrasena,
                            PASSWORD_DEFAULT
                        );

                        $actualizar = $conn->prepare(
                            "UPDATE usuarios
                             SET usuario = ?, rol = ?, contrasena = ?
                             WHERE id = ?"
                        );
                        $actualizar->bind_param(
                            "sssi",
                            $editar_usuario,
                            $editar_rol,
                            $hash,
                            $editar_id
                        );

                    } else {

                        $actualizar = $conn->prepare(
                            "UPDATE usuarios
                             SET usuario = ?, rol = ?
                             WHERE id = ?"
                        );
                        $actualizar->bind_param(
                            "ssi",
                            $editar_usuario,
                            $editar_rol,
                            $editar_id
                        );
                    }

                    if ($actualizar->execute()) {
                        $actualizar->close();

                        if ($editar_id === $usuario_id) {
                            $_SESSION["admin_usuario"] = $editar_usuario;
                        }

                        header("Location: usuarios.php?editado=1");
                        exit;
                    }

                    $error = "No se pudo actualizar el usuario.";
                    $actualizar->close();
                }
            }
        }

    } elseif ($accion === "eliminar") {

        $eliminar_id = filter_var(
            $_POST["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$eliminar_id || $eliminar_id <= 0) {
            $error = "Selecciona un usuario válido.";

        } elseif ($eliminar_id === $usuario_id) {
            $error = "No puedes eliminar tu propia cuenta.";

        } else {

            $consulta = $conn->prepare(
                "SELECT id FROM usuarios WHERE id = ? LIMIT 1"
            );
            $consulta->bind_param("i", $eliminar_id);
            $consulta->execute();
            $existe = $consulta->get_result()->fetch_assoc();
            $consulta->close();

            if (!$existe) {
                $error = "El usuario que deseas eliminar ya no existe.";
            } else {

                $eliminar = $conn->prepare(
                    "DELETE FROM usuarios WHERE id = ?"
                );
                $eliminar->bind_param("i", $eliminar_id);

                try {
                    if ($eliminar->execute()) {
                        $eliminar->close();
                        header("Location: usuarios.php?eliminado=1");
                        exit;
                    }
                    $error = "No se pudo eliminar el usuario.";
                } catch (mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1451) {
                        $error = "No se puede eliminar una cuenta vinculada a incidencias. Revisa sus asignaciones y referencias.";
                    } else {
                        error_log("Error al eliminar usuario: " . $e->getMessage());
                        $error = "No se pudo eliminar el usuario.";
                    }
                }
                $eliminar->close();
            }
        }

    } else {
        $error = "Operación no válida.";
    }
}

// Cargar los datos del usuario que se desea editar
if ($_SERVER["REQUEST_METHOD"] !== "POST" &&
    isset($_GET["editar"])) {

    $editar_id = filter_var(
        $_GET["editar"],
        FILTER_VALIDATE_INT
    );

    if ($editar_id && $editar_id > 0) {

        $consulta = $conn->prepare(
            "SELECT id, usuario, rol
             FROM usuarios WHERE id = ? LIMIT 1"
        );
        $consulta->bind_param("i", $editar_id);
        $consulta->execute();
        $datos_edicion = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if ($datos_edicion) {
            $accion_actual = "editar";
            $editar_usuario = $datos_edicion["usuario"];
            $editar_rol = $datos_edicion["rol"];
        } else {
            $error = "No se encontró el usuario seleccionado.";
        }
    }
}

// Consultar usuarios registrados
$usuarios = $conn->query(
    "SELECT id, usuario, rol
     FROM usuarios
     ORDER BY id ASC"
);
?>

<?php
require_once __DIR__ . "/../includes/recursos.php";

// Presentación: las filas ya consultadas se leen una vez para mostrar el resumen y la tabla.
$filas_usuarios = $usuarios->fetch_all(MYSQLI_ASSOC);
$total_admins = count(array_filter($filas_usuarios, static fn($f) => $f["rol"] === "admin"));
$total_tecnicos = count($filas_usuarios) - $total_admins;
$en_edicion = $accion_actual === "editar";
$iniciales_usuario = static fn(string $nombre): string =>
    mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $nombre), 0, 2, "UTF-8"), "UTF-8") ?: "?";
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestión de usuarios | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/dashboard.css', '../') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/usuarios.css', '../') ?>">
</head>

<body class="pagina-dashboard s-app">

<div class="dashboard-layout">

    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = 'usuarios';
    $nav_ticket = null;
    require __DIR__ . '/../includes/sidebar.php';
    ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="dashboard-main">

        <header class="dashboard-topbar">
            <div>
                <span class="topbar-etiqueta">Administración</span>
                <h1>Gestión de usuarios</h1>
                <p>Registra, consulta, edita y elimina las cuentas del sistema.</p>
            </div>
            <?php if ($en_edicion): ?>
                <a href="usuarios.php" class="btn-limpiar">
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    Registrar nuevo técnico
                </a>
            <?php endif; ?>
        </header>

        <!-- MENSAJES -->
        <?php if (isset($_GET["creado"])): ?>
            <div class="s-aviso s-aviso-exito s-usuarios-aviso" role="status">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div>El técnico fue registrado correctamente.</div>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET["editado"])): ?>
            <div class="s-aviso s-aviso-exito s-usuarios-aviso" role="status">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div>El usuario fue actualizado correctamente.</div>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET["eliminado"])): ?>
            <div class="s-aviso s-aviso-exito s-usuarios-aviso" role="status">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div>El usuario fue eliminado correctamente.</div>
            </div>
        <?php endif; ?>
        <?php if ($error !== ""): ?>
            <div class="s-aviso s-aviso-error s-usuarios-aviso" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <div><?= escaparUsuario($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- RESUMEN DE CUENTAS -->
        <section class="s-usuarios-resumen" aria-label="Resumen de cuentas">
            <div class="s-usuarios-dato s-usuarios-dato-destacado">
                <span class="s-usuarios-dato-icono"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                <span><strong class="s-mono"><?= count($filas_usuarios) ?></strong><small>Cuentas registradas</small></span>
            </div>
            <div class="s-usuarios-dato">
                <span class="s-usuarios-dato-icono s-dato-admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                <span><strong class="s-mono"><?= $total_admins ?></strong><small>Administradores</small></span>
            </div>
            <div class="s-usuarios-dato">
                <span class="s-usuarios-dato-icono s-dato-tecnico"><i class="fa-solid fa-user-gear" aria-hidden="true"></i></span>
                <span><strong class="s-mono"><?= $total_tecnicos ?></strong><small>Técnicos</small></span>
            </div>
        </section>

        <div class="s-usuarios-rejilla">

            <!-- FORMULARIO DE REGISTRO O EDICIÓN (mismos campos POST) -->
            <section class="s-usuarios-tarjeta s-usuarios-formulario<?= $en_edicion ? ' s-en-edicion' : '' ?>" aria-labelledby="titulo-formulario">

                <div class="s-usuarios-cabecera">
                    <span class="s-usuarios-icono">
                        <i class="fa-solid <?= $en_edicion ? 'fa-user-pen' : 'fa-user-plus' ?>" aria-hidden="true"></i>
                    </span>
                    <div>
                        <h2 id="titulo-formulario"><?= $en_edicion ? "Editar usuario" : "Registrar técnico" ?></h2>
                        <p>
                            <?= $en_edicion
                                ? "Modifica los datos de la cuenta seleccionada."
                                : "Crea una cuenta con acceso a la administración de incidencias." ?>
                        </p>
                    </div>
                </div>

                <?php if ($en_edicion): ?>
                    <p class="s-usuarios-modo">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                        Editando la cuenta <span class="s-mono">#<?= (int)$editar_id ?></span>
                    </p>
                <?php endif; ?>

                <form method="POST" class="s-usuarios-form">

                    <input type="hidden" name="csrf_token" value="<?= escaparUsuario($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="accion" value="<?= $en_edicion ? "editar" : "crear" ?>">
                    <?php if ($en_edicion): ?>
                        <input type="hidden" name="id" value="<?= (int)$editar_id ?>">
                    <?php endif; ?>

                    <div class="s-campo">
                        <label for="usuario" class="s-etiqueta">Nombre de usuario <span class="s-obligatorio" aria-hidden="true">*</span></label>
                        <div class="s-usuarios-control-icono">
                            <i class="fa-solid fa-at" aria-hidden="true"></i>
                            <input
                                type="text"
                                id="usuario"
                                name="usuario"
                                class="s-control"
                                placeholder="Ejemplo: soporte01"
                                value="<?= escaparUsuario($en_edicion ? $editar_usuario : $usuario_ingresado) ?>"
                                maxlength="50"
                                minlength="3"
                                autocomplete="off"
                                aria-describedby="ayuda-usuario"
                                required
                            >
                        </div>
                        <span id="ayuda-usuario" class="s-ayuda">De 3 a 50 caracteres: letras, números, puntos, guiones y guiones bajos.</span>
                    </div>

                    <?php if ($en_edicion): ?>
                        <div class="s-campo">
                            <label for="rol" class="s-etiqueta">Rol <span class="s-obligatorio" aria-hidden="true">*</span></label>
                            <select id="rol" name="rol" class="s-control" aria-describedby="ayuda-rol" required>
                                <option value="tecnico" <?= $editar_rol === "tecnico" ? "selected" : "" ?>>Técnico</option>
                                <option value="admin" <?= $editar_rol === "admin" ? "selected" : "" ?>>Administrador</option>
                            </select>
                            <span id="ayuda-rol" class="s-ayuda">Solo los administradores pueden gestionar cuentas de usuarios.</span>
                        </div>
                    <?php endif; ?>

                    <div class="s-usuarios-claves">
                        <div class="s-campo">
                            <label for="contrasena" class="s-etiqueta">
                                <?= $en_edicion ? "Nueva contraseña (opcional)" : "Contraseña" ?>
                                <?php if (!$en_edicion): ?><span class="s-obligatorio" aria-hidden="true">*</span><?php endif; ?>
                            </label>
                            <input
                                type="password"
                                id="contrasena"
                                name="contrasena"
                                class="s-control"
                                placeholder="<?= $en_edicion ? "Dejar vacío para conservarla" : "Mínimo 8 caracteres" ?>"
                                minlength="8"
                                autocomplete="new-password"
                                <?= $en_edicion ? "" : "required" ?>
                            >
                        </div>

                        <div class="s-campo">
                            <label for="confirmar" class="s-etiqueta">
                                Confirmar contraseña
                                <?php if (!$en_edicion): ?><span class="s-obligatorio" aria-hidden="true">*</span><?php endif; ?>
                            </label>
                            <input
                                type="password"
                                id="confirmar"
                                name="confirmar"
                                class="s-control"
                                placeholder="Repite la contraseña"
                                minlength="8"
                                autocomplete="new-password"
                                <?= $en_edicion ? "" : "required" ?>
                            >
                        </div>
                    </div>
                    <span class="s-ayuda s-usuarios-ayuda-clave">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <?= $en_edicion
                            ? "Si escribes una nueva contraseña, debe tener al menos 8 caracteres."
                            : "Al menos 8 caracteres. Se guarda cifrada y nunca se muestra." ?>
                    </span>

                    <div class="s-usuarios-botones">
                        <?php if ($en_edicion): ?>
                            <a href="usuarios.php" class="s-btn s-btn-secundario">Cancelar edición</a>
                        <?php endif; ?>
                        <button type="submit" class="s-btn s-btn-primario">
                            <i class="fa-solid <?= $en_edicion ? 'fa-floppy-disk' : 'fa-user-plus' ?>" aria-hidden="true"></i>
                            <?= $en_edicion ? "Guardar cambios" : "Registrar técnico" ?>
                        </button>
                    </div>
                </form>
            </section>

            <!-- LISTADO DE USUARIOS -->
            <section class="s-usuarios-tarjeta s-usuarios-listado" aria-labelledby="titulo-listado">

                <div class="s-usuarios-cabecera">
                    <span class="s-usuarios-icono"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-listado">Usuarios registrados</h2>
                        <p>Cuentas que pueden ingresar al sistema.</p>
                    </div>
                    <span class="s-usuarios-total"><?= count($filas_usuarios) ?> cuenta(s)</span>
                </div>

                <div class="s-usuarios-tabla-contenedor">
                    <table class="s-usuarios-tabla">
                        <caption class="s-solo-lector">Usuarios registrados: <?= count($filas_usuarios) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Usuario</th>
                                <th scope="col">Rol</th>
                                <th scope="col"><span class="s-th-derecha">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas_usuarios as $fila): ?>
                                <?php
                                $es_propia = (int)$fila["id"] === $usuario_id;
                                $es_admin_fila = $fila["rol"] === "admin";
                                $editando_fila = $en_edicion && (int)$editar_id === (int)$fila["id"];
                                ?>
                                <tr class="<?= $editando_fila ? 's-fila-editando' : '' ?>">
                                    <td class="s-celda-id-usuario">
                                        <span class="s-mono">#<?= (int)$fila["id"] ?></span>
                                    </td>
                                    <td class="s-celda-usuario">
                                        <span class="s-usuarios-persona">
                                            <span class="s-avatar<?= $es_admin_fila ? ' s-avatar-admin' : '' ?>" aria-hidden="true"><?= escaparUsuario($iniciales_usuario($fila["usuario"])) ?></span>
                                            <span class="s-usuarios-nombre"><?= escaparUsuario($fila["usuario"]) ?></span>
                                            <?php if ($es_propia): ?>
                                                <span class="s-usuarios-propia">Tu cuenta</span>
                                            <?php endif; ?>
                                            <?php if ($editando_fila): ?>
                                                <span class="s-usuarios-editando">En edición</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="s-celda-rol">
                                        <span class="s-rol <?= $es_admin_fila ? 's-rol-insignia-admin' : 's-rol-insignia-tecnico' ?>">
                                            <i class="fa-solid <?= $es_admin_fila ? 'fa-user-shield' : 'fa-user-gear' ?>" aria-hidden="true"></i>
                                            <?= $es_admin_fila ? "Administrador" : "Técnico" ?>
                                        </span>
                                    </td>
                                    <td class="s-celda-acciones-usuario">
                                        <div class="s-usuarios-acciones">
                                            <a
                                                href="usuarios.php?editar=<?= (int)$fila["id"] ?>"
                                                class="s-btn s-btn-secundario s-btn-sm"
                                                aria-label="Editar usuario <?= escaparUsuario($fila["usuario"]) ?>"
                                            >
                                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                Editar
                                            </a>
                                            <form
                                                method="POST"
                                                class="s-usuarios-form-eliminar"
                                                onsubmit="return confirm('¿Seguro que deseas eliminar este usuario? Esta acción no se puede deshacer.');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= escaparUsuario($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= (int)$fila["id"] ?>">
                                                <button
                                                    type="submit"
                                                    class="s-btn s-btn-riesgo s-btn-sm"
                                                    aria-label="<?= $es_propia ? 'No puedes eliminar tu propia cuenta' : 'Eliminar usuario ' . escaparUsuario($fila["usuario"]) ?>"
                                                    <?= $es_propia ? 'disabled title="No puedes eliminar tu propia cuenta"' : "" ?>
                                                >
                                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                    Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="s-usuarios-nota">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Solo los administradores pueden gestionar usuarios. Las contraseñas se almacenan de forma segura y no se muestran en la lista.
                </p>
            </section>

        </div>

    </main>

</div>

</body>
</html>

<?php
$conn->close();
?>
