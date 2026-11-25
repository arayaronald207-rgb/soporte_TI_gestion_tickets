/**
 * Ayudas de interfaz para las páginas públicas («Señal»).
 *
 * Botón «Mostrar / Ocultar» contraseña. Solo cambia el tipo del campo en el
 * navegador; no lee, guarda ni envía la contraseña por otro medio. El botón
 * está oculto en el HTML y solo se muestra cuando este script funciona.
 */
(function () {
    'use strict';

    var botones = document.querySelectorAll('[data-mostrar-clave]');

    Array.prototype.forEach.call(botones, function (boton) {
        var campo = document.getElementById(boton.getAttribute('data-mostrar-clave'));
        if (!campo) {
            return;
        }

        var texto = boton.querySelector('span');
        var icono = boton.querySelector('i');
        boton.hidden = false;

        boton.addEventListener('click', function () {
            var mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            boton.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
            if (texto) {
                texto.textContent = mostrar ? 'Ocultar' : 'Mostrar';
            }
            if (icono) {
                icono.className = mostrar ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            }
            campo.focus();
        });

        // Al enviar el formulario, el campo vuelve a ser de tipo contraseña.
        if (campo.form) {
            campo.form.addEventListener('submit', function () {
                campo.type = 'password';
            });
        }
    });
}());
