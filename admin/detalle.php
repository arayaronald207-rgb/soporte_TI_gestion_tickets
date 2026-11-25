<?php
require_once __DIR__ . "/../config/sesion.php";
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/autorizacion.php';
require_once __DIR__ . '/../config/historial.php';
require_once __DIR__ . '/../config/sla.php';
require_once __DIR__ . '/../config/encuestas.php';
$usuario_actual = usuarioAutenticado($conn, '../login.php');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    http_response_code(404);
    exit('No se encontró la incidencia.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';
$comentario = '';
try {
    // Incluso la lectura del historial mantiene el permiso hasta terminar de leer.
    // El bloqueo vuelve a consultar la asignacion actual, no la del formulario.
    $incidencia = bloquearIncidenciaParaCambio($conn, $id);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $comentario = is_string($_POST['comentario'] ?? null) ? trim($_POST['comentario']) : '';
        $accion = $_POST['accion'] ?? 'comentario';
        if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            $error = 'Solicitud no válida. Actualiza la página.';
        } elseif ($accion === 'codigo') {
            // Nuevo codigo para el solicitante (por ejemplo, si perdio el anterior).
            // Solo se guarda el hash; el codigo se muestra una unica vez al personal.
            $codigo_nuevo = generarCodigoSeguimiento();
            $hash_nuevo = hashCodigoSeguimiento($codigo_nuevo);
            $stmt = $conn->prepare('UPDATE incidencias SET codigo_seguimiento_hash = ? WHERE id = ?');
            $stmt->bind_param('si', $hash_nuevo, $id);
            $stmt->execute();
            $stmt->close();
            registrarEventoIncidencia($conn, $id, (int) $_SESSION['admin_id'], 'edicion',
                'Se generó un nuevo código de seguimiento para el solicitante. El código anterior dejó de ser válido.');
            $conn->commit();
            $_SESSION['codigo_generado'] = ['id' => $id, 'codigo' => $codigo_nuevo];
            header('Location: detalle.php?id=' . $id . '#codigo');
            exit;
        } elseif ($accion !== 'comentario') {
            http_response_code(422);
            $error = 'Operación no válida.';
        } elseif (!mb_check_encoding($comentario, 'UTF-8') ||
            preg_match('/\S/u', $comentario) !== 1 || mb_strlen($comentario, 'UTF-8') > 3000 ||
            strpos($comentario, "\0") !== false) {
            http_response_code(422);
            $error = 'Escribe un comentario de 1 a 3000 caracteres, con contenido válido.';
        } else {
            registrarEventoIncidencia($conn, $id, (int) $_SESSION['admin_id'], 'comentario', $comentario);
            $conn->commit();
            header('Location: detalle.php?id=' . $id . '&comentado=1#historial');
            exit;
        }
    }
    $stmt = $conn->prepare(
        'SELECT h.*, u.usuario AS autor, ta.usuario AS tecnico_anterior, tn.usuario AS tecnico_nuevo
         FROM incidencia_historial h JOIN usuarios u ON u.id = h.usuario_id
         LEFT JOIN usuarios ta ON ta.id = h.tecnico_anterior_id
         LEFT JOIN usuarios tn ON tn.id = h.tecnico_nuevo_id
         WHERE h.incidencia_id = ? ORDER BY h.fecha ASC, h.id ASC'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $incidencia['tecnico_id']);
    $stmt->execute();
    $responsable = $stmt->get_result()->fetch_assoc()['usuario'] ?? 'Sin asignar';
    $stmt->close();
    // SLA: instante real del registro, independiente de la zona de la conexion.
    $stmt = $conn->prepare('SELECT UNIX_TIMESTAMP(fecha_registro) AS registro_epoch FROM incidencias WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $registro_epoch = $stmt->get_result()->fetch_assoc()['registro_epoch'];
    $stmt->close();
    $sla = evaluarSla($incidencia['prioridad'], $incidencia['estado'],
        $registro_epoch === null ? null : (int) $registro_epoch, $incidencia['fecha_resolucion']);
    $encuesta = encuestaDeIncidencia($conn, $id);
    $conn->commit();
    // Codigo recien generado: se muestra una sola vez.
    $codigo_generado = null;
    if (($_SESSION['codigo_generado']['id'] ?? null) === $id) {
        $codigo_generado = (string) $_SESSION['codigo_generado']['codigo'];
    }
    unset($_SESSION['codigo_generado']);
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    error_log('Error en seguimiento de incidencia: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo completar la operación. Inténtalo de nuevo.');
}
function escapar($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$tipos = ['creacion' => 'Creación', 'comentario' => 'Comentario', 'edicion' => 'Edición',
    'asignacion' => 'Asignación', 'cambio_estado' => 'Cambio de estado',
    'archivado' => 'Archivado', 'desarchivado' => 'Desarchivado'];
$categorias = ['hardware' => 'Hardware', 'software' => 'Software', 'red' => 'Red'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?= (int) $id ?> | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>
    <?php
    // «?v=» con la fecha de modificación: evita que el navegador use una copia antigua en caché.
    $version_recurso = static fn(string $ruta): int => (int) @filemtime(__DIR__ . '/../' . $ruta);
    ?>
    <link rel="stylesheet" href="../css/tema.css?v=<?= $version_recurso('css/tema.css') ?>">
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/navegacion.css?v=<?= $version_recurso('css/navegacion.css') ?>">
    <link rel="stylesheet" href="../css/formularios.css?v=<?= $version_recurso('css/formularios.css') ?>">
    <link rel="stylesheet" href="../css/historial.css?v=<?= $version_recurso('css/historial.css') ?>">
    <link rel="stylesheet" href="../css/calidad.css?v=<?= $version_recurso('css/calidad.css') ?>">
    <script src="../js/formularios.js?v=<?= $version_recurso('js/formularios.js') ?>" defer></script>
</head>
<body class="pagina-dashboard s-app">
<div class="dashboard-layout">
    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = 'ticket';
    $nav_ticket = ['id' => (int) $id, 'seccion' => 'detalle', 'archivado' => (int) $incidencia['archivado'] === 1];
    require __DIR__ . '/../includes/sidebar.php';
    ?>
    <main class="dashboard-main">
        <?php
        // Presentación: tonos e iconos para valores ya leídos de la base de datos.
        $tono_estado = static fn(?string $valor): string => match ($valor) {
            'Pendiente' => 'pendiente',
            'En proceso' => 'proceso',
            'Resuelta' => 'resuelta',
            default => 'archivado'
        };
        $tono_prioridad = match ($incidencia['prioridad']) {
            'Alta' => 'alta',
            'Media' => 'media',
            default => 'baja'
        };
        $iconos_evento = [
            'creacion' => ['s-evento-creacion', 'fa-flag'],
            'comentario' => ['s-evento-comentario', 'fa-comment-dots'],
            'edicion' => ['s-evento-edicion', 'fa-pen'],
            'asignacion' => ['s-evento-asignacion', 'fa-user-gear'],
            'cambio_estado' => ['s-evento-estado', 'fa-arrows-rotate'],
            'archivado' => ['s-evento-archivado', 'fa-box-archive'],
            'desarchivado' => ['s-evento-desarchivado', 'fa-box-open'],
        ];
        $iconos_categoria = ['hardware' => 'fa-microchip', 'software' => 'fa-code', 'red' => 'fa-network-wired'];
        $clave_categoria = isset($categorias[$incidencia['categoria'] ?? '']) ? $incidencia['categoria'] : '';
        $numero_ticket = '#' . str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT);
        $iniciales = static fn(string $nombre): string =>
            mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $nombre), 0, 2, 'UTF-8'), 'UTF-8') ?: '?';
        $sin_responsable = $incidencia['tecnico_id'] === null;
        ?>

        <header class="dashboard-topbar">
            <div>
                <span class="topbar-etiqueta">Seguimiento</span>
                <h1>Ticket <span class="s-mono"><?= $numero_ticket ?></span></h1>
                <p>Información de la incidencia e historial de atención.</p>
            </div>
            <a href="index.php" class="btn-limpiar">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver al panel
            </a>
        </header>

        <!-- RESUMEN DEL TICKET -->
        <section class="s-detalle-resumen" aria-labelledby="titulo-ticket">

            <div class="s-detalle-resumen-principal">
                <span class="s-detalle-numero s-mono"><?= $numero_ticket ?></span>
                <h2 id="titulo-ticket" class="s-detalle-titulo<?= $incidencia['titulo'] === null ? ' s-sin-dato' : '' ?>">
                    <?= escapar($incidencia['titulo'] ?? 'Sin título') ?>
                </h2>

                <ul class="s-detalle-etiquetas" aria-label="Clasificación del ticket">
                    <li><span class="s-insignia s-insignia-<?= $tono_estado($incidencia['estado']) ?>"><span class="s-solo-lector">Estado:</span> <?= escapar($incidencia['estado']) ?></span></li>
                    <li><span class="s-insignia s-insignia-<?= $tono_prioridad ?>"><span class="s-solo-lector">Prioridad:</span> Prioridad <?= escapar(mb_strtolower($incidencia['prioridad'], 'UTF-8')) ?></span></li>
                    <li>
                        <span class="s-categoria s-categoria-<?= $clave_categoria !== '' ? escapar($clave_categoria) : 'ninguna' ?>">
                            <i class="fa-solid <?= $iconos_categoria[$clave_categoria] ?? 'fa-circle-question' ?>" aria-hidden="true"></i>
                            <span class="s-solo-lector">Categoría:</span>
                            <?= escapar($categorias[$incidencia['categoria']] ?? 'Sin categoría') ?>
                        </span>
                    </li>
                    <li>
                        <span class="s-sla s-sla-<?= escapar($sla['tono']) ?>" title="<?= escapar($sla['detalle']) ?>">
                            <i class="fa-solid fa-stopwatch" aria-hidden="true"></i>
                            SLA: <?= escapar($sla['etiqueta']) ?>
                        </span>
                    </li>
                    <?php if ((int) $incidencia['archivado'] === 1): ?>
                        <li><span class="s-insignia s-insignia-archivado">Archivado</span></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="s-detalle-responsable">
                <span class="s-detalle-responsable-etiqueta">Técnico responsable</span>
                <?php if ($sin_responsable): ?>
                    <span class="s-persona s-persona-sin-asignar">
                        <span class="s-avatar s-avatar-vacio" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                        <?= escapar($responsable) ?>
                    </span>
                <?php else: ?>
                    <span class="s-persona">
                        <span class="s-avatar" aria-hidden="true"><?= escapar($iniciales($responsable)) ?></span>
                        <?= escapar($responsable) ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- ACCIONES SEGÚN EL ROL (mismas URLs y condiciones) -->
            <nav class="s-detalle-acciones" aria-label="Acciones del ticket">
                <a href="editar.php?id=<?= (int) $id ?>" class="s-btn s-btn-secundario">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i> Editar
                </a>
                <a href="estado.php?id=<?= (int) $id ?>" class="s-btn s-btn-secundario">
                    <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Cambiar estado
                </a>
                <?php if ($_SESSION['admin_rol'] === 'admin'): ?>
                    <a href="asignar.php?id=<?= (int) $id ?>" class="s-btn s-btn-secundario">
                        <i class="fa-solid fa-user-gear" aria-hidden="true"></i> Asignar técnico
                    </a>
                    <a href="archivar.php?id=<?= (int) $id ?>" class="s-btn s-btn-riesgo">
                        <i class="fa-solid <?= (int) $incidencia['archivado'] === 1 ? 'fa-box-open' : 'fa-box-archive' ?>" aria-hidden="true"></i>
                        <?= (int) $incidencia['archivado'] === 1 ? 'Restaurar ticket' : 'Archivar ticket' ?>
                    </a>
                <?php endif; ?>
            </nav>
        </section>

        <?php if ((int) $incidencia['archivado'] === 1): ?>
            <div class="s-aviso s-aviso-info s-detalle-aviso">
                <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                <div>Ticket archivado el <?= escapar(fechaHistorialLocal($incidencia['fecha_archivado'])) ?> (Costa Rica), por el usuario #<?= (int) $incidencia['archivado_por'] ?>. Su información y seguimiento se conservan.</div>
            </div>
        <?php endif; ?>

        <div class="s-detalle-rejilla">

            <!-- COLUMNA PRINCIPAL: DESCRIPCIÓN E HISTORIAL -->
            <div class="s-detalle-principal">

                <section class="s-detalle-tarjeta" aria-labelledby="titulo-descripcion">
                    <div class="s-detalle-cabecera">
                        <span class="s-detalle-icono"><i class="fa-solid fa-align-left" aria-hidden="true"></i></span>
                        <div>
                            <h3 id="titulo-descripcion" class="s-detalle-subtitulo">Descripción del problema</h3>
                            <p>Reportado por <?= escapar($incidencia['reportado_por']) ?> · <?= escapar($incidencia['laboratorio']) ?> · <?= escapar($incidencia['equipo']) ?></p>
                        </div>
                    </div>
                    <p class="s-detalle-descripcion historial-texto"><?= escapar($incidencia['descripcion']) ?></p>
                </section>

                <section id="historial" class="s-detalle-tarjeta s-historial" aria-labelledby="titulo-historial">
                    <div class="s-detalle-cabecera s-historial-cabecera">
                        <span class="s-detalle-icono"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                        <div>
                            <h3 id="titulo-historial" class="s-detalle-subtitulo">Historial y seguimiento</h3>
                            <p>Del más antiguo al más reciente. Horas de Costa Rica (UTC−6).</p>
                        </div>
                        <span class="s-historial-total"><?= count($historial) ?> registro(s)</span>
                    </div>

                    <?php if (!$historial): ?>
                        <div class="s-vacio s-historial-vacio">
                            <span class="s-vacio-icono"><i class="fa-regular fa-clock" aria-hidden="true"></i></span>
                            <strong class="s-vacio-titulo">No hay seguimiento registrado todavía.</strong>
                            <p>Los cambios y comentarios sobre este ticket aparecerán aquí en orden cronológico.</p>
                        </div>
                    <?php else: ?>
                        <ol class="s-linea-tiempo">
                        <?php foreach ($historial as $evento): ?>
                            <?php
                            $es_comentario = $evento['tipo'] === 'comentario';
                            [$clase_evento, $icono_evento] = $iconos_evento[$evento['tipo']] ?? ['', 'fa-circle'];
                            ?>
                            <li class="s-evento <?= $clase_evento ?> historial-item <?= $es_comentario ? 'historial-comentario' : 'historial-evento' ?>" data-evento-id="<?= (int) $evento['id'] ?>">
                                <span class="s-evento-icono" aria-hidden="true"><i class="fa-solid <?= $icono_evento ?>"></i></span>

                                <div class="s-evento-meta">
                                    <strong class="s-evento-tipo"><?= escapar($tipos[$evento['tipo']] ?? $evento['tipo']) ?></strong>
                                    <span class="s-evento-autor">
                                        <?= escapar($evento['autor']) ?>
                                        <span class="s-mono">· Usuario #<?= (int) $evento['usuario_id'] ?></span>
                                    </span>
                                    <time class="s-evento-tiempo" datetime="<?= escapar(str_replace(' ', 'T', $evento['fecha']) . 'Z') ?>"><?= escapar(fechaHistorialLocal($evento['fecha'])) ?></time>
                                </div>

                                <p class="<?= $es_comentario ? 's-evento-cuerpo' : 's-evento-detalle' ?> historial-texto"><?= escapar($evento['detalle']) ?></p>

                                <?php if ($evento['tipo'] === 'asignacion'): ?>
                                    <p class="s-cambio">
                                        <span class="s-cambio-valor"><?= escapar($evento['tecnico_anterior'] ?? 'Sin asignar') ?></span>
                                        <span class="s-cambio-flecha" aria-hidden="true">→</span><span class="s-solo-lector">a</span>
                                        <span class="s-cambio-valor s-cambio-nuevo"><?= escapar($evento['tecnico_nuevo'] ?? 'Sin asignar') ?></span>
                                    </p>
                                <?php elseif ($evento['tipo'] === 'cambio_estado'): ?>
                                    <p class="s-cambio">
                                        <span class="s-insignia s-insignia-<?= $tono_estado($evento['estado_anterior']) ?>"><?= escapar($evento['estado_anterior']) ?></span>
                                        <span class="s-cambio-flecha" aria-hidden="true">→</span><span class="s-solo-lector">a</span>
                                        <span class="s-insignia s-insignia-<?= $tono_estado($evento['estado_nuevo']) ?>"><?= escapar($evento['estado_nuevo']) ?></span>
                                    </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <!-- NUEVO COMENTARIO (mismo destino, campos y validación) -->
                    <div class="s-comentar">
                        <?php if ($error !== ''): ?>
                            <div role="alert" class="s-aviso s-aviso-error">
                                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                                <div><?= escapar($error) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_GET['comentado']) && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
                            <div role="status" class="s-aviso s-aviso-exito">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                <div>Comentario guardado.</div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="detalle.php?id=<?= (int) $id ?>#historial" class="s-formulario s-comentar-form">
                            <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
                            <div class="s-campo">
                                <label for="comentario" class="s-etiqueta">
                                    <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
                                    Agregar comentario de seguimiento <span class="s-obligatorio" aria-hidden="true">*</span>
                                </label>
                                <textarea id="comentario" name="comentario" rows="4" maxlength="3000" class="s-control" data-contador
                                          placeholder="Describe la revisión realizada, el avance o el próximo paso…" required><?= escapar($comentario) ?></textarea>
                                <span class="s-ayuda">Máximo 3000 caracteres. Una vez guardado no se puede editar ni eliminar.</span>
                            </div>
                            <div class="s-comentar-acciones">
                                <span class="s-comentar-autor">
                                    <span class="s-avatar" aria-hidden="true"><?= escapar($iniciales((string) $_SESSION['admin_usuario'])) ?></span>
                                    <span class="s-comentar-autor-texto">
                                        <small>Comentarás como</small>
                                        <strong><?= escapar($_SESSION['admin_usuario']) ?></strong>
                                    </span>
                                </span>
                                <button type="submit" class="s-btn s-btn-primario">
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                    Guardar comentario
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- COLUMNA LATERAL: DATOS DE LA INCIDENCIA -->
            <aside class="s-ficha s-detalle-ficha" aria-labelledby="titulo-datos">
                <span class="s-rotulo" id="titulo-datos">Datos de la incidencia</span>
                <p class="s-ficha-numero s-mono"><?= $numero_ticket ?></p>
                <p class="s-ficha-titulo"><?= escapar($incidencia['titulo'] ?? 'Sin título') ?></p>
                <dl class="s-ficha-datos detalle-datos">
                    <?php foreach (['Categoría' => $categorias[$incidencia['categoria']] ?? 'Sin categoría',
                        'Reportado por' => $incidencia['reportado_por'], 'Laboratorio' => $incidencia['laboratorio'],
                        'Equipo' => $incidencia['equipo'], 'Prioridad' => $incidencia['prioridad'],
                        'Estado' => $incidencia['estado'], 'Técnico' => $responsable] as $etiqueta => $valor): ?>
                        <div><dt><?= escapar($etiqueta) ?></dt><dd><?= escapar($valor) ?></dd></div>
                    <?php endforeach; ?>
                </dl>

                <!-- ACUERDO DE NIVEL DE SERVICIO -->
                <div class="s-ficha-bloque" aria-labelledby="titulo-sla">
                    <span class="s-rotulo" id="titulo-sla">Acuerdo de nivel de servicio</span>
                    <p class="s-sla-resumen">
                        <span class="s-sla s-sla-<?= escapar($sla['tono']) ?>">
                            <i class="fa-solid fa-stopwatch" aria-hidden="true"></i> <?= escapar($sla['etiqueta']) ?>
                        </span>
                        <span><?= escapar($sla['detalle']) ?></span>
                    </p>
                    <dl class="s-ficha-datos">
                        <div><dt>Plazo (<?= escapar($incidencia['prioridad']) ?>)</dt><dd><?= (int) (slaLimiteSegundos($incidencia['prioridad']) / 3600) ?> horas</dd></div>
                        <?php if ($sla['limite_epoch'] !== null): ?>
                            <div><dt>Límite de resolución</dt><dd><?= escapar(fechaLocalDesdeEpoch($sla['limite_epoch'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- ENCUESTA DEL SOLICITANTE -->
                <div class="s-ficha-bloque" aria-labelledby="titulo-encuesta-ficha">
                    <span class="s-rotulo" id="titulo-encuesta-ficha">Encuesta de satisfacción</span>
                    <?php if ($encuesta !== null): ?>
                        <div class="s-encuesta-resultado">
                            <?= estrellasHtml((int) $encuesta['calificacion']) ?>
                            <strong><?= (int) $encuesta['calificacion'] ?>/5 · <?= escapar(ENCUESTA_ETIQUETAS[(int) $encuesta['calificacion']]) ?></strong>
                            <?php if ($encuesta['comentario'] !== null): ?>
                                <p class="historial-texto">«<?= escapar($encuesta['comentario']) ?>»</p>
                            <?php endif; ?>
                            <small>Respondida el <?= escapar(fechaHistorialLocal($encuesta['fecha'])) ?></small>
                        </div>
                    <?php elseif ($incidencia['estado'] === 'Resuelta'): ?>
                        <p class="s-ficha-nota">Pendiente de respuesta del solicitante.</p>
                    <?php else: ?>
                        <p class="s-ficha-nota">Disponible para el solicitante cuando el ticket esté resuelto.</p>
                    <?php endif; ?>
                </div>

                <!-- CÓDIGO DE SEGUIMIENTO DEL SOLICITANTE -->
                <div class="s-ficha-bloque" id="codigo" aria-labelledby="titulo-codigo">
                    <span class="s-rotulo" id="titulo-codigo">Código de seguimiento</span>
                    <?php if ($codigo_generado !== null): ?>
                        <div class="s-aviso s-aviso-alerta" role="status">
                            <i class="fa-solid fa-key" aria-hidden="true"></i>
                            <div>
                                Nuevo código: <strong class="s-mono"><?= escapar($codigo_generado) ?></strong><br>
                                Entrégalo al solicitante ahora: no volverá a mostrarse.
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="s-ficha-nota">
                            <?= $incidencia['codigo_seguimiento_hash'] === null
                                ? 'Este ticket no tiene código. Genera uno para que el solicitante pueda consultarlo y responder la encuesta.'
                                : 'El solicitante tiene un código activo. Si lo perdió, genera uno nuevo (el anterior dejará de funcionar).' ?>
                        </p>
                    <?php endif; ?>
                    <form method="POST" action="detalle.php?id=<?= (int) $id ?>">
                        <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="accion" value="codigo">
                        <button type="submit" class="s-btn s-btn-secundario s-btn-sm"
                                onclick="return confirm('¿Generar un nuevo código de seguimiento? El código anterior dejará de funcionar.');">
                            <i class="fa-solid fa-key" aria-hidden="true"></i> Generar código nuevo
                        </button>
                    </form>
                </div>
            </aside>

        </div>
    </main>
</div>
</body>
</html>
