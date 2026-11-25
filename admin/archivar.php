<?php
require_once __DIR__ . "/../config/sesion.php";
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/autorizacion.php';
require_once __DIR__ . '/../config/historial.php';
$usuario_actual = usuarioAutenticado($conn, '../login.php');
exigirAdministrador($usuario_actual);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(404);
    exit('No se encontró la incidencia.');
}
$incidencia = incidenciaPermitida($conn, $id, $usuario_actual);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $accion = $_POST['accion'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        $error = 'Solicitud no válida. Actualiza la página.';
    } elseif (!in_array($accion, ['archivar', 'restaurar'], true)) {
        http_response_code(422);
        $error = 'Selecciona una operación válida.';
    } else {
        try {
            $incidencia = bloquearIncidenciaParaCambio($conn, $id, true);
            $archivar = $accion === 'archivar';
            if ((int) $incidencia['archivado'] === (int) $archivar) {
                // No invertir una operacion ni duplicar eventos por un formulario viejo.
                $conn->rollback();
                http_response_code(409);
                $error = 'El ticket ya está en ese estado de archivado. Revisa la operación.';
            } else {
                $autor = (int) $_SESSION['admin_id'];
                if ($archivar) {
                    $stmt = $conn->prepare('UPDATE incidencias SET archivado = 1, fecha_archivado = UTC_TIMESTAMP(), archivado_por = ? WHERE id = ?');
                    $stmt->bind_param('ii', $autor, $id);
                } else {
                    // El CHECK existente exige NULL en los datos del archivo vigente.
                    // Las fechas y autores de archivos anteriores quedan en el historial.
                    $stmt = $conn->prepare('UPDATE incidencias SET archivado = 0, fecha_archivado = NULL, archivado_por = NULL WHERE id = ?');
                    $stmt->bind_param('i', $id);
                }
                $stmt->execute();
                $stmt->close();
                registrarEventoIncidencia($conn, $id, $autor, $archivar ? 'archivado' : 'desarchivado',
                    $archivar ? 'Se archivó la incidencia.' : 'Se restauró la incidencia a las listas de trabajo.');
                $conn->commit();
                header('Location: index.php?' . ($archivar ? 'archivados=1&archivado=1' : 'restaurado=1'));
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Error al archivar/restaurar incidencia: ' . $e->getMessage());
            http_response_code(500);
            $error = 'No se pudo guardar la operación. Inténtalo de nuevo.';
        }
    }
}
$esta_archivado = (int) $incidencia['archivado'] === 1;
$accion_titulo = $esta_archivado ? 'Restaurar' : 'Archivar';
function escapar($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= $accion_titulo ?> ticket | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>

    <link rel="stylesheet" href="../css/tema.css">
    <link rel="stylesheet" href="../css/estilos.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/navegacion.css">
</head>

<body class="pagina-dashboard s-app">

<div class="dashboard-layout">

    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = 'ticket';
    $nav_ticket = ['id' => (int) $id, 'seccion' => 'archivar', 'archivado' => $esta_archivado];
    require __DIR__ . '/../includes/sidebar.php';
    ?>

    <!-- CONTENIDO -->
    <main class="dashboard-main">

        <header class="dashboard-topbar">

            <div>

                <span class="topbar-etiqueta">
                    ADMINISTRACIÓN DE INCIDENCIAS
                </span>

                <h1><?= $accion_titulo ?> incidencia</h1>

                <p>
                    Confirma la operación. El ticket y su historial se conservarán.
                </p>

            </div>

            <a href="index.php" class="btn-limpiar">
                Volver
            </a>

        </header>

        <section class="tarjeta eliminar-tarjeta">

            <div class="eliminar-icono">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>

            <h2>¿<?= $accion_titulo ?> este ticket?</h2>

            <p class="eliminar-descripcion">
                <?= $esta_archivado ? "El ticket volverá a las listas de trabajo con su estado y asignación actuales." : "El ticket dejará de aparecer en las listas de trabajo normales." ?>
            </p>

            <div class="eliminar-detalles">

                <div>

                    <span>Número de ticket</span>

                    <strong>
                        #<?= str_pad(
                            (string) $incidencia["id"],
                            4,
                            "0",
                            STR_PAD_LEFT
                        ) ?>
                    </strong>

                </div>

                <div>

                    <span>Reportado por</span>

                    <strong>
                        <?= escapar($incidencia["reportado_por"]) ?>
                    </strong>

                </div>

                <div>

                    <span>Laboratorio</span>

                    <strong>
                        <?= escapar($incidencia["laboratorio"]) ?>
                    </strong>

                </div>

                <div>

                    <span>Equipo</span>

                    <strong>
                        <?= escapar($incidencia["equipo"]) ?>
                    </strong>

                </div>

            </div>

            <!-- MOSTRAR ERRORES -->
            <?php if ($error !== ""): ?>

                <div class="mensaje error">
                    <?= escapar($error) ?>
                </div>

            <?php endif; ?>

            <div class="eliminar-advertencia">

                <i class="fa-solid fa-circle-exclamation"></i>

                Solo un administrador puede archivar o restaurar este ticket.

            </div>

            <!-- FORMULARIO DE CONFIRMACIÓN -->
            <form method="POST" class="eliminar-form">
                <input type="hidden" name="accion" value="<?= $esta_archivado ? 'restaurar' : 'archivar' ?>">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapar($_SESSION["csrf_token"]) ?>"
                >

                <a href="index.php" class="btn-limpiar">
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="btn-eliminar-confirmar"
                >
                    <i class="fa-solid fa-box-archive"></i>
                    <?= $accion_titulo ?> ticket
                </button>

            </form>

        </section>

    </main>

</div>

</body>
</html>

<?php
$conn->close();
?>


