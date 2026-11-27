-- 016 · País y ciudad del cliente (27 nov 2026): regla «exterior = sin IVA» de TuFactura. País (Ecuador por defecto) y ciudad opcional;
--   y la dirección del cliente (para la factura). Con cédula o RUC ecuatoriano válido siempre se factura con IVA; con otro país y sin
--   cédula/RUC válido, la factura sale sin IVA. No toca los datos de los clientes que ya existen; se puede correr muchas veces sin dañar nada.
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS pais      VARCHAR(60) NOT NULL DEFAULT 'Ecuador';
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS ciudad    VARCHAR(80);
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS direccion VARCHAR(255);
