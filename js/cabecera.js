/**
 * Barra superior pública («Señal»): menú hamburguesa y menú de la cuenta.
 *
 * - Hamburguesa: se activa solo cuando los enlaces NO caben completos en una
 *   fila. Se mide la barra real (no el ancho de la ventana), así que funciona
 *   igual con cualquier escala de Windows o zoom del navegador, y aunque la
 *   barra tenga un ancho máximo menor que la ventana.
 * - Cuenta: <details> abre y cierra sin JavaScript; aquí se añade el cierre
 *   con Esc y con un clic fuera.
 * Sin JavaScript la barra sigue funcionando: los enlaces pasan a otra fila.
 */
(function () {
    'use strict';

    var cabecera = document.querySelector('[data-cabecera-publica]');
    if (!cabecera) { return; }
    var nav = cabecera.querySelector('.s-pub-nav');
    var boton = cabecera.querySelector('.s-pub-hamburguesa');
    var perfil = cabecera.querySelector('.s-pub-perfil');

    cabecera.classList.add('s-pub-js');
    boton.hidden = false;

    function abrirMenu(abrir) {
        cabecera.classList.toggle('s-pub-menu-abierto', abrir);
        boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    }

    // Mide en modo «una fila»: si el contenido de la navegación es más ancho
    // que el espacio que le deja la barra, los enlaces no caben completos.
    function evaluar() {
        var abierto = cabecera.classList.contains('s-pub-menu-abierto');
        cabecera.classList.remove('s-pub-compacta', 's-pub-menu-abierto');
        cabecera.classList.add('s-pub-midiendo');
        var noCabe = nav.scrollWidth > nav.clientWidth + 1;
        cabecera.classList.remove('s-pub-midiendo');
        cabecera.classList.toggle('s-pub-compacta', noCabe);
        abrirMenu(noCabe && abierto);
    }

    boton.addEventListener('click', function () {
        abrirMenu(!cabecera.classList.contains('s-pub-menu-abierto'));
        if (perfil) { perfil.open = false; }
    });

    if (perfil) {
        perfil.addEventListener('toggle', function () {
            if (perfil.open) { abrirMenu(false); }
        });
    }

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') { return; }
        if (cabecera.classList.contains('s-pub-menu-abierto')) {
            abrirMenu(false);
            boton.focus();
        } else if (perfil && perfil.open) {
            perfil.open = false;
            perfil.querySelector('summary').focus();
        }
    });

    // Clic (o toque) fuera: cierra los dos menús.
    document.addEventListener('click', function (evento) {
        if (cabecera.classList.contains('s-pub-menu-abierto') &&
            !nav.contains(evento.target) && !boton.contains(evento.target)) {
            abrirMenu(false);
        }
        if (perfil && perfil.open && !perfil.contains(evento.target)) {
            perfil.open = false;
        }
    });

    // Al salir con Tab del menú abierto, se cierra para no tapar la página.
    cabecera.addEventListener('focusout', function (evento) {
        var destino = evento.relatedTarget;
        if (!destino) { return; }
        if (cabecera.classList.contains('s-pub-menu-abierto') &&
            !nav.contains(destino) && destino !== boton) {
            abrirMenu(false);
        }
        if (perfil && perfil.open && !perfil.contains(destino)) {
            perfil.open = false;
        }
    });

    evaluar();
    if (window.ResizeObserver) {
        new ResizeObserver(evaluar).observe(cabecera.querySelector('.s-pub-cabecera-interior'));
    } else {
        window.addEventListener('resize', evaluar);
    }
    // Las fuentes web cambian el ancho del texto al terminar de cargar.
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(evaluar); }
}());
