-- 001 · Panel de super administrador (Tukán), ajustes de planes y vencimiento de planes.
-- Se puede correr muchas veces sin dañar nada.

CREATE TABLE IF NOT EXISTS superadmins (
    id                    SERIAL PRIMARY KEY,
    nombre                VARCHAR(100) NOT NULL,
    email                 VARCHAR(120) NOT NULL UNIQUE,
    password_hash         VARCHAR(255) NOT NULL,
    activo                BOOLEAN NOT NULL DEFAULT true,
    creado_en             TIMESTAMP NOT NULL DEFAULT now(),
    ultimo_ingreso        TIMESTAMP
);

-- Ajustes generales que el super administrador cambia desde su panel
CREATE TABLE IF NOT EXISTS ajustes (
    clave                 VARCHAR(40) PRIMARY KEY,
    valor                 VARCHAR(200) NOT NULL
);
INSERT INTO ajustes (clave, valor) VALUES
    ('dias_prueba', '7'),
    ('semestral_paga', '5'), ('semestral_recibe', '6'),
    ('anual_paga', '10'),    ('anual_recibe', '12'),
    ('dias_gracia', '3'),
    ('whatsapp_ventas', '593996408397')
ON CONFLICT (clave) DO NOTHING;

-- Qué hizo el super administrador y cuándo
CREATE TABLE IF NOT EXISTS registro_admin (
    id                    SERIAL PRIMARY KEY,
    admin_id              INT REFERENCES superadmins(id) ON DELETE SET NULL,
    salon_id              INT REFERENCES salones(id) ON DELETE SET NULL,
    accion                VARCHAR(300) NOT NULL,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);

ALTER TABLE salones ADD COLUMN IF NOT EXISTS activo_hasta DATE;        -- hasta cuándo está pagado
ALTER TABLE salones ADD COLUMN IF NOT EXISTS notas_admin TEXT;         -- notas internas de Tukán
ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS pagado_en DATE NOT NULL DEFAULT CURRENT_DATE;
ALTER TABLE suscripciones ADD COLUMN IF NOT EXISTS anulada BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE planes ADD COLUMN IF NOT EXISTS descripcion VARCHAR(300);
