<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Datos del panel del super administrador (Tukán): todos los salones, pagos y números del negocio.
 */
final class PanelTukan
{
    public const ESTADOS = [
        'prueba' => 'En demo', 'prueba_vencida' => 'Demo terminada', 'activo' => 'Al día',
        'por_vencer' => 'Venció (en gracia)', 'vencido' => 'Vencido', 'suspendido' => 'Suspendido', 'cancelado' => 'Cancelado',
    ];
    /** Formas de pago que registra el súper usuario (Google Play entra solo, con GooglePlay::aplicar()). */
    public const METODOS = ['transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'payphone' => 'Payphone', 'plux' => 'Plux',
                            'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'cortesia' => 'Cortesía (sin cobro)'];

    private Planes $planes;

    public function __construct(private PDO $db)
    {
        $this->planes = new Planes($db);
    }

    private const SQL_SALONES = "
        SELECT s.*, pl.nombre AS plan_nombre,
               u.nombre AS dueno, u.email AS dueno_email, u.id AS dueno_usuario_id,
               (SELECT count(*) FROM profesionales p WHERE p.salon_id = s.id AND p.activo) AS peluqueros,
               (SELECT count(*) FROM citas c WHERE c.salon_id = s.id AND c.inicio >= now() - interval '30 days') AS citas_30,
               (SELECT count(*) FROM clientes cl WHERE cl.salon_id = s.id) AS clientes,
               (SELECT max(c.creado) FROM (SELECT max(v.fecha) AS creado FROM ventas v WHERE v.salon_id = s.id
                                           UNION ALL SELECT max(c2.inicio) FROM citas c2 WHERE c2.salon_id = s.id AND c2.inicio <= now()) c) AS ultima_actividad
          FROM salones s
          JOIN planes pl ON pl.codigo = s.plan_codigo
     LEFT JOIN LATERAL (SELECT id, nombre, email FROM usuarios WHERE salon_id = s.id AND rol = 'dueno' ORDER BY id LIMIT 1) u ON true";

    /** Pagos que cuentan en las cifras: de salones que no están en la papelera, o hechos hasta el día en que se mandaron ahí. */
    public const SQL_PAGO_CUENTA = 'NOT EXISTS (SELECT 1 FROM salones sx WHERE sx.id = suscripciones.salon_id AND sx.eliminado_en IS NOT NULL
                                                AND suscripciones.pagado_en > sx.eliminado_en::date)';
    /** Pagos de salones que siguen vivos (para el ingreso mensual de hoy). */
    public const SQL_PAGO_VIVO = 'NOT EXISTS (SELECT 1 FROM salones sx WHERE sx.id = suscripciones.salon_id AND sx.eliminado_en IS NOT NULL)';

    /**
     * Lista de salones con su estado real. $filtro = '' | una clave de ESTADOS.
     * Los que están en la papelera (Anexo A) no salen; con $papelera = true salen solo ellos.
     */
    public function salones(string $filtro = '', string $buscar = '', ?DateTimeImmutable $hoy = null, bool $papelera = false): array
    {
        $sql = self::SQL_SALONES . ' WHERE s.eliminado_en IS ' . ($papelera ? 'NOT NULL' : 'NULL');
        $params = [];
        if (($buscar = trim($buscar)) !== '') {
            $sql .= ' AND (s.nombre ILIKE ? OR u.nombre ILIKE ? OR u.email ILIKE ? OR s.telefono ILIKE ? OR s.slug ILIKE ?)';
            $params = array_fill(0, 5, '%' . $buscar . '%');
        }
        $st = $this->db->prepare($sql . ' ORDER BY s.creado_en DESC');
        $st->execute($params);
        $lista = [];
        foreach ($st->fetchAll() as $s) {
            $s['estado_cuenta'] = $this->planes->estadoCuenta($s, $hoy);
            if ($filtro !== '' && $s['estado_cuenta'] !== $filtro) continue;
            $lista[] = $s;
        }
        return $lista;
    }

    public function salon(int $id, ?DateTimeImmutable $hoy = null): ?array
    {
        $st = $this->db->prepare(self::SQL_SALONES . ' WHERE s.id = ?');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) return null;
        $s['estado_cuenta'] = $this->planes->estadoCuenta($s, $hoy);
        return $s;
    }

    public function pagos(?int $salonId = null, int $limite = 100): array
    {
        $sql = 'SELECT su.*, s.nombre AS salon, pl.nombre AS plan_nombre FROM suscripciones su
                  JOIN salones s ON s.id = su.salon_id JOIN planes pl ON pl.codigo = su.plan_codigo';
        $params = [];
        if ($salonId) { $sql .= ' WHERE su.salon_id = ?'; $params[] = $salonId; }
        $st = $this->db->prepare($sql . ' ORDER BY su.pagado_en DESC, su.id DESC LIMIT ' . (int) $limite);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function registro(?int $salonId = null, int $limite = 30): array
    {
        $sql = 'SELECT r.*, a.nombre AS admin, s.nombre AS salon FROM registro_admin r
             LEFT JOIN superadmins a ON a.id = r.admin_id LEFT JOIN salones s ON s.id = r.salon_id';
        $params = [];
        if ($salonId) { $sql .= ' WHERE r.salon_id = ?'; $params[] = $salonId; }
        $st = $this->db->prepare($sql . ' ORDER BY r.creado_en DESC LIMIT ' . (int) $limite);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Números del negocio para la portada del panel. */
    public function resumen(?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $todos = $this->salones('', '', $hoy);
        $cuenta = array_fill_keys(array_keys(self::ESTADOS), 0);
        foreach ($todos as $s) $cuenta[$s['estado_cuenta']]++;

        $cobradoMes = $this->cobradoMes($hoy->format('Y-m'));
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND " . self::SQL_PAGO_CUENTA . " AND to_char(pagado_en,'YYYY') = ?");
        $st->execute([$hoy->format('Y')]);
        $cobradoAnio = (float) $st->fetchColumn();
        $mensual = $this->ingresoMensual($hoy);

        $en3 = $hoy->modify('+3 days')->format('Y-m-d');
        $en15 = $hoy->modify('+15 days')->format('Y-m-d');
        $pruebasPorTerminar = array_values(array_filter($todos, fn($s) => $s['estado_cuenta'] === 'prueba' && $s['prueba_hasta'] <= $en3));
        $porVencer = array_values(array_filter($todos, fn($s) => in_array($s['estado_cuenta'], ['activo', 'por_vencer'], true)
            && $s['activo_hasta'] && $s['activo_hasta'] <= $en15));
        $sinPagar = array_values(array_filter($todos, fn($s) => in_array($s['estado_cuenta'], ['prueba_vencida', 'vencido'], true)));

        return [
            'total' => count($todos), 'cuenta' => $cuenta,
            'cobrado_mes' => $cobradoMes, 'cobrado_anio' => $cobradoAnio, 'mensual' => $mensual,
            'pruebas_por_terminar' => $pruebasPorTerminar, 'por_vencer' => $porVencer, 'sin_pagar' => array_slice($sinPagar, 0, 20),
            'nuevos' => array_slice($todos, 0, 8),
        ];
    }

    /** Lo cobrado en un mes ('YYYY-MM'): pagos de suscripción no anulados. Lo usan el resumen y el Panel Tukán Central. */
    public function cobradoMes(string $mes): float
    {
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND " . self::SQL_PAGO_CUENTA . " AND to_char(pagado_en,'YYYY-MM') = ?");
        $st->execute([$mes]);
        return (float) $st->fetchColumn();
    }

    /** Ingreso mensual recurrente: lo que vale al mes cada pago vigente. */
    public function ingresoMensual(DateTimeImmutable $hoy): float
    {
        $st = $this->db->prepare('SELECT COALESCE(sum(monto / meses_recibidos),0) FROM suscripciones WHERE NOT anulada AND ' . self::SQL_PAGO_VIVO . ' AND inicio <= ? AND fin >= ?');
        $st->execute([$hoy->format('Y-m-d'), $hoy->format('Y-m-d')]);
        return (float) $st->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Acciones sobre un salón
    // ------------------------------------------------------------------

    public function cambiarPlan(int $salonId, string $plan): void
    {
        $st = $this->db->prepare('UPDATE salones SET plan_codigo = ? WHERE id = ? AND EXISTS (SELECT 1 FROM planes WHERE codigo = ?)');
        $st->execute([$plan, $salonId, $plan]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Plan no válido.');
    }

    public function cambiarEstado(int $salonId, string $estado): void
    {
        if (!in_array($estado, ['prueba', 'activo', 'suspendido', 'cancelado'], true)) throw new RuntimeException('Estado no válido.');
        // Al salir de "suspendido" se borra el motivo de la pausa (Papelera::pausar lo pone)
        $st = $this->db->prepare("UPDATE salones SET estado = ?,
                                         pausa_tipo = CASE WHEN ? = 'suspendido' THEN pausa_tipo END,
                                         pausa_motivo = CASE WHEN ? = 'suspendido' THEN pausa_motivo END,
                                         pausa_en = CASE WHEN ? = 'suspendido' THEN COALESCE(pausa_en, now()) END
                                   WHERE id = ?");
        $st->execute([$estado, $estado, $estado, $estado, $salonId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Salón no encontrado.');
    }

    /** Alarga la demo N días desde hoy (o desde su fin si aún no termina). */
    public function extenderPrueba(int $salonId, int $dias, ?DateTimeImmutable $hoy = null): string
    {
        if ($dias < 1 || $dias > 90) throw new RuntimeException('Elige de 1 a 90 días.');
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare("UPDATE salones SET estado = 'prueba',
                                         prueba_hasta = GREATEST(COALESCE(prueba_hasta, ?::date), ?::date) + ?::int
                                   WHERE id = ? RETURNING prueba_hasta");
        $st->execute([$hoy, $hoy, $dias, $salonId]);
        $r = $st->fetchColumn();
        if ($r === false) throw new RuntimeException('Salón no encontrado.');
        return (string) $r;
    }

    public function guardarNotas(int $salonId, string $notas): void
    {
        $this->db->prepare('UPDATE salones SET notas_admin = ? WHERE id = ?')->execute([mb_substr(trim($notas), 0, 4000) ?: null, $salonId]);
    }

    /** Nueva contraseña temporal para el dueño. Se muestra UNA vez al administrador. */
    public function nuevaClaveDueno(int $salonId): array
    {
        $st = $this->db->prepare("SELECT id, email, nombre FROM usuarios WHERE salon_id = ? AND rol = 'dueno' ORDER BY id LIMIT 1");
        $st->execute([$salonId]);
        $u = $st->fetch();
        if (!$u) throw new RuntimeException('Este salón no tiene dueño con acceso.');
        $clave = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
        $this->db->prepare('UPDATE usuarios SET password_hash = ?, activo = true WHERE id = ?')
                 ->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
        return ['email' => $u['email'], 'nombre' => $u['nombre'], 'clave' => $clave];
    }

    /**
     * Registra un pago de suscripción (valida todo lo que llega del formulario del panel o del Panel Tukán Central).
     * Campos: plan, periodo, inicio (AAAA-MM-DD), metodo (METODOS), monto (vacío = precio del plan; cortesía = 0),
     * dominio (opcional, pago anual) y fecha (opcional: día en que pagó; por defecto hoy).
     */
    public function registrarPago(int $salonId, array $d): array
    {
        $plan = (string) ($d['plan'] ?? '');
        $periodo = (string) ($d['periodo'] ?? '');
        $inicio = (string) ($d['inicio'] ?? '');
        $fi = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        if (!$fi || $fi->format('Y-m-d') !== $inicio) throw new RuntimeException('Elige la fecha de inicio.');
        $metodo = (string) ($d['metodo'] ?? '');
        if (!isset(self::METODOS[$metodo])) throw new RuntimeException('Elige cómo pagó.');
        $montoTxt = trim((string) ($d['monto'] ?? ''));
        if ($montoTxt !== '' && !is_numeric(str_replace(',', '.', $montoTxt))) throw new RuntimeException('Escribe el monto con números, por ejemplo 25,00.');
        $monto = $montoTxt === '' ? ($metodo === 'cortesia' ? 0.0 : null) : (float) str_replace(',', '.', $montoTxt);
        $dominio = strtolower(trim((string) ($d['dominio'] ?? ''))) ?: null;
        if ($dominio !== null && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $dominio)) throw new RuntimeException('El dominio no es válido (ej. barberiaelcorte.com).');
        $pagadoEn = null;
        if (($fecha = (string) ($d['fecha'] ?? '')) !== '') {
            $pagadoEn = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$pagadoEn || $pagadoEn->format('Y-m-d') !== $fecha) throw new RuntimeException('La fecha del pago no es válida.');
        }
        if (!$this->salon($salonId)) throw new RuntimeException('Salón no encontrado.');
        $id = $this->planes->suscribir($salonId, $plan, $periodo, $fi, $metodo, $dominio, $monto, $pagadoEn);
        $c = $this->planes->cotizar($plan, $periodo, $salonId);
        return ['id' => $id, 'monto' => $monto ?? $c['monto'], 'plan' => $plan, 'periodo' => $periodo, 'metodo' => $metodo];
    }
}
