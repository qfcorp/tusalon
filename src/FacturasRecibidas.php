<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;

/**
 * Facturas que el salón RECIBE de sus proveedores (compras y gastos),
 * importadas del SRI. Alimentan los gastos del panel del dueño.
 * TuSalón no emite facturas.
 */
final class FacturasRecibidas
{
    public const CATEGORIAS = [
        'productos_venta'   => 'Productos para vender',
        'insumos'           => 'Insumos (tintes, navajas, toallas)',
        'servicios_basicos' => 'Luz, agua, internet',
        'arriendo_local'    => 'Arriendo del local',
        'equipos'           => 'Equipos y mobiliario',
        'publicidad'        => 'Publicidad',
        'otros'             => 'Otros',
    ];

    public function __construct(private PDO $db) {}

    /**
     * Guarda una factura recibida. Si no se indica categoría, usa la que tenga
     * guardada ese proveedor. Si se indica, la recuerda para la próxima.
     * Devuelve false si la factura ya estaba importada (misma clave de acceso).
     */
    public function importar(int $salonId, array $f, ?string $categoria = null): bool
    {
        if ($categoria === null) {
            $st = $this->db->prepare('SELECT categoria FROM proveedores WHERE salon_id = ? AND ruc = ?');
            $st->execute([$salonId, $f['ruc_proveedor']]);
            $categoria = $st->fetchColumn() ?: 'otros';
        } else {
            if (!isset(self::CATEGORIAS[$categoria])) {
                throw new \RuntimeException("Categoría no válida: $categoria");
            }
            $this->db->prepare(
                'INSERT INTO proveedores (salon_id, ruc, nombre, categoria) VALUES (?,?,?,?)
                 ON CONFLICT (salon_id, ruc) DO UPDATE SET categoria = EXCLUDED.categoria, nombre = EXCLUDED.nombre'
            )->execute([$salonId, $f['ruc_proveedor'], $f['proveedor'] ?? null, $categoria]);
        }

        $st = $this->db->prepare(
            'INSERT INTO facturas_recibidas (salon_id, clave_acceso, fecha_emision, ruc_proveedor, proveedor,
                                             categoria, subtotal, iva, total, archivo_xml)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (salon_id, clave_acceso) DO NOTHING'
        );
        $st->execute([
            $salonId, $f['clave_acceso'], $f['fecha_emision'], $f['ruc_proveedor'], $f['proveedor'] ?? null,
            $categoria, $f['subtotal'], $f['iva'] ?? 0, $f['total'], $f['archivo_xml'] ?? null,
        ]);
        return $st->rowCount() === 1;
    }

    /** Total de gastos del periodo y desglose por categoría. */
    public function gastos(int $salonId, string $desde, string $hasta): array
    {
        $st = $this->db->prepare(
            'SELECT categoria, SUM(total) AS total FROM facturas_recibidas
              WHERE salon_id = ? AND fecha_emision BETWEEN ? AND ?
              GROUP BY categoria ORDER BY total DESC'
        );
        $st->execute([$salonId, $desde, $hasta]);
        $porCategoria = [];
        $total = 0.0;
        foreach ($st->fetchAll() as $r) {
            $porCategoria[$r['categoria']] = round((float) $r['total'], 2);
            $total += (float) $r['total'];
        }
        return ['total' => round($total, 2), 'por_categoria' => $porCategoria];
    }
}
