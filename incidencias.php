<?php
require_once __DIR__ . "/config/sesion.php";
require_once __DIR__ . '/config/parametros.php';

require_once __DIR__ . "/config/conexion.php";

// Comprobar si existe una sesión iniciada
require_once __DIR__ . "/config/autorizacion.php";
require_once __DIR__ . "/config/estados.php";
$usuario_actual = usuarioAutenticado($conn, "login.php");
$sesion_iniciada = true;
$es_admin = (int) ($usuario_actual["rol"] === "admin");
$usuario_id = (int) $usuario_actual["id"];

// Recibir filtros
validarParametrosTexto($_GET, ['laboratorio', 'estado', 'prioridad']);
$laboratorio = trim($_GET["laboratorio"] ?? "");
$estado = $_GET["estado"] ?? "";
$prioridad = $_GET["prioridad"] ?? "";

$estados_validos = ESTADOS_TICKET;
$prioridades_validas = ["Baja", "Media", "Alta"];
$categorias_validas = ["hardware" => "Hardware", "software" => "Software", "red" => "Red"];

if ($estado !== "" && !in_array($estado, $estados_validos, true)) {
    rechazarParametrosMalformados();
}

if ($prioridad !== "" && !in_array($prioridad, $prioridades_validas, true)) {
    rechazarParametrosMalformados();
}

// Estadísticas generales
$sql_estadisticas = "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(estado = 'Pendiente'), 0) AS pendientes,
        COALESCE(SUM(estado = 'En proceso'), 0) AS proceso,
        COALESCE(SUM(estado = 'Resuelta'), 0) AS resueltas,
        COALESCE(SUM(estado = 'Cerrado'), 0) AS cerrados
    FROM incidencias WHERE archivado = 0 AND (? = 1 OR tecnico_id = ?)
";

$stmt_estadisticas = $conn->prepare($sql_estadisticas);
$stmt_estadisticas->bind_param("ii", $es_admin, $usuario_id);
$stmt_estadisticas->execute();
$estadisticas = $stmt_estadisticas->get_result()->fetch_assoc();
$stmt_estadisticas->close();

// Consulta con filtros
$sql = "SELECT i.*, t.usuario AS tecnico_usuario FROM incidencias i
        LEFT JOIN usuarios t ON t.id = i.tecnico_id
        WHERE i.archivado = 0 AND (? = 1 OR i.tecnico_id = ?)";

$tipos = "ii";
$parametros = [$es_admin, $usuario_id];

if ($laboratorio !== "") {
    $sql .= " AND laboratorio LIKE ?";
    $tipos .= "s";
    $parametros[] = "%" . $laboratorio . "%";
}

if ($estado !== "") {
    $sql .= " AND estado = ?";
    $tipos .= "s";
    $parametros[] = $estado;
}

if ($prioridad !== "") {
    $sql .= " AND prioridad = ?";
    $tipos .= "s";
    $parametros[] = $prioridad;
}

$sql .= " ORDER BY i.fecha_registro DESC, i.id DESC";

$stmt = $conn->prepare($sql);

if ($tipos !== "") {
    $stmt->bind_param($tipos, ...$parametros);
}

$stmt->execute();
$resultado = $stmt->get_result();

// Escapar contenido introducido por usuarios
function escapar($texto) {
    return htmlspecialchars(
        (string)$texto,
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

    <title>Incidencias | Soporte TI</title>
    <?= faviconSenal() ?>

    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/metricas.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/tickets.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/consulta.css') ?>">
</head>

<body class="pagina-dashboard s-app">

<div class="dashboard-layout">

    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php): página interna, exige sesión -->
    <?php
    $nav_activa = 'listado';
    $nav_ticket = null;
    $nav_raiz = '';
    $nav_admin = 'admin/';
    require __DIR__ . '/includes/sidebar.php';
    ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main id="contenido" class="dashboard-main s-consulta">

        <!-- ENCABEZADO -->
        <header class="dashboard-topbar">
            <div>
                <span class="topbar-etiqueta">Área del personal de soporte</span>
                <h1>Consulta de incidencias</h1>
                <p>Consulta y seguimiento de tickets tecnológicos. Requiere iniciar sesión.</p>
            </div>
            <a href="registrar.php" class="btn-nuevo">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Nuevo ticket
            </a>
        </header>

        <?php
        $total_consulta = (int) $estadisticas["total"];
        $proporcion_consulta = static fn(int $n): float => $total_consulta > 0 ? round($n * 100 / $total_consulta, 1) : 0.0;
        ?>

        <!-- ESTADÍSTICAS -->
        <section class="s-metricas" aria-labelledby="titulo-resumen">
            <div class="s-seccion-cabecera">
                <div>
                    <span class="s-rotulo">Resumen</span>
                    <h2 id="titulo-resumen" class="s-seccion-titulo">Tickets sin archivar</h2>
                </div>
                <span class="s-alcance">
                    <i class="fa-solid <?= $es_admin ? 'fa-globe' : 'fa-user-check' ?>" aria-hidden="true"></i>
                    <?= $es_admin ? 'Alcance: todo el sistema' : 'Alcance: tus tickets asignados' ?>
                </span>
            </div>

            <div class="s-kpis s-kpis-consulta s-kpis-ciclo">
                <article class="s-kpi s-kpi-estado s-kpi-destacado">
                    <div class="s-kpi-cabecera">
                        <span class="s-kpi-icono"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                        <span class="s-kpi-etiqueta">Total de tickets</span>
                    </div>
                    <strong class="s-kpi-valor"><?= (int)$estadisticas["total"] ?></strong>
                    <span class="s-kpi-nota">Incidencias registradas</span>
                </article>

                <?php foreach ([
                    ['pendiente', 'fa-hourglass-half', 'Pendientes', (int)$estadisticas["pendientes"], 'Esperando atención'],
                    ['proceso', 'fa-screwdriver-wrench', 'En proceso', (int)$estadisticas["proceso"], 'En atención técnica'],
                    ['resuelta', 'fa-circle-check', 'Resueltas', (int)$estadisticas["resueltas"], 'Problemas solucionados'],
                    ['cerrado', 'fa-lock', 'Cerrados', (int)$estadisticas["cerrados"], 'Casos finalizados'],
                ] as [$tono_kpi, $icono_kpi, $etiqueta_kpi, $valor_kpi, $nota_kpi]): ?>
                    <article class="s-kpi s-kpi-estado s-kpi-<?= $tono_kpi ?>">
                        <div class="s-kpi-cabecera">
                            <span class="s-kpi-icono"><i class="fa-solid <?= $icono_kpi ?>" aria-hidden="true"></i></span>
                            <span class="s-kpi-etiqueta"><?= $etiqueta_kpi ?></span>
                        </div>
                        <strong class="s-kpi-valor"><?= $valor_kpi ?></strong>
                        <span class="s-kpi-barra" aria-hidden="true"><span style="width: <?= $proporcion_consulta($valor_kpi) ?>%"></span></span>
                        <span class="s-kpi-nota"><?= $nota_kpi ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- LISTADO DE INCIDENCIAS -->
        <section class="s-tickets" aria-labelledby="titulo-listado">

            <div class="s-seccion-cabecera s-tickets-cabecera">
                <div>
                    <span class="s-rotulo">Listado</span>
                    <h2 id="titulo-listado" class="s-seccion-titulo">Listado de incidencias</h2>
                </div>
            </div>
            <p class="s-seccion-descripcion">Consulta los tickets registrados.</p>

            <!-- FILTROS (mismos parámetros GET) -->
            <form method="GET" class="s-filtros" role="search" aria-label="Filtrar incidencias">

                <div class="s-filtro s-filtro-buscar">
                    <label for="laboratorio" class="s-filtro-etiqueta">Laboratorio</label>
                    <div class="s-buscador">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input
                            type="text"
                            id="laboratorio"
                            name="laboratorio"
                            class="s-filtro-control"
                            placeholder="Buscar laboratorio…"
                            value="<?= escapar($laboratorio) ?>"
                        >
                    </div>
                </div>

                <div class="s-filtro">
                    <label for="estado" class="s-filtro-etiqueta">Estado</label>
                    <select id="estado" name="estado" class="s-filtro-control">
                        <option value="">Todos los estados</option>
                        <?php foreach ($estados_validos as $e): ?>
                            <option value="<?= escapar($e) ?>" <?= $estado === $e ? "selected" : "" ?>><?= escapar($e) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="s-filtro">
                    <label for="prioridad" class="s-filtro-etiqueta">Prioridad</label>
                    <select id="prioridad" name="prioridad" class="s-filtro-control">
                        <option value="">Todas las prioridades</option>
                        <?php foreach ($prioridades_validas as $p): ?>
                            <option value="<?= escapar($p) ?>" <?= $prioridad === $p ? "selected" : "" ?>><?= escapar($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="s-filtros-botones">
                    <button type="submit" class="s-btn s-btn-primario">
                        <i class="fa-solid fa-filter" aria-hidden="true"></i>
                        Filtrar
                    </button>
                    <a href="incidencias.php" class="s-btn s-btn-secundario">
                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        Limpiar
                    </a>
                </div>
            </form>

            <div class="s-resultados" aria-live="polite">
                <span class="s-resultados-total">
                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                    Mostrando <strong><?= (int) $resultado->num_rows ?></strong> incidencia(s)
                </span>
            </div>

            <!-- TABLA: tarjetas en pantallas medianas y pequeñas -->
            <div class="s-tabla-contenedor">
                <table class="s-tabla-tickets s-tabla-consulta">
                    <caption class="s-solo-lector">Listado de incidencias: <?= (int) $resultado->num_rows ?> resultado(s)</caption>
                    <thead>
                        <tr>
                            <th scope="col">Ticket</th>
                            <th scope="col">Título</th>
                            <th scope="col">Categoría</th>
                            <th scope="col">Reportado por</th>
                            <th scope="col">Laboratorio</th>
                            <th scope="col">Equipo</th>
                            <th scope="col">Descripción</th>
                            <th scope="col">Prioridad</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Técnico</th>
                            <th scope="col">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php if ($resultado->num_rows > 0): ?>

                        <?php while ($fila = $resultado->fetch_assoc()): ?>

                            <?php
                            $tono_prioridad = match ($fila["prioridad"]) {
                                "Alta" => "alta",
                                "Media" => "media",
                                default => "baja"
                            };
                            $tono_estado = tonoEstado($fila["estado"]);
                            $clave_categoria = isset($categorias_validas[$fila["categoria"] ?? ""]) ? $fila["categoria"] : "";
                            $iconos_categoria = ["hardware" => "fa-microchip", "software" => "fa-code", "red" => "fa-network-wired"];
                            $tecnico_usuario = $fila["tecnico_usuario"] ?? null;
                            ?>

                            <tr class="s-ticket s-borde-prioridad-<?= $tono_prioridad ?>">

                                <td class="s-celda-id">
                                    <span class="s-id-ticket">#<?= str_pad($fila["id"], 4, "0", STR_PAD_LEFT) ?></span>
                                </td>

                                <td class="s-celda-titulo">
                                    <span class="s-ticket-titulo<?= $fila["titulo"] === null ? ' s-sin-dato' : '' ?>"><?= escapar($fila["titulo"] ?? "Sin título") ?></span>
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

                                <!-- DESCRIPCIÓN CON BOTÓN (abre la ventana emergente existente) -->
                                <td class="s-celda-acciones descripcion-celda">
                                    <button
                                        type="button"
                                        class="btn-ver-descripcion s-btn s-btn-secundario s-btn-sm"
                                        data-ticket="<?= (int)$fila["id"] ?>"
                                        data-descripcion="<?= escapar($fila["descripcion"]) ?>"
                                        data-titulo="<?= escapar($fila["titulo"] ?? "Sin título") ?>"
                                        data-categoria="<?= escapar($categorias_validas[$fila["categoria"] ?? ""] ?? "Sin categoría") ?>"
                                        onclick="abrirDescripcion(this)"
                                        aria-haspopup="dialog"
                                    >
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                        Ver descripción
                                    </button>
                                </td>

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

                                <td class="s-celda-tecnico" data-etiqueta="Técnico">
                                    <?php if ($tecnico_usuario === null): ?>
                                        <span class="s-persona s-persona-sin-asignar">Sin asignar</span>
                                    <?php else: ?>
                                        <span class="s-persona"><?= escapar($tecnico_usuario) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="s-celda-meta s-celda-fecha" data-etiqueta="Fecha">
                                    <span class="s-mono"><?= date("d/m/Y H:i", strtotime($fila["fecha_registro"])) ?></span>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr class="s-fila-vacia">
                            <td colspan="11">
                                <div class="s-vacio">
                                    <span class="s-vacio-icono"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span>
                                    <strong class="s-vacio-titulo">No se encontraron incidencias.</strong>
                                    <p>Prueba con otros criterios de búsqueda.</p>
                                </div>
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </section>

    </main>

</div>

<!-- VENTANA EMERGENTE DE DESCRIPCIÓN -->
<div
    id="modalDescripcion"
    class="modal-descripcion"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modalDescripcionTitulo"
    aria-hidden="true"
>
    <div class="modal-descripcion-contenido">

        <div class="modal-descripcion-encabezado">

            <h2 id="modalDescripcionTitulo">
                Descripción del ticket
            </h2>

            <button
                type="button"
                class="modal-descripcion-cerrar"
                onclick="cerrarDescripcion()"
                aria-label="Cerrar ventana"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <div class="modal-descripcion-cuerpo">

            <span class="modal-descripcion-etiqueta">TÍTULO</span>
            <p id="modalTicketTitulo" class="modal-descripcion-texto"></p>
            <span class="modal-descripcion-etiqueta">CATEGORÍA</span>
            <p id="modalTicketCategoria" class="modal-descripcion-texto"></p>

            <span class="modal-descripcion-etiqueta">
                DESCRIPCIÓN COMPLETA
            </span>

            <p
                id="modalDescripcionTexto"
                class="modal-descripcion-texto"
            ></p>

        </div>

        <div class="modal-descripcion-pie">
            <a id="modalSeguimiento" class="modal-descripcion-boton">Ver historial y comentar</a>

            <button
                type="button"
                class="modal-descripcion-boton"
                onclick="cerrarDescripcion()"
            >
                Cerrar
            </button>

        </div>

    </div>
</div>

<script>
    const modalDescripcion = document.getElementById("modalDescripcion");
    const modalDescripcionTitulo = document.getElementById("modalDescripcionTitulo");
    const modalDescripcionTexto = document.getElementById("modalDescripcionTexto");
    const modalTicketTitulo = document.getElementById("modalTicketTitulo");
    const modalTicketCategoria = document.getElementById("modalTicketCategoria");
    const botonCerrarModal = document.querySelector(".modal-descripcion-cerrar");

    let botonAnterior = null;

    function abrirDescripcion(boton) {
        botonAnterior = boton;

        const numeroTicket = boton.dataset.ticket;
        const descripcion = boton.dataset.descripcion;

        modalDescripcionTitulo.textContent = "Descripción del ticket #" +
            String(numeroTicket).padStart(4, "0");

        // textContent evita interpretar la descripción como HTML
        modalDescripcionTexto.textContent = descripcion;
        modalTicketTitulo.textContent = boton.dataset.titulo;
        modalTicketCategoria.textContent = boton.dataset.categoria;
        document.getElementById("modalSeguimiento").href = "admin/detalle.php?id=" + encodeURIComponent(boton.dataset.ticket);

        modalDescripcion.classList.add("abierto");
        modalDescripcion.setAttribute("aria-hidden", "false");

        document.body.classList.add("modal-abierto");
        botonCerrarModal.focus();
    }

    function cerrarDescripcion() {
        modalDescripcion.classList.remove("abierto");
        modalDescripcion.setAttribute("aria-hidden", "true");

        document.body.classList.remove("modal-abierto");

        if (botonAnterior) {
            botonAnterior.focus();
        }
    }

    // Cerrar al hacer clic fuera del contenido
    modalDescripcion.addEventListener("click", function(evento) {
        if (evento.target === modalDescripcion) {
            cerrarDescripcion();
        }
    });

    // Cerrar con la tecla Escape
    document.addEventListener("keydown", function(evento) {
        if (
            evento.key === "Escape" &&
            modalDescripcion.classList.contains("abierto")
        ) {
            cerrarDescripcion();
        }
    });
</script>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
