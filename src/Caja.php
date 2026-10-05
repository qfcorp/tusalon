<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/** Caja del día: lo cobrado, por método, y el cierre con el efectivo contado. */
final class Caja
{
    public function __construct(private PDO $db) {}

    public function resumenDia(int $salonId, string $fecha): array
    {
        $st = $this->db->prepare(
            "SELECT v.*, cl.nombre AS cliente,
                    (SELECT string_agg(DISTINCT p.nombre, ', ') FROM venta_items vi
                       JOIN profesionales p ON p.id = vi.profesional_id WHERE vi.venta_id = v.id) AS profesionales,
                    (SELECT string_agg(COALESCE(s.nombre, pr.nombre), ' + ') FROM venta_items vi
                       LEFT JOIN servicios s ON s.id = vi.servicio_id
                       LEFT JOIN productos pr ON pr.id = vi.producto_id WHERE vi.venta_id = v.id) AS detalle
               FROM ventas v LEFT JOIN clientes cl ON cl.id = v.cliente_id
              WHERE v.salon_id = ? AND v.fecha::date = ? AND NOT v.anulada
              ORDER BY v.fecha DESC"
        );
        $st->execute([$salonId, $fecha]);
        $ventas = $st->fetchAll();

        $porMetodo = [];
        $local = 0.0;
        $profesionales = 0.0;
        $efectivoLocal = 0.0;
        foreach ($ventas as $v) {
            $monto = (float) $v['total'] + (float) $v['propina'];
            if ($v['cobrado_por'] === 'local') {
                $local += $monto;
                $porMetodo[$v['metodo_pago']] = ($porMetodo[$v['metodo_pago']] ?? 0) + $monto;
                if ($v['metodo_pago'] === 'efectivo') {
                    $efectivoLocal += $monto;
                }
            } else {
                $profesionales += $monto;
            }
        }
        $st = $this->db->prepare('SELECT * FROM cierres_caja WHERE salon_id = ? AND fecha = ? AND sucursal_id IS NULL');
        $st->execute([$salonId, $fecha]);

        return [
            'ventas'                 => $ventas,
            'cobrado_local'          => round($local, 2),
            'cobrado_profesionales'  => round($profesionales, 2),
            'por_metodo'             => array_map(fn($x) => round($x, 2), $porMetodo),
            'efectivo_esperado'      => round($efectivoLocal, 2),
            'cierre'                 => $st->fetch() ?: null,
        ];
    }

    public function cerrar(int $salonId, string $fecha, float $efectivoContado): array
    {
        $r = $this->resumenDia($salonId, $fecha);
        if ($r['cierre']) {
            throw new RuntimeException('La caja de este día ya está cerrada.');
        }
        $st = $this->db->prepare(
            'INSERT INTO cierres_caja (salon_id, fecha, efectivo_esperado, efectivo_contado)
             VALUES (?,?,?,?) RETURNING diferencia'
        );
        $st->execute([$salonId, $fecha, $r['efectivo_esperado'], round($efectivoContado, 2)]);
        return ['esperado' => $r['efectivo_esperado'], 'contado' => round($efectivoContado, 2),
                'diferencia' => (float) $st->fetchColumn()];
    }
}
