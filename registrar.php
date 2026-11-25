<?php
require_once __DIR__ . "/config/sesion.php";
require_once __DIR__ . '/config/parametros.php';

require_once __DIR__ . "/config/conexion.php";

require_once __DIR__ . "/config/autorizacion.php";
require_once __DIR__ . "/config/historial.php";
require_once __DIR__ . "/config/encuestas.php";
$usuario_actual = usuarioAutenticado($conn, "login.php", false);
$admin_activo = $usuario_actual !== null;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$mensaje = "";
$ticket_creado = null;
$codigo_creado = null;

$nombre = "";
$laboratorio = "";
$equipo = "";
$descripcion = "";
$prioridad = "";
$titulo = "";
$categoria = "";
$categorias_validas = ["hardware" => "Hardware", "software" => "Software", "red" => "Red"];

if (isset($_SESSION["ticket_creado"])) {
    $ticket_creado = (int) $_SESSION["ticket_creado"];
    // El codigo solo se muestra una vez: en la base se guarda unicamente su hash.
    $codigo_creado = is_string($_SESSION["codigo_creado"] ?? null) ? $_SESSION["codigo_creado"] : null;
    unset($_SESSION["ticket_creado"], $_SESSION["codigo_creado"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    validarParametrosTexto($_POST, ['nombre', 'laboratorio', 'equipo', 'descripcion', 'prioridad', 'titulo', 'categoria']);
    // csrf_token conserva la comprobacion de tipo y hash_equals existentes.

    $nombre = trim($_POST["nombre"] ?? "");
    $laboratorio = trim($_POST["laboratorio"] ?? "");
    $equipo = trim($_POST["equipo"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $prioridad = $_POST["prioridad"] ?? "";
    $titulo = is_string($_POST["titulo"] ?? null) ? trim($_POST["titulo"]) : "";
    $categoria = is_string($_POST["categoria"] ?? null) ? $_POST["categoria"] : "";

    $prioridades = ["Baja", "Media", "Alta"];
    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $mensaje = "La sesión del formulario no es válida. Actualiza la página.";

    } elseif ($titulo === "" || !array_key_exists($categoria, $categorias_validas)) {
        $mensaje = "Completa el título y selecciona una categoría válida.";

    } elseif (
        $nombre === "" ||
        $laboratorio === "" ||
        $equipo === "" ||
        $descripcion === "" ||
        !in_array($prioridad, $prioridades, true)
    ) {
        $mensaje = "Completa todos los campos correctamente.";

    } elseif (
        mb_strlen($titulo, "UTF-8") > 150 ||
        mb_strlen($nombre) > 100 ||
        mb_strlen($laboratorio) > 100 ||
        mb_strlen($equipo) > 100 ||
        mb_strlen($descripcion) > 3000
    ) {
        $mensaje = "Uno de los campos supera la cantidad máxima de caracteres.";

    } else {

        try {
            $conn->begin_transaction();
            // El registro publico se conserva: no se inventa autor para visitantes.
            $autor = usuarioAutenticado($conn, 'login.php', false, true);
            // Codigo para que el solicitante consulte su ticket y responda la encuesta.
            $codigo_seguimiento = generarCodigoSeguimiento();
            $codigo_hash = hashCodigoSeguimiento($codigo_seguimiento);
            $sql = "INSERT INTO incidencias
                    (reportado_por, laboratorio, equipo, descripcion, prioridad, titulo, categoria,
                     codigo_seguimiento_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "ssssssss",
                $nombre,
                $laboratorio,
                $equipo,
                $descripcion,
                $prioridad,
                $titulo,
                $categoria,
                $codigo_hash
            );

            if ($stmt->execute()) {
                $nuevo_id = $conn->insert_id;
                if ($autor !== null) {
                    registrarEventoIncidencia($conn, $nuevo_id, (int) $autor['id'], 'creacion',
                        'Se creó la incidencia.');
                }
                $conn->commit();
                $_SESSION["ticket_creado"] = $nuevo_id;
                $_SESSION["codigo_creado"] = $codigo_seguimiento;
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                $stmt->close();

                header("Location: registrar.php");
                exit;

            } else {
                $conn->rollback();
                $mensaje = "No se pudo registrar el ticket.";
            }

            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Error al registrar incidencia: ' . $e->getMessage());
            http_response_code(500);
            $mensaje = 'No se pudo registrar el ticket. Inténtalo de nuevo.';
        }
    }
}

function escapar($valor) {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<?php require_once __DIR__ . "/includes/recursos.php"; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Crear Ticket | Soporte TI</title>
    <?= faviconSenal() ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/formularios.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/publico.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/calidad.css') ?>">
    <script src="<?= recursoVersionado('js/formularios.js') ?>" defer></script>
    <script src="<?= recursoVersionado('js/copiar.js') ?>" defer></script>
</head>
<?php if ($admin_activo): ?>
<!-- PERSONAL CON SESIÓN: navegación interna (menú lateral compartido) -->
<body class="pagina-dashboard s-app s-registro-interno">
<div class="dashboard-layout">
    <?php
    $nav_activa = 'registrar';
    $nav_ticket = null;
    $nav_raiz = '';
    $nav_admin = 'admin/';
    require __DIR__ . '/includes/sidebar.php';
    ?>
<main id="contenido" class="dashboard-main">
<?php else: ?>
<!-- VISITANTE: navegación pública -->
<body class="s-app s-publico">
<?php
$pub_activa = 'registrar';
require __DIR__ . '/includes/cabecera_publica.php';
?>
<main id="contenido" class="s-pub-contenedor s-pub-pagina">
<?php endif; ?>

    <header class="dashboard-topbar">
        <div>
            <span class="topbar-etiqueta">Centro de soporte</span>
            <h1>Registrar nueva incidencia</h1>
            <p>Describe el problema tecnológico y genera un ticket para darle seguimiento.</p>
        </div>
        <?php if ($admin_activo): ?>
            <div class="s-topbar-acciones">
                <a href="incidencias.php" class="btn-limpiar">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                    Ver incidencias
                </a>
                <a href="admin/index.php" class="btn-nuevo">
                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                    Volver al panel
                </a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($ticket_creado !== null): ?>

        <!-- CONFIRMACIÓN -->
        <section class="s-form-tarjeta s-registro-exito" aria-labelledby="titulo-exito" role="status">
            <span class="s-registro-exito-icono"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
            <h2 id="titulo-exito">¡Ticket registrado correctamente!</h2>
            <p>Tu incidencia fue guardada en el sistema. Conserva el número de ticket para identificarla.</p>

            <div class="s-registro-numero">
                <span>Número de ticket</span>
                <strong class="s-mono">#<?= str_pad($ticket_creado, 4, "0", STR_PAD_LEFT) ?></strong>
            </div>

            <?php if ($codigo_creado !== null): ?>
                <!-- CÓDIGO DE SEGUIMIENTO: se muestra una sola vez -->
                <div class="s-codigo-tarjeta">
                    <span class="s-codigo-rotulo">
                        <i class="fa-solid fa-key" aria-hidden="true"></i> Código de seguimiento
                    </span>
                    <div class="s-codigo-fila">
                        <strong class="s-codigo-valor" id="codigo-creado"><?= escapar($codigo_creado) ?></strong>
                        <button type="button" class="s-btn s-btn-sm s-codigo-copiar" data-copiar="codigo-creado" hidden>
                            <i class="fa-solid fa-copy" aria-hidden="true"></i>
                            <span class="s-copiar-texto">Copiar</span>
                        </button>
                    </div>
                    <p class="s-codigo-nota">
                        <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                        <span>
                            <strong>Este código solo se muestra esta vez.</strong>
                            Guárdalo: con el número de ticket y este código podrás consultar tu incidencia en
                            <a href="seguimiento.php">Consultar mi ticket</a> y, cuando esté resuelta,
                            calificar la atención recibida.
                        </span>
                    </p>
                </div>
            <?php endif; ?>

            <div class="s-registro-exito-acciones">
                <a href="registrar.php" class="s-btn s-btn-primario s-btn-grande">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Registrar otro ticket
                </a>
                <?php if ($admin_activo): ?>
                    <a href="incidencias.php" class="s-btn s-btn-secundario s-btn-grande">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                        Ver incidencias
                    </a>
                <?php else: ?>
                    <a href="index.php" class="s-btn s-btn-secundario s-btn-grande">
                        <i class="fa-solid fa-house" aria-hidden="true"></i>
                        Volver al inicio
                    </a>
                <?php endif; ?>
            </div>
        </section>

    <?php else: ?>

        <div class="s-registro-rejilla">

            <!-- FORMULARIO (mismos campos POST y validación del servidor) -->
            <section class="s-form-tarjeta" aria-labelledby="titulo-formulario">

                <div class="s-form-cabecera">
                    <span class="s-form-icono"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-formulario">Información de la incidencia</h2>
                        <p>Los campos marcados con <span class="s-obligatorio" aria-hidden="true">*</span> son obligatorios.</p>
                    </div>
                </div>

                <?php if ($mensaje !== ""): ?>
                    <div class="s-aviso s-aviso-error" role="alert">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong>No se registró el ticket.</strong>
                            <?= escapar($mensaje) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="s-formulario">

                    <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION["csrf_token"]) ?>">

                    <!-- SECCIÓN 1 -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">1</span> ¿Qué está pasando?</legend>

                        <div class="s-form-rejilla">
                            <div class="s-campo s-campo-ancho">
                                <label for="titulo" class="s-etiqueta">Título <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="titulo" name="titulo" maxlength="150" class="s-control" data-contador
                                       value="<?= escapar($titulo) ?>" placeholder="Resume el problema en una frase" required>
                                <span class="s-ayuda">Ejemplo: «La impresora del laboratorio no imprime».</span>
                            </div>

                            <div class="s-campo s-campo-ancho">
                                <label for="categoria" class="s-etiqueta">Categoría <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <select id="categoria" name="categoria" class="s-control" required>
                                    <option value="">Selecciona una categoría</option>
                                    <?php foreach ($categorias_validas as $valor => $etiqueta): ?>
                                        <option value="<?= escapar($valor) ?>" <?= $categoria === $valor ? "selected" : "" ?>><?= escapar($etiqueta) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="s-campo s-campo-ancho">
                                <label for="descripcion" class="s-etiqueta">Descripción del problema <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <textarea id="descripcion" name="descripcion" rows="6" maxlength="3000" class="s-control" data-contador
                                          placeholder="Describe qué está sucediendo, cuándo comenzó el problema y cualquier detalle que pueda ayudar…"
                                          required><?= escapar($descripcion) ?></textarea>
                                <span class="s-ayuda">Describe el problema con la mayor claridad posible.</span>
                            </div>
                        </div>
                    </fieldset>

                    <!-- SECCIÓN 2 -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">2</span> ¿Dónde y quién lo reporta?</legend>

                        <div class="s-form-rejilla">
                            <div class="s-campo s-campo-ancho">
                                <label for="nombre" class="s-etiqueta">Nombre de quien reporta <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="nombre" name="nombre" maxlength="100" class="s-control"
                                       value="<?= escapar($nombre) ?>" placeholder="Ejemplo: Ana Rojas" autocomplete="name" required>
                            </div>

                            <div class="s-campo">
                                <label for="laboratorio" class="s-etiqueta">Laboratorio o ubicación <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="laboratorio" name="laboratorio" maxlength="100" class="s-control"
                                       value="<?= escapar($laboratorio) ?>" placeholder="Ejemplo: Laboratorio 2" required>
                            </div>

                            <div class="s-campo">
                                <label for="equipo" class="s-etiqueta">Equipo afectado <span class="s-obligatorio" aria-hidden="true">*</span></label>
                                <input type="text" id="equipo" name="equipo" maxlength="100" class="s-control"
                                       value="<?= escapar($equipo) ?>" placeholder="Ejemplo: Computadora 05" required>
                            </div>
                        </div>
                    </fieldset>

                    <!-- SECCIÓN 3: PRIORIDAD CON GUÍA INTEGRADA -->
                    <fieldset class="s-form-seccion">
                        <legend><span class="s-form-paso">3</span> ¿Qué prioridad debo seleccionar? <span class="s-obligatorio" aria-hidden="true">*</span></legend>

                        <div class="s-opciones s-opciones-ciclo s-opciones-prioridad">
                            <?php foreach ([
                                "Baja" => ["baja", "fa-signal", "El equipo funciona, pero presenta una molestia menor."],
                                "Media" => ["media", "fa-triangle-exclamation", "El problema dificulta el trabajo habitual."],
                                "Alta" => ["alta", "fa-circle-exclamation", "El equipo o servicio no puede utilizarse."],
                            ] as $p => [$tono_prioridad, $icono_prioridad, $ayuda_prioridad]): ?>
                                <label class="s-opcion s-opcion-prioridad-<?= $tono_prioridad ?>">
                                    <input
                                        type="radio"
                                        name="prioridad"
                                        value="<?= escapar($p) ?>"
                                        <?= $prioridad === $p ? "checked" : "" ?>
                                        required
                                    >
                                    <span class="s-opcion-cuerpo">
                                        <span class="s-opcion-icono"><i class="fa-solid <?= $icono_prioridad ?>" aria-hidden="true"></i></span>
                                        <span class="s-opcion-texto">
                                            <strong><?= escapar($p) ?></strong>
                                            <small><?= escapar($ayuda_prioridad) ?></small>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="s-form-acciones">
                        <a href="index.php" class="s-btn s-btn-secundario">Cancelar</a>
                        <button type="submit" class="s-btn s-btn-primario">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            Registrar incidencia
                        </button>
                    </div>

                </form>
            </section>

            <!-- GUÍA SECUNDARIA (después del formulario en una sola columna) -->
            <aside class="s-registro-guia" aria-labelledby="titulo-guia">
                <h2 id="titulo-guia" class="s-registro-guia-titulo">
                    <i class="fa-solid fa-route" aria-hidden="true"></i>
                    Qué ocurre después
                </h2>
                <ol class="s-registro-pasos">
                    <li>
                        <span class="s-mono" aria-hidden="true">1</span>
                        <div><strong>Recibes un número de ticket</strong><small>Consérvalo para identificar tu incidencia.</small></div>
                    </li>
                    <li>
                        <span class="s-mono" aria-hidden="true">2</span>
                        <div><strong>Se asigna un técnico</strong><small>La administración revisa y asigna al responsable.</small></div>
                    </li>
                    <li>
                        <span class="s-mono" aria-hidden="true">3</span>
                        <div><strong>Seguimiento del caso</strong><small>El personal de soporte actualiza el estado hasta resolverlo.</small></div>
                    </li>
                </ol>
                <p class="s-registro-consejo">
                    <i class="fa-solid fa-lightbulb" aria-hidden="true"></i>
                    Incluye el nombre o número del equipo y desde cuándo ocurre el problema: ayuda a resolverlo más rápido.
                </p>
                <?php if (!$admin_activo): ?>
                    <p class="s-registro-nota">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        No necesitas una cuenta para registrar una incidencia. La consulta del listado está reservada al personal de soporte.
                    </p>
                <?php endif; ?>
            </aside>

        </div>

    <?php endif; ?>

</main>

<?php if ($admin_activo): ?>
</div>
<?php else: ?>
<?php require __DIR__ . '/includes/pie_publico.php'; ?>
<?php endif; ?>

</body>
</html>
