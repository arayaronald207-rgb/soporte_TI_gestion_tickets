<?php

/*
 * Acuerdos de nivel de servicio (SLA): plazo maximo de resolucion por prioridad.
 *
 * El plazo corre desde fecha_registro. Un ticket abierto esta:
 *   - "A tiempo"   si aun no consume el 75 % del plazo;
 *   - "Por vencer" si consumio el 75 % o mas, sin superar el plazo;
 *   - "Vencido"    si supero el plazo y sigue sin resolverse.
 * Un ticket Resuelta "Cumplio" o "Incumplio" segun su fecha_resolucion.
 *
 * Calculo independiente de la zona horaria de la conexion:
 *   UNIX_TIMESTAMP(fecha_registro) devuelve el instante real de un TIMESTAMP,
 *   y fecha_resolucion (DATETIME) se guarda en UTC por convencion del sistema.
 */

const SLA_HORAS = ['Alta' => 4, 'Media' => 24, 'Baja' => 72];
const SLA_FRACCION_AVISO = 0.75;

function slaLimiteSegundos(string $prioridad): int
{
    return (SLA_HORAS[$prioridad] ?? SLA_HORAS['Baja']) * 3600;
}

// Texto corto y legible para una duracion: "45 min", "3 h 20 min", "2 d 5 h".
function duracionLegible(int $segundos): string
{
    $segundos = max(0, $segundos);
    $dias = intdiv($segundos, 86400);
    $horas = intdiv($segundos % 86400, 3600);
    $minutos = intdiv($segundos % 3600, 60);
    if ($dias > 0) {
        return $dias . ' d' . ($horas > 0 ? ' ' . $horas . ' h' : '');
    }
    if ($horas > 0) {
        return $horas . ' h' . ($minutos > 0 ? ' ' . $minutos . ' min' : '');
    }
    return max(1, $minutos) . ' min';
}

function fechaLocalDesdeEpoch(int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))
        ->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('d/m/Y H:i');
}

/**
 * Evalua el SLA de un ticket.
 *
 * @param int|null    $registro_epoch   UNIX_TIMESTAMP(fecha_registro).
 * @param string|null $fecha_resolucion DATETIME en UTC o NULL.
 * @return array{clave:string, etiqueta:string, detalle:string, tono:string, limite_epoch:?int}
 */
function evaluarSla(string $prioridad, string $estado, ?int $registro_epoch,
                    ?string $fecha_resolucion, ?int $ahora = null): array
{
    $ahora ??= time();
    $limite = slaLimiteSegundos($prioridad);
    if (!$registro_epoch || $registro_epoch <= 0) {
        return ['clave' => 'sin_datos', 'etiqueta' => 'Sin datos', 'tono' => 'sin-datos',
            'detalle' => 'Fecha de registro desconocida.', 'limite_epoch' => null];
    }
    $limite_epoch = $registro_epoch + $limite;

    if ($estado === 'Resuelta') {
        $resuelto = null;
        if ($fecha_resolucion !== null) {
            try {
                $resuelto = (new DateTimeImmutable($fecha_resolucion, new DateTimeZone('UTC')))->getTimestamp();
            } catch (Exception $e) {
                $resuelto = null;
            }
        }
        if ($resuelto === null || $resuelto < $registro_epoch) {
            return ['clave' => 'sin_datos', 'etiqueta' => 'Sin datos', 'tono' => 'sin-datos',
                'detalle' => 'Resuelto sin fecha de resolución registrada.', 'limite_epoch' => $limite_epoch];
        }
        $duracion = $resuelto - $registro_epoch;
        if ($duracion <= $limite) {
            return ['clave' => 'cumplido', 'etiqueta' => 'Cumplió SLA', 'tono' => 'cumplido',
                'detalle' => 'Resuelto en ' . duracionLegible($duracion) . ' (plazo ' . duracionLegible($limite) . ').',
                'limite_epoch' => $limite_epoch];
        }
        return ['clave' => 'incumplido', 'etiqueta' => 'Incumplió SLA', 'tono' => 'incumplido',
            'detalle' => 'Resuelto con ' . duracionLegible($duracion - $limite) . ' de retraso.',
            'limite_epoch' => $limite_epoch];
    }

    $transcurrido = $ahora - $registro_epoch;
    if ($transcurrido > $limite) {
        return ['clave' => 'vencido', 'etiqueta' => 'Vencido', 'tono' => 'vencido',
            'detalle' => 'Venció hace ' . duracionLegible($transcurrido - $limite) . '.',
            'limite_epoch' => $limite_epoch];
    }
    $restante = $limite - $transcurrido;
    if ($transcurrido >= $limite * SLA_FRACCION_AVISO) {
        return ['clave' => 'por_vencer', 'etiqueta' => 'Por vencer', 'tono' => 'por-vencer',
            'detalle' => 'Quedan ' . duracionLegible($restante) . '.', 'limite_epoch' => $limite_epoch];
    }
    return ['clave' => 'a_tiempo', 'etiqueta' => 'A tiempo', 'tono' => 'a-tiempo',
        'detalle' => 'Quedan ' . duracionLegible($restante) . '.', 'limite_epoch' => $limite_epoch];
}

/**
 * Condicion SQL para filtrar tickets abiertos por situacion de SLA.
 * Solo usa constantes enteras del codigo y nombres de columna fijos (alias i).
 * UNIX_TIMESTAMP() y UNIX_TIMESTAMP(TIMESTAMP) no dependen de la zona de la conexion.
 */
function slaCondicionSql(string $clave): ?string
{
    $limite = sprintf("(CASE i.prioridad WHEN 'Alta' THEN %d WHEN 'Media' THEN %d ELSE %d END)",
        slaLimiteSegundos('Alta'), slaLimiteSegundos('Media'), slaLimiteSegundos('Baja'));
    $transcurrido = '(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(i.fecha_registro))';
    $aviso = sprintf('%s * %.2F', $limite, SLA_FRACCION_AVISO);
    return match ($clave) {
        'vencido' => "i.estado <> 'Resuelta' AND $transcurrido > $limite",
        'por_vencer' => "i.estado <> 'Resuelta' AND $transcurrido >= $aviso AND $transcurrido <= $limite",
        'a_tiempo' => "i.estado <> 'Resuelta' AND $transcurrido < $aviso",
        default => null,
    };
}

// Resumen de SLA de los tickets activos (sin archivar) dentro del alcance del rol.
function metricasSla(mysqli $conn, array $usuario): array
{
    $admin = (int) ($usuario['rol'] === 'admin');
    $id = (int) $usuario['id'];
    $datos = ['a_tiempo' => 0, 'por_vencer' => 0, 'vencido' => 0,
        'cumplido' => 0, 'incumplido' => 0, 'sin_datos' => 0, 'cumplimiento' => null];
    $stmt = $conn->prepare('SELECT prioridad, estado, UNIX_TIMESTAMP(fecha_registro) AS registro_epoch,
        fecha_resolucion FROM incidencias WHERE archivado = 0 AND (? = 1 OR tecnico_id = ?)');
    $stmt->bind_param('ii', $admin, $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $ahora = time();
    while ($fila = $resultado->fetch_assoc()) {
        $sla = evaluarSla($fila['prioridad'], $fila['estado'],
            $fila['registro_epoch'] === null ? null : (int) $fila['registro_epoch'],
            $fila['fecha_resolucion'], $ahora);
        $datos[$sla['clave']]++;
    }
    $stmt->close();
    $evaluados = $datos['cumplido'] + $datos['incumplido'];
    if ($evaluados > 0) {
        $datos['cumplimiento'] = $datos['cumplido'] * 100 / $evaluados;
    }
    return $datos;
}
