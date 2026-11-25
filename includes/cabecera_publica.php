<?php
/**
 * Barra superior compartida de las páginas de portal («Señal»):
 * index.php, registrar.php y seguimiento.php.
 *
 * Solo genera HTML. No consulta la base de datos ni concede permisos: mostrar
 * u ocultar un enlace es una ayuda visual; cada página mantiene su propio
 * control de acceso (por ejemplo, incidencias.php exige sesión).
 *
 * Variables que usa (definidas por la página):
 *   $usuario_actual  array|null  Resultado de usuarioAutenticado(); null para visitantes.
 *   $pub_activa      string      'inicio' | 'registrar' | 'seguimiento' | 'consulta'.
 *
 * Las variables internas usan el prefijo $pub_ y se eliminan al final.
 */

require_once __DIR__ . '/recursos.php';

$pub_activa = $pub_activa ?? '';
$pub_con_sesion = !empty($usuario_actual);
$pub_usuario = (string) ($usuario_actual['usuario'] ?? '');
$pub_rol = ($usuario_actual['rol'] ?? '') === 'admin' ? 'Administrador' : 'Técnico de soporte';
$pub_e = static fn($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pub_actual = static fn(string $clave): string => $pub_activa === $clave ? ' aria-current="page"' : '';
$pub_iniciales = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $pub_usuario), 0, 2, 'UTF-8'), 'UTF-8') ?: '?';
?>
<a href="#contenido" class="s-saltar">Saltar al contenido</a>

<header class="s-pub-cabecera<?= $pub_con_sesion ? ' s-pub-cabecera-sesion' : '' ?>" data-cabecera-publica>
    <div class="s-pub-contenedor s-pub-cabecera-interior">

        <a href="index.php" class="s-marca">
            <?= logotipoSenal('', 'oscuro', 40) ?>
            <span class="s-marca-texto">
                <strong>Soporte TI</strong>
                <small>Gestión de incidencias</small>
            </span>
        </a>

        <!-- Antes de <nav> para que, abierto, Tab pase del botón a los enlaces -->
        <button type="button" class="s-pub-hamburguesa" aria-expanded="false" aria-controls="pub-nav" hidden>
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
            <span class="s-pub-hamburguesa-texto">Menú</span>
        </button>

        <!-- Sin JavaScript los enlaces pasan a otra fila si no caben (nunca se cortan).
             Con JavaScript, si no caben completos en una fila se agrupan en el menú
             hamburguesa (js/cabecera.js mide la barra, no la ventana). -->
        <nav class="s-pub-nav" id="pub-nav" aria-label="Navegación principal">
            <a href="index.php" class="s-pub-enlace"<?= $pub_actual('inicio') ?>>
                <i class="fa-solid fa-house" aria-hidden="true"></i> Inicio
            </a>
            <a href="registrar.php" class="s-pub-enlace"<?= $pub_actual('registrar') ?>>
                <i class="fa-solid fa-circle-plus" aria-hidden="true"></i> Registrar incidencia
            </a>
            <a href="seguimiento.php" class="s-pub-enlace"<?= $pub_actual('seguimiento') ?>>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Consultar mi ticket
            </a>
            <?php if ($pub_con_sesion): ?>
                <!-- Solo con sesión: incidencias.php exige autenticación -->
                <a href="incidencias.php" class="s-pub-enlace"<?= $pub_actual('consulta') ?>>
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i> Consultar incidencias
                </a>
            <?php endif; ?>
        </nav>

        <div class="s-pub-cuenta">
            <?php if ($pub_con_sesion): ?>
                <a href="admin/index.php" class="s-btn s-btn-primario s-pub-acceso s-pub-panel">
                    <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
                    <span>Ir al panel</span>
                </a>
                <!-- <details> abre y cierra el menú también sin JavaScript -->
                <details class="s-pub-perfil">
                    <summary class="s-pub-perfil-boton" aria-label="Menú de la cuenta <?= $pub_e($pub_usuario) ?>">
                        <span class="s-avatar" aria-hidden="true"><?= $pub_e($pub_iniciales) ?></span>
                        <i class="fa-solid fa-chevron-down s-pub-perfil-flecha" aria-hidden="true"></i>
                    </summary>
                    <div class="s-pub-perfil-panel">
                        <div class="s-pub-perfil-datos">
                            <span class="s-avatar" aria-hidden="true"><?= $pub_e($pub_iniciales) ?></span>
                            <span>
                                <strong><?= $pub_e($pub_usuario) ?></strong>
                                <small><?= $pub_e($pub_rol) ?></small>
                            </span>
                        </div>
                        <form method="POST" action="admin/logout.php" class="s-form-salir">
                            <input type="hidden" name="logout_csrf_token" value="<?= $pub_e(tokenCsrfLogout()) ?>">
                            <button type="submit" class="s-pub-salir">
                                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                                Cerrar sesión
                            </button>
                        </form>
                    </div>
                </details>
            <?php else: ?>
                <a href="login.php" class="s-btn s-pub-acceso s-pub-acceso-claro">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>Acceso de personal</span>
                </a>
            <?php endif; ?>
        </div>

    </div>
</header>
<script src="<?= recursoVersionado('js/cabecera.js') ?>" defer></script>
<?php
unset($pub_con_sesion, $pub_usuario, $pub_rol, $pub_e, $pub_actual, $pub_iniciales);
