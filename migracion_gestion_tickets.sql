-- Migracion incremental de Gestion de Incidencias.
-- Destino: MariaDB 10.4.32 / InnoDB / utf8mb4_general_ci.
-- Archivo preparado para revision. Su creacion NO ejecuta esta migracion.
--
-- ANTES DE EJECUTAR (en una etapa posterior autorizada):
-- 1. Respaldar estructura y datos de la base ACTIVA, no usar el volcado antiguo
--    como sustituto. Registrar cantidades, IDs, datos y valores AUTO_INCREMENT.
-- 2. Ensayar primero en una copia aislada. Seleccionar explicitamente la base
--    destino en el cliente: este archivo NO contiene USE ni CREATE DATABASE,
--    para que el ensayo no cambie accidentalmente a la base de produccion.
-- 3. Confirmar version, tablas InnoDB, charset/collation e IDs INT con signo,
--    claves primarias y UNIQUE de usuarios.usuario del esquema original.
--    Confirmar que no existen las columnas, indices, restricciones o tabla
--    agregados aqui y que no hay una migracion aplicada parcialmente.
-- 4. Mantener foreign_key_checks y check_constraint_checks activados y usar
--    SQL estricto (STRICT_TRANS_TABLES o STRICT_ALL_TABLES). Detener escrituras
--    de la aplicacion y disponer de permisos ALTER, CREATE, INDEX y REFERENCES.
-- 5. Ejecutar UNA sola vez con un cliente que se detenga ante el primer error.
--    No usar opciones que continuen tras errores. No es un script idempotente:
--    se omite IF NOT EXISTS para no ocultar discrepancias del esquema.
--
-- ALTER TABLE y CREATE TABLE producen commits implicitos en MariaDB 10.4.
-- START TRANSACTION / ROLLBACK no revierten esta migracion completa.
-- Si falla una etapa, revisar el estado y recuperar desde el respaldo o preparar
-- una continuacion especifica; no volver a importar el archivo a ciegas.
--
-- CONSERVACION Y COMPATIBILIDAD:
-- Solo se agregan estructuras. No se reemplazan tablas, IDs, hashes, registros,
-- claves originales, estados ni valores AUTO_INCREMENT mediante este script.
-- Los INSERT actuales de PHP enumeran columnas: los campos nuevos admiten NULL
-- o tienen defaults compatibles. Los nuevos CHECK aceptan todos los registros
-- existentes al agregar las columnas. Titulo/categoria quedan temporalmente
-- anulables: exigirlos en altas nuevas y completar los antiguos antes de una
-- futura migracion a NOT NULL. No inferir categoria, autor ni fechas historicas.
-- No se generan filas de historial ni resoluciones artificiales.
--
-- FECHAS:
-- fecha_registro conserva su tipo TIMESTAMP y su default original, sin UPDATE,
-- conversion ni cambio de zona horaria. TIMESTAMP guarda el instante en UTC y
-- lo presenta segun la zona de la conexion; no aplicar un desfase a esos datos.
-- Los DATETIME nuevos representan UTC por convencion. Los desconocidos quedan
-- NULL: no usar la fecha de migracion ni fecha_registro como fecha de resolucion.
-- El default del historial usa UTC_TIMESTAMP(), independiente de la zona de la
-- conexion. MariaDB admite expresiones DEFAULT desde 10.2.1.
-- No hay ON UPDATE automatico que invente fechas de seguimiento/resolucion.
-- Esta migracion no modifica la zona global ni la de las conexiones PHP.
-- En la futura aplicacion, usar UTC_TIMESTAMP() para los DATETIME operativos,
-- establecer time_zone = '+00:00' en CADA conexion antes de leer/comparar
-- TIMESTAMP con DATETIME y convertir a America/Costa_Rica solo al presentar.
-- Configurar tambien la interpretacion de fechas en PHP; no basta con SQL.
--
-- REFERENCIAS DE COMPATIBILIDAD:
-- https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/create-table
-- https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint
-- https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys
-- https://mariadb.com/docs/server/reference/data-types/date-and-time-data-types/timestamp
-- https://mariadb.com/docs/server/reference/sql-functions/date-time-functions/utc_timestamp
-- https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit

-- 1. Usuarios: conservar cuentas y permitir su desactivacion futura.
ALTER TABLE `usuarios`
    ADD COLUMN `activo` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1: cuenta activa; 0: cuenta desactivada',
    ADD CONSTRAINT `chk_gt_usuarios_activo`
        CHECK (`activo` IN (0, 1));

-- 2. Incidencias: todos los datos nuevos desconocidos permanecen en NULL.
-- tecnico_resolucion_id NO es una copia automatica de tecnico_id.
-- Solo se registrara si el autor autenticado que resuelve es un tecnico.
-- Si resuelve un administrador, el tecnico de resolucion queda NULL y el
-- administrador se identifica en incidencia_historial.usuario_id.
ALTER TABLE `incidencias`
    ADD COLUMN `titulo` VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Pendiente de completar para tickets antiguos',
    ADD COLUMN `categoria` ENUM('hardware', 'software', 'red') NULL DEFAULT NULL
        COMMENT 'NULL: pendiente de clasificacion; sin inferencia automatica',
    ADD COLUMN `tecnico_id` INT(11) NULL DEFAULT NULL
        COMMENT 'Responsable actual; NULL significa sin asignar',
    ADD COLUMN `fecha_actualizacion` DATETIME NULL DEFAULT NULL
        COMMENT 'UTC: ultimo cambio o comentario conocido',
    ADD COLUMN `fecha_resolucion` DATETIME NULL DEFAULT NULL
        COMMENT 'UTC: resolucion vigente; NULL si pendiente o desconocida',
    ADD COLUMN `tecnico_resolucion_id` INT(11) NULL DEFAULT NULL
        COMMENT 'Tecnico autor real de la resolucion vigente; no se infiere',
    ADD COLUMN `archivado` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1: archivado; 0: visible en las bandejas habituales',
    ADD COLUMN `fecha_archivado` DATETIME NULL DEFAULT NULL
        COMMENT 'UTC: momento del archivado vigente',
    ADD COLUMN `archivado_por` INT(11) NULL DEFAULT NULL
        COMMENT 'Usuario autenticado que realizo el archivado vigente',

    ADD INDEX `idx_gt_inc_tecnico_estado` (`tecnico_id`, `estado`),
    ADD INDEX `idx_gt_inc_categoria_estado` (`categoria`, `estado`),
    ADD INDEX `idx_gt_inc_fecha_resolucion` (`fecha_resolucion`),
    ADD INDEX `idx_gt_inc_tecnico_resolucion` (`tecnico_resolucion_id`),
    ADD INDEX `idx_gt_inc_archivado_estado` (`archivado`, `estado`),
    ADD INDEX `idx_gt_inc_archivado_por` (`archivado_por`),

    ADD CONSTRAINT `chk_gt_inc_titulo`
        CHECK (`titulo` IS NULL OR CHAR_LENGTH(TRIM(`titulo`)) > 0),
    ADD CONSTRAINT `chk_gt_inc_categoria`
        CHECK (`categoria` IS NULL OR `categoria` IN ('hardware', 'software', 'red')),
    ADD CONSTRAINT `chk_gt_inc_archivado`
        CHECK (`archivado` IN (0, 1)),
    ADD CONSTRAINT `chk_gt_inc_archivo_datos`
        CHECK (
            (`archivado` = 0 AND `fecha_archivado` IS NULL AND `archivado_por` IS NULL)
            OR
            (`archivado` = 1 AND `fecha_archivado` IS NOT NULL AND `archivado_por` IS NOT NULL)
        ),

    ADD CONSTRAINT `fk_gt_inc_tecnico`
        FOREIGN KEY (`tecnico_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_gt_inc_tecnico_resolucion`
        FOREIGN KEY (`tecnico_resolucion_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT `fk_gt_inc_archivado_por`
        FOREIGN KEY (`archivado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

-- 3. Historial unico para comentarios y eventos; inicialmente vacio.
-- INT con signo coincide con los IDs existentes. Las claves foraneas se
-- declaran completas, no mediante REFERENCES abreviado en una columna.
CREATE TABLE `incidencia_historial` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `incidencia_id` INT(11) NOT NULL,
    `usuario_id` INT(11) NOT NULL COMMENT 'Autor autenticado real del evento',
    `tipo` ENUM(
        'creacion', 'comentario', 'edicion', 'asignacion',
        'cambio_estado', 'archivado', 'desarchivado'
    ) NOT NULL,
    `detalle` TEXT NOT NULL,
    `fecha` DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
        COMMENT 'UTC: momento real del evento, no retroactivo',
    `estado_anterior` ENUM('Pendiente', 'En proceso', 'Resuelta') NULL DEFAULT NULL,
    `estado_nuevo` ENUM('Pendiente', 'En proceso', 'Resuelta') NULL DEFAULT NULL,
    `tecnico_anterior_id` INT(11) NULL DEFAULT NULL,
    `tecnico_nuevo_id` INT(11) NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_gt_hist_incidencia_fecha` (`incidencia_id`, `fecha`, `id`),
    KEY `idx_gt_hist_usuario` (`usuario_id`),
    KEY `idx_gt_hist_tecnico_anterior` (`tecnico_anterior_id`),
    KEY `idx_gt_hist_tecnico_nuevo` (`tecnico_nuevo_id`),

    CONSTRAINT `chk_gt_hist_tipo`
        CHECK (`tipo` IN (
            'creacion', 'comentario', 'edicion', 'asignacion',
            'cambio_estado', 'archivado', 'desarchivado'
        )),
    CONSTRAINT `chk_gt_hist_detalle`
        CHECK (CHAR_LENGTH(TRIM(`detalle`)) > 0),

    CONSTRAINT `fk_gt_hist_incidencia`
        FOREIGN KEY (`incidencia_id`) REFERENCES `incidencias` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_gt_hist_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_gt_hist_tecnico_anterior`
        FOREIGN KEY (`tecnico_anterior_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_gt_hist_tecnico_nuevo`
        FOREIGN KEY (`tecnico_nuevo_id`) REFERENCES `usuarios` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- REGLAS PARA LA FUTURA IMPLEMENTACION PHP (no implementadas por este SQL):
-- * Validar usuario activo, rol y asignacion en el servidor en cada operacion.
--   Una FK valida existencia; no valida rol, identidad del autor ni permisos.
-- * Limitar consultas/operaciones de tecnicos a sus tickets y reservar las
--   asignaciones/reasignaciones al administrador. Restringir listados publicos.
-- * Guardar cambio y evento en una transaccion, volviendo a comprobar la
--   asignacion para evitar escrituras de un tecnico despues de una reasignacion.
-- * Historial acumulativo, sin edicion/borrado desde la aplicacion. La creacion
--   publica sin autor autenticado no produce un evento con usuario inventado.
-- * Resolver: fecha UTC real y autor real; nunca copiar al tecnico asignado
--   sin comprobar que es quien realiza la accion. No alterar la fecha al
--   editar un ticket ya resuelto. Reabrir limpia fecha_resolucion y
--   tecnico_resolucion_id; el historial conserva las resoluciones anteriores.
-- * No rellenar fechas antiguas por cambiar titulo, categoria o asignacion.
--   Un ticket antiguo Resuelta con fecha desconocida sigue con fecha NULL.
-- * Promedios: solo Resuelta con fechas conocidas, fecha_resolucion >=
--   fecha_registro y conexion UTC. Atribuir por tecnico_resolucion_id, nunca por
--   asignacion actual. Resoluciones de administrador/sin autor conocido no se
--   atribuyen a tecnicos. Indicar muestra y excluir fechas desconocidas.
-- * Archivar: establecer indicador, fecha UTC y autor juntos, guardar evento y
--   actualizar fecha_actualizacion; conservar estado y datos de resolucion.
--   Desarchivar: indicador 0, fecha/autor de archivo NULL y un nuevo evento.
--   Los eventos anteriores preservan quien archivo y cuando. No usar DELETE.
--   Filtrar archivados en bandejas; no excluirlos silenciosamente de metricas
--   historicas (el filtro de archivado debera ser explicito).
-- * Desactivar cuentas sin borrar sus referencias. Reasignar pendientes antes
--   de desactivar o cambiar el rol de un tecnico; invalidar acceso y sesiones.
--
-- LIMITES DE COMPATIBILIDAD:
-- El PHP actual no conoce activo, archivado, historial ni permisos por ticket.
-- Esta migracion prepara almacenamiento, no habilita esas funciones.
-- RESTRICT bloqueara DELETE de tickets con historial y usuarios referenciados;
-- sin historial/referencias aun podria haber borrado fisico con el PHP antiguo.
-- Coordinar el despliegue posterior de archivado/desactivacion antes de usar
-- estas funciones; no deshabilitar las claves foraneas para permitir borrados.
--
-- VERIFICACIONES POSTERIORES EN LA COPIA DE PRUEBA:
-- Comparar campos originales, IDs, hashes, cantidades y AUTO_INCREMENT con el
-- respaldo; comprobar que solo cambiaron las estructuras previstas.
-- Verificar activo=1, archivado=0, nuevos datos desconocidos NULL e historial
-- vacio para los registros migrados. Inspeccionar indices y las siete FKs.
-- Probar altas/ediciones del PHP actual, rechazo de referencias inexistentes,
-- proteccion del historial, campos de archivado coherentes y fechas UTC.
-- Confirmar que titulo/categoria siguen aceptando NULL hasta el saneamiento
-- posterior, sin completar ni clasificar datos automaticamente.
