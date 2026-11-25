/**
 * Ayudas visuales para los formularios administrativos («Señal»).
 *
 * Muestra un contador «usados / máximo» en los campos con data-contador.
 * Es solo informativo: no valida, no bloquea el envío y no cambia ningún
 * valor. Los límites reales siguen aplicándose en el servidor y con maxlength.
 */
(function () {
    'use strict';

    var campos = document.querySelectorAll('[data-contador][maxlength]');

    Array.prototype.forEach.call(campos, function (campo) {
        var maximo = parseInt(campo.getAttribute('maxlength'), 10);
        if (!maximo) {
            return;
        }

        var contador = document.createElement('span');
        contador.className = 's-contador';
        contador.setAttribute('aria-hidden', 'true');

        // Se coloca junto al texto de ayuda si existe; si no, después del campo.
        var ayuda = campo.parentNode.querySelector('.s-ayuda');
        if (ayuda) {
            var fila = document.createElement('div');
            fila.className = 's-ayuda-fila';
            ayuda.parentNode.insertBefore(fila, ayuda);
            fila.appendChild(ayuda);
            fila.appendChild(contador);
        } else {
            campo.insertAdjacentElement('afterend', contador);
        }

        var actualizar = function () {
            var usados = campo.value.length;
            contador.textContent = usados + ' / ' + maximo;
            contador.classList.toggle('s-contador-limite', usados >= maximo * 0.9);
        };

        campo.addEventListener('input', actualizar);
        actualizar();
    });
}());
