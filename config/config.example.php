<?php
/*
 * PLANTILLA DE CONFIGURACIÓN DE LA BASE DE DATOS
 *
 * 1. Copie este archivo como config/config.local.php
 * 2. Reemplace los valores de ejemplo por los de su instalación.
 *
 * config/config.local.php está excluido del repositorio (.gitignore) y la
 * carpeta config/ está bloqueada para HTTP por .htaccess. Nunca suba al
 * repositorio un archivo con credenciales reales.
 *
 * Se recomienda una cuenta de MariaDB/MySQL dedicada con privilegios mínimos
 * (ver Manual Técnico, "Crear la cuenta de base de datos de la aplicación"),
 * no la cuenta root.
 */
return [
    'host' => 'localhost',
    'port' => 3306,
    'usuario' => 'TU_USUARIO',
    'contrasena' => 'TU_CONTRASEÑA',
    'base_datos' => 'gestion_incidencias',
];
