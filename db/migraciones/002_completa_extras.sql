-- 002 · Plan Completa: inventario, paquetes, tarjetas de regalo, puntos,
--       ficha técnica de color con fotos y anticipo en línea.
-- Se puede correr muchas veces sin dañar nada.

-- ---------------------------------------------------------------
-- Ingresos que no son un servicio: venta de paquetes, tarjetas de regalo y anticipos.
-- Entran a la caja el día que se cobran.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingresos (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    fecha                 TIMESTAMP NOT NULL DEFAULT now(),
    concepto              VARCHAR(15) NOT NULL CHECK (concepto IN ('paquete','tarjeta_regalo','anticipo')),
    referencia_id         INT,                          -- id del paquete vendido, de la tarjeta o de la cita
    cliente_id            INT REFERENCES clientes(id) ON DELETE SET NULL,
    descripcion           VARCHAR(200) NOT NULL,
    metodo_pago           VARCHAR(20) NOT NULL DEFAULT 'efectivo'
                          CHECK (metodo_pago IN ('efectivo','tarjeta','transferencia','payphone','plux','deuna')),
    monto                 NUMERIC(10,2) NOT NULL CHECK (monto > 0),
    anulado               BOOLEAN NOT NULL DEFAULT false
);
CREATE INDEX IF NOT EXISTS idx_ingresos_fecha ON ingresos (salon_id, fecha);

-- Parte de una venta que ya estaba pagada antes (no entra dinero nuevo a la caja ese día)
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pagado_paquete  NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pagado_tarjeta  NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pagado_puntos   NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pagado_anticipo NUMERIC(10,2) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------
-- Inventario
-- ---------------------------------------------------------------
ALTER TABLE productos ADD COLUMN IF NOT EXISTS activo       BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS para_venta   BOOLEAN NOT NULL DEFAULT true;  -- false = solo para usar en el salón (tintes, oxidantes)
ALTER TABLE productos ADD COLUMN IF NOT EXISTS stock_minimo INT NOT NULL DEFAULT 0;         -- avisar cuando quede esto o menos
ALTER TABLE productos ADD COLUMN IF NOT EXISTS marca        VARCHAR(60);
ALTER TABLE productos ADD COLUMN IF NOT EXISTS creado_en    TIMESTAMP NOT NULL DEFAULT now();

CREATE TABLE IF NOT EXISTS movimientos_inventario (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    producto_id           INT NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    fecha                 TIMESTAMP NOT NULL DEFAULT now(),
    tipo                  VARCHAR(10) NOT NULL CHECK (tipo IN ('inicial','entrada','venta','uso','ajuste')),
    cantidad              INT NOT NULL,                 -- positivo = entra, negativo = sale
    costo_unitario        NUMERIC(10,2),
    venta_id              INT REFERENCES ventas(id) ON DELETE SET NULL,
    usuario_id            INT REFERENCES usuarios(id) ON DELETE SET NULL,
    nota                  VARCHAR(200)
);
CREATE INDEX IF NOT EXISTS idx_mov_inv ON movimientos_inventario (producto_id, fecha);

-- ---------------------------------------------------------------
-- Paquetes (ej. "5 cortes por $40") y lo que compró cada cliente
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paquetes (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    nombre                VARCHAR(100) NOT NULL,
    servicio_id           INT NOT NULL REFERENCES servicios(id),
    sesiones              INT NOT NULL CHECK (sesiones BETWEEN 2 AND 100),
    precio                NUMERIC(10,2) NOT NULL CHECK (precio > 0),
    vigencia_dias         INT CHECK (vigencia_dias IS NULL OR vigencia_dias > 0),   -- NULL = no vence
    activo                BOOLEAN NOT NULL DEFAULT true,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS paquetes_cliente (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    paquete_id            INT REFERENCES paquetes(id) ON DELETE SET NULL,
    cliente_id            INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
    servicio_id           INT NOT NULL REFERENCES servicios(id),
    nombre                VARCHAR(100) NOT NULL,
    sesiones_total        INT NOT NULL,
    sesiones_usadas       INT NOT NULL DEFAULT 0,
    precio_pagado         NUMERIC(10,2) NOT NULL,
    valor_sesion          NUMERIC(10,2) NOT NULL,       -- precio pagado / sesiones (lo que vale cada uso)
    comprado_en           TIMESTAMP NOT NULL DEFAULT now(),
    vence_en              DATE,
    anulado               BOOLEAN NOT NULL DEFAULT false,
    CHECK (sesiones_usadas BETWEEN 0 AND sesiones_total)
);
CREATE INDEX IF NOT EXISTS idx_paq_cliente ON paquetes_cliente (cliente_id);

CREATE TABLE IF NOT EXISTS paquete_usos (
    id                    SERIAL PRIMARY KEY,
    paquete_cliente_id    INT NOT NULL REFERENCES paquetes_cliente(id) ON DELETE CASCADE,
    venta_id              INT REFERENCES ventas(id) ON DELETE SET NULL,
    fecha                 TIMESTAMP NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------
-- Tarjetas de regalo (código + saldo)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tarjetas_regalo (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    codigo                VARCHAR(12) NOT NULL,
    monto                 NUMERIC(10,2) NOT NULL CHECK (monto > 0),
    saldo                 NUMERIC(10,2) NOT NULL CHECK (saldo >= 0),
    de_nombre             VARCHAR(100),                 -- quién la regala
    para_nombre           VARCHAR(100),                 -- para quién
    para_telefono         VARCHAR(20),
    mensaje               VARCHAR(200),
    vence_en              DATE,
    anulada               BOOLEAN NOT NULL DEFAULT false,
    creada_en             TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (salon_id, codigo)
);

CREATE TABLE IF NOT EXISTS tarjeta_usos (
    id                    SERIAL PRIMARY KEY,
    tarjeta_id            INT NOT NULL REFERENCES tarjetas_regalo(id) ON DELETE CASCADE,
    venta_id              INT REFERENCES ventas(id) ON DELETE SET NULL,
    monto                 NUMERIC(10,2) NOT NULL,
    fecha                 TIMESTAMP NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------
-- Puntos de fidelidad
-- ---------------------------------------------------------------
ALTER TABLE salones ADD COLUMN IF NOT EXISTS puntos_activo        BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE salones ADD COLUMN IF NOT EXISTS puntos_por_dolar     NUMERIC(6,2) NOT NULL DEFAULT 1;      -- puntos que gana por cada $1
ALTER TABLE salones ADD COLUMN IF NOT EXISTS puntos_valor         NUMERIC(8,4) NOT NULL DEFAULT 0.05;   -- cuánto vale 1 punto en dólares
ALTER TABLE salones ADD COLUMN IF NOT EXISTS puntos_minimo_canje  INT NOT NULL DEFAULT 100;
ALTER TABLE clientes ADD COLUMN IF NOT EXISTS puntos INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS puntos_movimientos (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    cliente_id            INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
    venta_id              INT REFERENCES ventas(id) ON DELETE SET NULL,
    puntos                INT NOT NULL,                 -- positivo = gana, negativo = canjea
    motivo                VARCHAR(120) NOT NULL,
    fecha                 TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_puntos_cliente ON puntos_movimientos (cliente_id, fecha);

-- ---------------------------------------------------------------
-- Ficha técnica de color (la tabla ya existía; se completa)
-- ---------------------------------------------------------------
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS salon_id      INT REFERENCES salones(id) ON DELETE CASCADE;
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS servicio      VARCHAR(100);     -- tinte, mechas, balayage, decoloración...
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS formula       TEXT;             -- mezcla exacta (ej. 30 g 7.1 + 10 g 8.0)
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS resultado     VARCHAR(200);
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS autoriza_fotos BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE fichas_tecnicas ADD COLUMN IF NOT EXISTS creada_en     TIMESTAMP NOT NULL DEFAULT now();
CREATE INDEX IF NOT EXISTS idx_fichas_cliente ON fichas_tecnicas (cliente_id, fecha);

-- ---------------------------------------------------------------
-- Anticipo en línea (para que el cliente no falte)
-- ---------------------------------------------------------------
ALTER TABLE salones ADD COLUMN IF NOT EXISTS anticipo_modo     VARCHAR(10) NOT NULL DEFAULT 'no';      -- no | todos | nuevos | faltas
ALTER TABLE salones ADD COLUMN IF NOT EXISTS anticipo_tipo     VARCHAR(10) NOT NULL DEFAULT 'porcentaje'; -- porcentaje | fijo
ALTER TABLE salones ADD COLUMN IF NOT EXISTS anticipo_valor    NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE salones ADD COLUMN IF NOT EXISTS anticipo_banco    TEXT;            -- datos para transferencia
ALTER TABLE salones ADD COLUMN IF NOT EXISTS anticipo_deuna    VARCHAR(20);     -- celular DeUna
ALTER TABLE salones ADD COLUMN IF NOT EXISTS payphone_token    TEXT;            -- claves de Payphone del salón
ALTER TABLE salones ADD COLUMN IF NOT EXISTS payphone_store_id VARCHAR(80);

ALTER TABLE citas ADD COLUMN IF NOT EXISTS anticipo_estado      VARCHAR(12) NOT NULL DEFAULT 'no';  -- no | pendiente | por_revisar | pagado | rechazado | usado | devuelto
ALTER TABLE citas ADD COLUMN IF NOT EXISTS anticipo_metodo      VARCHAR(20);
ALTER TABLE citas ADD COLUMN IF NOT EXISTS anticipo_comprobante VARCHAR(80);    -- foto del comprobante (fuera de la carpeta pública)
ALTER TABLE citas ADD COLUMN IF NOT EXISTS anticipo_referencia  VARCHAR(100);   -- código de Payphone o de la transferencia
ALTER TABLE citas ADD COLUMN IF NOT EXISTS anticipo_pagado_en   TIMESTAMP;
