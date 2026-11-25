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
    exit('No se encontro la incidencia.');
}
$incidencia = incidenciaPermitida($conn, $id, $usuario_actual);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';
$seleccionado = $incidencia['tecnico_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $tecnico_id = filter_var($_POST['tecnico_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        $error = 'Solicitud no válida. Actualiza la página.';
    } elseif (!$tecnico_id || $tecnico_id <= 0) {
        http_response_code(422);
        $error = 'Selecciona un técnico activo.';
    } else {
        try {
            $anterior = bloquearIncidenciaParaCambio($conn, $id, true);
            // Mantener al destinatario activo y con rol tecnico hasta el commit.
            $stmt = $conn->prepare(
                "SELECT id FROM usuarios WHERE id = ? AND rol = 'tecnico'
                 AND activo = 1 LOCK IN SHARE MODE"
            );
            $stmt->bind_param('i', $tecnico_id);
            $stmt->execute();
            $destinatario = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$destinatario) {
                $conn->rollback();
                http_response_code(422);
                $error = 'El usuario seleccionado no es un técnico activo.';
            } else {
                $stmt = $conn->prepare('UPDATE incidencias SET tecnico_id = ? WHERE id = ?');
                $stmt->bind_param('ii', $tecnico_id, $id);
                $stmt->execute();
                $stmt->close();
                if ($anterior['tecnico_id'] === null || (int) $anterior['tecnico_id'] !== $tecnico_id) {
                    registrarEventoIncidencia($conn, $id, (int) $_SESSION['admin_id'], 'asignacion',
                        $anterior['tecnico_id'] === null ? 'Se asignó un técnico al ticket.' : 'Se reasignó el ticket a otro técnico.',
                        null, null, $anterior['tecnico_id'], $tecnico_id);
                }
                $conn->commit();
                header('Location: index.php?asignado=1');
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Error al asignar incidencia: ' . $e->getMessage());
            http_response_code(500);
            $error = 'No se pudo guardar la asignación. Inténtalo de nuevo.';
        }
    }
    $seleccionado = $tecnico_id ?: $incidencia['tecnico_id'];
}

$tecnicos = $conn->query("SELECT id, usuario FROM usuarios WHERE rol = 'tecnico' AND activo = 1 ORDER BY usuario")->fetch_all(MYSQLI_ASSOC);
$responsable = 'Sin asignar';
if ($incidencia['tecnico_id'] !== null) {
    $stmt = $conn->prepare('SELECT usuario FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $incidencia['tecnico_id']);
    $stmt->execute();
    $responsable = $stmt->get_result()->fetch_assoc()['usuario'] ?? 'Sin asignar';
    $stmt->close();
}
function escapar($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar técnico | Soporte TI</title>
    <?php require_once __DIR__ . '/../includes/recursos.php'; ?>
    <?= faviconSenal('../') ?>
    <link rel="stylesheet" href="<?= recursoVersionado('css/tema.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/estilos.css', '../') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= recursoVersionado('css/navegacion.css', '../') ?>">
    <link rel="stylesheet" href="<?= recursoVersionado('css/formularios.css', '../') ?>">
    <script src="<?= recursoVersionado('js/formularios.js', '../') ?>" defer></script>
</head>
<body class="pagina-dashboard s-app">
<div class="dashboard-layout">
    <!-- MENÚ LATERAL COMPARTIDO (includes/sidebar.php) -->
    <?php
    $nav_activa = 'ticket';
    $nav_ticket = ['id' => (int) $id, 'seccion' => 'asignar', 'archivado' => (int) $incidencia['archivado'] === 1];
    require __DIR__ . '/../includes/sidebar.php';
    ?>
    <main class="dashboard-main">
        <header class="dashboard-topbar">
            <div>
                <span class="topbar-etiqueta">Gestión de tickets</span>
                <h1>Asignar técnico al ticket <span class="s-mono">#<?= str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT) ?></span></h1>
                <p>Selecciona al responsable de atender esta incidencia.</p>
            </div>
            <a href="index.php" class="btn-limpiar">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                Volver al panel
            </a>
        </header>

        <?php
        $iniciales_de = static fn(string $nombre): string =>
            mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $nombre), 0, 2, 'UTF-8'), 'UTF-8') ?: '?';
        $tono_prioridad_ficha = match ($incidencia['prioridad']) {
            'Alta' => 'alta',
            'Media' => 'media',
            default => 'baja'
        };
        $tono_estado_ficha = match ($incidencia['estado']) {
            'Resuelta' => 'resuelta',
            'En proceso' => 'proceso',
            'Cerrado' => 'cerrado',
            default => 'pendiente'
        };
        ?>
        <div class="s-form-pagina">

            <!-- FORMULARIO (mismo campo POST «tecnico_id») -->
            <section class="s-form-tarjeta" aria-labelledby="titulo-formulario">

                <div class="s-form-cabecera">
                    <span class="s-form-icono"><i class="fa-solid fa-user-gear" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="titulo-formulario">Técnico responsable</h2>
                        <p>Solo se muestran técnicos con cuenta activa.</p>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="s-aviso s-aviso-error" role="alert">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong>No se guardó la asignación.</strong>
                            <?= escapar($error) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!$tecnicos): ?>
                    <div class="s-aviso s-aviso-alerta" role="status">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <div>No hay técnicos activos disponibles. Revisa la gestión de usuarios.</div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="s-formulario">
                    <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">

                    <?php if ($tecnicos): ?>
                        <fieldset class="s-form-seccion">
                            <legend>Selecciona un técnico activo <span class="s-obligatorio" aria-hidden="true">*</span></legend>

                            <div class="s-opciones s-opciones-tecnicos">
                                <?php foreach ($tecnicos as $tecnico): ?>
                                    <?php $es_actual = $incidencia['tecnico_id'] !== null && (int) $incidencia['tecnico_id'] === (int) $tecnico['id']; ?>
                                    <label class="s-opcion s-opcion-tecnico">
                                        <input
                                            type="radio"
                                            name="tecnico_id"
                                            value="<?= (int) $tecnico['id'] ?>"
                                            <?= (int) $seleccionado === (int) $tecnico['id'] ? 'checked' : '' ?>
                                            required
                                        >
                                        <span class="s-opcion-cuerpo">
                                            <span class="s-avatar" aria-hidden="true"><?= escapar($iniciales_de($tecnico['usuario'])) ?></span>
                                            <span class="s-opcion-texto">
                                                <strong><?= escapar($tecnico['usuario']) ?></strong>
                                                <small>Técnico de soporte</small>
                                            </span>
                                            <?php if ($es_actual): ?>
                                                <span class="s-opcion-actual">Actual</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <div class="s-form-acciones">
                        <a href="index.php" class="s-btn s-btn-secundario">Cancelar</a>
                        <button type="submit" class="s-btn s-btn-primario" <?= !$tecnicos ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            Guardar asignación
                        </button>
                    </div>
                </form>
            </section>

            <!-- FICHA DEL TICKET -->
            <aside class="s-ficha" aria-label="Ticket seleccionado">
                <span class="s-rotulo">Ticket seleccionado</span>
                <p class="s-ficha-numero s-mono">#<?= str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT) ?></p>
                <p class="s-ficha-titulo"><?= escapar($incidencia['titulo'] ?? 'Sin título') ?></p>

                <dl class="s-ficha-datos">
                    <div>
                        <dt>Responsable actual</dt>
                        <dd>
                            <?php if ($incidencia['tecnico_id'] === null): ?>
                                <span class="s-persona s-persona-sin-asignar">Sin asignar</span>
                            <?php else: ?>
                                <span class="s-persona"><span class="s-avatar" aria-hidden="true"><?= escapar($iniciales_de($responsable)) ?></span><?= escapar($responsable) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Estado</dt>
                        <dd><span class="s-insignia s-insignia-<?= $tono_estado_ficha ?>"><?= escapar($incidencia['estado']) ?></span></dd>
                    </div>
                    <div>
                        <dt>Prioridad</dt>
                        <dd><span class="s-insignia s-insignia-<?= $tono_prioridad_ficha ?>"><?= escapar($incidencia['prioridad']) ?></span></dd>
                    </div>
                    <div>
                        <dt>Equipo</dt>
                        <dd><?= escapar($incidencia['equipo']) ?></dd>
                    </div>
                    <div>
                        <dt>Laboratorio</dt>
                        <dd><?= escapar($incidencia['laboratorio']) ?></dd>
                    </div>
                </dl>

                <div class="s-ficha-enlaces">
                    <a href="detalle.php?id=<?= (int) $id ?>" class="s-btn s-btn-fantasma s-btn-sm">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Ver historial
                    </a>
                </div>
            </aside>

        </div>
    </main>
</div>
</body>
</html>
<?php $conn->close(); ?>
