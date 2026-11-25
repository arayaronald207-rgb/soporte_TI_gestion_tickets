/**
 * Ajustes visuales globales de Chart.js para la identidad «Señal».
 *
 * Solo modifica valores por defecto (Chart.defaults): tipografía, color de
 * los textos, líneas de la rejilla y estilo de los recuadros informativos.
 * NO crea gráficos, NO cambia datos, tipos, identificadores ni los colores
 * de las series definidos en admin/index.php: cualquier opción escrita
 * explícitamente en cada gráfico sigue teniendo prioridad.
 *
 * Se carga con «defer» después de Chart.js, por lo que se ejecuta antes del
 * evento DOMContentLoaded en el que se construyen los gráficos.
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined' || !Chart.defaults) {
        return;
    }

    var estilos = getComputedStyle(document.documentElement);
    var variable = function (nombre, respaldo) {
        var valor = (estilos.getPropertyValue(nombre) || '').trim();
        return valor || respaldo;
    };

    var d = Chart.defaults;

    d.font.family = variable('--s-fuente', '"Segoe UI", Arial, sans-serif');
    d.font.size = 12;
    d.color = variable('--s-texto-suave', '#5B6675');
    d.borderColor = variable('--s-grafico-rejilla', '#EEF1F4');

    if (d.plugins && d.plugins.tooltip) {
        var t = d.plugins.tooltip;
        t.backgroundColor = variable('--s-grafito-900', '#0F1722');
        t.titleColor = '#FFFFFF';
        t.bodyColor = '#D5DCE4';
        t.padding = 10;
        t.cornerRadius = 8;
        t.boxPadding = 4;
        t.titleFont = { weight: '600' };
    }

    if (d.plugins && d.plugins.legend && d.plugins.legend.labels) {
        d.plugins.legend.labels.boxWidth = 10;
        d.plugins.legend.labels.boxHeight = 10;
    }
}());
