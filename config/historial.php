<?php

// Usar dentro de la misma transaccion que modifica/bloquea la incidencia.
// El autor procede de la sesion revalidada bajo bloqueo, nunca del formulario.
function registrarEventoIncidencia(
    mysqli $conn, int $incidencia_id, int $usuario_id, string $tipo, string $detalle,
    ?string $estado_anterior = null, ?string $estado_nuevo = null,
    ?int $tecnico_anterior = null, ?int $tecnico_nuevo = null
): void {
    $stmt = $conn->prepare(
        'INSERT INTO incidencia_historial
         (incidencia_id, usuario_id, tipo, detalle, fecha,
          estado_anterior, estado_nuevo, tecnico_anterior_id, tecnico_nuevo_id)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssii', $incidencia_id, $usuario_id, $tipo, $detalle,
        $estado_anterior, $estado_nuevo, $tecnico_anterior, $tecnico_nuevo);
    $stmt->execute();
    $stmt->close();
}

function fechaHistorialLocal(string $fecha): string
{
    return (new DateTimeImmutable($fecha, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('d/m/Y H:i:s');
}
