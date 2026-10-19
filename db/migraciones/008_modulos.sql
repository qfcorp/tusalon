-- 008 · TuSalón por módulos (PROTOCOLO_V2.md del Panel Tukán Central, Anexo B) y "todo se pide demo".
--   modulos: lo que el súper usuario cambió del catálogo (precio, descuento, descripción, orden). Los módulos y sus
--            valores por defecto están en src/Modulos.php; una fila aquí solo pisa esos valores.
--   planes.modulos: módulos que trae cada plan ('*' = todos). Básica = ninguno (solo la base), Completa = todos.
--   salones.modulos_extra / modulos_quitados: módulos encendidos además del plan (se cobran aparte) y módulos del plan
--            que se apagaron (con su descuento). salones.precio_acordado: precio fijo al mes (vacío = el calculado).
--            salones.modulos_demo: en una demo, los módulos que eligió el súper usuario (vacío = todos).
--   modulos_cambios: quién encendió o apagó qué, cuándo y por qué.
--   pedidos_modulos: "Pedir este módulo" desde Mi plan (llega al Panel Tukán Central como cambio_modulos).
--   pedidos_demo: el formulario público "Solicitar demo" (ya no hay prueba gratis: llega como demo_pedida).
-- Apagar un módulo nunca borra datos: solo se esconden sus pantallas, botones, avisos y tareas.
-- Se puede correr muchas veces sin dañar nada.

CREATE TABLE IF NOT EXISTS modulos (
    id              VARCHAR(40) PRIMARY KEY,
    nombre          VARCHAR(80),
    descripcion     VARCHAR(400),
    precio_mensual  NUMERIC(10,2),
    descuento       NUMERIC(10,2),
    orden           INT,
    actualizado     TIMESTAMP NOT NULL DEFAULT now()
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'planes' AND column_name = 'modulos') THEN
        ALTER TABLE planes ADD COLUMN modulos TEXT NOT NULL DEFAULT '';
        UPDATE planes SET modulos = '*' WHERE codigo = 'completa';
    END IF;
END $$;

ALTER TABLE salones ADD COLUMN IF NOT EXISTS modulos_extra TEXT NOT NULL DEFAULT '';
ALTER TABLE salones ADD COLUMN IF NOT EXISTS modulos_quitados TEXT NOT NULL DEFAULT '';
ALTER TABLE salones ADD COLUMN IF NOT EXISTS modulos_demo TEXT;
ALTER TABLE salones ADD COLUMN IF NOT EXISTS precio_acordado NUMERIC(10,2);

CREATE TABLE IF NOT EXISTS modulos_cambios (
    id              SERIAL PRIMARY KEY,
    salon_id        INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    momento         TIMESTAMP NOT NULL DEFAULT now(),
    quien           VARCHAR(200) NOT NULL,
    origen          VARCHAR(12) NOT NULL,              -- panel | central | demo
    encendidos      TEXT NOT NULL DEFAULT '',
    apagados        TEXT NOT NULL DEFAULT '',
    precio_antes    NUMERIC(10,2),
    precio_despues  NUMERIC(10,2),
    acordado_antes  NUMERIC(10,2),
    acordado        NUMERIC(10,2),
    motivo          VARCHAR(300)
);
CREATE INDEX IF NOT EXISTS idx_modulos_cambios ON modulos_cambios (salon_id, momento);

CREATE TABLE IF NOT EXISTS pedidos_modulos (
    id              SERIAL PRIMARY KEY,
    salon_id        INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    usuario_id      INT REFERENCES usuarios(id) ON DELETE SET NULL,
    modulos         TEXT NOT NULL,
    nota            VARCHAR(300),
    estado          VARCHAR(12) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','en_proceso','atendida','descartada')),
    creado          TIMESTAMP NOT NULL DEFAULT now(),
    actualizado     TIMESTAMP NOT NULL DEFAULT now(),
    atendido_por    VARCHAR(200),
    respuesta       VARCHAR(300)
);
CREATE INDEX IF NOT EXISTS idx_pedidos_modulos ON pedidos_modulos (salon_id, estado);

CREATE TABLE IF NOT EXISTS pedidos_demo (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL,
    salon           VARCHAR(120) NOT NULL,
    ciudad          VARCHAR(80),
    whatsapp        VARCHAR(30) NOT NULL,
    email           VARCHAR(120) NOT NULL,
    necesita        VARCHAR(600),
    modulos         TEXT NOT NULL DEFAULT '',
    estado          VARCHAR(12) NOT NULL DEFAULT 'nueva' CHECK (estado IN ('nueva','contactada','demo_creada','descartada')),
    creado          TIMESTAMP NOT NULL DEFAULT now(),
    actualizado     TIMESTAMP NOT NULL DEFAULT now(),
    ip              VARCHAR(45),
    salon_creado_id INT REFERENCES salones(id) ON DELETE SET NULL,
    atendido_por    VARCHAR(200),
    nota            VARCHAR(300)
);
CREATE INDEX IF NOT EXISTS idx_pedidos_demo ON pedidos_demo (estado, creado);
