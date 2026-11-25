/**
 * Botón «Copiar» para el código de seguimiento.
 *
 * <button type="button" data-copiar="id-del-elemento" hidden>…</button>
 * El botón empieza oculto: sin JavaScript no se muestra y el código sigue
 * pudiendo seleccionarse a mano (user-select: all).
 */
(function () {
    'use strict';

    function copiarTexto(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(texto);
        }
        // Respaldo para http sin contexto seguro (por ejemplo, acceso por IP en la red local).
        return new Promise(function (resolver, rechazar) {
            var area = document.createElement('textarea');
            area.value = texto;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(area);
            if (ok) { resolver(); } else { rechazar(); }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copiar]'), function (boton) {
        var origen = document.getElementById(boton.getAttribute('data-copiar'));
        if (!origen) { return; }
        var etiqueta = boton.querySelector('.s-copiar-texto');
        var icono = boton.querySelector('i');
        var anuncio = document.createElement('span');
        anuncio.className = 's-solo-lector';
        anuncio.setAttribute('aria-live', 'polite');
        boton.insertAdjacentElement('afterend', anuncio);
        var temporizador = null;

        function mostrar(texto, claseIcono, estado) {
            etiqueta.textContent = texto;
            icono.className = 'fa-solid ' + claseIcono;
            boton.classList.toggle('s-copiado', estado === 'ok');
            anuncio.textContent = estado ? texto : '';
        }

        boton.hidden = false;
        boton.addEventListener('click', function () {
            copiarTexto(origen.textContent.trim()).then(function () {
                mostrar('¡Copiado!', 'fa-check', 'ok');
            }, function () {
                mostrar('Selecciona y copia', 'fa-hand-pointer', 'error');
                var rango = document.createRange();
                rango.selectNodeContents(origen);
                var seleccion = window.getSelection();
                seleccion.removeAllRanges();
                seleccion.addRange(rango);
            });
            window.clearTimeout(temporizador);
            temporizador = window.setTimeout(function () {
                mostrar('Copiar', 'fa-copy', '');
            }, 2500);
        });
    });
}());
