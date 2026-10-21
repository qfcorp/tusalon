<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * TuSalón por módulos (PROTOCOLO_V2.md del Panel Tukán Central, Anexo B).
 *
 *  - Base: lo que todo salón tiene siempre (no se puede apagar).
 *  - Módulo: una función que se enciende o se apaga por salón. Los módulos y sus valores por defecto están aquí
 *    (CATALOGO); el súper usuario cambia precio, descuento, descripción y orden (tabla `modulos`, solo lo cambiado).
 *  - Plan: precio + módulos que trae (`planes.modulos`, '*' = todos).
 *  - Por salón: `modulos_extra` (además del plan, se cobran aparte), `modulos_quitados` (del plan, apagados, con su
 *    descuento) y `precio_acordado` (precio fijo al mes, o vacío).
 *    Activos = (módulos del plan − quitados) + extra, con sus dependencias.
 *  - Demo (estado "prueba"): todos encendidos, o los que eligió el súper usuario (`modulos_demo`).
 *  - Precio al mes = precio del plan + Σ precio de extra − Σ descuento de quitados (o el precio acordado).
 *  - Apagar nunca borra datos: se esconden menús, páginas (RUTAS), botones, páginas públicas, tareas y avisos.
 */
final class Modulos
{
    /** Lo que todo salón tiene siempre. */
    public const BASE = [
        'Agenda por sillas y citas (con WhatsApp de un toque para recordar)',
        'Clientes con su historial, cumpleaños y faltas',
        'Servicios con precio al cliente y pago al peluquero',
        'Equipo con los 4 tipos de peluquero: dueño, empleado, porcentaje y alquiler de puesto',
        'Cuentas del equipo: comisiones, reparto del porcentaje, propinas y arriendo',
        'Cobrar, caja del día y cierre de caja',
        'Horario del salón y de cada peluquero, almuerzo y vacaciones',
        'Enlace de cada cita para que el cliente confirme, cancele o califique',
        'Hasta 3 personas que atienden (el dueño cuenta)',
    ];

    public const GRUPOS = [
        'clientes' => 'Clientes y reservas',
        'equipo' => 'Equipo',
        'ventas' => 'Ventas y clientes fieles',
        'dinero' => 'Dinero y reportes',
        'avisos' => 'Avisos automáticos',
        'extras' => 'Extras',
    ];

    /** Módulos que no entran en ningún plan (ni con '*'): se cotizan aparte. */
    public const FUERA_DE_PLANES = ['asistente_ia'];

    /** Personas que atienden sin el módulo "equipo_ilimitado" (si el plan no pone otro número). */
    public const PERSONAS_SIN_MODULO = 3;

    /**
     * id => [ícono, nombre, grupo, descripción para vender, precio al mes (USD), descuento si se quita del plan (USD), depende de].
     * El orden de esta lista es el orden por defecto.
     */
    public const CATALOGO = [
        'reservas_linea' => ['🗓️', 'Reservas en línea', 'clientes',
            'Tu propio enlace para que tus clientes reserven solos desde el celular: eligen peluquero, servicio y una hora libre. Tú aceptas o rechazas. El cliente puede crear su cuenta, ver sus citas, cambiar la hora y ver sus fotos.', 5.0, 2.0, []],
        'anticipos' => ['💳', 'Anticipo en línea', 'clientes',
            'Pide un anticipo al reservar (a todos, a clientes nuevos o a quienes faltaron): transferencia o DeUna con foto del comprobante, o tarjeta con Payphone. Así nadie te deja la silla vacía.', 3.0, 1.0, ['reservas_linea']],
        'ficha_tecnica' => ['🎨', 'Ficha técnica de color', 'clientes',
            'Guarda la fórmula de cada tinte (marca, tono, oxidante, tiempo) con fotos de antes y después, para repetirla igual la próxima vez.', 3.0, 1.0, []],
        'recuperar_clientes' => ['🔁', 'Clientes por recuperar', 'clientes',
            'Lista de los clientes que hace semanas no vienen, con el mensaje de WhatsApp listo para invitarlos a volver.', 2.0, 1.0, []],
        'equipo_ilimitado' => ['👥', 'Personas ilimitadas', 'equipo',
            'Agrega todos los peluqueros que necesites. Sin este módulo, hasta 3 personas (contando al dueño).', 5.0, 2.0, []],
        'portal_peluquero' => ['📱', 'Portal y app del peluquero', 'equipo',
            'Cada peluquero entra con su propio acceso desde su celular (se instala como app): ve sus citas, lo que hizo, lo que ganó y su saldo, sin ver la caja ni nada tuyo.', 4.0, 1.5, []],
        'clientes_privados' => ['🔒', 'Clientes privados de quien alquila', 'equipo',
            'Quien alquila el puesto guarda sus propios clientes y les agenda en su silla. Tú ves la hora ocupada sin el nombre ni el celular.', 2.0, 1.0, ['portal_peluquero']],
        'recepcion' => ['🛎️', 'Cuenta de recepción', 'equipo',
            'Una cuenta para la persona de recepción: agenda, cobra, cierra la caja y responde las reservas, sin ver tus ganancias ni lo que gana cada peluquero.', 3.0, 1.0, []],
        'inventario' => ['📦', 'Inventario y venta de productos', 'ventas',
            'Productos para vender y de uso del salón (tintes), aviso de stock bajo, mercadería que llegó, venta al cobrar con comisión de producto para el peluquero.', 3.0, 1.0, []],
        'paquetes_regalo' => ['🎁', 'Paquetes y tarjetas de regalo', 'ventas',
            'Vende paquetes como "4 cortes por $50" (se descuenta una sesión en cada visita) y tarjetas de regalo con código y saldo.', 3.0, 1.0, []],
        'puntos' => ['⭐', 'Puntos para clientes fieles', 'ventas',
            'Tus clientes ganan puntos cada vez que pagan y los cambian por descuentos. Tú decides cuánto vale cada punto.', 2.0, 1.0, []],
        'gastos_sri' => ['🧾', 'Gastos con facturas del SRI', 'dinero',
            'Sube las facturas que te dan tus proveedores (XML, el reporte TXT del SRI o la clave de acceso) y mira tus gastos y tu ganancia real cada mes. TuSalón no hace facturas: solo lee las que recibes.', 4.0, 1.5, []],
        'reportes' => ['📊', 'Reporte del mes', 'dinero',
            'Lo más vendido, el mejor peluquero, las estrellas, las horas y días más vacíos y las faltas. Te llega solo el día 1 de cada mes.', 3.0, 1.0, []],
        'telegram' => ['📲', 'Avisos automáticos por Telegram', 'avisos',
            'Recordatorio automático al cliente el día anterior, pedido de calificación después del servicio, saludo de cumpleaños y avisos de cada reserva a tu equipo con botones Aceptar y Rechazar.', 3.0, 1.0, []],
        // Por cotización (precio 0 en el catálogo: el que se acuerde va como precio acordado). No está en ningún plan.
        'asistente_ia' => ['✨', 'Asistente IA', 'extras',
            'Arma la cita a partir del mensaje de WhatsApp del cliente con una hora libre de tu agenda, escribe el mensaje para los clientes que no vuelven y responde las dudas al instante.', 0.0, 0.0, []],
    ];

    /**
     * Módulo de cada página (?r=…). '' = base (no depende de ningún módulo).
     * Toda ruta de public/index.php debe estar aquí (tests/prueba_modulos.php lo revisa).
     */
    public const RUTAS = [
        // públicas
        'login' => '', 'registro' => '', 'privacidad' => '', 'admin_entrar' => '', 'telegram' => '', 'confirmar' => '',
        'reservar' => 'reservas_linea', 'horas' => 'reservas_linea', 'cliente_entrar' => 'reservas_linea',
        'cliente_salir' => '', 'mi_cuenta' => 'reservas_linea', 'foto' => 'reservas_linea', 'payphone' => 'anticipos',
        // del salón
        'inicio' => '', 'agenda' => '', 'cita' => '', 'cita_nueva' => '', 'clientes' => '', 'cliente' => '', 'servicios' => '',
        'equipo' => '', 'caja' => '', 'cobrar' => '', 'cuentas' => '', 'completa' => '', 'salir' => '',
        'prueba_terminada' => '', 'horario' => '', 'solicitudes' => '', 'horario_peluquero' => '', 'avisos_clientes' => '',
        'play_compra' => '',
        'mi_portal' => 'portal_peluquero', 'fotos' => 'reservas_linea', 'reporte' => 'reportes', 'inventario' => 'inventario',
        'paquetes' => 'paquetes_regalo', 'tarjetas' => 'paquetes_regalo', 'puntos' => 'puntos', 'ficha' => 'ficha_tecnica',
        'ficha_foto' => 'ficha_tecnica', 'comprobante' => 'anticipos', 'gastos' => 'gastos_sri', 'recepcion' => 'recepcion',
        'mis_clientes' => 'clientes_privados', 'administradores' => '', 'ayuda' => '', 'asistente' => 'asistente_ia',
        // panel de Tukán (no es de un salón)
        'admin' => '', 'admin_salones' => '', 'admin_salon' => '', 'admin_nuevo' => '', 'admin_planes' => '', 'admin_ver' => '',
        'admin_volver' => '', 'admin_salir' => '', 'admin_faq' => '', 'admin_demo_correo' => '',
    ];

    private ?array $catalogo = null;
    private ?array $planes = null;

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Catálogo y planes
    // ------------------------------------------------------------------

    public static function existe(string $id): bool
    {
        return isset(self::CATALOGO[$id]);
    }

    public static function ids(): array
    {
        return array_keys(self::CATALOGO);
    }

    /** Catálogo con lo que cambió el súper usuario: id => {id, nombre, icono, descripcion, precio_mensual, descuento, depende_de, grupo, grupo_nombre, orden}. */
    public function catalogo(): array
    {
        if ($this->catalogo !== null) return $this->catalogo;
        $cambios = [];
        try {
            foreach ($this->db->query('SELECT * FROM modulos')->fetchAll() as $f) $cambios[$f['id']] = $f;
        } catch (\PDOException) {
            // base sin la migración 008: valores por defecto
        }
        $r = [];
        $i = 0;
        foreach (self::CATALOGO as $id => [$icono, $nombre, $grupo, $desc, $precio, $descuento, $dep]) {
            $i++;
            $c = $cambios[$id] ?? [];
            $r[$id] = [
                'id' => $id, 'nombre' => ($c['nombre'] ?? null) ?: $nombre, 'icono' => $icono,
                'descripcion' => ($c['descripcion'] ?? null) ?: $desc,
                'precio_mensual' => round(isset($c['precio_mensual']) ? (float) $c['precio_mensual'] : $precio, 2),
                'descuento' => round(isset($c['descuento']) ? (float) $c['descuento'] : $descuento, 2),
                'depende_de' => $dep, 'grupo' => $grupo, 'grupo_nombre' => self::GRUPOS[$grupo] ?? $grupo,
                'orden' => isset($c['orden']) ? (int) $c['orden'] : $i * 10,
            ];
        }
        uasort($r, fn($a, $b) => [$a['orden'], $a['id']] <=> [$b['orden'], $b['id']]);
        return $this->catalogo = $r;
    }

    public function nombre(string $id): string
    {
        return $this->catalogo()[$id]['nombre'] ?? $id;
    }

    /** Lista "a,b" o array -> ids válidos, sin repetir, en el orden del catálogo. */
    public static function limpiar(string|array|null $lista): array
    {
        if ($lista === null) return [];
        $l = is_array($lista) ? $lista : explode(',', $lista);
        $l = array_map(fn($x) => trim((string) $x), $l);
        return array_values(array_filter(self::ids(), fn($id) => in_array($id, $l, true)));
    }

    public static function texto(array $ids): string
    {
        return implode(',', self::limpiar($ids));
    }

    /** Planes: codigo => {codigo, nombre, precio_mensual, max_profesionales, modulos:[ids]}. */
    public function planes(): array
    {
        if ($this->planes !== null) return $this->planes;
        $r = [];
        foreach ($this->db->query('SELECT * FROM planes ORDER BY precio_mensual, codigo')->fetchAll() as $p) {
            $p['modulos'] = $this->modulosDePlan((string) ($p['modulos'] ?? ''));
            $p['precio_mensual'] = round((float) $p['precio_mensual'], 2);
            $r[$p['codigo']] = $p;
        }
        return $this->planes = $r;
    }

    public function modulosDePlan(string $valor): array
    {
        $valor = trim($valor);
        return $valor === '*' ? array_values(array_diff(self::ids(), self::FUERA_DE_PLANES)) : self::limpiar($valor);
    }

    public function olvidar(): void
    {
        $this->catalogo = null;
        $this->planes = null;
        self::$cache = [];
    }

    // ------------------------------------------------------------------
    // Módulos de un salón
    // ------------------------------------------------------------------

    private static array $cache = [];

    /** Fila mínima del salón para calcular sus módulos. */
    public function salon(int $salonId): ?array
    {
        $st = $this->db->prepare('SELECT id, plan_codigo, estado, modulos_extra, modulos_quitados, modulos_demo, precio_acordado FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        return $st->fetch() ?: null;
    }

    /** Agrega las dependencias de cada módulo (encender uno enciende lo que necesita). */
    public static function conDependencias(array $ids): array
    {
        $r = self::limpiar($ids);
        do {
            $mas = false;
            foreach ($r as $id) foreach (self::CATALOGO[$id][6] as $dep) {
                if (!in_array($dep, $r, true)) { $r[] = $dep; $mas = true; }
            }
        } while ($mas);
        return self::limpiar($r);
    }

    /** Quita los que se quedaron sin una dependencia (si se apagó lo que necesitan). */
    public static function sinHuerfanos(array $ids): array
    {
        $r = self::limpiar($ids);
        do {
            $antes = count($r);
            $r = array_values(array_filter($r, fn($id) => !array_diff(self::CATALOGO[$id][6], $r)));
        } while (count($r) !== $antes);
        return $r;
    }

    /** modulos_demo = NINGUNO: una demo con solo la base (vacío o NULL = todos). */
    public const NINGUNO = 'ninguno';

    /** ¿Es demo? (estado "prueba": todos los módulos, o los que eligió el súper usuario). */
    public static function esDemo(array $s): bool
    {
        return ($s['estado'] ?? '') === 'prueba';
    }

    /**
     * Detalle de los módulos de un salón: del_plan, extra, quitados, activos, demo, precios.
     * $s necesita: id, plan_codigo, estado, modulos_extra, modulos_quitados, modulos_demo, precio_acordado.
     * $plan: para calcular con otro plan (cotizar un cambio de plan con los mismos extra y quitados).
     */
    public function detalle(array $s, ?string $plan = null): array
    {
        $plan ??= (string) $s['plan_codigo'];
        $planes = $this->planes();
        $pl = $planes[$plan] ?? null;
        $delPlan = $pl['modulos'] ?? [];
        $extra = array_values(array_diff(self::limpiar($s['modulos_extra'] ?? ''), $delPlan));
        $quitados = array_values(array_intersect(self::limpiar($s['modulos_quitados'] ?? ''), $delPlan));
        $contratados = self::sinHuerfanos(array_merge(array_diff($delPlan, $quitados), $extra));
        $demo = self::esDemo($s);
        if ($demo) {
            $elegidos = $s['modulos_demo'] ?? null;
            $elegidos = $elegidos === null ? '' : trim((string) $elegidos);
            $activos = $elegidos === '' ? self::ids() : ($elegidos === self::NINGUNO ? [] : self::conDependencias(self::limpiar($elegidos)));
        } else {
            $activos = $contratados;
        }
        $cat = $this->catalogo();
        $precioPlan = round((float) ($pl['precio_mensual'] ?? 0), 2);
        $precioExtra = round(array_sum(array_map(fn($id) => $cat[$id]['precio_mensual'], $extra)), 2);
        $descuento = round(array_sum(array_map(fn($id) => $cat[$id]['descuento'], $quitados)), 2);
        $acordado = isset($s['precio_acordado']) && $s['precio_acordado'] !== null && $s['precio_acordado'] !== '' ? round((float) $s['precio_acordado'], 2) : null;
        $calculado = max(0.0, round($precioPlan + $precioExtra - $descuento, 2));
        return [
            'plan' => $plan, 'del_plan' => $delPlan, 'extra' => $extra, 'quitados' => $quitados,
            'activos' => self::limpiar($activos), 'contratados' => $contratados, 'demo' => $demo,
            'precio_plan' => $precioPlan, 'precio_extra' => $precioExtra, 'descuento' => $descuento,
            'precio_calculado' => $calculado, 'precio_acordado' => $acordado, 'mensual' => $acordado ?? $calculado,
        ];
    }

    /** Ids de los módulos encendidos de un salón (fila o id). */
    public function activos(array|int $salon): array
    {
        $s = is_int($salon) ? $this->salon($salon) : $salon;
        if (!$s) return [];
        $k = implode('|', [$s['id'] ?? '', $s['plan_codigo'] ?? '', $s['estado'] ?? '', $s['modulos_extra'] ?? '', $s['modulos_quitados'] ?? '', $s['modulos_demo'] ?? '-']);
        return self::$cache[$k] ??= $this->detalle($s)['activos'];
    }

    public function activo(array|int $salon, string $modulo): bool
    {
        if ($modulo === '') return true;
        return in_array($modulo, $this->activos($salon), true);
    }

    /** Máximo de personas que atienden (null = sin límite). */
    public function maxPersonas(array|int $salon): ?int
    {
        $s = is_int($salon) ? $this->salon($salon) : $salon;
        if (!$s || $this->activo($s, 'equipo_ilimitado')) return null;
        $max = $this->planes()[$s['plan_codigo']]['max_profesionales'] ?? null;
        return $max === null ? self::PERSONAS_SIN_MODULO : (int) $max;
    }

    // ------------------------------------------------------------------
    // Cambiar los módulos de un salón (panel de Tukán y Panel Tukán Central)
    // ------------------------------------------------------------------

    /**
     * Enciende y apaga módulos de un salón. Valida dependencias: no se apaga uno del que depende otro que queda encendido;
     * encender uno enciende sus dependencias. $precioAcordado: false = no cambiar, null = quitar, número = fijar.
     * Devuelve ['detalle' => …, 'encendidos' => […], 'apagados' => […], 'por_dependencia' => […], 'sin_cambios' => bool].
     * En una demo cambia lo que ve la demo (modulos_demo) y también lo que tendrá al pagar.
     */
    public function cambiar(int $salonId, array $encender, array $apagar, float|null|false $precioAcordado, string $motivo,
                            string $quien, string $origen = 'panel'): array
    {
        foreach (array_merge($encender, $apagar) as $id) {
            if (!is_string($id) || !self::existe($id)) throw new RuntimeException('Ese módulo no existe en TuSalón: ' . mb_substr((string) (is_scalar($id) ? $id : '?'), 0, 40) . '.');
        }
        $encender = self::limpiar($encender);
        $apagar = self::limpiar($apagar);
        if (array_intersect($encender, $apagar)) throw new RuntimeException('Un módulo no puede encenderse y apagarse a la vez.');
        if ($precioAcordado !== false && $precioAcordado !== null && ($precioAcordado < 0 || $precioAcordado > 100000)) {
            throw new RuntimeException('El precio acordado debe estar entre 0 y 100.000.');
        }
        $s = $this->salon($salonId);
        if (!$s) throw new RuntimeException('Salón no encontrado.');
        $antes = $this->detalle($s);
        $porDep = array_values(array_diff(self::conDependencias($encender), $encender));
        $encender = self::conDependencias($encender);

        // Lo que quedará contratado (y lo que verá la demo)
        $objetivo = function (array $actual) use ($encender, $apagar): array {
            return self::limpiar(array_diff(array_merge($actual, $encender), $apagar));
        };
        $contratados = $objetivo($antes['contratados']);
        $demoAntes = $antes['demo'] ? $antes['activos'] : null;
        $demo = $antes['demo'] ? $objetivo($antes['activos']) : null;
        foreach ([$contratados, $demo ?? []] as $lista) {
            foreach ($lista as $id) {
                $faltan = array_diff(self::CATALOGO[$id][6], $lista);
                if ($faltan) {
                    throw new RuntimeException('No se puede apagar «' . $this->nombre(reset($faltan)) . '»: «' . $this->nombre($id)
                        . '» lo necesita. Apaga primero «' . $this->nombre($id) . '».');
                }
            }
        }
        $delPlan = $antes['del_plan'];
        $extra = array_values(array_diff($contratados, $delPlan));
        $quitados = array_values(array_diff($delPlan, $contratados));
        $acordado = $precioAcordado === false ? $antes['precio_acordado'] : ($precioAcordado === null ? null : round($precioAcordado, 2));
        $sinCambios = $extra === $antes['extra'] && $quitados === $antes['quitados'] && $acordado === $antes['precio_acordado']
                      && ($demo === null || $demo === $demoAntes);
        $encendidos = array_values(array_diff($antes['demo'] ? ($demo ?? []) : $contratados, $antes['activos']));
        $apagados = array_values(array_diff($antes['activos'], $antes['demo'] ? ($demo ?? []) : $contratados));
        if (!$sinCambios) {
            $this->db->prepare('UPDATE salones SET modulos_extra = ?, modulos_quitados = ?, precio_acordado = ?, modulos_demo = ? WHERE id = ?')
                     ->execute([implode(',', $extra), implode(',', $quitados), $acordado,
                                $demo === null ? $s['modulos_demo'] : ($demo === self::ids() ? null : ($demo === [] ? self::NINGUNO : implode(',', $demo))), $salonId]);
            self::$cache = [];
            $despues = $this->detalle($this->salon($salonId));
            $this->db->prepare('INSERT INTO modulos_cambios (salon_id, quien, origen, encendidos, apagados, precio_antes, precio_despues, acordado_antes, acordado, motivo)
                                VALUES (?,?,?,?,?,?,?,?,?,?)')
                     ->execute([$salonId, mb_substr($quien, 0, 200), $origen, implode(',', $encendidos), implode(',', $apagados),
                                $antes['mensual'], $despues['mensual'], $antes['precio_acordado'], $acordado, mb_substr(trim($motivo), 0, 300) ?: null]);
        } else {
            $despues = $antes;
        }
        return ['detalle' => $despues, 'antes' => $antes, 'encendidos' => $encendidos, 'apagados' => $apagados, 'por_dependencia' => $porDep, 'sin_cambios' => $sinCambios];
    }

    /** Texto corto de un cambio: "Encendió: A, B · Apagó: C · Precio acordado $30,00". */
    public function textoCambio(array $r): string
    {
        $p = [];
        if ($r['encendidos']) $p[] = 'Encendió: ' . implode(', ', array_map([$this, 'nombre'], $r['encendidos']));
        if ($r['apagados']) $p[] = 'Apagó: ' . implode(', ', array_map([$this, 'nombre'], $r['apagados']));
        if ($r['por_dependencia']) $p[] = '(se encendió también ' . implode(', ', array_map([$this, 'nombre'], $r['por_dependencia'])) . ', que se necesita)';
        if ($r['detalle']['precio_acordado'] !== $r['antes']['precio_acordado']) {
            $p[] = $r['detalle']['precio_acordado'] === null ? 'Sin precio acordado' : 'Precio acordado $' . number_format($r['detalle']['precio_acordado'], 2, ',', '.');
        }
        return $p ? implode(' · ', $p) : 'Sin cambios en los módulos';
    }

    /** Historial de cambios de un salón (lo más nuevo primero). */
    public function historial(int $salonId, int $limite = 20): array
    {
        $st = $this->db->prepare('SELECT * FROM modulos_cambios WHERE salon_id = ? ORDER BY momento DESC, id DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Editar el catálogo y los planes (súper usuario)
    // ------------------------------------------------------------------

    /**
     * $modulos: [id => {precio_mensual?, descuento?, descripcion?, orden?, nombre?}]; $planes: [codigo => [ids] | '*'].
     * Devuelve la lista de cambios (texto) para la auditoría.
     */
    public function guardarCatalogo(array $modulos, array $planes = []): array
    {
        $cambios = [];
        $cat = $this->catalogo();
        $up = $this->db->prepare('INSERT INTO modulos (id, nombre, descripcion, precio_mensual, descuento, orden, actualizado) VALUES (?,?,?,?,?,?, now())
                                  ON CONFLICT (id) DO UPDATE SET nombre = EXCLUDED.nombre, descripcion = EXCLUDED.descripcion, precio_mensual = EXCLUDED.precio_mensual,
                                                                 descuento = EXCLUDED.descuento, orden = EXCLUDED.orden, actualizado = now()');
        foreach ($modulos as $id => $m) {
            if (!is_string($id) || !self::existe($id)) throw new RuntimeException('Ese módulo no existe en TuSalón: ' . mb_substr((string) $id, 0, 40) . '.');
            if (!is_array($m)) throw new RuntimeException("Los datos del módulo $id no son válidos.");
            $a = $cat[$id];
            $num = function (string $k, float $max) use ($m, $a, $id): float {
                if (!array_key_exists($k, $m) || $m[$k] === null || $m[$k] === '') return (float) $a[$k];
                $v = is_string($m[$k]) ? str_replace(',', '.', trim($m[$k])) : $m[$k];
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) throw new RuntimeException("Revisa el valor de \"$k\" en el módulo $id.");
                return round((float) $v, 2);
            };
            $precio = $num('precio_mensual', 10000);
            $descuento = $num('descuento', 10000);
            $desc = array_key_exists('descripcion', $m) && $m['descripcion'] !== null ? trim((string) $m['descripcion']) : $a['descripcion'];
            if (mb_strlen($desc) < 5 || mb_strlen($desc) > 400) throw new RuntimeException("La descripción del módulo $id debe tener de 5 a 400 letras.");
            $nombre = array_key_exists('nombre', $m) && $m['nombre'] !== null ? trim((string) $m['nombre']) : $a['nombre'];
            if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 80) throw new RuntimeException("El nombre del módulo $id debe tener de 3 a 80 letras.");
            $orden = array_key_exists('orden', $m) && $m['orden'] !== null && $m['orden'] !== '' ? $m['orden'] : $a['orden'];
            if (!is_numeric($orden) || (int) $orden < 0 || (int) $orden > 100000) throw new RuntimeException("El orden del módulo $id no es válido.");
            $orden = (int) $orden;
            if ([$precio, $descuento, $desc, $nombre, $orden] === [$a['precio_mensual'], $a['descuento'], $a['descripcion'], $a['nombre'], $a['orden']]) continue;
            $up->execute([$id, $nombre, $desc, $precio, $descuento, $orden]);
            $cambios[] = "$id: \$" . number_format($precio, 2, ',', '.') . ($descuento != $a['descuento'] ? ' (descuento $' . number_format($descuento, 2, ',', '.') . ')' : '');
        }
        $pl = $this->planes();
        foreach ($planes as $codigo => $lista) {
            if (!isset($pl[$codigo])) throw new RuntimeException('Ese plan no existe en TuSalón.');
            if ($lista === '*' || $lista === ['*']) $valor = '*';
            else {
                if (!is_array($lista)) throw new RuntimeException("La lista de módulos del plan $codigo no es válida.");
                foreach ($lista as $id) if (!is_string($id) || !self::existe($id)) throw new RuntimeException('Ese módulo no existe en TuSalón: ' . mb_substr((string) (is_scalar($id) ? $id : '?'), 0, 40) . '.');
                $ids = array_values(array_diff(self::conDependencias($lista), self::FUERA_DE_PLANES));   // el Asistente IA no va en planes
                $valor = $ids === array_values(array_diff(self::ids(), self::FUERA_DE_PLANES)) ? '*' : implode(',', $ids);
            }
            $st = $this->db->prepare('SELECT modulos FROM planes WHERE codigo = ?');
            $st->execute([$codigo]);
            if (trim((string) $st->fetchColumn()) === $valor) continue;
            $this->db->prepare('UPDATE planes SET modulos = ? WHERE codigo = ?')->execute([$valor, $codigo]);
            $cambios[] = "plan $codigo: " . ($valor === '*' ? 'todos' : ($valor === '' ? 'solo la base' : $valor));
        }
        $this->olvidar();
        return $cambios;
    }

    // ------------------------------------------------------------------
    // "Pedir este módulo" desde Mi plan (solicitud cambio_modulos)
    // ------------------------------------------------------------------

    public function pedir(int $salonId, ?int $usuarioId, array $modulos, string $nota = ''): int
    {
        $modulos = self::limpiar($modulos);
        if (!$modulos) throw new RuntimeException('Elige el módulo que quieres.');
        $s = $this->salon($salonId);
        if (!$s) throw new RuntimeException('Salón no encontrado.');
        $ya = $this->detalle($s)['contratados'];
        $faltan = array_values(array_diff(self::conDependencias($modulos), $ya));
        if (!$faltan) throw new RuntimeException('Ese módulo ya está en tu plan.');
        // Un pedido pendiente igual no se repite
        $st = $this->db->prepare("SELECT id FROM pedidos_modulos WHERE salon_id = ? AND modulos = ? AND estado IN ('pendiente','en_proceso')");
        $st->execute([$salonId, implode(',', $faltan)]);
        if ($id = $st->fetchColumn()) return (int) $id;
        $st = $this->db->prepare('INSERT INTO pedidos_modulos (salon_id, usuario_id, modulos, nota) VALUES (?,?,?,?) RETURNING id');
        $st->execute([$salonId, $usuarioId, implode(',', $faltan), mb_substr(trim($nota), 0, 300) ?: null]);
        return (int) $st->fetchColumn();
    }

    public function pedidos(?int $salonId = null, bool $soloAbiertos = true): array
    {
        $sql = 'SELECT p.*, s.nombre AS salon, u.nombre AS usuario FROM pedidos_modulos p JOIN salones s ON s.id = p.salon_id
             LEFT JOIN usuarios u ON u.id = p.usuario_id WHERE s.eliminado_en IS NULL';
        $params = [];
        if ($salonId) { $sql .= ' AND p.salon_id = ?'; $params[] = $salonId; }
        if ($soloAbiertos) $sql .= " AND p.estado IN ('pendiente','en_proceso')";
        $st = $this->db->prepare($sql . ' ORDER BY p.creado DESC, p.id DESC LIMIT 200');
        $st->execute($params);
        return $st->fetchAll();
    }

    public function pedido(int $id): ?array
    {
        $st = $this->db->prepare('SELECT p.*, s.nombre AS salon FROM pedidos_modulos p JOIN salones s ON s.id = p.salon_id WHERE p.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function cerrarPedido(int $id, string $estado, string $quien, string $respuesta = ''): void
    {
        if (!in_array($estado, ['en_proceso', 'atendida', 'descartada'], true)) throw new RuntimeException('Estado no válido.');
        $this->db->prepare('UPDATE pedidos_modulos SET estado = ?, atendido_por = ?, respuesta = ?, actualizado = now() WHERE id = ?')
                 ->execute([$estado, mb_substr($quien, 0, 200), mb_substr(trim($respuesta), 0, 300) ?: null, $id]);
    }
}
