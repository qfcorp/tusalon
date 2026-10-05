<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Planes, prueba gratis y suscripciones de TuSalón.
 *  - Básica $25 / Completa $35 al mes
 *  - Semestral: paga 5, recibe 6
 *  - Anual: paga 10, recibe 12 + dominio propio (a nombre de Tukán)
 *  - Prueba gratis: 7 días
 */
final class Planes
{
    /** Valores por defecto; el super administrador los cambia en su panel (tabla ajustes). */
    public const AJUSTES = [
        'dias_prueba' => '7',
        'semestral_paga' => '5', 'semestral_recibe' => '6',
        'anual_paga' => '10', 'anual_recibe' => '12',
        'dias_gracia' => '3',
        'whatsapp_ventas' => '593996408397',
    ];
    public const PERIODOS_NOMBRE = ['mensual' => 'Mensual', 'semestral' => 'Semestral', 'anual' => 'Anual'];

    private ?array $ajustes = null;

    public function __construct(private PDO $db) {}

    public function ajustes(): array
    {
        if ($this->ajustes === null) {
            $this->ajustes = self::AJUSTES;
            try {
                foreach ($this->db->query('SELECT clave, valor FROM ajustes')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    $this->ajustes[$k] = (string) $v;
                }
            } catch (\PDOException) {
                // base sin la migración 001: se usan los valores por defecto
            }
        }
        return $this->ajustes;
    }

    public function ajuste(string $clave): string
    {
        return $this->ajustes()[$clave] ?? '';
    }

    /** Guarda ajustes validados. */
    public function guardarAjustes(array $valores): void
    {
        $n = fn($k, $min, $max) => (isset($valores[$k]) && ctype_digit((string) $valores[$k])
            && (int) $valores[$k] >= $min && (int) $valores[$k] <= $max) ? (string) (int) $valores[$k]
            : throw new RuntimeException("Revisa el valor de \"$k\".");
        $limpio = [
            'dias_prueba' => $n('dias_prueba', 0, 90),
            'semestral_paga' => $n('semestral_paga', 1, 6), 'semestral_recibe' => $n('semestral_recibe', 6, 12),
            'anual_paga' => $n('anual_paga', 1, 12), 'anual_recibe' => $n('anual_recibe', 12, 24),
            'dias_gracia' => $n('dias_gracia', 0, 30),
        ];
        if ((int) $limpio['semestral_paga'] > (int) $limpio['semestral_recibe'] || (int) $limpio['anual_paga'] > (int) $limpio['anual_recibe']) {
            throw new RuntimeException('Los meses que paga no pueden ser más que los que recibe.');
        }
        $wa = preg_replace('/\D+/', '', (string) ($valores['whatsapp_ventas'] ?? ''));
        if (strlen($wa) < 10 || strlen($wa) > 15) throw new RuntimeException('El WhatsApp de ventas debe tener el código del país, ej. 593991234567.');
        $limpio['whatsapp_ventas'] = $wa;
        $st = $this->db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor');
        foreach ($limpio as $k => $v) $st->execute([$k, $v]);
        $this->ajustes = null;
    }

    /** meses que paga => meses que recibe, y si incluye dominio */
    public function periodos(): array
    {
        $a = $this->ajustes();
        return [
            'mensual'   => ['paga' => 1, 'recibe' => 1, 'dominio' => false],
            'semestral' => ['paga' => (int) $a['semestral_paga'], 'recibe' => (int) $a['semestral_recibe'], 'dominio' => false],
            'anual'     => ['paga' => (int) $a['anual_paga'], 'recibe' => (int) $a['anual_recibe'], 'dominio' => true],
        ];
    }

    /** Texto de la promoción: "Paga 5, recibe 6". */
    public function textoPeriodo(string $periodo): string
    {
        $p = $this->periodos()[$periodo];
        return $periodo === 'mensual' ? 'Mensual'
            : 'Paga ' . $p['paga'] . ', recibe ' . $p['recibe'] . ($p['dominio'] ? ' + dominio propio' : '');
    }

    public function lista(): array
    {
        return $this->db->query('SELECT * FROM planes ORDER BY precio_mensual')->fetchAll();
    }

    public function guardarPlan(string $codigo, string $nombre, float $precio, ?int $maxProfesionales, string $descripcion = ''): void
    {
        if (mb_strlen(trim($nombre)) < 2) throw new RuntimeException('Escribe el nombre del plan.');
        if ($precio < 0 || $precio > 10000) throw new RuntimeException('Precio no válido.');
        if ($maxProfesionales !== null && $maxProfesionales < 1) throw new RuntimeException('El máximo de personas debe ser 1 o más (vacío = sin límite).');
        $st = $this->db->prepare('UPDATE planes SET nombre = ?, precio_mensual = ?, max_profesionales = ?, descripcion = ? WHERE codigo = ?');
        $st->execute([trim($nombre), round($precio, 2), $maxProfesionales, trim($descripcion) ?: null, $codigo]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Plan no encontrado.');
    }

    /**
     * Estado real de la cuenta de un salón (lo que decide si puede usar el sistema):
     * prueba, prueba_vencida, activo, por_vencer (dentro de los días de gracia), vencido, suspendido, cancelado.
     */
    public function estadoCuenta(array $salon, ?DateTimeImmutable $hoy = null): string
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        return match ($salon['estado']) {
            'suspendido', 'cancelado' => $salon['estado'],
            'prueba' => (!empty($salon['prueba_hasta']) && $salon['prueba_hasta'] < $hoy) ? 'prueba_vencida' : 'prueba',
            default => (function () use ($salon, $hoy) {
                if (empty($salon['activo_hasta']) || $salon['activo_hasta'] >= $hoy) return 'activo';
                $gracia = (new DateTimeImmutable($salon['activo_hasta']))->modify('+' . (int) $this->ajuste('dias_gracia') . ' days')->format('Y-m-d');
                return $gracia >= $hoy ? 'por_vencer' : 'vencido';
            })(),
        };
    }

    /** ¿Puede usar el sistema? */
    public function alDia(array $salon, ?DateTimeImmutable $hoy = null): bool
    {
        return in_array($this->estadoCuenta($salon, $hoy), ['prueba', 'activo', 'por_vencer'], true);
    }

    /** Crea un salón nuevo en prueba gratis. Devuelve su id. */
    public function crearSalon(string $nombre, string $slug, string $plan, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $hasta = $hoy->modify('+' . (int) $this->ajuste('dias_prueba') . ' days')->format('Y-m-d');
        $st = $this->db->prepare(
            "INSERT INTO salones (nombre, slug, plan_codigo, estado, prueba_hasta)
             VALUES (?, ?, ?, 'prueba', ?) RETURNING id"
        );
        $st->execute([$nombre, $slug, $plan, $hasta]);
        return (int) $st->fetchColumn();
    }

    /** Calcula lo que paga y lo que recibe un salón según plan y periodo. */
    public function cotizar(string $plan, string $periodo): array
    {
        $periodos = $this->periodos();
        if (!isset($periodos[$periodo])) {
            throw new RuntimeException("Periodo no válido: $periodo");
        }
        $st = $this->db->prepare('SELECT precio_mensual FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        $precio = $st->fetchColumn();
        if ($precio === false) {
            throw new RuntimeException("Plan no válido: $plan");
        }
        $p = $periodos[$periodo];
        $monto = round((float) $precio * $p['paga'], 2);
        return [
            'plan'             => $plan,
            'periodo'          => $periodo,
            'meses_pagados'    => $p['paga'],
            'meses_recibidos'  => $p['recibe'],
            'monto'            => $monto,
            'precio_por_mes'   => round($monto / $p['recibe'], 2),
            'incluye_dominio'  => $p['dominio'],
        ];
    }

    /** Desde cuándo empieza un pago nuevo: el día después de lo ya pagado, o hoy si ya venció. */
    public function inicioSugerido(int $salonId, ?DateTimeImmutable $hoy = null): DateTimeImmutable
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare('SELECT activo_hasta FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $hasta = $st->fetchColumn();
        if ($hasta && $hasta >= $hoy->format('Y-m-d')) return (new DateTimeImmutable($hasta))->modify('+1 day');
        return $hoy;
    }

    /** Registra el pago de una suscripción, activa el salón y lo deja pagado hasta el fin. */
    public function suscribir(int $salonId, string $plan, string $periodo, DateTimeImmutable $inicio,
                              string $metodoPago, ?string $dominio = null, ?float $monto = null): int
    {
        $c = $this->cotizar($plan, $periodo);
        if ($monto !== null) {
            if ($monto < 0) throw new RuntimeException('El monto no puede ser negativo.');
            $c['monto'] = round($monto, 2);   // descuento o precio especial
        }
        $fin = $inicio->modify('+' . $c['meses_recibidos'] . ' months')->modify('-1 day');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        $st = $this->db->prepare(
            'INSERT INTO suscripciones (salon_id, plan_codigo, periodo, meses_pagados, meses_recibidos,
                                        monto, inicio, fin, metodo_pago, incluye_dominio, dominio)
             VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id'
        );
        $st->execute([
            $salonId, $plan, $periodo, $c['meses_pagados'], $c['meses_recibidos'], $c['monto'],
            $inicio->format('Y-m-d'), $fin->format('Y-m-d'), $metodoPago,
            $c['incluye_dominio'] ? 'true' : 'false',
            $c['incluye_dominio'] ? $dominio : null,
        ]);
        $id = (int) $st->fetchColumn();
        $this->db->prepare("UPDATE salones SET estado = 'activo', plan_codigo = ?,
                                   activo_hasta = GREATEST(COALESCE(activo_hasta, ?::date), ?::date) WHERE id = ?")
                 ->execute([$plan, $fin->format('Y-m-d'), $fin->format('Y-m-d'), $salonId]);
        if ($propia) $this->db->commit();
        return $id;
    }

    /** Anula un pago registrado por error y recalcula hasta cuándo está pagado. */
    public function anularPago(int $suscripcionId): void
    {
        $st = $this->db->prepare('UPDATE suscripciones SET anulada = true WHERE id = ? AND NOT anulada RETURNING salon_id');
        $st->execute([$suscripcionId]);
        $salonId = $st->fetchColumn();
        if (!$salonId) throw new RuntimeException('Ese pago no existe o ya estaba anulado.');
        $this->db->prepare('UPDATE salones SET activo_hasta = (SELECT max(fin) FROM suscripciones WHERE salon_id = ? AND NOT anulada) WHERE id = ?')
                 ->execute([$salonId, $salonId]);
    }

    /** ¿El plan del salón permite agregar otro profesional? (Básica: hasta 3, dueño incluido) */
    public function puedeAgregarProfesional(int $salonId): bool
    {
        $st = $this->db->prepare(
            'SELECT p.max_profesionales,
                    (SELECT count(*) FROM profesionales WHERE salon_id = s.id AND activo) AS actuales
               FROM salones s JOIN planes p ON p.codigo = s.plan_codigo
              WHERE s.id = ?'
        );
        $st->execute([$salonId]);
        $r = $st->fetch();
        return $r['max_profesionales'] === null || (int) $r['actuales'] < (int) $r['max_profesionales'];
    }

    public function esPlanCompleta(int $salonId): bool
    {
        $st = $this->db->prepare('SELECT plan_codigo FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        return $st->fetchColumn() === 'completa';
    }
}
