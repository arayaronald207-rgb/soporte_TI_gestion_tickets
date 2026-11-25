/**
 * Ventana modal de confirmación propia («Señal»): reemplaza a confirm().
 *
 * Uso declarativo en un formulario:
 *   <form method="POST" data-confirmar
 *         data-confirmar-titulo="¿Eliminar usuario?"
 *         data-confirmar-mensaje="Esta acción no se puede deshacer."
 *         data-confirmar-tipo="peligro"            (advertencia | peligro | info)
 *         data-confirmar-boton="Eliminar usuario">
 *
 * Al confirmar se añade el campo oculto «confirmado=1» y se envía el formulario.
 * Sin JavaScript el formulario se envía sin ese campo y el servidor muestra
 * una confirmación en la propia página; por eso la acción nunca queda rota.
 *
 * Uso programático: SenalDialogo.confirmar({ titulo, mensaje, tipo, boton })
 * devuelve una promesa que se resuelve con true (confirmar) o false (cancelar).
 */
(function () {
    'use strict';

    var ICONOS = {
        advertencia: 'fa-triangle-exclamation',
        peligro: 'fa-circle-exclamation',
        info: 'fa-circle-info'
    };
    var ENFOCABLES = 'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var contador = 0;
    var abierto = null;

    function crear(etiqueta, clase, texto) {
        var nodo = document.createElement(etiqueta);
        if (clase) { nodo.className = clase; }
        if (texto) { nodo.textContent = texto; }
        return nodo;
    }

    function confirmar(opciones) {
        opciones = opciones || {};
        if (abierto) { abierto.cerrar(false); }

        var tipo = ICONOS[opciones.tipo] ? opciones.tipo : 'advertencia';
        var destructiva = tipo === 'peligro' || opciones.destructiva === true;
        var origen = opciones.origen || document.activeElement;
        var id = 's-dialogo-' + (++contador);

        var fondo = crear('div', 's-dialogo-fondo');
        var caja = crear('div', 's-dialogo s-dialogo-' + tipo);
        caja.setAttribute('role', 'dialog');
        caja.setAttribute('aria-modal', 'true');
        caja.setAttribute('aria-labelledby', id + '-titulo');
        caja.setAttribute('aria-describedby', id + '-mensaje');

        var icono = crear('span', 's-dialogo-icono');
        icono.setAttribute('aria-hidden', 'true');
        icono.appendChild(crear('i', 'fa-solid ' + ICONOS[tipo]));

        var titulo = crear('h2', 's-dialogo-titulo', opciones.titulo || '¿Confirmar acción?');
        titulo.id = id + '-titulo';
        var mensaje = crear('p', 's-dialogo-mensaje', opciones.mensaje || '');
        mensaje.id = id + '-mensaje';

        var acciones = crear('div', 's-dialogo-acciones');
        var cancelar = crear('button', 's-btn s-btn-secundario', opciones.cancelar || 'Cancelar');
        cancelar.type = 'button';
        var aceptar = crear('button', 's-btn ' + (destructiva ? 's-btn-peligro' : 's-btn-primario'),
            opciones.boton || 'Confirmar');
        aceptar.type = 'button';
        acciones.appendChild(cancelar);
        acciones.appendChild(aceptar);

        var texto = crear('div', 's-dialogo-texto');
        texto.appendChild(titulo);
        texto.appendChild(mensaje);
        caja.appendChild(icono);
        caja.appendChild(texto);
        caja.appendChild(acciones);
        fondo.appendChild(caja);

        // El resto de la página queda fuera del alcance del teclado y del lector.
        var hermanos = Array.prototype.filter.call(document.body.children, function (nodo) {
            return !nodo.hasAttribute('inert') && nodo.tagName !== 'SCRIPT';
        });

        return new Promise(function (resolver) {
            var terminado = false;

            function teclado(evento) {
                if (evento.key === 'Escape') {
                    evento.preventDefault();
                    cerrar(false);
                } else if (evento.key === 'Tab') {
                    var lista = caja.querySelectorAll(ENFOCABLES);
                    var primero = lista[0];
                    var ultimo = lista[lista.length - 1];
                    if (!caja.contains(document.activeElement)) {
                        evento.preventDefault();
                        primero.focus();
                    } else if (evento.shiftKey && document.activeElement === primero) {
                        evento.preventDefault();
                        ultimo.focus();
                    } else if (!evento.shiftKey && document.activeElement === ultimo) {
                        evento.preventDefault();
                        primero.focus();
                    }
                }
            }

            function cerrar(resultado) {
                if (terminado) { return; }
                terminado = true;
                abierto = null;
                document.removeEventListener('keydown', teclado, true);
                hermanos.forEach(function (nodo) { nodo.removeAttribute('inert'); });
                document.documentElement.classList.remove('s-dialogo-activo');
                fondo.classList.remove('s-dialogo-visible');
                var quitar = function () { if (fondo.parentNode) { fondo.parentNode.removeChild(fondo); } };
                fondo.addEventListener('transitionend', quitar, { once: true });
                window.setTimeout(quitar, 300);
                if (origen && typeof origen.focus === 'function' && document.contains(origen)) {
                    origen.focus();
                }
                resolver(resultado);
            }

            abierto = { cerrar: cerrar };
            cancelar.addEventListener('click', function () { cerrar(false); });
            aceptar.addEventListener('click', function () { cerrar(true); });
            fondo.addEventListener('mousedown', function (evento) {
                if (evento.target === fondo) { cerrar(false); }
            });
            document.addEventListener('keydown', teclado, true);

            document.body.appendChild(fondo);
            hermanos.forEach(function (nodo) { nodo.setAttribute('inert', ''); });
            document.documentElement.classList.add('s-dialogo-activo');
            // Fuerza el estilo inicial para que la transición de entrada se vea.
            void fondo.offsetWidth;
            fondo.classList.add('s-dialogo-visible');
            // En acciones destructivas el foco inicial va a «Cancelar», la opción segura.
            (destructiva ? cancelar : aceptar).focus();
        });
    }

    // Formularios con data-confirmar: el modal sustituye al confirm() nativo.
    document.addEventListener('submit', function (evento) {
        var formulario = evento.target;
        if (!formulario.hasAttribute || !formulario.hasAttribute('data-confirmar')) { return; }
        if (formulario.querySelector('input[name="confirmado"]')) { return; }
        evento.preventDefault();
        var d = formulario.dataset;
        confirmar({
            titulo: d.confirmarTitulo,
            mensaje: d.confirmarMensaje,
            tipo: d.confirmarTipo,
            boton: d.confirmarBoton,
            cancelar: d.confirmarCancelar,
            origen: evento.submitter || document.activeElement
        }).then(function (aceptado) {
            if (!aceptado) { return; }
            var campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = 'confirmado';
            campo.value = '1';
            formulario.appendChild(campo);
            formulario.submit();
        });
    });

    window.SenalDialogo = { confirmar: confirmar };
}());
