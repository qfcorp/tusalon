-- =====================================================================
-- TuSalón — Esquema de base de datos (PostgreSQL 16)
-- Un solo sistema para muchos salones: cada tabla lleva salon_id.
-- Los 4 tipos de peluquero: dueno, empleado, porcentaje, alquiler.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------
-- Parámetros legales por año (salario básico, IESS)
-- ---------------------------------------------------------------
CREATE TABLE parametros_anuales (
    anio                  INT PRIMARY KEY,
    salario_basico        NUMERIC(10,2) NOT NULL,      -- SBU
    iess_personal_pct     NUMERIC(5,2)  NOT NULL,      -- 9.45
    iess_patronal_pct     NUMERIC(5,2)  NOT NULL,      -- 11.15
    fondos_reserva_pct    NUMERIC(5,2)  NOT NULL       -- 8.33
);

-- ---------------------------------------------------------------
-- Planes de TuSalón y suscripciones
-- ---------------------------------------------------------------
CREATE TABLE planes (
    codigo                VARCHAR(20) PRIMARY KEY,     -- basica, completa
    nombre                VARCHAR(50)  NOT NULL,
    precio_mensual        NUMERIC(10,2) NOT NULL,
    max_profesionales     INT,                          -- NULL = ilimitado
    max_sucursales        INT                           -- NULL = ilimitado
);

CREATE TABLE salones (
    id                    SERIAL PRIMARY KEY,
    nombre                VARCHAR(120) NOT NULL,
    slug                  VARCHAR(60)  NOT NULL UNIQUE, -- tusalon.app/<slug>
    ruc                   VARCHAR(13),
    telefono              VARCHAR(20),
    plan_codigo           VARCHAR(20)  NOT NULL REFERENCES planes(codigo),
    estado                VARCHAR(15)  NOT NULL DEFAULT 'prueba'
                          CHECK (estado IN ('prueba','activo','suspendido','cancelado')),
    prueba_hasta          DATE,                         -- 7 días gratis
    intervalo_reservas    INT NOT NULL DEFAULT 15,      -- cada cuántos minutos se ofrecen horas
    anticipacion_minutos  INT NOT NULL DEFAULT 30,      -- reservar con al menos X minutos de anticipación
    -- Reservas en línea: quién las acepta y a quién se avisa (lo programa el dueño)
    acepta_reservas       VARCHAR(12) NOT NULL DEFAULT 'dueno'
                          CHECK (acepta_reservas IN ('automatico','dueno','peluquero','cualquiera')),
    avisar_a              VARCHAR(10) NOT NULL DEFAULT 'ambos'
                          CHECK (avisar_a IN ('dueno','peluquero','ambos')),
    minutos_para_aceptar  INT NOT NULL DEFAULT 120,     -- si nadie acepta, la hora se libera
    creado_en             TIMESTAMP    NOT NULL DEFAULT now()
);

CREATE TABLE suscripciones (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    plan_codigo           VARCHAR(20) NOT NULL REFERENCES planes(codigo),
    periodo               VARCHAR(10) NOT NULL CHECK (periodo IN ('mensual','semestral','anual')),
    meses_pagados         INT NOT NULL,                 -- 1, 5 o 10
    meses_recibidos       INT NOT NULL,                 -- 1, 6 o 12
    monto                 NUMERIC(10,2) NOT NULL,
    inicio                DATE NOT NULL,
    fin                   DATE NOT NULL,
    metodo_pago           VARCHAR(20),                  -- payphone, plux, transferencia
    incluye_dominio       BOOLEAN NOT NULL DEFAULT false,
    dominio               VARCHAR(120),                 -- registrado a nombre de Tukán
    CHECK (meses_recibidos >= meses_pagados)
);

-- ---------------------------------------------------------------
-- Sucursales y usuarios
-- ---------------------------------------------------------------
CREATE TABLE sucursales (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    nombre                VARCHAR(80) NOT NULL,
    direccion             VARCHAR(200)
);

-- Horario de atención del salón (0 = domingo ... 6 = sábado). Sin fila = cerrado.
CREATE TABLE horarios (
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    dia_semana            INT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    abre                  TIME NOT NULL,
    cierra                TIME NOT NULL,
    PRIMARY KEY (salon_id, dia_semana),
    CHECK (cierra > abre)
);

-- ---------------------------------------------------------------
-- Profesionales (peluqueros) y su forma de pago
-- ---------------------------------------------------------------
CREATE TABLE profesionales (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    sucursal_id           INT REFERENCES sucursales(id),
    nombre                VARCHAR(100) NOT NULL,
    cedula                VARCHAR(13),
    telefono              VARCHAR(20),
    tipo                  VARCHAR(12) NOT NULL
                          CHECK (tipo IN ('dueno','empleado','porcentaje','alquiler')),
    fecha_ingreso         DATE NOT NULL DEFAULT CURRENT_DATE,
    activo                BOOLEAN NOT NULL DEFAULT true,
    color_agenda          VARCHAR(7) DEFAULT '#6B5BD2'
);

-- Regla de pago vigente de cada profesional (se guarda historial).
CREATE TABLE reglas_pago (
    id                    SERIAL PRIMARY KEY,
    profesional_id        INT NOT NULL REFERENCES profesionales(id) ON DELETE CASCADE,
    vigente_desde         DATE NOT NULL,
    vigente_hasta         DATE,
    -- Empleado
    sueldo_mensual        NUMERIC(10,2) DEFAULT 0,
    comision_servicio_pct NUMERIC(5,2)  DEFAULT 0,
    comision_producto_pct NUMERIC(5,2)  DEFAULT 0,      -- también para arrendatario que vende productos del local
    afiliado_iess         BOOLEAN DEFAULT false,
    garantizar_basico     BOOLEAN DEFAULT true,         -- completar hasta el SBU si no llega
    -- Porcentaje
    pct_profesional       NUMERIC(5,2),                 -- 50, 60...
    quien_cobra           VARCHAR(12) DEFAULT 'local'
                          CHECK (quien_cobra IN ('local','profesional')),
    -- Alquiler
    monto_arriendo        NUMERIC(10,2),
    frecuencia_arriendo   VARCHAR(10)
                          CHECK (frecuencia_arriendo IN ('semanal','quincenal','mensual')),
    CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
);

-- Escalas de comisión (plan Completa): "si pasa de X, sube a Y %".
CREATE TABLE escalas_comision (
    id                    SERIAL PRIMARY KEY,
    regla_id              INT NOT NULL REFERENCES reglas_pago(id) ON DELETE CASCADE,
    desde_monto           NUMERIC(10,2) NOT NULL,
    pct                   NUMERIC(5,2)  NOT NULL
);

CREATE TABLE usuarios (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    profesional_id        INT REFERENCES profesionales(id),
    nombre                VARCHAR(100) NOT NULL,
    email                 VARCHAR(120) NOT NULL UNIQUE,
    password_hash         VARCHAR(255) NOT NULL,
    rol                   VARCHAR(12) NOT NULL
                          CHECK (rol IN ('dueno','admin','recepcion','profesional')),
    telegram_chat_id      BIGINT,                       -- avisos por Telegram (lo conecta el propio usuario)
    telegram_codigo       VARCHAR(32),                  -- código de un solo uso para conectar Telegram
    activo                BOOLEAN NOT NULL DEFAULT true
);

-- ---------------------------------------------------------------
-- Clientes y ficha técnica
-- ---------------------------------------------------------------
CREATE TABLE clientes (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    -- Si tiene valor, el cliente es privado de ese arrendatario.
    profesional_privado_id INT REFERENCES profesionales(id),
    nombre                VARCHAR(120) NOT NULL,
    telefono              VARCHAR(20),
    email                 VARCHAR(120),
    cedula                VARCHAR(13),
    fecha_nacimiento      DATE,
    alergias              TEXT,
    notas                 TEXT,
    -- Cuenta opcional del cliente (para ver su historial). Sin contraseña = cliente invitado.
    password_hash         VARCHAR(255),
    acepta_fotos          BOOLEAN NOT NULL DEFAULT false,  -- permiso para guardar fotos de sus servicios
    cuenta_creada_en      TIMESTAMP,
    creado_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX uq_cuenta_cliente ON clientes (salon_id, lower(email)) WHERE password_hash IS NOT NULL;

CREATE TABLE fichas_tecnicas (
    id                    SERIAL PRIMARY KEY,
    cliente_id            INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
    profesional_id        INT REFERENCES profesionales(id),
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    marca_tinte           VARCHAR(60),
    tono                  VARCHAR(40),
    oxidante              VARCHAR(20),                  -- 20 vol, 30 vol...
    tiempo_minutos        INT,
    foto_antes            VARCHAR(255),
    foto_despues          VARCHAR(255),
    notas                 TEXT
);

-- ---------------------------------------------------------------
-- Servicios y productos
-- ---------------------------------------------------------------
CREATE TABLE servicios (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    -- Si tiene valor, es un servicio propio de ese arrendatario (su precio).
    profesional_id        INT REFERENCES profesionales(id),
    nombre                VARCHAR(100) NOT NULL,
    precio                NUMERIC(10,2) NOT NULL,       -- lo que cobra al cliente
    -- Lo que el dueño le paga al peluquero (empleado) por hacer este servicio.
    -- Si es NULL, se usa el % de comisión de su regla de pago.
    pago_profesional      NUMERIC(10,2) CHECK (pago_profesional IS NULL OR pago_profesional >= 0),
    duracion_minutos      INT NOT NULL DEFAULT 30,
    reserva_online        BOOLEAN NOT NULL DEFAULT true, -- aparece en el portal de reservas
    activo                BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE productos (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    nombre                VARCHAR(100) NOT NULL,
    precio                NUMERIC(10,2) NOT NULL,
    costo                 NUMERIC(10,2) NOT NULL DEFAULT 0,
    stock                 INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------
-- Agenda
-- ---------------------------------------------------------------
CREATE TABLE citas (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    sucursal_id           INT REFERENCES sucursales(id),
    cliente_id            INT REFERENCES clientes(id),
    profesional_id        INT NOT NULL REFERENCES profesionales(id),
    inicio                TIMESTAMP NOT NULL,
    fin                   TIMESTAMP NOT NULL,
    estado                VARCHAR(12) NOT NULL DEFAULT 'reservada'
                          CHECK (estado IN ('pendiente','reservada','confirmada','atendida','no_asistio','cancelada','rechazada')),
    expira_en             TIMESTAMP,                    -- solicitud en línea sin aceptar: se libera a esta hora
    origen                VARCHAR(10) NOT NULL DEFAULT 'local' CHECK (origen IN ('local','online')),
    anticipo              NUMERIC(10,2) NOT NULL DEFAULT 0,
    notas                 TEXT,
    CHECK (fin > inicio)
);

CREATE TABLE cita_servicios (
    cita_id               INT NOT NULL REFERENCES citas(id) ON DELETE CASCADE,
    servicio_id           INT NOT NULL REFERENCES servicios(id),
    precio                NUMERIC(10,2) NOT NULL,       -- precio acordado (el dueño lo puede cambiar al agendar)
    PRIMARY KEY (cita_id, servicio_id)
);

-- Fotos de servicios (solo clientes con cuenta que aceptaron). El archivo vive fuera de la carpeta pública.
CREATE TABLE fotos (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    cliente_id            INT NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
    cita_id               INT REFERENCES citas(id) ON DELETE SET NULL,
    profesional_id        INT REFERENCES profesionales(id),
    momento               VARCHAR(8) NOT NULL DEFAULT 'despues' CHECK (momento IN ('antes','despues')),
    archivo               VARCHAR(120) NOT NULL,         -- nombre aleatorio en uploads/fotos/
    creada_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX idx_fotos_cliente ON fotos (cliente_id, creada_en);

-- Avisos dentro del sistema (campana) para dueño y peluqueros
CREATE TABLE notificaciones (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    usuario_id            INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    cita_id               INT REFERENCES citas(id) ON DELETE CASCADE,
    texto                 VARCHAR(300) NOT NULL,
    leida                 BOOLEAN NOT NULL DEFAULT false,
    creada_en             TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX idx_notif_usuario ON notificaciones (usuario_id, leida);

-- ---------------------------------------------------------------
-- Ventas (cobros). Clave: quién recibe el dinero.
-- ---------------------------------------------------------------
CREATE TABLE ventas (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    cita_id               INT REFERENCES citas(id),
    cliente_id            INT REFERENCES clientes(id),
    fecha                 TIMESTAMP NOT NULL DEFAULT now(),
    cobrado_por           VARCHAR(12) NOT NULL DEFAULT 'local'
                          CHECK (cobrado_por IN ('local','profesional')),
    cobrado_por_profesional_id INT REFERENCES profesionales(id),
    metodo_pago           VARCHAR(20) NOT NULL DEFAULT 'efectivo'
                          CHECK (metodo_pago IN ('efectivo','tarjeta','transferencia','payphone','plux','deuna')),
    total                 NUMERIC(10,2) NOT NULL,
    propina               NUMERIC(10,2) NOT NULL DEFAULT 0,
    anulada               BOOLEAN NOT NULL DEFAULT false,
    CHECK (cobrado_por = 'local' OR cobrado_por_profesional_id IS NOT NULL)
);

CREATE TABLE venta_items (
    id                    SERIAL PRIMARY KEY,
    venta_id              INT NOT NULL REFERENCES ventas(id) ON DELETE CASCADE,
    tipo                  VARCHAR(10) NOT NULL CHECK (tipo IN ('servicio','producto')),
    servicio_id           INT REFERENCES servicios(id),
    producto_id           INT REFERENCES productos(id),
    profesional_id        INT NOT NULL REFERENCES profesionales(id),
    cantidad              INT NOT NULL DEFAULT 1 CHECK (cantidad > 0),
    precio_unitario       NUMERIC(10,2) NOT NULL,
    subtotal              NUMERIC(10,2) NOT NULL,
    -- Lo que gana el profesional por este ítem (sin importar quién cobró)
    ganancia_profesional  NUMERIC(10,2) NOT NULL DEFAULT 0,
    CHECK ((tipo = 'servicio' AND servicio_id IS NOT NULL) OR
           (tipo = 'producto' AND producto_id IS NOT NULL))
);

-- ---------------------------------------------------------------
-- Cuenta corriente de cada profesional.
-- Monto POSITIVO = el local le debe al profesional.
-- Monto NEGATIVO = el profesional le debe al local.
-- ---------------------------------------------------------------
CREATE TABLE movimientos_profesional (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    profesional_id        INT NOT NULL REFERENCES profesionales(id),
    fecha                 DATE NOT NULL DEFAULT CURRENT_DATE,
    tipo                  VARCHAR(20) NOT NULL CHECK (tipo IN (
                              'comision_servicio','comision_producto','porcentaje',
                              'parte_local','propina','sueldo','arriendo',
                              'venta_producto_local','cobro_recibido_local',
                              'escala_comision','anticipo','prestamo','pago','ajuste')),
    monto                 NUMERIC(10,2) NOT NULL,
    venta_id              INT REFERENCES ventas(id),
    liquidacion_id        INT,                          -- se llena al liquidar
    descripcion           VARCHAR(200)
);

CREATE TABLE liquidaciones (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    profesional_id        INT NOT NULL REFERENCES profesionales(id),
    desde                 DATE NOT NULL,
    hasta                 DATE NOT NULL,
    total                 NUMERIC(10,2) NOT NULL,       -- + a pagar al profesional / - a cobrarle
    estado                VARCHAR(10) NOT NULL DEFAULT 'borrador'
                          CHECK (estado IN ('borrador','pagada')),
    creada_en             TIMESTAMP NOT NULL DEFAULT now()
);

ALTER TABLE movimientos_profesional
    ADD CONSTRAINT fk_mov_liquidacion FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones(id);

-- ---------------------------------------------------------------
-- Caja y facturas RECIBIDAS (compras y gastos del salón, importadas del SRI)
-- ---------------------------------------------------------------
CREATE TABLE cierres_caja (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    sucursal_id           INT REFERENCES sucursales(id),
    fecha                 DATE NOT NULL,
    efectivo_esperado     NUMERIC(10,2) NOT NULL,
    efectivo_contado      NUMERIC(10,2) NOT NULL,
    diferencia            NUMERIC(10,2) GENERATED ALWAYS AS (efectivo_contado - efectivo_esperado) STORED,
    UNIQUE (salon_id, sucursal_id, fecha)
);

-- Facturas que le emiten AL salón sus proveedores (tintes, shampoo, luz, agua,
-- arriendo del local, equipos...). Se importan del SRI y alimentan los gastos
-- del panel del dueño. TuSalón NO emite facturas.
CREATE TABLE facturas_recibidas (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    clave_acceso          VARCHAR(49) NOT NULL,
    fecha_emision         DATE NOT NULL,
    ruc_proveedor         VARCHAR(13) NOT NULL,
    proveedor             VARCHAR(200),
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros'
                          CHECK (categoria IN ('productos_venta','insumos','servicios_basicos',
                                               'arriendo_local','equipos','publicidad','otros')),
    subtotal              NUMERIC(10,2) NOT NULL,
    iva                   NUMERIC(10,2) NOT NULL DEFAULT 0,
    total                 NUMERIC(10,2) NOT NULL,
    archivo_xml           VARCHAR(255),
    importada_en          TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (salon_id, clave_acceso)
);

-- Cada proveedor queda con su categoría para clasificar solas las próximas facturas.
CREATE TABLE proveedores (
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    ruc                   VARCHAR(13) NOT NULL,
    nombre                VARCHAR(200),
    categoria             VARCHAR(20) NOT NULL DEFAULT 'otros',
    PRIMARY KEY (salon_id, ruc)
);

-- Índices para las consultas más comunes
CREATE INDEX idx_citas_agenda      ON citas (salon_id, profesional_id, inicio);
CREATE INDEX idx_ventas_fecha      ON ventas (salon_id, fecha);
CREATE INDEX idx_items_profesional ON venta_items (profesional_id);
CREATE INDEX idx_mov_profesional   ON movimientos_profesional (profesional_id, fecha);
CREATE INDEX idx_clientes_salon    ON clientes (salon_id, nombre);
CREATE INDEX idx_facturas_fecha    ON facturas_recibidas (salon_id, fecha_emision);

-- ---------------------------------------------------------------
-- Datos fijos
-- ---------------------------------------------------------------
INSERT INTO parametros_anuales VALUES (2026, 482.00, 9.45, 11.15, 8.33);

INSERT INTO planes VALUES
    ('basica',   'Básica',   25.00, 3,    1),
    ('completa', 'Completa', 35.00, NULL, NULL);

COMMIT;
