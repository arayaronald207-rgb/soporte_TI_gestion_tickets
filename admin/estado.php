<?php
require_once __DIR__ . "/../config/sesion.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/autorizacion.php";
require_once __DIR__ . "/../config/historial.php";
$usuario_actual = usuarioAutenticado($conn, "../login.php");

// Crear token de seguridad para el formulario
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Validar el ID de la incidencia
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    header("Location: index.php");
    exit;
}

// No revelar tickets ajenos ni permitir acceso cambiando el ID.
$incidencia = incidenciaPermitida($conn, $id, $usuario_actual);

$error = "";

$estados_validos = [
    "Pendiente",
    "En proceso",
    "Resuelta"
];

// Procesar el formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $estado = $_POST["estado"] ?? "";
    $token = $_POST["csrf_token"] ?? "";

    // Validar token de seguridad
    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {

        $error = "Solicitud no válida. Actualiza la página.";

    } elseif (!in_array($estado, $estados_validos, true)) {

        $error = "Selecciona un estado válido.";

    } else {

        try {
            $anterior = bloquearIncidenciaParaCambio($conn, $id);
            // Actualizar estado
            $stmt = $conn->prepare(
                "UPDATE incidencias
                 SET estado = ?
                 WHERE id = ?"
            );

            $stmt->bind_param("si", $estado, $id);

            if ($stmt->execute()) {
                if ($anterior['estado'] !== $estado) {
                    // Solo una transicion real genera datos nuevos de resolucion.
                    if ($estado === 'Resuelta') {
                        $resolutor = $_SESSION['admin_rol'] === 'tecnico' ? (int) $_SESSION['admin_id'] : null;
                        $fechas = $conn->prepare('UPDATE incidencias SET fecha_resolucion = UTC_TIMESTAMP(), tecnico_resolucion_id = ? WHERE id = ?');
                        $fechas->bind_param('ii', $resolutor, $id);
                    } else {
                        $fechas = $conn->prepare('UPDATE incidencias SET fecha_resolucion = NULL, tecnico_resolucion_id = NULL WHERE id = ?');
                        $fechas->bind_param('i', $id);
                    }
                    $fechas->execute();
                    $fechas->close();
                    registrarEventoIncidencia($conn, $id, (int) $_SESSION['admin_id'], 'cambio_estado',
                        'Se cambió el estado del ticket.', $anterior['estado'], $estado);
                }
                $conn->commit();

                $stmt->close();

                header("Location: index.php?estado=actualizado");
                exit;
            }

            $error = "No se pudo actualizar el estado.";

            $stmt->close();
            $conn->rollback();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log("Error al gestionar incidencia: " . $e->getMessage());
            http_response_code(500);
            $error = "No se pudo guardar la operación. Inténtalo de nuevo.";
        }
    }
}

// Evitar que se interprete HTML en los datos
function escapar($valor) {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Cambiar estado | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css', '../') ?>">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/formularios.css', '../') ?>">
    <script src="<?= recursoVersionado('js/formularios.js', '../') ?>" defer></script>
</head>

<body class="pagina-dashboard s-app">

<div class="dashboard-layout">

    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = 'ticket';
    $nav_ticket = ['id' => (int) $id, 'seccion' => 'estado', 'archivado' => (int) $incidencia['archivado'] === 1];
    require __DIR__ . '/../includes/sidebar.php';
    ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="dashboard-main">

        <!-- ENCABEZADO -->
        <header class="dashboard-topbar">

            <div>
                <span class="topbar-etiqueta">Seguimiento de incidencias</span>
                <h1>Cambiar estado del ticket</h1>
                <p>Actualiza el progreso de la incidencia dentro de su ciclo de vida.</p>
            </div>

            <a href="index.php" class="btn-limpiar">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver al panel
            </a>

        </header>

        <?php
        $tono_prioridad_ficha = match ($incidencia["prioridad"]) {
            "Alta" => "alta",
            "Media" => "media",
            default => "baja"
        };
        $estados_presentacion = [
            "Pendiente" => ["pendiente", "fa-hourglass-half", "Registrado y a la espera de atención."],
            "En proceso" => ["proceso", "fa-screwdriver-wrench", "Un técnico está trabajando en la solución."],
            "Resuelta" => ["resuelta", "fa-circle-check", "El problema quedó solucionado."],
        ];
        ?>
        <div class="s-form-pagina">

            <!-- FORMULARIO (mismo campo POST «estado» y mismos valores) -->
            <section class="s-form-tarjeta" aria-labelledby="titulo-formulario">

                <div class="s-form-cabecera">
                    <span class="s-form-icono"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-formulario">Nuevo estado</h2>
                        <p>Elige la etapa en la que se encuentra ahora la incidencia.</p>
                    </div>
                </div>

                <!-- MOSTRAR ERRORES -->
                <?php if ($error !== ""): ?>
                    <div class="s-aviso s-aviso-error" role="alert">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong>No se actualizó el estado.</strong>
                            <?= escapar($error) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="s-formulario">

                    <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION["csrf_token"]) ?>">

                    <fieldset class="s-form-seccion">
                        <legend>Ciclo de vida <span class="s-obligatorio" aria-hidden="true">*</span></legend>

                        <div class="s-opciones s-opciones-ciclo">
                            <?php foreach ($estados_validos as $e): ?>
                                <?php [$tono_opcion, $icono_opcion, $texto_opcion] = $estados_presentacion[$e]; ?>
                                <label class="s-opcion s-opcion-<?= $tono_opcion ?>">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="<?= escapar($e) ?>"
                                        <?= $incidencia["estado"] === $e ? "checked" : "" ?>
                                        required
                                    >
                                    <span class="s-opcion-cuerpo">
                                        <span class="s-opcion-icono"><i class="fa-solid <?= $icono_opcion ?>" aria-hidden="true"></i></span>
                                        <span class="s-opcion-texto">
                                            <strong><?= escapar($e) ?></strong>
                                            <small><?= escapar($texto_opcion) ?></small>
                                        </span>
                                        <?php if ($incidencia["estado"] === $e): ?>
                                            <span class="s-opcion-actual">Actual</span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <p class="s-ayuda s-form-nota">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        Al marcar «Resuelta» se registra la fecha de resolución; si el ticket se reabre, esa resolución deja de contar en el promedio.
                    </p>

                    <!-- BOTONES -->
                    <div class="s-form-acciones">
                        <a href="index.php" class="s-btn s-btn-secundario">Cancelar</a>
                        <button type="submit" class="s-btn s-btn-primario">
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            Guardar estado
                        </button>
                    </div>

                </form>
            </section>

            <!-- FICHA DEL TICKET -->
            <aside class="s-ficha" aria-label="Ticket seleccionado">
                <span class="s-rotulo">Ticket seleccionado</span>
                <p class="s-ficha-numero s-mono">#<?= str_pad((string) (int) $incidencia["id"], 4, "0", STR_PAD_LEFT) ?></p>
                <p class="s-ficha-titulo"><?= escapar($incidencia["titulo"] ?? "Sin título") ?></p>

                <dl class="s-ficha-datos">
                    <div>
                        <dt>Prioridad</dt>
                        <dd><span class="s-insignia s-insignia-<?= $tono_prioridad_ficha ?>"><?= escapar($incidencia["prioridad"]) ?></span></dd>
                    </div>
                    <div>
                        <dt>Equipo</dt>
                        <dd><?= escapar($incidencia["equipo"]) ?></dd>
                    </div>
                    <div>
                        <dt>Laboratorio</dt>
                        <dd><?= escapar($incidencia["laboratorio"]) ?></dd>
                    </div>
                </dl>

                <div class="s-ficha-enlaces">
                    <a href="detalle.php?id=<?= (int) $incidencia["id"] ?>" class="s-btn s-btn-fantasma s-btn-sm">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Ver historial
                    </a>
                </div>
            </aside>

        </div>

    </main>

</div>

</body>
</html>

<?php
$conn->close();
?>
