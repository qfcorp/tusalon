-- 007 · Pausar y borrar clientes desde el Panel Tukán Central (PROTOCOLO_V2.md del Central, Anexo A).
--   salones.pausa_tipo / pausa_motivo / pausa_en: por qué Tukán pausó la cuenta (estado = 'suspendido'). El salón ve el
--                motivo, salvo en "revision" y "abuso" (texto genérico). Reactivar los borra.
--   salones.eliminado_en / eliminado_motivo / eliminado_por / eliminado_copia: papelera. Un salón con eliminado_en no
--                entra, no sale en listas ni cifras y su página de reservas da 404. Se restaura dentro de 30 días; después
--                bin/tareas.php lo borra de verdad. eliminado_copia = ruta del JSON comprimido con todas sus filas.
-- Se puede correr muchas veces sin dañar nada.

ALTER TABLE salones ADD COLUMN IF NOT EXISTS pausa_tipo VARCHAR(20);
ALTER TABLE salones ADD COLUMN IF NOT EXISTS pausa_motivo VARCHAR(300);
ALTER TABLE salones ADD COLUMN IF NOT EXISTS pausa_en TIMESTAMP;
ALTER TABLE salones ADD COLUMN IF NOT EXISTS eliminado_en TIMESTAMP;
ALTER TABLE salones ADD COLUMN IF NOT EXISTS eliminado_motivo VARCHAR(300);
ALTER TABLE salones ADD COLUMN IF NOT EXISTS eliminado_por VARCHAR(200);
ALTER TABLE salones ADD COLUMN IF NOT EXISTS eliminado_copia TEXT;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'salones_pausa_tipo_check') THEN
        ALTER TABLE salones ADD CONSTRAINT salones_pausa_tipo_check
            CHECK (pausa_tipo IS NULL OR pausa_tipo IN ('falta_pago','pedido_cliente','revision','abuso','otro'));
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS salones_eliminado_en ON salones (eliminado_en) WHERE eliminado_en IS NOT NULL;
