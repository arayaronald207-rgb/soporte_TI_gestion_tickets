<?php

/*
 * Ciclo de vida del ticket: Pendiente -> En proceso -> Resuelta -> Cerrado.
 *
 * - Entre Pendiente, En proceso y Resuelta se puede avanzar o reabrir.
 * - Solo se cierra un ticket que esta en Resuelta (tecnico asignado o administrador;
 *   el acceso del tecnico a sus tickets lo controla incidenciaPermitida()).
 * - Cerrado es el estado final: solo el administrador puede sacarlo de ahi.
 * - Resuelta y Cerrado son estados "resueltos": conservan fecha_resolucion y
 *   cuentan en el SLA (Cumplio/Incumplio), en el tiempo promedio y en la encuesta.
 */

const ESTADOS_TICKET = ['Pendiente', 'En proceso', 'Resuelta', 'Cerrado'];
const ESTADOS_RESUELTOS = ['Resuelta', 'Cerrado'];

function estadoResuelto(?string $estado): bool
{
    return in_array($estado, ESTADOS_RESUELTOS, true);
}

// Fragmento SQL fijo (sin datos del usuario) para "estado resuelto" sobre una columna.
function estadosResueltosSql(string $columna = 'estado'): string
{
    return $columna . " IN ('Resuelta', 'Cerrado')";
}

// Clase visual del estado (insignias, opciones, indicadores).
function tonoEstado(?string $estado): string
{
    return match ($estado) {
        'Pendiente' => 'pendiente',
        'En proceso' => 'proceso',
        'Resuelta' => 'resuelta',
        'Cerrado' => 'cerrado',
        default => 'pendiente',
    };
}

/**
 * Valida un cambio de estado. Devuelve null si esta permitido o el motivo del rechazo.
 * Repetir el estado actual siempre esta permitido (no produce cambios).
 */
function motivoTransicionNoPermitida(string $actual, string $nuevo, string $rol): ?string
{
    if (!in_array($nuevo, ESTADOS_TICKET, true)) {
        return 'Selecciona un estado válido.';
    }
    if ($actual === $nuevo) {
        return null;
    }
    if ($actual === 'Cerrado' && $rol !== 'admin') {
        return 'El ticket está cerrado. Solo un administrador puede reabrirlo.';
    }
    if ($nuevo === 'Cerrado' && $actual !== 'Resuelta') {
        return 'Solo se puede cerrar un ticket que esté en estado Resuelta.';
    }
    return null;
}
