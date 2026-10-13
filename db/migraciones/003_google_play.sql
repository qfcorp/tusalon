-- 003 · Cobro de planes con Google Play (app de Android).
-- Se puede correr muchas veces sin dañar nada.

CREATE TABLE IF NOT EXISTS google_play_compras (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    producto              VARCHAR(80) NOT NULL,          -- ej. tusalon_completa_mensual
    plan_codigo           VARCHAR(20) NOT NULL REFERENCES planes(codigo),
    periodo               VARCHAR(10) NOT NULL CHECK (periodo IN ('mensual','semestral','anual')),
    token                 TEXT NOT NULL UNIQUE,          -- purchaseToken de Google
    pedido                VARCHAR(80),                   -- orderId de Google (GPA.xxxx)
    estado                VARCHAR(40) NOT NULL,          -- SUBSCRIPTION_STATE_ACTIVE, _CANCELED, _EXPIRED...
    expira                TIMESTAMP,
    renueva_sola          BOOLEAN NOT NULL DEFAULT true,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    revisado_en           TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_gp_salon ON google_play_compras (salon_id);
CREATE INDEX IF NOT EXISTS idx_gp_expira ON google_play_compras (expira);

-- De dónde vino cada pago del plan (web o Google Play)
ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS google_play_id INT REFERENCES google_play_compras(id) ON DELETE SET NULL;
