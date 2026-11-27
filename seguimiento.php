<?php
/*
 * Consulta pública del ticket y encuesta de satisfacción del solicitante.
 *
 * El solicitante no tiene cuenta: se identifica con el número de ticket y el
 * código de seguimiento que recibió al registrarlo. La autorización se guarda
 * en su sesión junto con el hash vigente; si el personal genera un código
 * nuevo, la autorización anterior deja de ser válida.
 */
require_once __DIR__ . '/config/sesion.php';
require_once __DIR__ . '/config/parametros.php';
require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/autorizacion.php';
require_once __DIR__ . '/config/historial.php';
require_once __DIR__ . '/config/encuestas.php';
require_once __DIR__ . '/config/estados.php';
require_once __DIR__ . '/config/confirmaciones.php';

$usuario_actual = usuarioAutenticado($conn, 'login.php', false);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Límite básico contra intentos repetidos de adivinar códigos (por sesión).
const SEGUIMIENTO_MAX_INTENTOS = 5;
const SEGUIMIENTO_BLOQUEO_SEGUNDOS = 600;

$error = '';
$error_confirmacion = '';
$respuesta_ingresada = '';
$comentario_confirmacion = '';
$numero_ingresado = '';
$autorizados = is_array($_SESSION['seguimiento_tickets'] ?? null) ? $_SESSION['seguimiento_tickets'] : [];

// Devuelve el ticket solo si la sesión lo autorizó con el código vigente.
$ticketAutorizado = static function (int $id) use ($conn, $autorizados): ?array {
    if (!isset($autorizados[$id])) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, titulo, categoria, prioridad, estado, laboratorio, equipo, tecnico_id,
        archivado, UNIX_TIMESTAMP(fecha_registro) AS registro_epoch, fecha_resolucion, fecha_cierre, codigo_seguimiento_hash
        FROM incidencias WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ticket || $ticket['codigo_seguimiento_hash'] === null ||
        !hash_equals($ticket['codigo_seguimiento_hash'], (string) $autorizados[$id])) {
        return null;
    }
    return $ticket;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarParametrosTexto($_POST, ['accion', 'numero', 'codigo', 'ticket', 'calificacion', 'comentario',
        'confirmacion', 'respuesta', 'comentario_confirmacion']);
    $token = $_POST['csrf_token'] ?? '';
    $accion = $_POST['accion'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        $error = 'La sesión del formulario no es válida. Actualiza la página.';

    } elseif ($accion === 'consultar') {
        $numero_ingresado = trim($_POST['numero'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $intentos = $_SESSION['seguimiento_intentos'] ?? ['cantidad' => 0, 'desde' => time()];
        if (time() - $intentos['desde'] > SEGUIMIENTO_BLOQUEO_SEGUNDOS) {
            $intentos = ['cantidad' => 0, 'desde' => time()];
        }
        $numero = (int) preg_replace('/\D/', '', $numero_ingresado);

        if ($intentos['cantidad'] >= SEGUIMIENTO_MAX_INTENTOS) {
            http_response_code(429);
            $error = 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo.';
        } elseif ($numero <= 0 || $codigo === '' || mb_strlen($codigo, 'UTF-8') > 30) {
            http_response_code(422);
            $error = 'Escribe el número de ticket y el código de seguimiento.';
        } else {
            $stmt = $conn->prepare('SELECT codigo_seguimiento_hash FROM incidencias WHERE id = ?');
            $stmt->bind_param('i', $numero);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $hash = hashCodigoSeguimiento($codigo);
            if ($fila && $fila['codigo_seguimiento_hash'] !== null && hash_equals($fila['codigo_seguimiento_hash'], $hash)) {
                unset($_SESSION['seguimiento_intentos']);
                $autorizados[$numero] = $hash;
                // Conservar solo los 10 tickets consultados más recientemente.
                $_SESSION['seguimiento_tickets'] = array_slice($autorizados, -10, null, true);
                header('Location: seguimiento.php?ticket=' . $numero);
                exit;
            }
            $intentos['cantidad']++;
            $_SESSION['seguimiento_intentos'] = $intentos;
            http_response_code(422);
            // Mismo mensaje para ticket inexistente y código incorrecto.
            $error = 'El número de ticket o el código de seguimiento no coinciden.';
        }

    } elseif ($accion === 'encuesta') {
        $id = filter_var($_POST['ticket'] ?? null, FILTER_VALIDATE_INT);
        $calificacion = filter_var($_POST['calificacion'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $comentario = trim($_POST['comentario'] ?? '');
        if (!$id || $ticketAutorizado($id) === null) {
            http_response_code(403);
            $error = 'Vuelve a consultar tu ticket con el número y el código de seguimiento.';
        } elseif ($calificacion === false) {
            http_response_code(422);
            $error = 'Selecciona una calificación de 1 a 5 estrellas.';
            $_GET['ticket'] = (string) $id;
        } elseif (mb_strlen($comentario, 'UTF-8') > ENCUESTA_COMENTARIO_MAX) {
            http_response_code(422);
            $error = 'El comentario no puede superar los ' . ENCUESTA_COMENTARIO_MAX . ' caracteres.';
            $_GET['ticket'] = (string) $id;
        } else {
            try {
                $conn->begin_transaction();
                // Bloquear el ticket: el estado podría cambiar mientras se responde.
                $stmt = $conn->prepare('SELECT estado FROM incidencias WHERE id = ? FOR UPDATE');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $estado_actual = $stmt->get_result()->fetch_assoc()['estado'] ?? null;
                $stmt->close();
                // Resuelta o Cerrado: cerrar el ticket no impide calificar la atención.
                if (!estadoResuelto($estado_actual)) {
                    $conn->rollback();
                    http_response_code(409);
                    $error = 'La encuesta solo está disponible cuando el ticket está resuelto o cerrado.';
                } elseif (encuestaDeIncidencia($conn, $id) !== null) {
                    $conn->rollback();
                    http_response_code(409);
                    $error = 'Esta encuesta ya fue respondida. ¡Gracias!';
                } else {
                    $comentario_guardado = $comentario === '' ? null : $comentario;
                    $stmt = $conn->prepare('INSERT INTO encuestas_satisfaccion (incidencia_id, calificacion, comentario)
                        VALUES (?, ?, ?)');
                    $stmt->bind_param('iis', $id, $calificacion, $comentario_guardado);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    header('Location: seguimiento.php?ticket=' . $id . '&encuesta=1');
                    exit;
                }
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ((int) $e->getCode() === 1062) {
                    $error = 'Esta encuesta ya fue respondida. ¡Gracias!';
                } else {
                    error_log('Error al guardar encuesta: ' . $e->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo guardar la encuesta. Inténtalo de nuevo.';
                }
            }
            $_GET['ticket'] = (string) $id;
        }
    } elseif ($accion === 'confirmar') {
        // Respuesta a la pregunta del tecnico («¿ya funciona?»). Solo informa: no cambia el estado.
        $id = filter_var($_POST['ticket'] ?? null, FILTER_VALIDATE_INT);
        $confirmacion_id = filter_var($_POST['confirmacion'] ?? null, FILTER_VALIDATE_INT);
        $respuesta_ingresada = (string) ($_POST['respuesta'] ?? '');
        $comentario_confirmacion = trim((string) ($_POST['comentario_confirmacion'] ?? ''));
        $motivo = motivoRespuestaInvalida($respuesta_ingresada, $comentario_confirmacion);
        if (!$id || $ticketAutorizado($id) === null) {
            http_response_code(403);
            $error = 'Vuelve a consultar tu ticket con el número y el código de seguimiento.';
        } elseif (!$confirmacion_id) {
            http_response_code(422);
            $error_confirmacion = 'La pregunta no es válida. Actualiza la página.';
            $_GET['ticket'] = (string) $id;
        } elseif ($motivo !== null) {
            http_response_code(422);
            $error_confirmacion = $motivo;
            $_GET['ticket'] = (string) $id;
        } else {
            try {
                $conn->begin_transaction();
                // Bloquear el ticket: el estado podria cambiar mientras se responde.
                $stmt = $conn->prepare('SELECT estado FROM incidencias WHERE id = ? FOR UPDATE');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $estado_actual = $stmt->get_result()->fetch_assoc()['estado'] ?? null;
                $stmt->close();
                if (!in_array($estado_actual, ESTADOS_CONFIRMACION, true)) {
                    $conn->rollback();
                    http_response_code(409);
                    $error_confirmacion = 'Esta pregunta ya no admite respuesta porque cambió el estado del ticket.';
                } else {
                    $comentario_guardado = $comentario_confirmacion === '' ? null : $comentario_confirmacion;
                    // Solo la pregunta pendiente de este ticket: una respuesta por pregunta.
                    $stmt = $conn->prepare('UPDATE confirmaciones_solicitante
                        SET respuesta = ?, comentario = ?, fecha_respuesta = UTC_TIMESTAMP()
                        WHERE id = ? AND incidencia_id = ? AND respuesta IS NULL');
                    $stmt->bind_param('ssii', $respuesta_ingresada, $comentario_guardado, $confirmacion_id, $id);
                    $stmt->execute();
                    $actualizadas = $stmt->affected_rows;
                    $stmt->close();
                    if ($actualizadas !== 1) {
                        $conn->rollback();
                        http_response_code(409);
                        $error_confirmacion = 'Esta pregunta ya fue respondida.';
                    } else {
                        $conn->commit();
                        header('Location: seguimiento.php?ticket=' . $id . '&confirmado=1#confirmacion');
                        exit;
                    }
                }
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log('Error al guardar confirmacion: ' . $e->getMessage());
                http_response_code(500);
                $error_confirmacion = 'No se pudo guardar tu respuesta. Inténtalo de nuevo.';
            }
            $_GET['ticket'] = (string) $id;
        }
    } else {
        http_response_code(422);
        $error = 'Operación no válida.';
    }
}

validarParametrosTexto($_GET, ['ticket', 'encuesta', 'confirmado']);
$ticket = null;
$encuesta = null;
$id_consultado = filter_var($_GET['ticket'] ?? null, FILTER_VALIDATE_INT);
if ($id_consultado && $id_consultado > 0) {
    $ticket = $ticketAutorizado($id_consultado);
    if ($ticket === null && $error === '') {
        $error = 'Para ver un ticket, escribe su número y el código de seguimiento.';
    }
}
$pregunta_pendiente = null;
$ultima_respuesta = null;
if ($ticket !== null) {
    $encuesta = encuestaDeIncidencia($conn, (int) $ticket['id']);
    // La pregunta pendiente solo se ofrece mientras el ticket admite respuesta.
    if (in_array($ticket['estado'], ESTADOS_CONFIRMACION, true)) {
        $pregunta_pendiente = confirmacionPendiente($conn, (int) $ticket['id']);
    }
    foreach (confirmacionesDeIncidencia($conn, (int) $ticket['id']) as $confirmacion) {
        if ($confirmacion['respuesta'] !== null) {
            $ultima_respuesta = $confirmacion;
        }
    }
}

function escapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once __DIR__ . '/includes/recursos.php';
$categorias = ['hardware' => 'Hardware', 'software' => 'Software', 'red' => 'Red'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultar mi ticket | Soporte TI</title>
    <?= faviconSenal() ?>
    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/formularios.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/publico.css') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/calidad.css') ?>">
    <script src="<?= recursoVersionado('js/formularios.js') ?>" defer></script>
</head>
<body class="s-app s-publico">
<?php
$pub_activa = 'seguimiento';
require __DIR__ . '/includes/cabecera_publica.php';
?>
<main id="contenido" class="s-pub-contenedor s-pub-pagina">

    <header class="dashboard-topbar">
        <div>
            <span class="topbar-etiqueta">Centro de soporte</span>
            <h1>Consultar mi ticket</h1>
            <p>Revisa el avance de tu incidencia y califica la atención cuando esté resuelta o cerrada.</p>
        </div>
        <?php if ($ticket !== null): ?>
            <a href="seguimiento.php" class="btn-limpiar">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                Consultar otro ticket
            </a>
        <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?>
        <div class="s-aviso s-aviso-error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div><?= escapar($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($ticket === null): ?>

        <!-- FORMULARIO DE CONSULTA -->
        <div class="s-registro-rejilla">
            <section class="s-form-tarjeta" aria-labelledby="titulo-consulta">
                <div class="s-form-cabecera">
                    <span class="s-form-icono"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-consulta">Datos de tu ticket</h2>
                        <p>Los recibiste en pantalla al registrar la incidencia.</p>
                    </div>
                </div>
                <form method="POST" class="s-formulario">
                    <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="consultar">
                    <div class="s-form-rejilla">
                        <div class="s-campo">
                            <label for="numero" class="s-etiqueta">Número de ticket <span class="s-obligatorio" aria-hidden="true">*</span></label>
                            <input type="text" id="numero" name="numero" maxlength="12" class="s-control s-mono"
                                   inputmode="numeric" value="<?= escapar($numero_ingresado) ?>" placeholder="Ejemplo: #0012" required>
                        </div>
                        <div class="s-campo">
                            <label for="codigo" class="s-etiqueta">Código de seguimiento <span class="s-obligatorio" aria-hidden="true">*</span></label>
                            <input type="text" id="codigo" name="codigo" maxlength="30" class="s-control s-mono"
                                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                                   placeholder="Ejemplo: 3FA2-9C1B-07DE" required>
                        </div>
                    </div>
                    <div class="s-form-acciones">
                        <button type="submit" class="s-btn s-btn-primario">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            Consultar ticket
                        </button>
                    </div>
                </form>
            </section>

            <aside class="s-registro-guia" aria-labelledby="titulo-guia-seguimiento">
                <h2 id="titulo-guia-seguimiento" class="s-registro-guia-titulo">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    ¿Perdiste tu código?
                </h2>
                <p>Comunícate con el centro de informática indicando tu número de ticket. El personal de soporte puede generarte un código nuevo.</p>
                <p>¿Aún no reportas tu problema? <a href="registrar.php">Registra una incidencia</a>.</p>
            </aside>
        </div>

    <?php else: ?>

        <?php
        $numero_ticket = '#' . str_pad((string) (int) $ticket['id'], 4, '0', STR_PAD_LEFT);
        $pasos = ['Pendiente' => 1, 'En proceso' => 2, 'Resuelta' => 3, 'Cerrado' => 4];
        $paso_final = max($pasos);
        $paso_actual = $pasos[$ticket['estado']] ?? 1;
        $tono_estado = tonoEstado($ticket['estado']);
        ?>

        <?php if (isset($_GET['encuesta']) && $encuesta !== null): ?>
            <div class="s-aviso s-aviso-exito" role="status">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div><strong>¡Gracias por tu opinión!</strong> Tu calificación ayuda a mejorar el servicio de soporte.</div>
            </div>
        <?php endif; ?>

        <div class="s-registro-rejilla">
            <div class="s-seguimiento-principal">

                <!-- ESTADO DEL TICKET -->
                <section class="s-form-tarjeta" aria-labelledby="titulo-estado">
                    <div class="s-form-cabecera">
                        <span class="s-form-icono"><i class="fa-solid fa-route" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="titulo-estado">Ticket <span class="s-mono"><?= $numero_ticket ?></span></h2>
                            <p><?= escapar($ticket['titulo'] ?? 'Sin título') ?></p>
                        </div>
                    </div>

                    <ol class="s-progreso" aria-label="Avance del ticket">
                        <?php foreach (['Pendiente' => 'Registrado', 'En proceso' => 'En atención', 'Resuelta' => 'Resuelto', 'Cerrado' => 'Cerrado'] as $estado_paso => $texto_paso): ?>
                            <?php $n = $pasos[$estado_paso]; ?>
                            <li class="s-progreso-paso<?= $n < $paso_actual ? ' s-completado' : '' ?><?= $n === $paso_actual ? ' s-actual' : '' ?>"
                                <?= $n === $paso_actual ? 'aria-current="step"' : '' ?>>
                                <span class="s-progreso-punto" aria-hidden="true"><?= $n < $paso_actual || ($n === $paso_final && $paso_actual === $paso_final) ? '<i class="fa-solid fa-check"></i>' : $n ?></span>
                                <span><?= $texto_paso ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <?php if ((int) $ticket['archivado'] === 1): ?>
                        <div class="s-aviso s-aviso-info">
                            <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                            <div>El centro de soporte archivó este ticket. Su información se conserva.</div>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($pregunta_pendiente !== null || $ultima_respuesta !== null || $error_confirmacion !== ''): ?>
                <!-- CONFIRMACIÓN: EL TÉCNICO PREGUNTA SI YA FUNCIONA -->
                <section id="confirmacion" class="s-form-tarjeta s-confirmacion-solicitante" aria-labelledby="titulo-confirmacion">
                    <div class="s-form-cabecera">
                        <span class="s-form-icono"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="titulo-confirmacion">El técnico te pregunta</h2>
                            <p>Tu respuesta ayuda a confirmar si el problema quedó resuelto.</p>
                        </div>
                    </div>

                    <?php if ($error_confirmacion !== ''): ?>
                        <div class="s-aviso s-aviso-error" role="alert">
                            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                            <div><?= escapar($error_confirmacion) ?></div>
                        </div>
                    <?php elseif (isset($_GET['confirmado']) && $ultima_respuesta !== null): ?>
                        <div class="s-aviso s-aviso-exito" role="status">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                            <div><strong>¡Gracias!</strong> El técnico verá tu respuesta.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($pregunta_pendiente !== null): ?>
                        <p class="s-confirmacion-pregunta historial-texto"><strong>«<?= escapar($pregunta_pendiente['pregunta']) ?>»</strong></p>
                        <form method="POST" action="seguimiento.php?ticket=<?= (int) $ticket['id'] ?>#confirmacion" class="s-formulario">
                            <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="accion" value="confirmar">
                            <input type="hidden" name="ticket" value="<?= (int) $ticket['id'] ?>">
                            <input type="hidden" name="confirmacion" value="<?= (int) $pregunta_pendiente['id'] ?>">

                            <fieldset class="s-form-seccion">
                                <legend>Tu respuesta <span class="s-obligatorio" aria-hidden="true">*</span></legend>
                                <div class="s-opciones">
                                    <?php foreach (['funciona' => ['resuelta', 'fa-circle-check', 'El problema quedó resuelto.'],
                                        'sigue_fallando' => ['pendiente', 'fa-triangle-exclamation', 'Todavía hay una falla. Cuéntanos cuál.']] as $valor => [$tono, $icono, $texto]): ?>
                                        <label class="s-opcion s-opcion-<?= $tono ?>">
                                            <input type="radio" name="respuesta" value="<?= $valor ?>" <?= $respuesta_ingresada === $valor ? 'checked' : '' ?> required>
                                            <span class="s-opcion-cuerpo">
                                                <span class="s-opcion-icono"><i class="fa-solid <?= $icono ?>" aria-hidden="true"></i></span>
                                                <span class="s-opcion-texto">
                                                    <strong><?= escapar(CONFIRMACION_RESPUESTAS[$valor]) ?></strong>
                                                    <small><?= escapar($texto) ?></small>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <div class="s-campo">
                                <label for="comentario_confirmacion" class="s-etiqueta">Comentario</label>
                                <textarea id="comentario_confirmacion" name="comentario_confirmacion" rows="3"
                                          maxlength="<?= CONFIRMACION_COMENTARIO_MAX ?>" class="s-control" data-contador
                                          aria-describedby="ayuda-comentario-confirmacion"
                                          placeholder="¿Qué sigue fallando? ¿Cuándo ocurre?"><?= escapar($comentario_confirmacion) ?></textarea>
                                <div class="s-aviso s-aviso-info" id="ayuda-comentario-confirmacion">
                                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                    <div>Si eliges <strong>«No, el problema sigue»</strong>, el comentario es <strong>obligatorio</strong>: describe qué sigue fallando. Si ya funciona, es opcional.</div>
                                </div>
                            </div>

                            <div class="s-form-acciones">
                                <button type="submit" class="s-btn s-btn-primario">
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                    Enviar respuesta
                                </button>
                            </div>
                        </form>
                    <?php elseif ($ultima_respuesta !== null): ?>
                        <div class="s-encuesta-resultado">
                            <p class="historial-texto">«<?= escapar($ultima_respuesta['pregunta']) ?>»</p>
                            <strong><?= escapar(CONFIRMACION_RESPUESTAS[$ultima_respuesta['respuesta']]) ?></strong>
                            <?php if ($ultima_respuesta['comentario'] !== null): ?>
                                <p class="historial-texto">«<?= escapar($ultima_respuesta['comentario']) ?>»</p>
                            <?php endif; ?>
                            <small>Respondida el <?= escapar(fechaHistorialLocal($ultima_respuesta['fecha_respuesta'])) ?> (Costa Rica).</small>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <!-- ENCUESTA DE SATISFACCIÓN -->
                <section class="s-form-tarjeta s-encuesta" aria-labelledby="titulo-encuesta">
                    <div class="s-form-cabecera">
                        <span class="s-form-icono"><i class="fa-solid fa-star" aria-hidden="true"></i></span>
                        <div>
                            <h2 id="titulo-encuesta">Encuesta de satisfacción</h2>
                            <p>¿Cómo calificas la atención que recibiste?</p>
                        </div>
                    </div>

                    <?php if ($encuesta !== null): ?>
                        <div class="s-encuesta-resultado">
                            <?= estrellasHtml((int) $encuesta['calificacion']) ?>
                            <strong><?= (int) $encuesta['calificacion'] ?>/5 · <?= escapar(ENCUESTA_ETIQUETAS[(int) $encuesta['calificacion']]) ?></strong>
                            <?php if ($encuesta['comentario'] !== null): ?>
                                <p class="historial-texto">«<?= escapar($encuesta['comentario']) ?>»</p>
                            <?php endif; ?>
                            <small>Respondida el <?= escapar(fechaHistorialLocal($encuesta['fecha'])) ?> (Costa Rica). ¡Gracias!</small>
                        </div>
                    <?php elseif (!estadoResuelto($ticket['estado'])): ?>
                        <div class="s-aviso s-aviso-info">
                            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                            <div>La encuesta estará disponible cuando el personal de soporte marque tu ticket como resuelto.</div>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="s-formulario">
                            <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="accion" value="encuesta">
                            <input type="hidden" name="ticket" value="<?= (int) $ticket['id'] ?>">

                            <fieldset class="s-form-seccion">
                                <legend>Calificación <span class="s-obligatorio" aria-hidden="true">*</span></legend>
                                <div class="s-calificacion">
                                    <?php for ($nota = 5; $nota >= 1; $nota--): ?>
                                        <input type="radio" id="nota-<?= $nota ?>" name="calificacion" value="<?= $nota ?>" required>
                                        <label for="nota-<?= $nota ?>" title="<?= escapar(ENCUESTA_ETIQUETAS[$nota]) ?>">
                                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                                            <span class="s-solo-lector"><?= $nota ?> estrellas: <?= escapar(ENCUESTA_ETIQUETAS[$nota]) ?></span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                                <p class="s-ayuda">1 estrella = muy insatisfecho · 5 estrellas = muy satisfecho.</p>
                            </fieldset>

                            <div class="s-campo">
                                <label for="comentario" class="s-etiqueta">Comentario (opcional)</label>
                                <textarea id="comentario" name="comentario" rows="3" maxlength="<?= ENCUESTA_COMENTARIO_MAX ?>"
                                          class="s-control" data-contador
                                          placeholder="¿Qué te pareció la atención? ¿Qué podríamos mejorar?"></textarea>
                                <span class="s-ayuda">La encuesta solo puede responderse una vez.</span>
                            </div>

                            <div class="s-form-acciones">
                                <button type="submit" class="s-btn s-btn-primario">
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                    Enviar encuesta
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            </div>

            <!-- DATOS DEL TICKET -->
            <aside class="s-ficha" aria-label="Datos del ticket">
                <span class="s-rotulo">Datos del ticket</span>
                <p class="s-ficha-numero s-mono"><?= $numero_ticket ?></p>
                <dl class="s-ficha-datos">
                    <div><dt>Estado</dt><dd><span class="s-insignia s-insignia-<?= $tono_estado ?>"><?= escapar($ticket['estado']) ?></span></dd></div>
                    <div><dt>Prioridad</dt><dd><?= escapar($ticket['prioridad']) ?></dd></div>
                    <div><dt>Categoría</dt><dd><?= escapar($categorias[$ticket['categoria'] ?? ''] ?? 'Sin categoría') ?></dd></div>
                    <div><dt>Laboratorio</dt><dd><?= escapar($ticket['laboratorio']) ?></dd></div>
                    <div><dt>Equipo</dt><dd><?= escapar($ticket['equipo']) ?></dd></div>
                    <div><dt>Técnico</dt><dd><?= $ticket['tecnico_id'] === null ? 'Pendiente de asignación' : 'Asignado' ?></dd></div>
                    <div><dt>Registrado</dt><dd><?= (int) $ticket['registro_epoch'] > 0
                        ? escapar((new DateTimeImmutable('@' . (int) $ticket['registro_epoch']))
                            ->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('d/m/Y H:i'))
                        : 'Sin dato' ?></dd></div>
                    <?php if (estadoResuelto($ticket['estado']) && $ticket['fecha_resolucion'] !== null): ?>
                        <div><dt>Resuelto</dt><dd><?= escapar(fechaHistorialLocal($ticket['fecha_resolucion'])) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($ticket['estado'] === 'Cerrado' && $ticket['fecha_cierre'] !== null): ?>
                        <div><dt>Cerrado</dt><dd><?= escapar(fechaHistorialLocal($ticket['fecha_cierre'])) ?></dd></div>
                    <?php endif; ?>
                </dl>
            </aside>
        </div>

    <?php endif; ?>

</main>
<?php require __DIR__ . '/includes/pie_publico.php'; ?>
</body>
</html>
<?php $conn->close(); ?>
