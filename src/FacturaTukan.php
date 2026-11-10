<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use Throwable;

require_once __DIR__ . '/TukanFactura.php';
foreach (['Db', 'Modulos'] as $c) if (!class_exists(__NAMESPACE__ . '\\' . $c)) require_once __DIR__ . "/$c.php";   // pruebas sin cargador automático

/**
 * Factura electrónica con TuFactura (módulo «facturacion», APAGADO de fábrica: se enciende desde el Panel Tukán Central cuando el
 * negocio compra TuFactura). (9 nov 2026) La pieza común está en src/TukanFactura.php (copia de
 * tufactura/docs/enlace/TukanFactura.php: no se cambia aquí).
 *  - Cada cobro (tabla ventas) se puede facturar con un toque desde «Factura electrónica», o solo al cobrar si el negocio lo eligió.
 *  - Se factura lo que se cobró (servicios y productos); la propina no va en la factura.
 *  - La clave tf_ de cada negocio se guarda cifrada con TUKAN_FACTURA_LLAVE (o, si no está, con la clave de la base de datos).
 */
final class FacturaTukan
{
    public const PROGRAMA = 'TuSalón';
    public const ORIGEN = 'venta';
    private static array $columnas = [];

    private static ?PDO $pdo = null;

    /** La base que se usa (la del pedido; en las tareas y pruebas, la que se pasa en alCobrar). */
    public static function db(?PDO $db = null): PDO
    {
        if ($db !== null) self::$pdo = $db;
        return self::$pdo ??= function_exists('db') ? \db() : Db::conectar();
    }

    public static function enlace(?PDO $db = null): \TukanFactura
    {
        static $t = [];
        $db = self::db($db);
        $llave = getenv('TUKAN_FACTURA_LLAVE') ?: ('TuSalón|' . (getenv('TUSALON_DB_PASS') ?: '') . '|' . (getenv('TUSALON_DB_NAME') ?: 'tusalon'));
        return $t[spl_object_id($db)] ??= new \TukanFactura($db, $llave, self::PROGRAMA);
    }

    public static function activo(int $negocio): bool
    {
        return (new Modulos(self::db()))->activo($negocio, \TukanFactura::MODULO);
    }

    private static function hay(string $tabla, string $columna): bool
    {
        $k = "$tabla.$columna";
        if (!isset(self::$columnas[$k])) {
            $st = self::db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?');
            $st->execute([$tabla, $columna]);
            self::$columnas[$k] = (bool) $st->fetchColumn();
        }
        return self::$columnas[$k];
    }

    /** Lo que se manda a TuFactura de un cobro: [cliente, items, extra], o null si no existe o está anulado. */
    public static function datos(int $negocio, string $ventaId): ?array
    {
        if (!ctype_digit($ventaId)) return null;
        $ruc = self::hay('clientes', 'ruc') ? 'c.ruc' : 'NULL';
        $dir = self::hay('clientes', 'direccion') ? 'c.direccion' : 'NULL';
        $st = self::db()->prepare("SELECT v.*, c.nombre AS c_nombre, c.cedula AS c_cedula, $ruc AS c_ruc, c.email AS c_email, c.telefono AS c_telefono, $dir AS c_direccion
                               FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id WHERE v.id = ? AND v.salon_id = ? AND NOT v.anulada");
        $st->execute([(int) $ventaId, $negocio]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) return null;
        $concepto = self::hay('venta_items', 'concepto') ? 'vi.concepto' : 'NULL';
        $st = self::db()->prepare("SELECT vi.cantidad, vi.precio_unitario, $concepto AS concepto, s.nombre AS servicio, p.nombre AS producto
                               FROM venta_items vi LEFT JOIN servicios s ON s.id = vi.servicio_id LEFT JOIN productos p ON p.id = vi.producto_id
                              WHERE vi.venta_id = ? ORDER BY vi.id");
        $st->execute([(int) $ventaId]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $items[] = ['descripcion' => $i['concepto'] ?: ($i['servicio'] ?: ($i['producto'] ?: 'Servicio')), 'cantidad' => (float) $i['cantidad'], 'precio' => (float) $i['precio_unitario']];
        }
        $ident = trim((string) ($v['c_ruc'] ?: $v['c_cedula']));
        $cliente = ['identificacion' => $ident, 'nombre' => (string) $v['c_nombre'], 'correo' => (string) $v['c_email'], 'telefono' => (string) $v['c_telefono'], 'direccion' => (string) $v['c_direccion']];
        return [$cliente, $items, ['forma' => (string) $v['metodo_pago'], 'info' => [self::PROGRAMA => 'Cobro #' . $v['id']]]];
    }

    /** Los últimos cobros para facturar a mano: [id, fecha, cliente, total, detalle]. */
    public static function cobros(int $negocio, int $limite = 25): array
    {
        $concepto = self::hay('venta_items', 'concepto') ? 'vi.concepto, ' : '';
        $st = self::db()->prepare("SELECT v.id, v.fecha, c.nombre, v.total,
                                    (SELECT string_agg(COALESCE({$concepto}s.nombre, p.nombre, 'Servicio'), ', ') FROM venta_items vi
                                       LEFT JOIN servicios s ON s.id = vi.servicio_id LEFT JOIN productos p ON p.id = vi.producto_id WHERE vi.venta_id = v.id) AS detalle
                               FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
                              WHERE v.salon_id = ? AND NOT v.anulada ORDER BY v.fecha DESC, v.id DESC LIMIT " . max(1, min(100, $limite)));
        $st->execute([$negocio]);
        return array_map(fn($r) => [(string) $r['id'], (string) $r['fecha'], (string) $r['nombre'], (float) $r['total'], (string) $r['detalle']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Al terminar cada cobro (Liquidacion::registrarVenta): si el negocio eligió «automático», sale la factura sola. Nunca tumba el cobro. */
    public static function alCobrar(PDO $db, int $negocio, int $ventaId): void
    {
        try {
            self::db($db);
            if (!self::activo($negocio)) return;
            self::enlace($db)->alCobrar($negocio, true, self::ORIGEN, (string) $ventaId, fn() => self::datos($negocio, (string) $ventaId));
        } catch (Throwable $e) {
            error_log(self::PROGRAMA . ' TuFactura: ' . $e->getMessage());
        }
    }
}
