<?php
/**
 * Menú lateral compartido del área administrativa (identidad «Señal»).
 *
 * Este archivo SOLO genera HTML: no consulta la base de datos, no inicia
 * sesión y no concede permisos. Ocultar un enlace es una ayuda visual;
 * la protección real sigue en cada página (usuarioAutenticado(),
 * exigirAdministrador() e incidenciaPermitida()).
 *
 * Variables que debe definir la página antes de incluirlo:
 *   $usuario_actual  array  Resultado de usuarioAutenticado() (id, usuario, rol).
 *   $nav_activa      string 'panel' | 'archivados' | 'usuarios' | 'ticket' | 'listado' | 'registrar'.
 *   $nav_ticket      array|null  Solo en páginas de un ticket:
 *                    ['id' => int, 'seccion' => 'detalle'|'editar'|'estado'|'asignar'|'archivar',
 *                     'archivado' => bool]
 *
 * Variables opcionales para páginas fuera de admin/ (por ejemplo, incidencias.php):
 *   $nav_raiz        string Ruta a la raíz del proyecto (por defecto '../').
 *   $nav_admin       string Ruta a la carpeta admin/ (por defecto '', ya estamos en ella).
 *
 * Todas las variables internas usan el prefijo $nav_ y se eliminan al final
 * para no interferir con las variables de la página.
 */

require_once __DIR__ . '/recursos.php';

$nav_usuario = (string) ($usuario_actual['usuario'] ?? '');
$nav_es_admin = ($usuario_actual['rol'] ?? '') === 'admin';
$nav_activa = $nav_activa ?? 'panel';
$nav_ticket = $nav_ticket ?? null;
$nav_raiz = $nav_raiz ?? '../';
$nav_admin = $nav_admin ?? '';
$nav_version_js = (int) @filemtime(__DIR__ . '/../js/navegacion.js');

$nav_e = static fn($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Iniciales para el avatar (admite acentos y ñ).
$nav_iniciales = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $nav_usuario), 0, 2, 'UTF-8'), 'UTF-8');
if ($nav_iniciales === '') {
    $nav_iniciales = '?';
}
$nav_rol_texto = $nav_es_admin ? 'Administrador' : 'Técnico de soporte';

// Devuelve los atributos de un enlace activo.
$nav_actual = static fn(bool $activo): string => $activo ? ' class="s-menu-enlace s-activo" aria-current="page"' : ' class="s-menu-enlace"';

$nav_ticket_id = $nav_ticket ? (int) $nav_ticket['id'] : 0;
$nav_seccion = $nav_ticket['seccion'] ?? '';
?>
<script>document.documentElement.classList.add('s-js');</script>

<!-- BARRA SUPERIOR MÓVIL (visible solo en pantallas pequeñas) -->
<div class="s-barra-movil">
    <button type="button" class="s-menu-boton" data-menu-abrir
            aria-controls="s-menu" aria-expanded="false" aria-label="Abrir menú de navegación">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
    </button>
    <a href="<?= $nav_admin ?>index.php" class="s-marca s-marca-compacta">
        <?= logotipoSenal($nav_raiz, 'oscuro', 32) ?>
        <strong>Soporte TI</strong>
    </a>
    <span class="s-avatar" title="<?= $nav_e($nav_usuario . ' · ' . $nav_rol_texto) ?>" aria-hidden="true"><?= $nav_e($nav_iniciales) ?></span>
</div>

<div class="s-menu-velo" data-menu-cerrar hidden></div>

<!-- MENÚ LATERAL -->
<aside id="s-menu" class="s-menu" aria-label="Navegación principal">

    <div class="s-menu-cabecera">
        <a href="<?= $nav_admin ?>index.php" class="s-marca">
            <?= logotipoSenal($nav_raiz, 'oscuro', 40) ?>
            <span class="s-marca-texto">
                <strong>Soporte TI</strong>
                <small>Gestión de incidencias</small>
            </span>
        </a>
        <button type="button" class="s-menu-cerrar" data-menu-cerrar aria-label="Cerrar menú de navegación">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
    <span class="s-pulso" aria-hidden="true"></span>

    <nav class="s-menu-nav">

        <p class="s-menu-grupo">Operación</p>
        <ul>
            <li>
                <a href="<?= $nav_admin ?>index.php"<?= $nav_actual($nav_activa === 'panel') ?>>
                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                    <span><?= $nav_es_admin ? 'Panel de control' : 'Mi panel' ?></span>
                </a>
            </li>
            <?php if ($nav_es_admin): ?>
                <li>
                    <a href="<?= $nav_admin ?>index.php?archivados=1"<?= $nav_actual($nav_activa === 'archivados') ?>>
                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                        <span>Tickets archivados</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <?php if ($nav_ticket_id > 0): ?>
            <p class="s-menu-grupo">
                Ticket <span class="s-mono">#<?= $nav_e(str_pad((string) $nav_ticket_id, 4, '0', STR_PAD_LEFT)) ?></span>
            </p>
            <ul>
                <li>
                    <a href="<?= $nav_admin ?>detalle.php?id=<?= $nav_ticket_id ?>"<?= $nav_actual($nav_seccion === 'detalle') ?>>
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                        <span>Detalle e historial</span>
                    </a>
                </li>
                <li>
                    <a href="<?= $nav_admin ?>editar.php?id=<?= $nav_ticket_id ?>"<?= $nav_actual($nav_seccion === 'editar') ?>>
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                        <span>Editar ticket</span>
                    </a>
                </li>
                <li>
                    <a href="<?= $nav_admin ?>estado.php?id=<?= $nav_ticket_id ?>"<?= $nav_actual($nav_seccion === 'estado') ?>>
                        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                        <span>Cambiar estado</span>
                    </a>
                </li>
                <?php if ($nav_es_admin): ?>
                    <li>
                        <a href="<?= $nav_admin ?>asignar.php?id=<?= $nav_ticket_id ?>"<?= $nav_actual($nav_seccion === 'asignar') ?>>
                            <i class="fa-solid fa-user-gear" aria-hidden="true"></i>
                            <span>Asignar técnico</span>
                        </a>
                    </li>
                    <li>
                        <a href="<?= $nav_admin ?>archivar.php?id=<?= $nav_ticket_id ?>"<?= $nav_actual($nav_seccion === 'archivar') ?>>
                            <i class="fa-solid <?= !empty($nav_ticket['archivado']) ? 'fa-box-open' : 'fa-box-archive' ?>" aria-hidden="true"></i>
                            <span><?= !empty($nav_ticket['archivado']) ? 'Restaurar ticket' : 'Archivar ticket' ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($nav_es_admin): ?>
            <p class="s-menu-grupo">Administración</p>
            <ul>
                <li>
                    <a href="<?= $nav_admin ?>usuarios.php"<?= $nav_actual($nav_activa === 'usuarios') ?>>
                        <i class="fa-solid fa-users-gear" aria-hidden="true"></i>
                        <span>Gestión de usuarios</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <p class="s-menu-grupo">Portal del solicitante</p>
        <ul>
            <li>
                <a href="<?= $nav_raiz ?>registrar.php"<?= $nav_actual($nav_activa === 'registrar') ?>>
                    <i class="fa-solid fa-circle-plus" aria-hidden="true"></i>
                    <span>Registrar ticket</span>
                </a>
            </li>
            <li>
                <a href="<?= $nav_raiz ?>incidencias.php"<?= $nav_actual($nav_activa === 'listado') ?>>
                    <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                    <span>Consulta de incidencias</span>
                </a>
            </li>
            <li>
                <a href="<?= $nav_raiz ?>index.php" class="s-menu-enlace">
                    <i class="fa-solid fa-house" aria-hidden="true"></i>
                    <span>Página principal</span>
                </a>
            </li>
        </ul>

    </nav>

    <!-- IDENTIFICACIÓN DEL USUARIO -->
    <div class="s-menu-usuario">
        <span class="s-avatar" aria-hidden="true"><?= $nav_e($nav_iniciales) ?></span>
        <span class="s-menu-usuario-datos">
            <strong><?= $nav_e($nav_usuario) ?></strong>
            <small class="<?= $nav_es_admin ? 's-rol-admin' : 's-rol-tecnico' ?>"><?= $nav_e($nav_rol_texto) ?></small>
        </span>
        <form method="POST" action="<?= $nav_admin ?>logout.php" class="s-form-salir">
            <input type="hidden" name="logout_csrf_token" value="<?= $nav_e(tokenCsrfLogout()) ?>">
            <button type="submit" class="s-menu-salir" title="Cerrar sesión" aria-label="Cerrar sesión">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            </button>
        </form>
    </div>

</aside>

<script src="<?= $nav_raiz ?>js/navegacion.js?v=<?= $nav_version_js ?>" defer></script>
<?php
unset($nav_usuario, $nav_es_admin, $nav_e, $nav_iniciales, $nav_rol_texto,
      $nav_actual, $nav_ticket_id, $nav_seccion, $nav_raiz, $nav_admin, $nav_version_js);
