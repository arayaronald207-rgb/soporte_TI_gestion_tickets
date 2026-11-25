<?php
require_once __DIR__ . "/../config/sesion.php";

require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/autorizacion.php";
$usuario_actual = usuarioAutenticado($conn, "../login.php");
$usuario_id = (int) $usuario_actual["id"];
$es_admin = $usuario_actual["rol"] === "admin";
$puede_ver_todos = (int) $es_admin;
$ver_archivados = (int) ($es_admin && ($_GET['archivados'] ?? '') === '1');

// Escapar contenido HTML
function escapar($valor) {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        "UTF-8"
    );
}

// =====================================
// ESTADÍSTICAS GENERALES
// =====================================

require_once __DIR__ . '/../config/estados.php';
require_once __DIR__ . '/../config/metricas.php';
$metricas = metricasIncidencias($conn, $usuario_actual);
// Funciones adicionales: acuerdos de nivel de servicio y satisfaccion del solicitante.
require_once __DIR__ . '/../config/sla.php';
require_once __DIR__ . '/../config/encuestas.php';
$metricas_sla = metricasSla($conn, $usuario_actual);
$metricas_encuestas = metricasEncuestas($conn, $usuario_actual);
$total = $metricas['activos'];
$pendientes = $metricas['estados']['Pendiente'];
$en_proceso = $metricas['estados']['En proceso'];
$resueltas = $metricas['estados']['Resuelta'];
$cerrados = $metricas['estados']['Cerrado'];
$baja = $metricas['prioridades']['Baja'];
$media = $metricas['prioridades']['Media'];
$alta = $metricas['prioridades']['Alta'];

// =====================================
// BUSCADOR Y FILTROS
// =====================================

$buscar = is_string($_GET["buscar"] ?? null) ? trim($_GET["buscar"]) : "";
$estado = $_GET["estado"] ?? "";

if ($estado === "actualizado") {
    $estado = "";
}

$prioridad = $_GET["prioridad"] ?? "";

$estados_validos = ESTADOS_TICKET;
$prioridades_validas = ["Baja", "Media", "Alta"];
$categorias_validas = ["hardware" => "Hardware", "software" => "Software", "red" => "Red"];
$categoria_filtro = $_GET["categoria"] ?? "";
if (!is_string($categoria_filtro) ||
    (!array_key_exists($categoria_filtro, $categorias_validas) && $categoria_filtro !== "sin_categoria")) {
    $categoria_filtro = "";
}

if (!in_array($estado, $estados_validos, true)) {
    $estado = "";
}

if (!in_array($prioridad, $prioridades_validas, true)) {
    $prioridad = "";
}

// Filtro de SLA (solo tickets abiertos): vencidos, por vencer o a tiempo.
$opciones_sla = ["vencido" => "Vencidos", "por_vencer" => "Por vencer", "a_tiempo" => "A tiempo"];
$sla_filtro = $_GET["sla"] ?? "";
if (!is_string($sla_filtro) || !array_key_exists($sla_filtro, $opciones_sla)) {
    $sla_filtro = "";
}

$tecnico_filtro = "";
$tecnicos_filtro = [];
if ($es_admin) {
    $tecnico_filtro = $_GET["tecnico_id"] ?? "";
    if (!is_string($tecnico_filtro) || ($tecnico_filtro !== "" && $tecnico_filtro !== "sin_asignar" &&
        (!ctype_digit($tecnico_filtro) || (int) $tecnico_filtro <= 0))) {
        $tecnico_filtro = "";
    }
    // Incluir antiguos responsables para localizar tambien sus asignaciones.
    $tecnicos_filtro = $conn->query(
        "SELECT id, usuario FROM usuarios WHERE rol = 'tecnico'
         OR id IN (SELECT tecnico_id FROM incidencias WHERE tecnico_id IS NOT NULL)
         ORDER BY usuario"
    )->fetch_all(MYSQLI_ASSOC);
}

$sql = "SELECT i.*, UNIX_TIMESTAMP(i.fecha_registro) AS registro_epoch, t.usuario AS tecnico_usuario
        FROM incidencias i
        LEFT JOIN usuarios t ON t.id = i.tecnico_id
        WHERE (? = 1 OR i.tecnico_id = ?) AND i.archivado = ?";

$parametros = [$puede_ver_todos, $usuario_id, $ver_archivados];
$tipos = "iii";
if ($categoria_filtro === "sin_categoria") {
    $sql .= " AND i.categoria IS NULL";
} elseif ($categoria_filtro !== "") {
    $sql .= " AND i.categoria = ?";
    $parametros[] = $categoria_filtro;
    $tipos .= "s";
}
if ($es_admin && $tecnico_filtro === "sin_asignar") {
    $sql .= " AND i.tecnico_id IS NULL";
} elseif ($es_admin && $tecnico_filtro !== "") {
    $sql .= " AND i.tecnico_id = ?";
    $parametros[] = (int) $tecnico_filtro;
    $tipos .= "i";
}

if ($buscar !== "") {

    $sql .= " AND (
        CAST(i.id AS CHAR) LIKE ?
        OR reportado_por LIKE ?
        OR laboratorio LIKE ?
        OR equipo LIKE ?
    )";

    $termino = "%" . $buscar . "%";

    for ($i = 0; $i < 4; $i++) {
        $parametros[] = $termino;
        $tipos .= "s";
    }
}

if ($estado !== "") {
    $sql .= " AND estado = ?";
    $parametros[] = $estado;
    $tipos .= "s";
}

if ($prioridad !== "") {
    $sql .= " AND prioridad = ?";
    $parametros[] = $prioridad;
    $tipos .= "s";
}

if ($sla_filtro !== "") {
    // Fragmento construido solo con constantes del codigo (config/sla.php).
    $sql .= " AND " . slaCondicionSql($sla_filtro);
}

$sql .= " ORDER BY i.fecha_registro DESC, i.id DESC";

$stmt = $conn->prepare($sql);

if (!empty($parametros)) {
    $stmt->bind_param($tipos, ...$parametros);
}

$stmt->execute();

$resultado = $stmt->get_result();
$cantidad_resultados = $resultado->num_rows;

// =====================================
// MENSAJES DE ACCIONES
// =====================================

$mensaje_accion = "";

if (isset($_GET["asignado"])) {
    $mensaje_accion = "La asignación de la incidencia fue actualizada.";
} elseif (isset($_GET["actualizado"])) {
    $mensaje_accion = "La incidencia fue actualizada correctamente.";
} elseif (($_GET["estado"] ?? "") === "actualizado") {
    $mensaje_accion = "El estado de la incidencia fue actualizado.";
} elseif (isset($_GET["archivado"])) {
    $mensaje_accion = "La incidencia fue archivada. Su información e historial se conservan.";
} elseif (isset($_GET["restaurado"])) {
    $mensaje_accion = "La incidencia fue restaurada a las listas de trabajo.";
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Panel administrativo | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/dashboard.css', '../') ?>">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"
            defer></script>
    <!-- Ajustes visuales globales de Chart.js (tipografía y colores neutros); no toca datos ni configuración de los gráficos -->
    <script src="<?= recursoVersionado('js/graficos-tema.js', '../') ?>" defer></script>
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/metricas.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/tickets.css', '../') ?>">
    <link rel="stylesheet" href="../css/calidad.css?v=<?= (int) @filemtime(__DIR__ . '/../css/calidad.css') ?>">
</head>

<body class="pagina-dashboard s-app">

<div class="dashboard-layout">

    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = $ver_archivados ? 'archivados' : 'panel';
    $nav_ticket = null;
    require __DIR__ . '/../includes/sidebar.php';
    ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="dashboard-main">

        <header class="dashboard-topbar">

            <div>
                <span class="topbar-etiqueta">
                    <?= $es_admin ? 'Centro de operaciones' : 'Área técnica' ?>
                </span>

                <h1><?= $es_admin ? 'Panel de control' : 'Mi panel' ?></h1>

                <p>
                    Bienvenido,
                    <?= escapar($_SESSION["admin_usuario"]) ?>
                </p>
            </div>

            <a href="../registrar.php" class="btn-nuevo">
                <i class="fa-solid fa-plus"></i>
                Nuevo ticket
            </a>

        </header>

        <?php if ($mensaje_accion !== ""): ?>

            <div class="admin-mensaje-exito">
                <i class="fa-solid fa-circle-check"></i>
                <?= escapar($mensaje_accion) ?>
            </div>

        <?php endif; ?>

        <!-- ================================================
             CENTRO DE OPERACIONES · INDICADORES (Etapa 2)
             Solo presentación: los valores provienen de
             metricasIncidencias() sin cambios.
        ================================================= -->
        <?php
        // Proporción visual de cada estado respecto a los tickets activos (solo para la barra).
        $proporcion_activos = static fn(int $cantidad): float =>
            $total > 0 ? round($cantidad * 100 / $total, 1) : 0.0;
        ?>
        <section class="s-metricas" aria-labelledby="titulo-indicadores">

            <div class="s-seccion-cabecera">
                <div>
                    <span class="s-rotulo">Centro de operaciones</span>
                    <h2 id="titulo-indicadores" class="s-seccion-titulo">Indicadores de soporte</h2>
                </div>
                <span class="s-alcance">
                    <i class="fa-solid <?= $es_admin ? 'fa-globe' : 'fa-user-check' ?>" aria-hidden="true"></i>
                    <?= $es_admin ? 'Alcance: todo el sistema' : 'Alcance: tus tickets asignados' ?>
                </span>
            </div>

            <p class="s-seccion-descripcion">
                «Activos» significa sin archivar, incluidos los resueltos y los cerrados.
                <?= $es_admin ? 'Estadísticas generales del sistema.' : 'Estadísticas exclusivamente de los tickets actualmente asignados a tu cuenta.' ?>
            </p>

            <div class="s-kpis">

                <!-- Indicadores principales -->
                <article class="s-kpi s-kpi-principal s-kpi-destacado">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Tickets activos</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $total ?></strong>
                    <span class="s-kpi-nota">Todos los estados, sin archivados</span>
                </article>

                <article class="s-kpi s-kpi-principal s-kpi-sin-asignar<?= $metricas['sin_asignar'] > 0 ? ' s-kpi-atencion' : '' ?>">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Tickets sin asignar</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $metricas['sin_asignar'] ?></strong>
                    <span class="s-kpi-nota">
                        <?= $metricas['sin_asignar'] > 0 ? 'Activos sin técnico responsable' : 'Solo activos · ninguno pendiente de asignar' ?>
                    </span>
                    <?php if ($es_admin && $metricas['sin_asignar'] > 0): ?>
                        <a href="index.php?tecnico_id=sin_asignar" class="s-kpi-accion">
                            Ver tickets sin asignar
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </article>

                <article class="s-kpi s-kpi-principal s-kpi-tiempo">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Tiempo promedio de resolución</span>
                    </div>
                    <?php if ($metricas['promedio_segundos'] === null): ?>
                        <strong class="s-kpi-valor s-kpi-valor-texto">Sin datos suficientes</strong>
                    <?php elseif ($metricas['promedio_segundos'] > 48 * 3600): ?>
                        <?php // Más de 48 horas: se expresa en días para que sea legible (p. ej. «19,7 días»). ?>
                        <strong class="s-kpi-valor" title="<?= escapar(number_format($metricas['promedio_segundos'] / 3600, 2, ',', '.')) ?> horas"><?= escapar(number_format($metricas['promedio_segundos'] / 86400, 1, ',', '.')) ?><small class="s-kpi-unidad">días</small></strong>
                    <?php else: ?>
                        <strong class="s-kpi-valor"><?= escapar(number_format($metricas['promedio_segundos'] / 3600, 2, ',', '.')) ?><small class="s-kpi-unidad">h</small></strong>
                    <?php endif; ?>
                    <span class="s-kpi-nota"><?= $metricas['muestras_resolucion'] ?> ticket(s) resueltos o cerrados activos con fechas válidas</span>
                </article>

                <!-- Indicadores por estado (rejilla propia: cuatro estados + archivados) -->
                <div class="s-kpis-ciclo">
                <?php foreach ([
                    ['pendiente', 'fa-hourglass-half', 'Pendientes', $pendientes, 'Esperando atención'],
                    ['proceso', 'fa-screwdriver-wrench', 'En proceso', $en_proceso, 'Actualmente en atención'],
                    ['resuelta', 'fa-circle-check', 'Resueltas', $resueltas, 'Solucionadas, pendientes de cierre'],
                    ['cerrado', 'fa-lock', 'Cerrados', $cerrados, 'Casos finalizados'],
                ] as [$clase_kpi, $icono_kpi, $etiqueta_kpi, $valor_kpi, $nota_kpi]): ?>
                    <article class="s-kpi s-kpi-estado s-kpi-<?= $clase_kpi ?>">
                        <div class="s-kpi-cabecera">
                            <span class="s-kpi-icono"><i class="fa-solid <?= $icono_kpi ?>" aria-hidden="true"></i></span>
                            <span class="s-kpi-etiqueta"><?= $etiqueta_kpi ?></span>
                        </div>
                        <strong class="s-kpi-valor"><?= (int) $valor_kpi ?></strong>
                        <span class="s-kpi-barra" aria-hidden="true"><span style="width: <?= $proporcion_activos((int) $valor_kpi) ?>%"></span></span>
                        <span class="s-kpi-nota"><?= $nota_kpi ?></span>
                    </article>
                <?php endforeach; ?>

                <article class="s-kpi s-kpi-estado s-kpi-archivado">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Archivados</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $metricas['archivados'] ?></strong>
                    <span class="s-kpi-barra s-kpi-barra-vacia" aria-hidden="true"></span>
                    <span class="s-kpi-nota">Excluidos de los demás indicadores</span>
                </article>
                </div>

            </div>

            <details class="s-notas">
                <summary>
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    ¿Cómo se calculan estos indicadores?
                </summary>
                <ul>
                    <li>El promedio va desde el registro hasta la resolución vigente e incluye los tickets resueltos y los cerrados (cerrar conserva la fecha de resolución). Excluye fechas desconocidas, anteriores al registro o futuras. Una reapertura descarta la resolución anterior del promedio. Si supera las 48 horas se muestra en días.</li>
                    <li>«Sin técnico de resolución registrado» incluye resoluciones del administrador y registros antiguos sin autor técnico conocido.</li>
                    <li>Las tablas de cada gráfico permiten consultar los valores aunque no carguen los gráficos.</li>
                    <li>Los filtros del listado no modifican estos indicadores: siempre resumen todos los tickets permitidos de tu cuenta.</li>
                </ul>
            </details>

        </section>

        <!-- ================================================
             CALIDAD DEL SERVICIO (funciones adicionales):
             acuerdos de nivel de servicio (SLA) y encuestas
             de satisfacción del solicitante.
        ================================================= -->
        <section class="s-metricas s-calidad" aria-labelledby="titulo-calidad">

            <div class="s-seccion-cabecera">
                <div>
                    <span class="s-rotulo">Calidad del servicio</span>
                    <h2 id="titulo-calidad" class="s-seccion-titulo">SLA y satisfacción del solicitante</h2>
                </div>
                <span class="s-alcance">
                    <i class="fa-solid fa-stopwatch" aria-hidden="true"></i>
                    Plazos: Alta <?= SLA_HORAS['Alta'] ?> h · Media <?= SLA_HORAS['Media'] ?> h · Baja <?= SLA_HORAS['Baja'] ?> h
                </span>
            </div>

            <div class="s-kpis">
                <article class="s-kpi s-kpi-estado s-kpi-sla-vencido<?= $metricas_sla['vencido'] > 0 ? ' s-kpi-atencion' : '' ?>">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">SLA vencido</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $metricas_sla['vencido'] ?></strong>
                    <span class="s-kpi-nota">Abiertos que superaron su plazo</span>
                    <?php if ($metricas_sla['vencido'] > 0): ?>
                        <a href="index.php?sla=vencido" class="s-kpi-accion">Ver vencidos <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </article>

                <article class="s-kpi s-kpi-estado s-kpi-sla-por-vencer">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-hourglass-end" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">SLA por vencer</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $metricas_sla['por_vencer'] ?></strong>
                    <span class="s-kpi-nota">Consumieron el <?= (int) (SLA_FRACCION_AVISO * 100) ?> % o más del plazo</span>
                    <?php if ($metricas_sla['por_vencer'] > 0): ?>
                        <a href="index.php?sla=por_vencer" class="s-kpi-accion">Ver por vencer <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </article>

                <article class="s-kpi s-kpi-estado s-kpi-sla-a-tiempo">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-clock" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">SLA a tiempo</span>
                    </div>
                    <strong class="s-kpi-valor"><?= $metricas_sla['a_tiempo'] ?></strong>
                    <span class="s-kpi-nota">Abiertos dentro del plazo</span>
                </article>

                <article class="s-kpi s-kpi-estado s-kpi-sla-cumplimiento">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-medal" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Cumplimiento de SLA</span>
                    </div>
                    <?php if ($metricas_sla['cumplimiento'] === null): ?>
                        <strong class="s-kpi-valor s-kpi-valor-texto">Sin datos suficientes</strong>
                    <?php else: ?>
                        <strong class="s-kpi-valor"><?= escapar(number_format($metricas_sla['cumplimiento'], 1, ',', '.')) ?><small class="s-kpi-unidad">%</small></strong>
                    <?php endif; ?>
                    <span class="s-kpi-nota"><?= $metricas_sla['cumplido'] ?> de <?= $metricas_sla['cumplido'] + $metricas_sla['incumplido'] ?> resueltos dentro del plazo</span>
                </article>

                <article class="s-kpi s-kpi-principal s-kpi-satisfaccion">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Satisfacción promedio</span>
                    </div>
                    <?php if ($metricas_encuestas['promedio'] === null): ?>
                        <strong class="s-kpi-valor s-kpi-valor-texto">Sin encuestas</strong>
                    <?php else: ?>
                        <strong class="s-kpi-valor"><?= escapar(number_format($metricas_encuestas['promedio'], 1, ',', '.')) ?><small class="s-kpi-unidad">/ 5</small></strong>
                        <?= estrellasHtml((int) round($metricas_encuestas['promedio'])) ?>
                    <?php endif; ?>
                    <span class="s-kpi-nota"><?= $metricas_encuestas['respondidas'] ?> encuesta(s) respondida(s)</span>
                </article>

                <article class="s-kpi s-kpi-principal s-kpi-respuesta">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Tasa de respuesta</span>
                    </div>
                    <?php if ($metricas_encuestas['tasa_respuesta'] === null): ?>
                        <strong class="s-kpi-valor s-kpi-valor-texto">Sin datos suficientes</strong>
                    <?php else: ?>
                        <strong class="s-kpi-valor"><?= escapar(number_format($metricas_encuestas['tasa_respuesta'], 1, ',', '.')) ?><small class="s-kpi-unidad">%</small></strong>
                    <?php endif; ?>
                    <span class="s-kpi-nota">Encuestas respondidas sobre tickets resueltos o cerrados con código de seguimiento</span>
                </article>

                <article class="s-kpi s-kpi-principal s-kpi-distribucion">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Distribución de calificaciones</span>
                    </div>
                    <ul class="s-distribucion" aria-label="Cantidad de encuestas por calificación">
                        <?php foreach ($metricas_encuestas['distribucion'] as $nota => $cantidad): ?>
                            <?php $porcentaje = $metricas_encuestas['respondidas'] > 0 ? round($cantidad * 100 / $metricas_encuestas['respondidas']) : 0; ?>
                            <li>
                                <span class="s-distribucion-nota"><?= $nota ?> <i class="fa-solid fa-star" aria-hidden="true"></i></span>
                                <span class="s-distribucion-barra" aria-hidden="true"><span style="width: <?= $porcentaje ?>%"></span></span>
                                <span class="s-distribucion-cantidad"><?= $cantidad ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            </div>

            <?php if ($es_admin && $metricas_encuestas['por_tecnico']): ?>
                <details class="s-datos s-calidad-tecnicos" open>
                    <summary>Satisfacción por técnico que resolvió</summary>
                    <table>
                        <thead><tr><th scope="col">Técnico</th><th scope="col">Encuestas</th><th scope="col">Promedio</th></tr></thead>
                        <tbody>
                        <?php foreach ($metricas_encuestas['por_tecnico'] as $fila_tecnico): ?>
                            <tr>
                                <td><?= escapar($fila_tecnico['nombre']) ?></td>
                                <td><?= $fila_tecnico['cantidad'] ?></td>
                                <td><?= estrellasHtml((int) round($fila_tecnico['promedio'])) ?> <?= escapar(number_format($fila_tecnico['promedio'], 1, ',', '.')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <details class="s-notas">
                <summary>
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    ¿Cómo se calculan estos indicadores?
                </summary>
                <ul>
                    <li>El plazo del SLA corre desde el registro del ticket y depende de su prioridad. «Por vencer» indica que ya se consumió el <?= (int) (SLA_FRACCION_AVISO * 100) ?> % del plazo.</li>
                    <li>El cumplimiento considera los tickets activos resueltos o cerrados con fecha de resolución conocida.</li>
                    <li>Las encuestas las responde el solicitante desde «Consultar mi ticket», una sola vez por ticket y solo cuando está resuelto o cerrado. Incluyen tickets archivados.</li>
                    <li>La satisfacción por técnico se atribuye a quien resolvió el ticket, no al responsable actual.</li>
                </ul>
            </details>
        </section>

        <!-- ================================================
             GRÁFICOS (identificadores de canvas sin cambios)
        ================================================= -->
        <section class="s-graficos" aria-labelledby="titulo-graficos">

            <div class="s-seccion-cabecera s-graficos-cabecera">
                <div>
                    <span class="s-rotulo">Distribución</span>
                    <h2 id="titulo-graficos" class="s-seccion-titulo">Análisis de tickets activos</h2>
                </div>
            </div>

            <div class="s-graficos-rejilla">

                <article class="s-grafico s-grafico-estado">
                    <header class="s-grafico-cabecera">
                        <span class="s-grafico-icono"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i></span>
                        <div>
                            <h3>Tickets por estado</h3>
                            <p>Tickets activos, sin archivados</p>
                        </div>
                    </header>
                    <div class="s-grafico-lienzo">
                        <canvas id="graficoEstados" role="img" aria-label="Tickets activos por estado"></canvas>
                    </div>
                </article>

                <article class="s-grafico s-grafico-prioridad">
                    <header class="s-grafico-cabecera">
                        <span class="s-grafico-icono"><i class="fa-solid fa-signal" aria-hidden="true"></i></span>
                        <div>
                            <h3>Tickets por prioridad</h3>
                            <p>Nivel de urgencia de las solicitudes</p>
                        </div>
                    </header>
                    <div class="s-grafico-lienzo">
                        <canvas id="graficoPrioridades" role="img" aria-label="Tickets activos por prioridad"></canvas>
                    </div>
                </article>

                <article class="s-grafico s-grafico-categoria">
                    <header class="s-grafico-cabecera">
                        <span class="s-grafico-icono"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
                        <div>
                            <h3>Tickets por categoría</h3>
                            <p>Activos, incluidos los pendientes de clasificar</p>
                        </div>
                    </header>
                    <div class="s-grafico-lienzo">
                        <canvas id="graficoCategorias" role="img" aria-label="Tickets activos por categoría"></canvas>
                    </div>
                    <details class="s-datos">
                        <summary>Ver datos en tabla</summary>
                        <table class="s-tabla-datos">
                            <thead><tr><th scope="col">Categoría</th><th scope="col">Tickets</th></tr></thead>
                            <tbody>
                            <?php foreach ($metricas['categorias'] as $clave => $cantidad): ?>
                                <tr><td><?= escapar($categorias_validas[$clave] ?? 'Sin categoría') ?></td><td><?= $cantidad ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                </article>

                <?php foreach (['asignados_por_tecnico' => ['graficoAsignados', 'Tickets asignados por técnico', 'Responsable actual; incluye todos los estados activos.', 'fa-user-gear'],
                    'resueltos_por_tecnico' => ['graficoResueltos', 'Tickets resueltos por técnico', 'Autor real de la resolución, no el responsable actual. Resueltos y cerrados activos.', 'fa-user-check']] as $clave => $grafico): ?>
                    <article class="s-grafico s-grafico-tecnicos">
                        <header class="s-grafico-cabecera">
                            <span class="s-grafico-icono"><i class="fa-solid <?= $grafico[3] ?>" aria-hidden="true"></i></span>
                            <div>
                                <h3><?= escapar($grafico[1]) ?></h3>
                                <p><?= escapar($grafico[2]) ?></p>
                            </div>
                        </header>
                        <?php if (!$metricas[$clave]): ?>
                            <p class="s-grafico-vacio"><i class="fa-regular fa-folder-open" aria-hidden="true"></i> Sin tickets para mostrar.</p>
                        <?php endif; ?>
                        <div class="s-grafico-lienzo<?= !$metricas[$clave] ? ' s-grafico-lienzo-vacio' : '' ?>">
                            <canvas id="<?= escapar($grafico[0]) ?>" role="img" aria-label="<?= escapar($grafico[1]) ?>"></canvas>
                        </div>
                        <details class="s-datos">
                            <summary>Ver datos en tabla</summary>
                            <table class="s-tabla-datos">
                                <thead><tr><th scope="col">Técnico / atribución</th><th scope="col">Tickets</th></tr></thead>
                                <tbody>
                                <?php foreach ($metricas[$clave] as $fila_metrica): ?>
                                    <tr><td><?= escapar($fila_metrica['nombre']) ?><?= $fila_metrica['id'] === null ? '' : ' <span class="s-mono">(#' . $fila_metrica['id'] . ')</span>' ?></td><td><?= $fila_metrica['cantidad'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </article>
                <?php endforeach; ?>

            </div>
        </section>

        <!-- ================================================
             GESTIÓN DE TICKETS (Etapa 3)
             Solo presentación: la consulta, los parámetros GET
             y las URLs de las acciones no cambian.
        ================================================= -->
        <?php
        // Filtros aplicados (valores ya validados más arriba) para mostrarlos como chips.
        $filtros_aplicados = array_filter([
            'archivados' => $ver_archivados ? '1' : '',
            'buscar' => $buscar,
            'estado' => $estado,
            'prioridad' => $prioridad,
            'tecnico_id' => $tecnico_filtro,
            'categoria' => $categoria_filtro,
            'sla' => $sla_filtro,
        ], static fn($valor) => $valor !== '');

        // URL del listado sin un filtro concreto (mismos parámetros GET existentes).
        $url_sin_filtro = static function (string $clave) use ($filtros_aplicados): string {
            $consulta = http_build_query(array_diff_key($filtros_aplicados, [$clave => true]));
            return 'index.php' . ($consulta !== '' ? '?' . $consulta : '');
        };

        $nombre_tecnico_filtro = 'Sin asignar';
        if ($tecnico_filtro !== '' && $tecnico_filtro !== 'sin_asignar') {
            $nombre_tecnico_filtro = '#' . $tecnico_filtro;
            foreach ($tecnicos_filtro as $tecnico) {
                if ((string) $tecnico['id'] === $tecnico_filtro) {
                    $nombre_tecnico_filtro = $tecnico['usuario'];
                }
            }
        }

        $chips_filtros = [];
        if ($buscar !== '') { $chips_filtros['buscar'] = 'Búsqueda: «' . $buscar . '»'; }
        if ($estado !== '') { $chips_filtros['estado'] = 'Estado: ' . $estado; }
        if ($prioridad !== '') { $chips_filtros['prioridad'] = 'Prioridad: ' . $prioridad; }
        if ($tecnico_filtro !== '') { $chips_filtros['tecnico_id'] = 'Técnico: ' . $nombre_tecnico_filtro; }
        if ($categoria_filtro !== '') {
            $chips_filtros['categoria'] = 'Categoría: ' . ($categorias_validas[$categoria_filtro] ?? 'Sin categoría');
        }
        if ($sla_filtro !== '') { $chips_filtros['sla'] = 'SLA: ' . $opciones_sla[$sla_filtro]; }

        $iconos_categoria = ['hardware' => 'fa-microchip', 'software' => 'fa-code', 'red' => 'fa-network-wired'];
        $url_limpiar = 'index.php' . ($ver_archivados ? '?archivados=1' : '');
        ?>
        <section class="s-tickets" aria-labelledby="titulo-tickets">

            <div class="s-seccion-cabecera s-tickets-cabecera">
                <div>
                    <span class="s-rotulo">Gestión de tickets</span>
                    <h2 id="titulo-tickets" class="s-seccion-titulo">
                        <?= $ver_archivados ? 'Incidencias archivadas' : 'Administrar incidencias' ?>
                    </h2>
                </div>

                <?php if ($es_admin): ?>
                    <nav class="s-pestanas s-tickets-pestanas" aria-label="Vista de tickets">
                        <a href="index.php" class="s-pestana<?= !$ver_archivados ? ' s-activa' : '' ?>"<?= !$ver_archivados ? ' aria-current="page"' : '' ?>>
                            <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                            Sin archivar
                            <span class="s-pestana-contador"><?= $total ?></span>
                        </a>
                        <a href="index.php?archivados=1" class="s-pestana<?= $ver_archivados ? ' s-activa' : '' ?>"<?= $ver_archivados ? ' aria-current="page"' : '' ?>>
                            <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                            Archivados
                            <span class="s-pestana-contador"><?= $metricas['archivados'] ?></span>
                        </a>
                    </nav>
                <?php endif; ?>
            </div>

            <p class="s-seccion-descripcion">
                Estos filtros solo afectan al listado; los indicadores resumen todos los tickets permitidos de tu cuenta.
            </p>

            <!-- FILTROS (mismos nombres de parámetros GET) -->
            <form method="GET" action="index.php" class="s-filtros" role="search" aria-label="Filtrar incidencias">
                <?php if ($ver_archivados): ?><input type="hidden" name="archivados" value="1"><?php endif; ?>

                <div class="s-filtro s-filtro-buscar">
                    <label for="filtro-buscar" class="s-filtro-etiqueta">Buscar</label>
                    <div class="s-buscador">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input
                            type="text"
                            id="filtro-buscar"
                            name="buscar"
                            class="s-filtro-control"
                            placeholder="Número de ticket, nombre, laboratorio o equipo…"
                            value="<?= escapar($buscar) ?>"
                        >
                    </div>
                </div>

                <div class="s-filtro">
                    <label for="filtro-estado" class="s-filtro-etiqueta">Estado</label>
                    <select id="filtro-estado" name="estado" class="s-filtro-control">
                        <option value="">Todos los estados</option>
                        <?php foreach ($estados_validos as $opcion): ?>
                            <option value="<?= escapar($opcion) ?>" <?= $estado === $opcion ? "selected" : "" ?>><?= escapar($opcion) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="s-filtro">
                    <label for="filtro-prioridad" class="s-filtro-etiqueta">Prioridad</label>
                    <select id="filtro-prioridad" name="prioridad" class="s-filtro-control">
                        <option value="">Todas las prioridades</option>
                        <?php foreach ($prioridades_validas as $opcion): ?>
                            <option value="<?= escapar($opcion) ?>" <?= $prioridad === $opcion ? "selected" : "" ?>><?= escapar($opcion) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="s-filtro">
                    <label for="filtro-categoria" class="s-filtro-etiqueta">Categoría</label>
                    <select id="filtro-categoria" name="categoria" class="s-filtro-control">
                        <option value="">Todas las categorías</option>
                        <option value="sin_categoria" <?= $categoria_filtro === "sin_categoria" ? "selected" : "" ?>>Sin categoría</option>
                        <?php foreach ($categorias_validas as $valor => $etiqueta): ?>
                            <option value="<?= escapar($valor) ?>" <?= $categoria_filtro === $valor ? "selected" : "" ?>><?= escapar($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="s-filtro">
                    <label for="filtro-sla" class="s-filtro-etiqueta">SLA</label>
                    <select id="filtro-sla" name="sla" class="s-filtro-control">
                        <option value="">Todos</option>
                        <?php foreach ($opciones_sla as $valor => $etiqueta): ?>
                            <option value="<?= escapar($valor) ?>" <?= $sla_filtro === $valor ? "selected" : "" ?>><?= escapar($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($es_admin): ?>
                    <div class="s-filtro">
                        <label for="filtro-tecnico" class="s-filtro-etiqueta">Técnico</label>
                        <select id="filtro-tecnico" name="tecnico_id" class="s-filtro-control">
                            <option value="">Todos los técnicos</option>
                            <option value="sin_asignar" <?= $tecnico_filtro === "sin_asignar" ? "selected" : "" ?>>Sin asignar</option>
                            <?php foreach ($tecnicos_filtro as $tecnico): ?>
                                <option value="<?= (int) $tecnico["id"] ?>" <?= $tecnico_filtro === (string) $tecnico["id"] ? "selected" : "" ?>><?= escapar($tecnico["usuario"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="s-filtros-botones">
                    <button type="submit" class="s-btn s-btn-primario">
                        <i class="fa-solid fa-filter" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    <a href="<?= $url_limpiar ?>" class="s-btn s-btn-secundario">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        Limpiar
                    </a>
                </div>
            </form>

            <!-- RESUMEN DE RESULTADOS Y FILTROS ACTIVOS -->
            <div class="s-resultados" aria-live="polite">
                <span class="s-resultados-total">
                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                    Mostrando <strong><?= $cantidad_resultados ?></strong> incidencia(s)
                </span>
                <?php if ($chips_filtros): ?>
                    <ul class="s-chips" aria-label="Filtros activos">
                        <?php foreach ($chips_filtros as $clave_chip => $texto_chip): ?>
                            <li class="s-chip">
                                <?= escapar($texto_chip) ?>
                                <a href="<?= escapar($url_sin_filtro($clave_chip)) ?>" class="s-chip-quitar" aria-label="Quitar filtro <?= escapar($texto_chip) ?>" title="Quitar filtro">
                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- LISTADO: tabla en escritorio, tarjetas en pantallas medianas y pequeñas -->
            <div class="s-tabla-contenedor">
                <table class="s-tabla-tickets">
                    <caption class="s-solo-lector">
                        <?= $ver_archivados ? 'Incidencias archivadas' : 'Incidencias sin archivar' ?>: <?= $cantidad_resultados ?> resultado(s)
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Ticket</th>
                            <th scope="col">Título</th>
                            <th scope="col">Categoría</th>
                            <th scope="col">Reportado por</th>
                            <th scope="col">Laboratorio</th>
                            <th scope="col">Equipo</th>
                            <th scope="col">Prioridad</th>
                            <th scope="col">Estado</th>
                            <th scope="col">SLA</th>
                            <th scope="col">Técnico</th>
                            <th scope="col"><span class="s-th-acciones">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php if ($cantidad_resultados === 0): ?>

                        <tr class="s-fila-vacia">
                            <td colspan="11">
                                <div class="s-vacio">
                                    <span class="s-vacio-icono"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
                                    <strong class="s-vacio-titulo">No se encontraron incidencias</strong>
                                    <p>Prueba con otros criterios de búsqueda.</p>
                                    <?php if ($chips_filtros): ?>
                                        <a href="<?= $url_limpiar ?>" class="s-btn s-btn-secundario s-btn-sm">Quitar todos los filtros</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php while ($fila = $resultado->fetch_assoc()): ?>

                            <?php
                            $tono_prioridad = match ($fila["prioridad"]) {
                                "Alta" => "alta",
                                "Media" => "media",
                                default => "baja"
                            };

                            $tono_estado = tonoEstado($fila["estado"]);

                            $clave_categoria = isset($categorias_validas[$fila["categoria"] ?? ""]) ? $fila["categoria"] : "";
                            $numero_ticket = "#" . str_pad($fila["id"], 4, "0", STR_PAD_LEFT);
                            $tecnico_usuario = $fila["tecnico_usuario"] ?? null;
                            $sla_fila = evaluarSla($fila["prioridad"], $fila["estado"],
                                $fila["registro_epoch"] === null ? null : (int) $fila["registro_epoch"],
                                $fila["fecha_resolucion"]);
                            $iniciales_tecnico = $tecnico_usuario === null ? "" : mb_strtoupper(
                                mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $tecnico_usuario), 0, 2, "UTF-8"), "UTF-8");
                            ?>

                            <tr class="s-ticket s-borde-prioridad-<?= $tono_prioridad ?>">

                                <td class="s-celda-id">
                                    <span class="s-id-ticket"><?= $numero_ticket ?></span>
                                </td>

                                <td class="s-celda-titulo">
                                    <a href="detalle.php?id=<?= (int) $fila['id'] ?>" class="s-ticket-titulo<?= $fila["titulo"] === null ? ' s-sin-dato' : '' ?>">
                                        <?= escapar($fila["titulo"] ?? "Sin título") ?>
                                    </a>
                                </td>

                                <td class="s-celda-categoria" data-etiqueta="Categoría">
                                    <span class="s-categoria s-categoria-<?= $clave_categoria !== '' ? escapar($clave_categoria) : 'ninguna' ?>">
                                        <i class="fa-solid <?= $iconos_categoria[$clave_categoria] ?? 'fa-circle-question' ?>" aria-hidden="true"></i>
                                        <?= escapar($categorias_validas[$fila["categoria"] ?? ""] ?? "Sin categoría") ?>
                                    </span>
                                </td>

                                <td class="s-celda-meta" data-etiqueta="Reportado por"><?= escapar($fila["reportado_por"]) ?></td>
                                <td class="s-celda-meta" data-etiqueta="Laboratorio"><?= escapar($fila["laboratorio"]) ?></td>
                                <td class="s-celda-meta" data-etiqueta="Equipo"><?= escapar($fila["equipo"]) ?></td>

                                <td class="s-celda-prioridad">
                                    <span class="s-insignia s-insignia-<?= $tono_prioridad ?>">
                                        <span class="s-solo-lector">Prioridad:</span>
                                        <?= escapar($fila["prioridad"]) ?>
                                    </span>
                                </td>

                                <td class="s-celda-estado">
                                    <span class="s-insignia s-insignia-<?= $tono_estado ?>">
                                        <span class="s-solo-lector">Estado:</span>
                                        <?= escapar($fila["estado"]) ?>
                                    </span>
                                </td>

                                <td class="s-celda-sla" data-etiqueta="SLA">
                                    <span class="s-sla s-sla-<?= escapar($sla_fila["tono"]) ?>" title="<?= escapar($sla_fila["detalle"]) ?>">
                                        <span class="s-solo-lector">SLA:</span>
                                        <?= escapar($sla_fila["etiqueta"]) ?>
                                    </span>
                                    <?php if (in_array($sla_fila["clave"], ["a_tiempo", "por_vencer", "vencido"], true)): ?>
                                        <small class="s-sla-detalle"><?= escapar($sla_fila["detalle"]) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td class="s-celda-tecnico" data-etiqueta="Técnico">
                                    <?php if ($tecnico_usuario === null): ?>
                                        <span class="s-persona s-persona-sin-asignar">
                                            <span class="s-avatar s-avatar-vacio" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                            Sin asignar
                                        </span>
                                    <?php else: ?>
                                        <span class="s-persona">
                                            <span class="s-avatar" aria-hidden="true"><?= escapar($iniciales_tecnico) ?></span>
                                            <?= escapar($tecnico_usuario) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="s-celda-acciones">
                                    <div class="s-acciones" role="group" aria-label="Acciones del ticket <?= $numero_ticket ?>">
                                        <a href="detalle.php?id=<?= (int) $fila['id'] ?>" class="s-accion s-accion-detalle" title="Detalle e historial" aria-label="Detalle e historial del ticket <?= $numero_ticket ?>">
                                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                                            <span class="s-accion-texto">Historial</span>
                                        </a>
                                        <?php if ($es_admin): ?>
                                            <a href="asignar.php?id=<?= (int) $fila["id"] ?>" class="s-accion s-accion-asignar" title="Asignar o reasignar técnico" aria-label="Asignar o reasignar técnico del ticket <?= $numero_ticket ?>">
                                                <i class="fa-solid fa-user-gear" aria-hidden="true"></i>
                                                <span class="s-accion-texto">Asignar</span>
                                            </a>
                                        <?php endif; ?>

                                        <a href="editar.php?id=<?= (int)$fila["id"] ?>" class="s-accion s-accion-editar" title="Editar incidencia" aria-label="Editar incidencia <?= $numero_ticket ?>">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                            <span class="s-accion-texto">Editar</span>
                                        </a>

                                        <a href="estado.php?id=<?= (int)$fila["id"] ?>" class="s-accion s-accion-estado" title="Cambiar estado" aria-label="Cambiar estado del ticket <?= $numero_ticket ?>">
                                            <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                                            <span class="s-accion-texto">Estado</span>
                                        </a>

                                        <?php if ($es_admin): ?>
                                            <a
                                                href="archivar.php?id=<?= (int)$fila["id"] ?>"
                                                class="s-accion s-accion-archivar"
                                                title="<?= $ver_archivados ? 'Restaurar incidencia' : 'Archivar incidencia' ?>"
                                                aria-label="<?= $ver_archivados ? 'Restaurar incidencia' : 'Archivar incidencia' ?> <?= $numero_ticket ?>"
                                            >
                                                <i class="fa-solid <?= $ver_archivados ? 'fa-box-open' : 'fa-box-archive' ?>" aria-hidden="true"></i>
                                                <span class="s-accion-texto"><?= $ver_archivados ? 'Restaurar' : 'Archivar' ?></span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </section>

    </main>

</div>

<script id="metricasDatos" type="application/json"><?= json_encode($metricas, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    if (typeof Chart === "undefined") {
        console.warn("No se pudo cargar Chart.js");
        return;
    }

    // DATOS REALES DE MYSQL
    const estados = [
        <?= $pendientes ?>,
        <?= $en_proceso ?>,
        <?= $resueltas ?>,
        <?= $cerrados ?>
    ];

    const prioridades = [
        <?= $baja ?>,
        <?= $media ?>,
        <?= $alta ?>
    ];

    // COLORES DE LA IDENTIDAD «SEÑAL»: se leen de css/tema.css para que gráficos,
    // indicadores e insignias usen la misma semántica. Valores de respaldo idénticos.
    const estilosTema = typeof getComputedStyle === 'function' ? getComputedStyle(document.documentElement) : null;
    const colorTema = (variable, respaldo) => (estilosTema && estilosTema.getPropertyValue(variable).trim()) || respaldo;
    const colores = {
        pendiente: colorTema('--s-estado-pendiente', '#F2A33A'),
        proceso: colorTema('--s-estado-proceso', '#2563EB'),
        resuelta: colorTema('--s-estado-resuelta', '#16A34A'),
        cerrado: colorTema('--s-estado-cerrado', '#6D28D9'),
        baja: colorTema('--s-prioridad-baja', '#8A94A3'),
        media: colorTema('--s-prioridad-media', '#F2A33A'),
        alta: colorTema('--s-prioridad-alta', '#DC2626'),
        barras: colorTema('--s-grafico-1', '#0B7C8A'),
        barrasActivo: colorTema('--s-cian-700', '#0A6E7B'),
        superficie: colorTema('--s-superficie', '#FFFFFF'),
        rejilla: colorTema('--s-grafico-rejilla', '#EEF1F4')
    };

    const metricas = JSON.parse(document.getElementById('metricasDatos').textContent);
    const barrasMetricas = (id, labels, cantidades, horizontal = false) => new Chart(document.getElementById(id), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Tickets activos', data: cantidades, backgroundColor: colores.barras,
            hoverBackgroundColor: colores.barrasActivo, borderRadius: 6, maxBarThickness: 44 }] },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x',
            plugins: { legend: { display: false } },
            scales: { [horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    barrasMetricas('graficoCategorias', ['Hardware', 'Software', 'Red', 'Sin categoría'], Object.values(metricas.categorias));
    [['graficoAsignados', 'asignados_por_tecnico'], ['graficoResueltos', 'resueltos_por_tecnico']].forEach(([id, clave]) => {
        barrasMetricas(id, metricas[clave].map(fila => fila.nombre + (fila.id === null ? '' : ' (#' + fila.id + ')')),
            metricas[clave].map(fila => fila.cantidad), true);
    });

    // GRÁFICO POR ESTADO
    new Chart(
        document.getElementById("graficoEstados"),
        {
            type: "doughnut",

            data: {
                labels: ["Pendientes", "En proceso", "Resueltas", "Cerrados"],

                datasets: [{
                    data: estados,
                    backgroundColor: [
                        colores.pendiente,
                        colores.proceso,
                        colores.resuelta,
                        colores.cerrado
                    ],
                    borderColor: colores.superficie,
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "72%",

                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        }
    );

    // GRÁFICO POR PRIORIDAD
    new Chart(
        document.getElementById("graficoPrioridades"),
        {
            type: "bar",

            data: {
                labels: ["Baja", "Media", "Alta"],

                datasets: [{
                    label: "Tickets",
                    data: prioridades,
                    backgroundColor: [
                        colores.baja,
                        colores.media,
                        colores.alta
                    ],
                    borderRadius: 8,
                    maxBarThickness: 65
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        display: false
                    }
                },

                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: colores.rejilla
                        }
                    },

                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        }
    );
});
</script>

</body>
</html>

<?php
$stmt->close();
?>
