-- 006 · Facturas recibidas con pantalla (Más → Gastos y facturas), clientes privados de quien alquila
--       el puesto y usuario de recepción.
--   facturas_recibidas: tipo (factura, nota de crédito…), número, detalle de lo comprado, a nombre de quién
--                       vino (id_comprador), de dónde se importó (xml, txt, sri, clave) y el XML autorizado.
--                       "completa" = ya tiene el XML con el detalle (las que llegan solo del TXT se completan
--                       con el botón "Completar con el SRI").
--   clientes.creado_por_usuario: quién creó la ficha (para los clientes privados del arrendatario).
--   usuarios.creado_en: cuándo se creó la cuenta (lista de recepción).
-- Se puede correr muchas veces sin dañar nada.

ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS tipo VARCHAR(15) NOT NULL DEFAULT 'factura';
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS numero VARCHAR(20);
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS detalle VARCHAR(400);
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS id_comprador VARCHAR(20);
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS origen VARCHAR(6) NOT NULL DEFAULT 'xml';
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS completa BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS xml TEXT;
ALTER TABLE facturas_recibidas ADD COLUMN IF NOT EXISTS importada_por INT REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE clientes ADD COLUMN IF NOT EXISTS creado_por_usuario INT REFERENCES usuarios(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_clientes_privados ON clientes (salon_id, profesional_privado_id) WHERE profesional_privado_id IS NOT NULL;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS creado_en TIMESTAMP NOT NULL DEFAULT now();
