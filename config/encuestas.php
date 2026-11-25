<?php

/*
 * Encuestas de satisfaccion y codigo de seguimiento del solicitante.
 *
 * El solicitante no tiene cuenta: al registrar un ticket recibe un codigo
 * aleatorio (48 bits) con formato XXXX-XXXX-XXXX. En la base solo se guarda su
 * hash SHA-256, de modo que ni el personal ni una copia de la base lo revelan.
 */

const ENCUESTA_COMENTARIO_MAX = 1000;
const ENCUESTA_ETIQUETAS = [1 => 'Muy insatisfecho', 2 => 'Insatisfecho', 3 => 'Regular',
    4 => 'Satisfecho', 5 => 'Muy satisfecho'];

function generarCodigoSeguimiento(): string
{
    return implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4));
}

// Normaliza lo que escribe el solicitante (mayusculas, sin guiones ni espacios).
function hashCodigoSeguimiento(string $codigo): string
{
    return hash('sha256', preg_replace('/[^A-F0-9]/', '', strtoupper($codigo)));
}

function encuestaDeIncidencia(mysqli $conn, int $incidencia_id): ?array
{
    $stmt = $conn->prepare('SELECT calificacion, comentario, fecha FROM encuestas_satisfaccion WHERE incidencia_id = ?');
    $stmt->bind_param('i', $incidencia_id);
    $stmt->execute();
    $encuesta = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $encuesta ?: null;
}

// Resultados de encuestas dentro del alcance del rol (incluye tickets archivados:
// la opinion del solicitante sigue siendo valida aunque el ticket se archive).
function metricasEncuestas(mysqli $conn, array $usuario): array
{
    $admin = (int) ($usuario['rol'] === 'admin');
    $id = (int) $usuario['id'];
    $datos = ['respondidas' => 0, 'promedio' => null, 'distribucion' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        'elegibles' => 0, 'tasa_respuesta' => null, 'por_tecnico' => []];

    $stmt = $conn->prepare('SELECT e.calificacion, COUNT(*) AS cantidad FROM encuestas_satisfaccion e
        JOIN incidencias i ON i.id = e.incidencia_id WHERE (? = 1 OR i.tecnico_id = ?) GROUP BY e.calificacion');
    $stmt->bind_param('ii', $admin, $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $suma = 0;
    while ($fila = $resultado->fetch_assoc()) {
        $nota = (int) $fila['calificacion'];
        $datos['distribucion'][$nota] = (int) $fila['cantidad'];
        $datos['respondidas'] += (int) $fila['cantidad'];
        $suma += $nota * (int) $fila['cantidad'];
    }
    $stmt->close();
    if ($datos['respondidas'] > 0) {
        $datos['promedio'] = $suma / $datos['respondidas'];
    }

    // Tickets que podian recibir encuesta: resueltos y con codigo de seguimiento.
    $stmt = $conn->prepare("SELECT COUNT(*) AS cantidad FROM incidencias i
        WHERE i.estado = 'Resuelta' AND i.codigo_seguimiento_hash IS NOT NULL AND (? = 1 OR i.tecnico_id = ?)");
    $stmt->bind_param('ii', $admin, $id);
    $stmt->execute();
    $datos['elegibles'] = (int) $stmt->get_result()->fetch_assoc()['cantidad'];
    $stmt->close();
    // Una encuesta respondida sigue contando aunque el ticket se haya reabierto.
    $base = max($datos['elegibles'], $datos['respondidas']);
    if ($base > 0) {
        $datos['tasa_respuesta'] = $datos['respondidas'] * 100 / $base;
    }

    // Promedio por tecnico que resolvio el ticket (solo administrador).
    if ($admin === 1) {
        $resultado = $conn->query('SELECT u.usuario, COUNT(*) AS cantidad, AVG(e.calificacion) AS promedio
            FROM encuestas_satisfaccion e JOIN incidencias i ON i.id = e.incidencia_id
            LEFT JOIN usuarios u ON u.id = i.tecnico_resolucion_id
            GROUP BY u.id, u.usuario ORDER BY promedio DESC, cantidad DESC');
        while ($fila = $resultado->fetch_assoc()) {
            $datos['por_tecnico'][] = ['nombre' => $fila['usuario'] ?? 'Sin técnico de resolución registrado',
                'cantidad' => (int) $fila['cantidad'], 'promedio' => (float) $fila['promedio']];
        }
    }
    return $datos;
}

// Estrellas accesibles (texto alternativo para lectores de pantalla).
function estrellasHtml(int $nota): string
{
    $html = '<span class="s-estrellas" role="img" aria-label="' . $nota . ' de 5 estrellas">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<i class="' . ($i <= $nota ? 'fa-solid' : 'fa-regular') . ' fa-star" aria-hidden="true"></i>';
    }
    return $html . '</span>';
}
