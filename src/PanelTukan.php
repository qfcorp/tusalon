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
        'prueba' => 'En prueba', 'prueba_vencida' => 'Prueba terminada', 'activo' => 'Al día',
        'por_vencer' => 'Venció (en gracia)', 'vencido' => 'Vencido', 'suspendido' => 'Suspendido', 'cancelado' => 'Cancelado',
    ];

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

    /** Lista de salones con su estado real. $filtro = '' | una clave de ESTADOS. */
    public function salones(string $filtro = '', string $buscar = '', ?DateTimeImmutable $hoy = null): array
    {
        $sql = self::SQL_SALONES;
        $params = [];
        if (($buscar = trim($buscar)) !== '') {
            $sql .= ' WHERE s.nombre ILIKE ? OR u.nombre ILIKE ? OR u.email ILIKE ? OR s.telefono ILIKE ? OR s.slug ILIKE ?';
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

        $mes = $hoy->format('Y-m');
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND to_char(pagado_en,'YYYY-MM') = ?");
        $st->execute([$mes]);
        $cobradoMes = (float) $st->fetchColumn();
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND to_char(pagado_en,'YYYY') = ?");
        $st->execute([$hoy->format('Y')]);
        $cobradoAnio = (float) $st->fetchColumn();
        // Ingreso mensual recurrente: lo que vale al mes cada pago vigente
        $st = $this->db->prepare('SELECT COALESCE(sum(monto / meses_recibidos),0) FROM suscripciones WHERE NOT anulada AND inicio <= ? AND fin >= ?');
        $st->execute([$hoy->format('Y-m-d'), $hoy->format('Y-m-d')]);
        $mensual = (float) $st->fetchColumn();

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
        $this->db->prepare('UPDATE salones SET estado = ? WHERE id = ?')->execute([$estado, $salonId]);
    }

    /** Alarga la prueba gratis N días desde hoy (o desde su fin si aún no termina). */
    public function extenderPrueba(int $salonId, int $dias, ?DateTimeImmutable $hoy = null): string
    {
        if ($dias < 1 || $dias > 90) throw new RuntimeException('Elige de 1 a 90 días.');
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare("UPDATE salones SET estado = 'prueba',
                                         prueba_hasta = GREATEST(COALESCE(prueba_hasta, ?::date), ?::date) + ?::int
                                   WHERE id = ? RETURNING prueba_hasta");
        $st->execute([$hoy, $hoy, $dias, $salonId]);
        return (string) $st->fetchColumn();
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
}
