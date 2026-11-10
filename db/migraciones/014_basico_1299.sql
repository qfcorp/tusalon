-- =====================================================================
-- 014 — El plan Básico pasa de $12 a $12,99 al mes (decisión de Dimitry, 10 nov 2026, Excel «Precios y planes Tukán»).
--   Más IVA, igual que siempre (con tarjeta se cobra 14,94). Semestral y anual se calculan solos del mensual (5 y 10 meses).
--   No cambia nada más: sigue con la base + 3 módulos a elegir, cada módulo más $5, el Asistente IA $3 y el Completo $25.
-- Se pone UNA sola vez (marca 'basico_1299' en ajustes) y solo si el Básico seguía en $12: si Dimitry ya lo cambió desde el
-- Panel Tukán Central, no se pisa. No toca el precio acordado de ningún cliente.
-- =====================================================================
SET client_min_messages = warning;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'basico_1299') THEN
        UPDATE planes SET precio_mensual = 12.99 WHERE codigo = 'basica' AND precio_mensual = 12.00;
        INSERT INTO ajustes (clave, valor) VALUES ('basico_1299', '2026-11-10');
    END IF;
END $$;
