<?php

// Se carga solo en las rutas revisadas en esta fase. No modifica sesiones ni CSRF.
// Las excepciones inesperadas se registran sin incluir valores de formularios.
set_exception_handler(static function (Throwable $error): void {
    error_log('Error no controlado: ' . get_class($error) . ' en ' . $error->getFile() . ':' . $error->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No se pudo completar la solicitud. Inténtalo de nuevo.');
});

function rechazarParametrosMalformados(): never
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('La solicitud contiene parámetros con un formato no válido. Revisa los datos e inténtalo de nuevo.');
}

// GET y POST suministran cadenas o arreglos. No convertir arreglos a texto ni
// sustituirlos silenciosamente por campos vacios. Los campos ausentes conservan
// sus validaciones habituales en cada formulario.
function validarParametrosTexto(array $entrada, array $campos): void
{
    foreach ($campos as $campo) {
        if (!array_key_exists($campo, $entrada)) {
            continue;
        }
        $valor = $entrada[$campo];
        if (!is_string($valor) || strpos($valor, "\0") !== false || !mb_check_encoding($valor, 'UTF-8')) {
            rechazarParametrosMalformados();
        }
    }
}
