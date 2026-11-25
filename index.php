<?php
require_once __DIR__ . "/config/sesion.php";

require_once __DIR__ . "/config/conexion.php";

require_once __DIR__ . "/config/autorizacion.php";
$usuario_actual = usuarioAutenticado($conn, "login.php", false);
$admin_activo = $usuario_actual !== null;
$incidencias_recientes = [];
if ($usuario_actual) {
    $es_admin = (int) ($usuario_actual["rol"] === "admin");
    $usuario_id = (int) $usuario_actual["id"];
    $stmt = $conn->prepare(
        "SELECT id, titulo, equipo, laboratorio, descripcion, estado FROM incidencias
         WHERE archivado = 0 AND (? = 1 OR tecnico_id = ?)
         ORDER BY fecha_registro DESC, id DESC LIMIT 3"
    );
    $stmt->bind_param("ii", $es_admin, $usuario_id);
    $stmt->execute();
    $incidencias_recientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Evitar que el contenido de la base de datos
// se interprete como código HTML
function escapar($texto) {
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Elegir la apariencia de cada estado
function claseEstado($estado) {
    return match ($estado) {
        "Pendiente" => "pendiente",
        "En proceso" => "proceso",
        "Resuelta" => "resuelta",
        "Cerrado" => "cerrado",
        default => "pendiente"
    };
}

// Elegir el icono según el equipo registrado
function obtenerIconoEquipo($equipo) {
    $equipo = mb_strtolower((string) $equipo, "UTF-8");

    if (
        str_contains($equipo, "impresora") ||
        str_contains($equipo, "printer")
    ) {
        return [
            "icono" => "fa-print",
            "color" => "morado"
        ];
    }

    if (
        str_contains($equipo, "router") ||
        str_contains($equipo, "red") ||
        str_contains($equipo, "wifi") ||
        str_contains($equipo, "internet")
    ) {
        return [
            "icono" => "fa-wifi",
            "color" => "verde"
        ];
    }

    return [
        "icono" => "fa-desktop",
        "color" => "azul"
    ];
}
?>

<?php require_once __DIR__ . "/includes/recursos.php"; ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Soporte TI | Gestión de Incidencias</title>
    <?= faviconSenal() ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/publico.css') ?>">
</head>

<body class="s-app s-publico">

<?php
$pub_activa = 'inicio';
require __DIR__ . '/includes/cabecera_publica.php';
?>

<main id="contenido">

    <!-- SECCIÓN PRINCIPAL -->
    <section class="s-pub-hero">
        <div class="s-pub-contenedor s-pub-hero-rejilla">

            <div class="s-pub-hero-texto">
                <span class="s-pub-pildora">
                    <span class="s-pub-pildora-punto" aria-hidden="true"></span>
                    Plataforma de soporte tecnológico
                </span>

                <h1>
                    Gestiona tus incidencias
                    <span>desde un solo lugar.</span>
                </h1>

                <p>
                    Una plataforma diseñada para registrar, organizar y dar seguimiento
                    a los problemas tecnológicos de manera rápida y sencilla.
                </p>

                <div class="s-pub-hero-acciones">
                    <a href="registrar.php" class="s-btn s-btn-primario s-btn-grande">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Registrar incidencia
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                    <?php if ($admin_activo): ?>
                        <a href="admin/index.php" class="s-btn s-btn-grande s-btn-sobre-oscuro">
                            <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                            Ir al panel
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="s-btn s-btn-grande s-btn-sobre-oscuro">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            Acceder al sistema
                        </a>
                    <?php endif; ?>
                </div>

                <ul class="s-pub-beneficios">
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i> Registro sencillo</li>
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i> Seguimiento de tickets</li>
                    <li><i class="fa-solid fa-check" aria-hidden="true"></i> Gestión centralizada</li>
                </ul>
            </div>

            <!-- FLUJO DE INCIDENCIAS: tickets reales con sesión; proceso ilustrativo para visitantes -->
            <section class="s-flujo" aria-labelledby="titulo-flujo">

                <header class="s-flujo-cabecera">
                    <span class="s-flujo-rotulo">
                        <span class="s-flujo-rotulo-punto" aria-hidden="true"></span>
                        <?= $admin_activo ? "Seguimiento" : "Proceso de atención" ?>
                    </span>
                    <h2 id="titulo-flujo" class="s-flujo-titulo">Flujo de incidencias</h2>
                    <p class="s-flujo-subtitulo">
                        <?php if (!$admin_activo): ?>
                            Así avanza una incidencia desde que la reportas hasta que se resuelve.
                        <?php elseif ($usuario_actual["rol"] === "admin"): ?>
                            Actividad reciente: últimos 3 · todo el sistema.
                        <?php else: ?>
                            Actividad reciente: últimos 3 · tus tickets asignados.
                        <?php endif; ?>
                    </p>
                </header>

                <?php if ($admin_activo && count($incidencias_recientes) > 0): ?>

                    <!-- TICKETS REALES (misma consulta y mismo alcance por rol) -->
                    <ol class="s-flujo-lista">
                        <?php foreach ($incidencias_recientes as $incidencia): ?>
                            <?php
                            $clase_estado = claseEstado($incidencia["estado"]);
                            $numero_reciente = "#" . str_pad((string) (int) $incidencia["id"], 4, "0", STR_PAD_LEFT);
                            // Con título: título como texto principal y equipo junto al laboratorio.
                            // Registros antiguos sin título (NULL o vacío): el equipo pasa a ser el texto principal.
                            $tiene_titulo = trim((string) ($incidencia["titulo"] ?? "")) !== "";
                            $nombre_reciente = $tiene_titulo ? $incidencia["titulo"] : $incidencia["equipo"];
                            $ubicacion_reciente = $tiene_titulo
                                ? $incidencia["equipo"] . " · " . $incidencia["laboratorio"]
                                : $incidencia["laboratorio"];
                            ?>
                            <li class="s-flujo-paso" data-estado="<?= escapar($clase_estado) ?>">
                                <span class="s-flujo-nodo" aria-hidden="true"></span>
                                <a href="admin/detalle.php?id=<?= (int) $incidencia["id"] ?>" class="s-flujo-tarjeta"
                                   aria-label="Ticket <?= $numero_reciente ?>: <?= escapar($nombre_reciente) ?>, <?= escapar($ubicacion_reciente) ?>. Estado: <?= escapar($incidencia["estado"]) ?>. Ver detalle">
                                    <span class="s-flujo-tarjeta-sup">
                                        <span class="s-flujo-numero"><?= $numero_reciente ?></span>
                                        <span class="s-flujo-estado"><?= escapar($incidencia["estado"]) ?></span>
                                    </span>
                                    <strong class="s-flujo-nombre" title="<?= escapar($nombre_reciente) ?>"><?= escapar($nombre_reciente) ?></strong>
                                    <span class="s-flujo-pie">
                                        <span class="s-flujo-ubicacion" title="<?= escapar($ubicacion_reciente) ?>">
                                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                            <?= escapar($ubicacion_reciente) ?>
                                        </span>
                                        <span class="s-flujo-abrir" aria-hidden="true">
                                            Ver detalle <i class="fa-solid fa-arrow-right"></i>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <a href="incidencias.php" class="s-flujo-accion">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                        Ver todas las incidencias
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>

                <?php elseif ($admin_activo): ?>

                    <!-- PERSONAL SIN TICKETS EN SU ALCANCE -->
                    <div class="s-flujo-vacio">
                        <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                        <p>No hay incidencias disponibles para tu cuenta.</p>
                    </div>

                <?php else: ?>

                    <!-- VISITANTE: el proceso de atención, sin datos de tickets reales -->
                    <ol class="s-flujo-lista s-flujo-ilustrativo">
                        <li class="s-flujo-paso" data-estado="pendiente">
                            <span class="s-flujo-nodo" aria-hidden="true"></span>
                            <div class="s-flujo-tarjeta">
                                <span class="s-flujo-tarjeta-sup">
                                    <span class="s-flujo-numero">Paso 1</span>
                                    <span class="s-flujo-estado">Pendiente</span>
                                </span>
                                <strong class="s-flujo-nombre">Reportas la incidencia</strong>
                                <span class="s-flujo-detalle">Completas el formulario y recibes un número de ticket.</span>
                            </div>
                        </li>
                        <li class="s-flujo-paso" data-estado="proceso">
                            <span class="s-flujo-nodo" aria-hidden="true"></span>
                            <div class="s-flujo-tarjeta">
                                <span class="s-flujo-tarjeta-sup">
                                    <span class="s-flujo-numero">Paso 2</span>
                                    <span class="s-flujo-estado">En proceso</span>
                                </span>
                                <strong class="s-flujo-nombre">Un técnico la atiende</strong>
                                <span class="s-flujo-detalle">La administración asigna al responsable y registra el seguimiento.</span>
                            </div>
                        </li>
                        <li class="s-flujo-paso" data-estado="resuelta">
                            <span class="s-flujo-nodo" aria-hidden="true"></span>
                            <div class="s-flujo-tarjeta">
                                <span class="s-flujo-tarjeta-sup">
                                    <span class="s-flujo-numero">Paso 3</span>
                                    <span class="s-flujo-estado">Resuelta</span>
                                </span>
                                <strong class="s-flujo-nombre">Se resuelve el problema</strong>
                                <span class="s-flujo-detalle">El técnico registra la solución y puedes calificar la atención.</span>
                            </div>
                        </li>
                        <li class="s-flujo-paso" data-estado="cerrado">
                            <span class="s-flujo-nodo" aria-hidden="true"></span>
                            <div class="s-flujo-tarjeta">
                                <span class="s-flujo-tarjeta-sup">
                                    <span class="s-flujo-numero">Paso 4</span>
                                    <span class="s-flujo-estado">Cerrado</span>
                                </span>
                                <strong class="s-flujo-nombre">El caso se cierra</strong>
                                <span class="s-flujo-detalle">Queda finalizado y su historial se conserva.</span>
                            </div>
                        </li>
                    </ol>

                    <p class="s-flujo-nota">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        La consulta de incidencias está reservada al personal de soporte.
                    </p>
                    <a href="registrar.php" class="s-flujo-accion">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Registrar una incidencia
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>

                <?php endif; ?>
            </section>

        </div>
    </section>

    <!-- CÓMO FUNCIONA -->
    <section class="s-pub-seccion" aria-labelledby="titulo-proceso">
        <div class="s-pub-contenedor">
            <div class="s-pub-seccion-titulo">
                <span class="s-rotulo">Cómo funciona</span>
                <h2 id="titulo-proceso">Del reporte a la solución en tres pasos</h2>
            </div>

            <ol class="s-pub-pasos">
                <li class="s-pub-paso">
                    <span class="s-pub-paso-numero" aria-hidden="true">01</span>
                    <span class="s-pub-paso-icono"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
                    <h3>Registra la incidencia</h3>
                    <p>Completa el formulario con el problema, la ubicación y la prioridad. Recibirás un número de ticket.</p>
                </li>
                <li class="s-pub-paso">
                    <span class="s-pub-paso-numero" aria-hidden="true">02</span>
                    <span class="s-pub-paso-icono"><i class="fa-solid fa-user-gear" aria-hidden="true"></i></span>
                    <h3>Se asigna un técnico</h3>
                    <p>La administración revisa el ticket y lo asigna al técnico responsable de atenderlo.</p>
                </li>
                <li class="s-pub-paso">
                    <span class="s-pub-paso-numero" aria-hidden="true">03</span>
                    <span class="s-pub-paso-icono"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <h3>Seguimiento hasta resolverla</h3>
                    <p>El personal actualiza el estado y registra comentarios en el historial del ticket.</p>
                </li>
            </ol>
        </div>
    </section>

    <!-- FUNCIONALIDADES -->
    <section class="s-pub-seccion s-pub-seccion-alterna" aria-labelledby="titulo-funciones">
        <div class="s-pub-contenedor">
            <div class="s-pub-seccion-titulo">
                <span class="s-rotulo">Funcionalidades</span>
                <h2 id="titulo-funciones">Todo lo que necesitas en un solo lugar</h2>
                <p>Simplifica el proceso de atención y seguimiento de incidencias tecnológicas.</p>
            </div>

            <div class="s-pub-funciones">
                <article class="s-pub-funcion">
                    <span class="s-pub-funcion-icono"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                    <h3>Registro de incidencias</h3>
                    <p>Reporta problemas tecnológicos mediante un formulario sencillo y organizado.</p>
                    <a href="registrar.php">Crear ticket <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </article>

                <article class="s-pub-funcion">
                    <span class="s-pub-funcion-icono"><i class="fa-solid fa-magnifying-glass-chart" aria-hidden="true"></i></span>
                    <h3>Seguimiento de tickets</h3>
                    <?php if ($admin_activo): ?>
                        <p>Consulta las incidencias registradas, su prioridad y el estado de atención.</p>
                        <a href="incidencias.php">Ver incidencias <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <!-- Sin consulta pública: incidencias.php exige iniciar sesión -->
                        <p>El personal de soporte da seguimiento a cada incidencia, su prioridad y su estado de atención.</p>
                        <a href="login.php">Acceso de personal <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </article>

                <article class="s-pub-funcion">
                    <span class="s-pub-funcion-icono"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                    <h3>Administración segura</h3>
                    <p>Gestiona y actualiza las incidencias mediante un panel de acceso protegido.</p>
                    <a href="<?= $admin_activo ? 'admin/index.php' : 'login.php' ?>">Ir al panel <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                </article>
            </div>
        </div>
    </section>

    <!-- LLAMADO A LA ACCIÓN -->
    <section class="s-pub-contenedor">
        <div class="s-pub-cta">
            <div>
                <span class="s-rotulo">¿Necesitas soporte?</span>
                <h2>Estamos listos para recibir tu incidencia.</h2>
                <p>Registra tu solicitud y facilita el seguimiento de los problemas tecnológicos.</p>
            </div>
            <a href="registrar.php" class="s-btn s-btn-primario s-btn-grande">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Registrar incidencia
            </a>
        </div>
    </section>

</main>

<?php require __DIR__ . '/includes/pie_publico.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
