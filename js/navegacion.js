/**
 * Menú lateral adaptable (identidad «Señal»).
 * Solo controla la apertura y el cierre del menú en pantallas pequeñas;
 * no interviene en formularios, permisos ni datos.
 */
(function () {
    'use strict';

    var menu = document.getElementById('s-menu');
    var abrir = document.querySelector('[data-menu-abrir]');
    var velo = document.querySelector('.s-menu-velo');
    if (!menu || !abrir || !velo) {
        return;
    }

    var cerrarBotones = document.querySelectorAll('[data-menu-cerrar]');
    var escritorio = window.matchMedia('(min-width: 1024px)');
    var ultimoFoco = null;

    function enfocables() {
        return Array.prototype.filter.call(
            menu.querySelectorAll('a[href], button:not([disabled])'),
            function (el) { return el.offsetParent !== null; }
        );
    }

    function abrirMenu() {
        ultimoFoco = document.activeElement;
        menu.classList.add('s-abierto');
        velo.hidden = false;
        abrir.setAttribute('aria-expanded', 'true');
        document.body.classList.add('s-menu-bloqueo');
        var primero = menu.querySelector('.s-menu-cerrar');
        if (primero) {
            primero.focus();
        }
    }

    function cerrarMenu(devolverFoco) {
        if (!menu.classList.contains('s-abierto')) {
            return;
        }
        menu.classList.remove('s-abierto');
        velo.hidden = true;
        abrir.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('s-menu-bloqueo');
        if (devolverFoco !== false && ultimoFoco && typeof ultimoFoco.focus === 'function') {
            ultimoFoco.focus();
        }
    }

    abrir.addEventListener('click', abrirMenu);

    Array.prototype.forEach.call(cerrarBotones, function (boton) {
        boton.addEventListener('click', function () { cerrarMenu(); });
    });

    // Al elegir un enlace en móvil, se cierra el menú sin mover el foco.
    menu.addEventListener('click', function (evento) {
        if (evento.target.closest('a') && !escritorio.matches) {
            cerrarMenu(false);
        }
    });

    document.addEventListener('keydown', function (evento) {
        if (!menu.classList.contains('s-abierto')) {
            return;
        }
        if (evento.key === 'Escape') {
            cerrarMenu();
            return;
        }
        // Mantiene el foco dentro del menú mientras está abierto.
        if (evento.key === 'Tab') {
            var lista = enfocables();
            if (!lista.length) {
                return;
            }
            var primero = lista[0];
            var ultimo = lista[lista.length - 1];
            if (evento.shiftKey && document.activeElement === primero) {
                evento.preventDefault();
                ultimo.focus();
            } else if (!evento.shiftKey && document.activeElement === ultimo) {
                evento.preventDefault();
                primero.focus();
            }
        }
    });

    // Si la ventana pasa a tamaño escritorio, se restablece el estado.
    var alCambiar = function (e) {
        if (e.matches) {
            cerrarMenu(false);
        }
    };
    if (escritorio.addEventListener) {
        escritorio.addEventListener('change', alCambiar);
    } else if (escritorio.addListener) {
        escritorio.addListener(alCambiar);
    }
}());
