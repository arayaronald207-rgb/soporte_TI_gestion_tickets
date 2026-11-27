<?php

require_once __DIR__ . '/estados.php';

/*
 * Confirmacion del solicitante ("¿ya funciona?").
 *
 * El tecnico asignado (o el administrador) pregunta al solicitante si el
 * problema quedo resuelto; el solicitante responde desde "Consultar mi ticket"
 * con su codigo de seguimiento. La respuesta solo informa: no cambia el estado.
 *
 * Reglas (validar siempre con el ticket bloqueado FOR UPDATE):
 *   - Solo con el ticket En proceso o Resuelta y con codigo de seguimiento.
 *   - Una sola pregunta pendiente por ticket; cada pregunta se responde una vez.
 *   - "sigue_fallando" exige comentario; con "funciona" es opcional.
 */

const CONFIRMACION_PREGUNTA_MAX = 500;
const CONFIRMACION_COMENTARIO_MAX = 1000;
const CONFIRMACION_PREGUNTA_SUGERIDA = '¿El equipo o servicio ya funciona correctamente?';
const ESTADOS_CONFIRMACION = ['En proceso', 'Resuelta'];
const CONFIRMACION_RESPUESTAS = [
    'funciona' => 'Sí, ya funciona',
    'sigue_fallando' => 'No, el problema sigue',
];

// Pregunta sin responder del ticket, o null.
function confirmacionPendiente(mysqli $conn, int $incidencia_id): ?array
{
    $stmt = $conn->prepare('SELECT id, pregunta, preguntado_por, fecha_pregunta FROM confirmaciones_solicitante
        WHERE incidencia_id = ? AND respuesta IS NULL ORDER BY fecha_pregunta DESC, id DESC LIMIT 1');
    $stmt->bind_param('i', $incidencia_id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fila ?: null;
}

// Todas las preguntas del ticket (con el usuario que pregunto), en orden cronologico.
function confirmacionesDeIncidencia(mysqli $conn, int $incidencia_id): array
{
    $stmt = $conn->prepare('SELECT c.id, c.pregunta, c.fecha_pregunta, c.respuesta, c.comentario, c.fecha_respuesta,
            u.usuario AS preguntado_por_usuario
        FROM confirmaciones_solicitante c JOIN usuarios u ON u.id = c.preguntado_por
        WHERE c.incidencia_id = ? ORDER BY c.fecha_pregunta ASC, c.id ASC');
    $stmt->bind_param('i', $incidencia_id);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $filas;
}

/**
 * Motivo por el que no se puede preguntar al solicitante, o null si se puede.
 * $incidencia debe venir leida bajo bloqueo (estado y codigo_seguimiento_hash).
 */
function motivoNoPreguntar(mysqli $conn, array $incidencia): ?string
{
    if (!in_array($incidencia['estado'] ?? null, ESTADOS_CONFIRMACION, true)) {
        return 'Solo se puede preguntar al solicitante con el ticket En proceso o Resuelta.';
    }
    if (($incidencia['codigo_seguimiento_hash'] ?? null) === null) {
        return 'El solicitante no tiene código de seguimiento. Genera uno y entrégaselo antes de preguntar.';
    }
    if (confirmacionPendiente($conn, (int) $incidencia['id']) !== null) {
        return 'Ya hay una pregunta pendiente de respuesta para este ticket.';
    }
    return null;
}

// Texto valido: UTF-8, con contenido, sin bytes nulos y dentro del maximo.
function textoConfirmacionValido(string $texto, int $maximo): bool
{
    return mb_check_encoding($texto, 'UTF-8') && preg_match('/\S/u', $texto) === 1
        && mb_strlen($texto, 'UTF-8') <= $maximo && strpos($texto, "\0") === false;
}

/**
 * Valida la respuesta del solicitante. Devuelve null si es valida o el motivo.
 * El comentario llega ya recortado; '' significa sin comentario.
 */
function motivoRespuestaInvalida(string $respuesta, string $comentario): ?string
{
    if (!isset(CONFIRMACION_RESPUESTAS[$respuesta])) {
        return 'Indica si el problema ya se resolvió o si sigue.';
    }
    if ($comentario === '') {
        return $respuesta === 'sigue_fallando'
            ? 'Cuéntanos qué sigue fallando para que el técnico pueda revisarlo.'
            : null;
    }
    if (!textoConfirmacionValido($comentario, CONFIRMACION_COMENTARIO_MAX)) {
        return 'El comentario debe tener entre 1 y ' . CONFIRMACION_COMENTARIO_MAX . ' caracteres válidos.';
    }
    return null;
}
