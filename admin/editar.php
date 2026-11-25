<?php
require_once __DIR__ . "/../config/sesion.php";
require_once __DIR__ . "/../config/parametros.php";
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/autorizacion.php";
require_once __DIR__ . "/../config/historial.php";
$usuario_actual = usuarioAutenticado($conn, "../login.php");

// Crear token CSRF
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Validar ID del ticket
validarParametrosTexto($_GET, ['id']);
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    if (isset($_GET['id'])) {
        rechazarParametrosMalformados();
    }
    header("Location: index.php");
    exit;
}

// No revelar tickets ajenos ni permitir acceso cambiando el ID.
$incidencia = incidenciaPermitida($conn, $id, $usuario_actual);

$error = "";
$categorias_validas = ["hardware" => "Hardware", "software" => "Software", "red" => "Red"];

// Procesar el formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validarParametrosTexto($_POST, ['nombre', 'laboratorio', 'equipo', 'descripcion', 'prioridad', 'titulo', 'categoria']);
    // csrf_token mantiene su validacion de tipo y hash_equals existentes.

    $nombre = trim($_POST["nombre"] ?? "");
    $laboratorio = trim($_POST["laboratorio"] ?? "");
    $equipo = trim($_POST["equipo"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $prioridad = $_POST["prioridad"] ?? "";
    $titulo = is_string($_POST["titulo"] ?? null) ? trim($_POST["titulo"]) : "";
    $categoria = is_string($_POST["categoria"] ?? null) ? $_POST["categoria"] : "";

    $prioridades_validas = ["Baja", "Media", "Alta"];

    $token = $_POST["csrf_token"] ?? "";

    $token_valido = is_string($token) &&
        hash_equals($_SESSION["csrf_token"], $token);

    // Mantener los valores escritos si ocurre un error
    $incidencia["reportado_por"] = $nombre;
    $incidencia["laboratorio"] = $laboratorio;
    $incidencia["equipo"] = $equipo;
    $incidencia["descripcion"] = $descripcion;
    $incidencia["prioridad"] = $prioridad;
    $incidencia["titulo"] = $titulo;
    $incidencia["categoria"] = $categoria;

    // Validaciones
    if (!$token_valido) {

        $error = "Solicitud no válida. Actualiza la página e inténtalo de nuevo.";

    } elseif ($titulo === "" || !array_key_exists($categoria, $categorias_validas)) {
        $error = "Completa el título y selecciona una categoría válida.";

    } elseif (
        $nombre === "" ||
        $laboratorio === "" ||
        $equipo === "" ||
        $descripcion === "" ||
        !in_array($prioridad, $prioridades_validas, true)
    ) {

        $error = "Completa correctamente todos los campos.";

    } elseif (
        mb_strlen($titulo, "UTF-8") > 150 ||
        mb_strlen($nombre) > 100 ||
        mb_strlen($laboratorio) > 100 ||
        mb_strlen($equipo) > 100 ||
        mb_strlen($descripcion) > 3000
    ) {

        $error = "Uno de los campos supera la cantidad máxima de caracteres.";

    } else {

        try {
            $anterior = bloquearIncidenciaParaCambio($conn, $id);
            // Actualizar la incidencia
            $sql = "UPDATE incidencias
                    SET reportado_por = ?,
                        laboratorio = ?,
                        equipo = ?,
                        descripcion = ?,
                        prioridad = ?,
                        titulo = ?,
                        categoria = ?
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "sssssssi",
                $nombre,
                $laboratorio,
                $equipo,
                $descripcion,
                $prioridad,
                $titulo,
                $categoria,
                $id
            );

            if ($stmt->execute()) {
                $cambios = [];
                foreach (['reportado_por' => 'Reportado por', 'laboratorio' => 'Laboratorio',
                    'equipo' => 'Equipo', 'descripcion' => 'Descripción', 'prioridad' => 'Prioridad',
                    'titulo' => 'Título', 'categoria' => 'Categoría'] as $campo => $etiqueta) {
                    if ($anterior[$campo] !== $incidencia[$campo]) {
                        $cambios[] = $etiqueta . ': ' . ($anterior[$campo] ?? '(sin completar)') .
                            ' → ' . $incidencia[$campo];
                    }
                }
                if ($cambios) {
                    registrarEventoIncidencia($conn, $id, (int) $_SESSION['admin_id'], 'edicion',
                        "Se editó la información del ticket:\n" . implode("\n", $cambios));
                }
                $conn->commit();

                $stmt->close();

                header("Location: index.php?actualizado=1");
                exit;

            } else {

                $error = "No se pudo actualizar la incidencia.";
            }

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

// Escapar contenido HTML
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

    <title>Editar ticket | Soporte TI</title>
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
    $nav_ticket = ['id' => (int) $id, 'seccion' => 'editar', 'archivado' => (int) $incidencia['archivado'] === 1];
    require __DIR__ . '/../includes/sidebar.php';
    ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="dashboard-main">

        <header class="dashboard-topbar">

            <div>
                <span class="topbar-etiqueta">Gestión de tickets</span>
                <h1>Editar ticket <span class="s-mono">#<?= str_pad((string) (int) $id, 4, "0", STR_PAD_LEFT) ?></span></h1>
                <p>Actualiza la información de la incidencia y guarda los cambios.</p>
            </div>

            <a href="index.php" class="btn-limpiar">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver al panel
            </a>

        </header>

        <?php
        $tono_estado_ficha = match ($incidencia["estado"]) {
            "Resuelta" => "resuelta",
            "En proceso" => "proceso",
            "Cerrado" => "cerrado",
            default => "pendiente"
        };
        ?>
        <div class="s-form-pagina">

            <!-- FORMULARIO (mismos campos POST y validación del servidor) -->
            <section class="s-form-tarjeta" aria-labelledby="titulo-formulario">

                <div class="s-form-cabecera">
                    <span class="s-form-icono"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-formulario">Información del ticket</h2>
                        <p>Los campos marcados con <span class="s-obligatorio" aria-hidden="true">*</span> son obligatorios.</p>
                    </div>
                </div>

                <!-- MOSTRAR ERRORES -->
                <?php if ($error !== ""): ?>
                    <div class="s-aviso s-aviso-error" role="alert">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong>No se guardaron los cambios.</strong>
                            <?= escapar($error) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="s-formulario">

                    <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION["csrf_token"]) ?>">

                    <!-- SECCIÓN 1 -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">1</span> Clasificación</legend>

                        <div class="s-form-rejilla">
                            <div class="s-campo s-campo-ancho">
                                <label for="titulo" class="s-etiqueta">Título <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="titulo" name="titulo" maxlength="150" class="s-control" data-contador
                                       value="<?= escapar($incidencia["titulo"] ?? "") ?>"
                                       placeholder="Resume el problema en una frase" required>
                                <span class="s-ayuda">Máximo 150 caracteres.</span>
                            </div>

                            <div class="s-campo">
                                <label for="categoria" class="s-etiqueta">Categoría <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <select id="categoria" name="categoria" class="s-control" required>
                                    <option value="">Selecciona una categoría</option>
                                    <?php foreach ($categorias_validas as $valor => $etiqueta): ?>
                                        <option value="<?= escapar($valor) ?>" <?= ($incidencia["categoria"] ?? "") === $valor ? "selected" : "" ?>><?= escapar($etiqueta) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="s-campo">
                                <label for="prioridad" class="s-etiqueta">Prioridad <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <select id="prioridad" name="prioridad" class="s-control" required>
                                    <?php foreach (["Baja", "Media", "Alta"] as $p): ?>
                                        <option value="<?= escapar($p) ?>" <?= $incidencia["prioridad"] === $p ? "selected" : "" ?>><?= escapar($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- SECCIÓN 2 -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">2</span> Solicitante y ubicación</legend>

                        <div class="s-form-rejilla s-form-rejilla-3">
                            <div class="s-campo">
                                <label for="nombre" class="s-etiqueta">Nombre de quien reporta <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="nombre" name="nombre" maxlength="100" class="s-control"
                                       value="<?= escapar($incidencia["reportado_por"]) ?>" required>
                            </div>

                            <div class="s-campo">
                                <label for="laboratorio" class="s-etiqueta">Laboratorio <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="laboratorio" name="laboratorio" maxlength="100" class="s-control"
                                       value="<?= escapar($incidencia["laboratorio"]) ?>" required>
                            </div>

                            <div class="s-campo">
                                <label for="equipo" class="s-etiqueta">Equipo afectado <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="equipo" name="equipo" maxlength="100" class="s-control"
                                       value="<?= escapar($incidencia["equipo"]) ?>" required>
                            </div>
                        </div>
                    </fieldset>

                    <!-- SECCIÓN 3 -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">3</span> Detalle del problema</legend>

                        <div class="s-campo">
                            <label for="descripcion" class="s-etiqueta">Descripción del problema <span class="s-obligatorio" aria-hidden="true">*</span></label>
                            <textarea id="descripcion" name="descripcion" rows="6" maxlength="3000" class="s-control" data-contador
                                      required><?= escapar($incidencia["descripcion"]) ?></textarea>
                            <span class="s-ayuda">Máximo 3000 caracteres.</span>
                        </div>
                    </fieldset>

                    <!-- BOTONES -->
                    <div class="s-form-acciones">
                        <a href="index.php" class="s-btn s-btn-secundario">Cancelar</a>
                        <button type="submit" class="s-btn s-btn-primario">
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            Guardar cambios
                        </button>
                    </div>

                </form>
            </section>

            <!-- FICHA DEL TICKET -->
            <aside class="s-ficha" aria-label="Ticket en edición">
                <span class="s-rotulo">Ticket en edición</span>
                <p class="s-ficha-numero s-mono">#<?= str_pad((string) (int) $id, 4, "0", STR_PAD_LEFT) ?></p>

                <dl class="s-ficha-datos">
                    <div>
                        <dt>Estado actual</dt>
                        <dd><span class="s-insignia s-insignia-<?= $tono_estado_ficha ?>"><?= escapar($incidencia["estado"]) ?></span></dd>
                    </div>
                    <?php if ((int) ($incidencia["archivado"] ?? 0) === 1): ?>
                        <div>
                            <dt>Archivo</dt>
                            <dd><span class="s-insignia s-insignia-archivado">Archivado</span></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <p class="s-ficha-nota">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    El estado se modifica desde su propia pantalla. Cada cambio guardado queda registrado en el historial.
                </p>

                <div class="s-ficha-enlaces">
                    <a href="detalle.php?id=<?= (int) $id ?>" class="s-btn s-btn-fantasma s-btn-sm">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Ver historial
                    </a>
                    <a href="estado.php?id=<?= (int) $id ?>" class="s-btn s-btn-fantasma s-btn-sm">
                        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Cambiar estado
                    </a>
                </div>
            </aside>

        </div>

    </main>

</div>

</body>
</html>
