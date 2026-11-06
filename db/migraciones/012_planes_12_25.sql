-- =====================================================================
-- 012 — Precios de $12 y $25 para todos los programas Tukán (decisión de Dimitry, 6 nov 2026).
--   «El básico más 3 módulos 12 más IVA, cada módulo el mismo valor 5 dólares… el plan total cuesta 25 más IVA todo incluido,
--    los 3 dólares de IA… uso limitado. El plan básico debe ser atractivo.»
--   - Básica (basica): $12 al mes, la base + 3 módulos que el cliente elige; cada módulo más $5.
--   - Completa (completa): $25 al mes, todos los módulos con el Asistente IA incluido (con tope de uso: Ia::TOPE de $1,50 al mes).
--   - Catálogo: cada módulo $5; el Asistente IA $3 (en Básica va aparte y no cuenta entre los 3). Los módulos gratis siguen
--     gratis.
--   - Los nombres y los módulos de cada plan NO cambian (solo precios). Lo mismo se puede cambiar después desde el Panel Tukán
--     Central → Módulos y precios.
-- Se pone UNA sola vez (marca 'planes_12_25' en ajustes): si después Dimitry cambia precios desde el panel, el instalador no los
-- vuelve a pisar. Se puede correr muchas veces sin dañar nada.
-- =====================================================================
SET client_min_messages = warning;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'planes_12_25') THEN
        UPDATE planes SET precio_mensual = 12.00, modulos_elegir = 3, precio_modulo_extra = 5.00 WHERE codigo = 'basica';
        UPDATE planes SET precio_mensual = 25.00 WHERE codigo = 'completa';
        INSERT INTO modulos (id, precio_mensual) VALUES
            ('reservas_linea', 5.00),
            ('anticipos', 5.00),
            ('ficha_tecnica', 5.00),
            ('recuperar_clientes', 5.00),
            ('equipo_ilimitado', 5.00),
            ('portal_peluquero', 5.00),
            ('clientes_privados', 5.00),
            ('recepcion', 5.00),
            ('inventario', 5.00),
            ('paquetes_regalo', 5.00),
            ('puntos', 5.00),
            ('gastos_sri', 5.00),
            ('reportes', 5.00),
            ('telegram', 5.00),
            ('asistente_ia', 3.00)
        ON CONFLICT (id) DO UPDATE SET precio_mensual = EXCLUDED.precio_mensual, actualizado = now();
        INSERT INTO ajustes (clave, valor) VALUES ('planes_12_25', '2026-11-06');
    END IF;
END $$;
