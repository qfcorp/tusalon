-- 015 · Demos automáticas de 3 días (10 nov 2026, pedido de Dimitry). Se puede correr muchas veces.
--   pedidos_demo: el formulario «Solicitar demo» ahora pide 7 datos obligatorios; se agregan el teléfono de contacto y la dirección
--   (el celular va en la columna whatsapp, como antes).
--   dias_prueba: las demos duran 3 días. Se cambia UNA sola vez (marca demos_3_dias) y solo si seguía en el 7 de fábrica:
--   no pisa un valor que Tukán ya haya cambiado.
ALTER TABLE pedidos_demo ADD COLUMN IF NOT EXISTS telefono VARCHAR(30);
ALTER TABLE pedidos_demo ADD COLUMN IF NOT EXISTS direccion VARCHAR(200);
UPDATE ajustes SET valor = '3' WHERE clave = 'dias_prueba' AND valor = '7' AND NOT EXISTS (SELECT 1 FROM ajustes WHERE clave = 'demos_3_dias');
INSERT INTO ajustes (clave, valor) VALUES ('demos_3_dias', '2026-11-10') ON CONFLICT (clave) DO NOTHING;
