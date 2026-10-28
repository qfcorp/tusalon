-- 011 · Planes de $20 y $45 para todos los programas Tukán (decisión de Dimitry, 28 oct 2026).
--   - Básica $20 al mes: la base + 3 módulos que el salón elige. Cada módulo más: $5 al mes.
--   - Completa $45 al mes: todos los módulos, con el Asistente IA incluido (con el mismo tope de uso de siempre).
--   - Asistente IA en Básica: módulo extra de $5 al mes (no cuenta entre los 3 que elige).
--   TuSalón no tiene módulo de sedes: no hay nada que cobrar aparte por sede.
-- Columnas nuevas de planes:
--   modulos_elegir       cuántos módulos extra van incluidos en el precio del plan (0 = ninguno)
--   precio_modulo_extra  lo que cuesta cada módulo extra después de esos (NULL = el precio del catálogo)
-- Los precios y módulos se ponen UNA sola vez (marca 'planes_20_45' en ajustes): si después Dimitry los cambia desde el
-- panel, el instalador no los vuelve a pisar. Se puede correr muchas veces sin dañar nada.

ALTER TABLE planes ADD COLUMN IF NOT EXISTS modulos_elegir INT NOT NULL DEFAULT 0;
ALTER TABLE planes ADD COLUMN IF NOT EXISTS precio_modulo_extra NUMERIC(10,2);

-- Los salones en Básica que ya pagan (no las demos) conservan lo que les traía su plan: pasa a sus módulos extra
-- (cuentan entre los 3 que eligen). Normalmente Básica era solo la base y no cambia nada.
UPDATE salones s SET modulos_extra = trim(BOTH ',' FROM concat_ws(',', NULLIF(s.modulos_extra, ''), NULLIF(p.modulos, '')))
  FROM planes p
 WHERE p.codigo = s.plan_codigo AND s.plan_codigo = 'basica' AND s.estado <> 'prueba' AND p.modulos <> '' AND p.modulos <> '*'
   AND NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_20_45');

UPDATE planes SET nombre = 'Básica', precio_mensual = 20.00, modulos = '', modulos_elegir = 3, precio_modulo_extra = 5.00,
                  descripcion = 'La base + 3 módulos a tu elección. Cada módulo más: $5 al mes.'
 WHERE codigo = 'basica' AND NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_20_45');
UPDATE planes SET nombre = 'Completa', precio_mensual = 45.00, modulos = '*', modulos_elegir = 0, precio_modulo_extra = NULL,
                  max_profesionales = NULL, descripcion = 'Todos los módulos, con el Asistente IA incluido.'
 WHERE codigo = 'completa' AND NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_20_45');

-- Asistente IA $5 (si el súper usuario lo había cambiado en el catálogo, se pone en el precio nuevo)
UPDATE modulos SET precio_mensual = 5.00 WHERE id = 'asistente_ia' AND NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_20_45');

INSERT INTO ajustes (clave, valor) VALUES ('planes_20_45', '2026-10-28') ON CONFLICT (clave) DO NOTHING;
