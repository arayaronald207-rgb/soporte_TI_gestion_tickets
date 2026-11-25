<?php
/**
 * Rutas de recursos estáticos con versión para evitar copias antiguas en caché.
 *
 * Apache entrega los CSS y JS sin cabeceras de caché, por lo que el navegador
 * puede reutilizar una copia vieja. Añadir «?v=<fecha de modificación>» cambia la
 * URL cada vez que el archivo cambia, y el navegador descarga la versión nueva.
 *
 * Solo genera texto: no lee datos del usuario ni de la base de datos.
 */

if (!function_exists('recursoVersionado')) {
    /**
     * @param string $ruta  Ruta del recurso desde la raíz del proyecto (por ejemplo, 'css/tema.css').
     * @param string $raiz  Prefijo relativo desde la página actual hasta la raíz ('' o '../').
     */
    function recursoVersionado(string $ruta, string $raiz = ''): string
    {
        $version = (int) @filemtime(__DIR__ . '/../' . $ruta);
        return htmlspecialchars($raiz . $ruta . '?v=' . $version, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('logotipoSenal')) {
    /**
     * Logotipo «Ciclo colaborativo» como imagen decorativa (alt vacío): siempre
     * se acompaña del texto «Soporte TI», que es el nombre accesible de la marca.
     *
     * @param string $raiz      Prefijo relativo hasta la raíz ('' o '../').
     * @param string $variante  'oscuro' (fondos grafito) o 'claro' (fondos blancos).
     * @param int    $tamano    Ancho y alto en píxeles.
     */
    function logotipoSenal(string $raiz = '', string $variante = 'oscuro', int $tamano = 40): string
    {
        $archivo = $variante === 'claro' ? 'imagenes/marca/logo-ciclo-claro.svg' : 'imagenes/marca/logo-ciclo.svg';
        return '<img src="' . recursoVersionado($archivo, $raiz) . '" class="s-marca-logo" alt="" width="'
            . $tamano . '" height="' . $tamano . '" decoding="async">';
    }
}

if (!function_exists('faviconSenal')) {
    /** Enlace del favicon SVG simplificado. */
    function faviconSenal(string $raiz = ''): string
    {
        return '<link rel="icon" type="image/svg+xml" href="' . recursoVersionado('imagenes/marca/favicon.svg', $raiz) . '">';
    }
}
