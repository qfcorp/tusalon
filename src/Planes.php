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
    public const DIAS_PRUEBA = 7;

    /** meses que paga => meses que recibe, y si incluye dominio */
    private const PERIODOS = [
        'mensual'   => ['paga' => 1,  'recibe' => 1,  'dominio' => false],
        'semestral' => ['paga' => 5,  'recibe' => 6,  'dominio' => false],
        'anual'     => ['paga' => 10, 'recibe' => 12, 'dominio' => true],
    ];

    public function __construct(private PDO $db) {}

    /** Crea un salón nuevo en prueba gratis de 7 días. Devuelve su id. */
    public function crearSalon(string $nombre, string $slug, string $plan, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $hasta = $hoy->modify('+' . self::DIAS_PRUEBA . ' days')->format('Y-m-d');
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
        if (!isset(self::PERIODOS[$periodo])) {
            throw new RuntimeException("Periodo no válido: $periodo");
        }
        $st = $this->db->prepare('SELECT precio_mensual FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        $precio = $st->fetchColumn();
        if ($precio === false) {
            throw new RuntimeException("Plan no válido: $plan");
        }
        $p = self::PERIODOS[$periodo];
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

    /** Registra el pago de una suscripción y activa el salón. */
    public function suscribir(int $salonId, string $plan, string $periodo, DateTimeImmutable $inicio,
                              string $metodoPago, ?string $dominio = null): int
    {
        $c = $this->cotizar($plan, $periodo);
        $fin = $inicio->modify('+' . $c['meses_recibidos'] . ' months')->modify('-1 day');
        $this->db->beginTransaction();
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
        $this->db->prepare("UPDATE salones SET estado = 'activo', plan_codigo = ? WHERE id = ?")
                 ->execute([$plan, $salonId]);
        $this->db->commit();
        return $id;
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
