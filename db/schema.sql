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
    horas_cancelacion     INT NOT NULL DEFAULT 2,       -- el cliente puede cancelar/cambiar hasta X horas antes
    max_faltas            INT NOT NULL DEFAULT 2,       -- con estas faltas, no puede reservar en línea
    semanas_sin_volver    INT NOT NULL DEFAULT 6,       -- "clientes por recuperar"
    google_resenas_url    VARCHAR(300),                 -- enlace para dejar reseña en Google
    reporte_enviado_mes   CHAR(7),                      -- 'AAAA-MM' del último reporte mensual enviado
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

-- Horario propio de cada peluquero (si no tiene filas, usa el del salón). Sin fila ese día = no trabaja.
CREATE TABLE horarios_profesional (
    profesional_id        INT NOT NULL REFERENCES profesionales(id) ON DELETE CASCADE,
    dia_semana            INT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    abre                  TIME NOT NULL,
    cierra                TIME NOT NULL,
    almuerzo_desde        TIME,
    almuerzo_hasta        TIME,
    PRIMARY KEY (profesional_id, dia_semana),
    CHECK (cierra > abre),
    CHECK ((almuerzo_desde IS NULL AND almuerzo_hasta IS NULL) OR (almuerzo_hasta > almuerzo_desde))
);

-- Vacaciones, días libres o permisos de un peluquero
CREATE TABLE bloqueos (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    profesional_id        INT NOT NULL REFERENCES profesionales(id) ON DELETE CASCADE,
    desde                 TIMESTAMP NOT NULL,
    hasta                 TIMESTAMP NOT NULL,
    motivo                VARCHAR(80),
    CHECK (hasta > desde)
);
CREATE INDEX idx_bloqueos ON bloqueos (profesional_id, desde);

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
    cumple_mes            SMALLINT CHECK (cumple_mes BETWEEN 1 AND 12),   -- cumpleaños sin año (opcional)
    cumple_dia            SMALLINT CHECK (cumple_dia BETWEEN 1 AND 31),
    cumple_saludo_anio    INT,                          -- último año en que se le felicitó
    telegram_chat_id      BIGINT,                       -- recordatorios por Telegram (lo conecta el cliente)
    telegram_codigo       VARCHAR(32),
    faltas_desde          TIMESTAMP,                    -- el dueño "perdona" las faltas anteriores a esta fecha
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
    descripcion           TEXT,                         -- explicación detallada para el cliente
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

-- Qué peluqueros hacen cada servicio y a qué precio. Sin filas = lo hacen todos al precio normal.
CREATE TABLE servicio_profesional (
    servicio_id           INT NOT NULL REFERENCES servicios(id) ON DELETE CASCADE,
    profesional_id        INT NOT NULL REFERENCES profesionales(id) ON DELETE CASCADE,
    precio                NUMERIC(10,2) CHECK (precio IS NULL OR precio >= 0),   -- NULL = precio normal
    PRIMARY KEY (servicio_id, profesional_id)
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
    token                 VARCHAR(32) UNIQUE DEFAULT md5(random()::text || clock_timestamp()::text), -- enlace del cliente (confirmar, cancelar, calificar)
    recordatorio_en       TIMESTAMP,                    -- cuándo se envió el recordatorio del día anterior
    confirmada_cliente_en TIMESTAMP,                    -- cuándo el cliente confirmó que vendrá
    calificacion_pedida_en TIMESTAMP,                   -- cuándo se le pidió calificar el servicio
    cancelada_por         VARCHAR(12) CHECK (cancelada_por IN ('cliente','salon')),
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

-- Calificación del cliente después del servicio
CREATE TABLE calificaciones (
    id                    SERIAL PRIMARY KEY,
    salon_id              INT NOT NULL REFERENCES salones(id) ON DELETE CASCADE,
    cita_id               INT NOT NULL UNIQUE REFERENCES citas(id) ON DELETE CASCADE,
    profesional_id        INT REFERENCES profesionales(id),
    cliente_id            INT REFERENCES clientes(id),
    estrellas             SMALLINT NOT NULL CHECK (estrellas BETWEEN 1 AND 5),
    comentario            VARCHAR(500),
    creada_en             TIMESTAMP NOT NULL DEFAULT now()
);

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

-- ---------------------------------------------------------------
-- Migraciones (mismo contenido que db/migraciones/)
-- ---------------------------------------------------------------
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

-- 004 · Conector con el Panel Tukán Central (protocolo v1).
--   superadmins.creado_por_central: el super usuario lo creó el panel central al entrar por primera vez
--   tukan_central_nonces: cada enlace del panel central sirve UNA sola vez (se borran los vencidos)
--   tukan_central_pedidos: límite de 30 pedidos por minuto por IP a /tukan_central.php
-- Se puede correr muchas veces sin dañar nada.

ALTER TABLE superadmins ADD COLUMN IF NOT EXISTS creado_por_central BOOLEAN NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS tukan_central_nonces (
    nonce                 CHAR(32) PRIMARY KEY,
    vence                 TIMESTAMPTZ NOT NULL           -- después de esto el token ya no sirve (exp + 30 s)
);
CREATE INDEX IF NOT EXISTS tukan_central_nonces_vence ON tukan_central_nonces (vence);

CREATE TABLE IF NOT EXISTS tukan_central_pedidos (
    ip                    VARCHAR(45) NOT NULL,
    minuto                TIMESTAMPTZ NOT NULL,
    n                     INT NOT NULL DEFAULT 1,
    PRIMARY KEY (ip, minuto)
);

-- 005 · Panel Tukán Central, protocolo v2 (docs del Central: PROTOCOLO_V2.md §7 y §10.3).
--   salones.origen: de dónde vino cada cliente (registro = se registró solo, super = panel de Tukán,
--                   central = demo creada desde el Panel Tukán Central). Los viejos: "super" si el registro del
--                   panel dice "Salón creado desde el panel…", si no "registro".
--   tukan_central_pedidos.rechazados: límite de 30 pedidos rechazados (401) por minuto por IP (además de 120 en total)
--   tukan_central_operaciones: cada "ejecutar" del Central con su clave de operación (no se repite un pago ni una demo
--                   si el Central reintenta). Guarda la respuesta SIN contraseñas. Se borran a los 90 días.
--   tukan_central_atenciones: recordatorios y avisos que el Central marcó como "en proceso", "atendida" o "descartada"
--                   (registros nuevos, seguimiento de pruebas, renovaciones, dominios gratis, pagos de Google Play).
-- Se puede correr muchas veces sin dañar nada.

ALTER TABLE salones ADD COLUMN IF NOT EXISTS origen VARCHAR(12);
UPDATE salones s SET origen = CASE
         WHEN EXISTS (SELECT 1 FROM registro_admin r WHERE r.salon_id = s.id AND r.accion LIKE 'Salón creado desde el panel%') THEN 'super'
         ELSE 'registro' END
 WHERE origen IS NULL;
ALTER TABLE salones ALTER COLUMN origen SET DEFAULT 'registro';
ALTER TABLE salones ALTER COLUMN origen SET NOT NULL;

ALTER TABLE tukan_central_pedidos ADD COLUMN IF NOT EXISTS rechazados INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS tukan_central_operaciones (
    operacion_id          CHAR(32) PRIMARY KEY,
    accion                VARCHAR(40) NOT NULL,
    cliente_id            VARCHAR(80),
    cuerpo_hash           CHAR(43) NOT NULL,             -- el mismo valor que "bh" del token
    estado                VARCHAR(10) NOT NULL CHECK (estado IN ('en_curso','ok','error')),
    http                  INT,
    respuesta             TEXT,                          -- JSON de la respuesta SIN claves
    email                 VARCHAR(120) NOT NULL,
    creado                TIMESTAMP NOT NULL DEFAULT now(),
    terminado             TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_central_operaciones_creado ON tukan_central_operaciones (creado);

CREATE TABLE IF NOT EXISTS tukan_central_atenciones (
    solicitud_id          VARCHAR(80) PRIMARY KEY,       -- "seguimiento_prueba:123:faltan_2:2026-10-20"
    tipo                  VARCHAR(30) NOT NULL,
    estado                VARCHAR(12) NOT NULL CHECK (estado IN ('en_proceso','atendida','descartada')),
    nota                  VARCHAR(300),
    email                 VARCHAR(120) NOT NULL,
    nombre                VARCHAR(100),
    momento               TIMESTAMP NOT NULL DEFAULT now()
);


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


-- 008 · TuSalón por módulos (PROTOCOLO_V2.md del Panel Tukán Central, Anexo B) y "todo se pide demo".
--   modulos: lo que el súper usuario cambió del catálogo (precio, descuento, descripción, orden). Los módulos y sus
--            valores por defecto están en src/Modulos.php; una fila aquí solo pisa esos valores.
--   planes.modulos: módulos que trae cada plan ('*' = todos). Básica = ninguno (solo la base), Completa = todos (desde 010 también el Asistente IA).
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

-- (migración 009) · IA de Tukán (compartido/tukan_ia.php del Panel Tukán Central, guía compartido/LEEME_IA.md, Anexo C de PROTOCOLO_V2.md).
--   tukan_ia_faq: preguntas frecuentes de la Ayuda (las mismas para todos los salones). 'automatica' = la escribió la IA,
--                 'manual' = una persona (el súper usuario). aprobada = 1 cuando el súper la revisó.
--   tukan_ia_gasto: lo que gastó cada salón en IA cada mes (negocio_id = id del salón, mes = '2026-10').
--   salones.ia_tope_usd: tope del mes en USD que puso Tukán (vacío = el sugerido: $0,50 sin el módulo «Asistente IA»,
--                 $4,00 con el módulo, venga en Completa o comprado aparte; ver 010).
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

-- 010 · El módulo «Asistente IA» (asistente_ia) viene INCLUIDO en el plan Completa (decisión de Dimitry, 21 oct 2026).
--   En Básica sigue como extra por cotización (salones.modulos_extra). Completa normalmente es '*' (todos los módulos, y
--   desde ahora '*' también trae el Asistente IA: Modulos::FUERA_DE_PLANES quedó vacío); si el súper usuario guardó
--   Completa con una lista, aquí se le agrega asistente_ia.
--   Tope de IA con el módulo (en el plan o comprado aparte): $4,00 al mes por salón (Ia::TOPE_CON_MODULO);
--   sin el módulo la Ayuda sigue con $0,50. salones.ia_tope_usd vacío = el sugerido.
-- Se puede correr muchas veces sin dañar nada.

UPDATE planes
   SET modulos = CASE WHEN btrim(modulos) = '' THEN 'asistente_ia' ELSE modulos || ',asistente_ia' END
 WHERE codigo = 'completa'
   AND modulos <> '*'
   AND (',' || replace(modulos, ' ', '') || ',') NOT LIKE '%,asistente_ia,%';

COMMIT;
