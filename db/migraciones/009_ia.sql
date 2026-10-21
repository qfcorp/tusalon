-- 009 · IA de Tukán (compartido/tukan_ia.php del Panel Tukán Central, guía compartido/LEEME_IA.md, Anexo C de PROTOCOLO_V2.md).
--   tukan_ia_faq: preguntas frecuentes de la Ayuda (las mismas para todos los salones). 'automatica' = la escribió la IA,
--                 'manual' = una persona (el súper usuario). aprobada = 1 cuando el súper la revisó.
--   tukan_ia_gasto: lo que gastó cada salón en IA cada mes (negocio_id = id del salón, mes = '2026-10').
--   salones.ia_tope_usd: tope del mes en USD que puso Tukán (vacío = el sugerido: $0,50 sin el módulo «Asistente IA»,
--                 $3,00 con el módulo).
-- Se puede correr muchas veces sin dañar nada.

CREATE TABLE IF NOT EXISTS tukan_ia_faq (
  id BIGSERIAL PRIMARY KEY,
  pregunta VARCHAR(500) NOT NULL,
  pregunta_normalizada VARCHAR(500) NOT NULL,
  respuesta TEXT NOT NULL,
  usos INTEGER NOT NULL DEFAULT 0,
  origen VARCHAR(12) NOT NULL DEFAULT 'automatica',
  aprobada SMALLINT NOT NULL DEFAULT 0,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_ia_faq_norm ON tukan_ia_faq (pregunta_normalizada);

CREATE TABLE IF NOT EXISTS tukan_ia_gasto (
  negocio_id VARCHAR(64) NOT NULL,
  mes CHAR(7) NOT NULL,
  costo_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
  llamadas INTEGER NOT NULL DEFAULT 0,
  fallidas INTEGER NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  ahorradas INTEGER NOT NULL DEFAULT 0,
  ayudas_nuevas INTEGER NOT NULL DEFAULT 0,
  actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (negocio_id, mes)
);

ALTER TABLE salones ADD COLUMN IF NOT EXISTS ia_tope_usd NUMERIC(10,4);
