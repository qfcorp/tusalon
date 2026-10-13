<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Inventario de productos: los que se venden al cliente (shampoo, ceras) y los que
 * se usan en el salón (tintes, oxidantes). Cada entrada, venta, uso o ajuste queda anotado.
 */
final class Inventario
{
    public function __construct(private PDO $db) {}

    public function lista(int $salonId, bool $incluirInactivos = false): array
    {
        $st = $this->db->prepare(
            'SELECT p.*, (p.activo AND p.stock <= p.stock_minimo) AS bajo
               FROM productos p WHERE p.salon_id = ?' . ($incluirInactivos ? '' : ' AND p.activo') . '
              ORDER BY p.activo DESC, p.nombre'
        );
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    /** Productos que se pueden vender en el cobro. */
    public function paraVender(int $salonId): array
    {
        $st = $this->db->prepare('SELECT id, nombre, marca, precio, stock FROM productos
                                   WHERE salon_id = ? AND activo AND para_venta ORDER BY nombre');
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    public function producto(int $salonId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM productos WHERE id = ? AND salon_id = ?');
        $st->execute([$id, $salonId]);
        return $st->fetch() ?: null;
    }

    /** Crea o actualiza un producto. Al crearlo, el stock inicial queda anotado. */
    public function guardar(int $salonId, ?int $id, array $d, ?int $usuarioId = null): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $marca = trim((string) ($d['marca'] ?? '')) ?: null;
        $precio = round((float) ($d['precio'] ?? 0), 2);
        $costo = round((float) ($d['costo'] ?? 0), 2);
        $minimo = (int) ($d['stock_minimo'] ?? 0);
        $paraVenta = !empty($d['para_venta']);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del producto.');
        if ($precio < 0 || $costo < 0) throw new RuntimeException('El precio y el costo no pueden ser negativos.');
        if ($paraVenta && $precio <= 0) throw new RuntimeException('Pon el precio de venta al cliente.');
        if ($minimo < 0) throw new RuntimeException('El stock mínimo no puede ser negativo.');

        if ($id) {
            $st = $this->db->prepare('UPDATE productos SET nombre = ?, marca = ?, precio = ?, costo = ?, stock_minimo = ?,
                                             para_venta = ?, activo = ? WHERE id = ? AND salon_id = ?');
            $st->execute([$nombre, $marca, $precio, $costo, $minimo, $paraVenta ? 't' : 'f',
                          !array_key_exists('activo', $d) || !empty($d['activo']) ? 't' : 'f', $id, $salonId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el producto.');
            return $id;
        }
        $inicial = (int) ($d['stock'] ?? 0);
        if ($inicial < 0) throw new RuntimeException('El stock inicial no puede ser negativo.');
        $st = $this->db->prepare('INSERT INTO productos (salon_id, nombre, marca, precio, costo, stock, stock_minimo, para_venta)
                                  VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$salonId, $nombre, $marca, $precio, $costo, $inicial, $minimo, $paraVenta ? 't' : 'f']);
        $nuevo = (int) $st->fetchColumn();
        if ($inicial > 0) {
            $this->anotar($salonId, $nuevo, 'inicial', $inicial, $costo, null, $usuarioId, 'Stock inicial');
        }
        return $nuevo;
    }

    /** Llegó mercadería del proveedor. */
    public function entrada(int $salonId, int $productoId, int $cantidad, ?float $costo, string $nota = '', ?int $usuarioId = null): void
    {
        if ($cantidad <= 0) throw new RuntimeException('La cantidad que llegó debe ser mayor a cero.');
        $this->mover($salonId, $productoId, 'entrada', $cantidad, $costo, $nota ?: 'Llegó del proveedor', $usuarioId);
        if ($costo !== null && $costo >= 0) {
            $this->db->prepare('UPDATE productos SET costo = ? WHERE id = ? AND salon_id = ?')->execute([round($costo, 2), $productoId, $salonId]);
        }
    }

    /** Se usó en el salón (ej. un tinte en una clienta). */
    public function uso(int $salonId, int $productoId, int $cantidad, string $nota = '', ?int $usuarioId = null): void
    {
        if ($cantidad <= 0) throw new RuntimeException('La cantidad usada debe ser mayor a cero.');
        $this->mover($salonId, $productoId, 'uso', -$cantidad, null, $nota ?: 'Usado en el salón', $usuarioId);
    }

    /** Conteo físico: el stock real es este. */
    public function ajustar(int $salonId, int $productoId, int $real, string $nota = '', ?int $usuarioId = null): int
    {
        if ($real < 0) throw new RuntimeException('El conteo no puede ser negativo.');
        $p = $this->producto($salonId, $productoId);
        if (!$p) throw new RuntimeException('No se encontró el producto.');
        $dif = $real - (int) $p['stock'];
        if ($dif !== 0) {
            $this->mover($salonId, $productoId, 'ajuste', $dif, null, $nota ?: 'Conteo físico', $usuarioId);
        }
        return $dif;
    }

    private function mover(int $salonId, int $productoId, string $tipo, int $cantidad, ?float $costo, string $nota, ?int $usuarioId): void
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT stock, nombre FROM productos WHERE id = ? AND salon_id = ? FOR UPDATE');
            $st->execute([$productoId, $salonId]);
            $p = $st->fetch();
            if (!$p) throw new RuntimeException('No se encontró el producto.');
            if ((int) $p['stock'] + $cantidad < 0) {
                throw new RuntimeException("Solo hay {$p['stock']} de {$p['nombre']}. Si el conteo real es otro, usa \"Corregir conteo\".");
            }
            $this->db->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?')->execute([$cantidad, $productoId]);
            $this->anotar($salonId, $productoId, $tipo, $cantidad, $costo, null, $usuarioId, $nota);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }

    public function anotar(int $salonId, int $productoId, string $tipo, int $cantidad, ?float $costo,
                           ?int $ventaId, ?int $usuarioId, string $nota): void
    {
        $this->db->prepare('INSERT INTO movimientos_inventario (salon_id, producto_id, tipo, cantidad, costo_unitario, venta_id, usuario_id, nota)
                            VALUES (?,?,?,?,?,?,?,?)')
                 ->execute([$salonId, $productoId, $tipo, $cantidad, $costo, $ventaId, $usuarioId, mb_substr($nota, 0, 200)]);
    }

    public function movimientos(int $salonId, int $productoId, int $limite = 50): array
    {
        $st = $this->db->prepare('SELECT m.*, u.nombre AS usuario FROM movimientos_inventario m
                               LEFT JOIN usuarios u ON u.id = m.usuario_id
                                   WHERE m.salon_id = ? AND m.producto_id = ? ORDER BY m.fecha DESC, m.id DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId, $productoId]);
        return $st->fetchAll();
    }

    /** Productos con poco stock (para el aviso en el inicio). */
    public function bajos(int $salonId): array
    {
        $st = $this->db->prepare('SELECT id, nombre, stock, stock_minimo FROM productos
                                   WHERE salon_id = ? AND activo AND stock <= stock_minimo ORDER BY stock, nombre');
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    /** Cuánto dinero hay en el estante (al costo) y cuánto valdría vendido. */
    public function valor(int $salonId): array
    {
        $st = $this->db->prepare('SELECT COALESCE(sum(stock * costo),0) AS costo, COALESCE(sum(CASE WHEN para_venta THEN stock * precio ELSE 0 END),0) AS venta
                                    FROM productos WHERE salon_id = ? AND activo AND stock > 0');
        $st->execute([$salonId]);
        $r = $st->fetch();
        return ['costo' => round((float) $r['costo'], 2), 'venta' => round((float) $r['venta'], 2)];
    }
}
