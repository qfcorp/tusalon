<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Facturas que el salón RECIBE de sus proveedores (compras y gastos),
 * importadas del SRI. Alimentan los gastos del panel del dueño.
 * TuSalón no emite facturas.
 *
 * Tres formas de importarlas (pantalla Más → Gastos y facturas, plan Completa):
 *  - los XML que manda el proveedor o se bajan de SRI en línea (uno, varios o un .zip),
 *  - el reporte TXT "Comprobantes electrónicos recibidos" de SRI en línea (luego se completan con el SRI),
 *  - pegando claves de acceso (se descargan del servicio público del SRI).
 * Las notas de crédito restan del gasto.
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

    /** Cuántas claves se descargan del SRI por vez (cada una tarda unos segundos). */
    public const MAX_SRI = 15;

    public function __construct(private PDO $db) {}

    /** Categoría recordada para un proveedor (o null). */
    public function categoriaProveedor(int $salonId, string $ruc): ?string
    {
        $st = $this->db->prepare('SELECT categoria FROM proveedores WHERE salon_id = ? AND ruc = ?');
        $st->execute([$salonId, $ruc]);
        $c = $st->fetchColumn();
        return $c === false ? null : (string) $c;
    }

    private function recordarProveedor(int $salonId, string $ruc, ?string $nombre, string $categoria): void
    {
        $this->db->prepare(
            'INSERT INTO proveedores (salon_id, ruc, nombre, categoria) VALUES (?,?,?,?)
             ON CONFLICT (salon_id, ruc) DO UPDATE SET categoria = EXCLUDED.categoria, nombre = COALESCE(EXCLUDED.nombre, proveedores.nombre)'
        )->execute([$salonId, $ruc, $nombre, $categoria]);
    }

    /**
     * Guarda una factura recibida. Si no se indica categoría, usa la que tenga
     * guardada ese proveedor. Si se indica, la recuerda para la próxima.
     * Devuelve false si la factura ya estaba importada (misma clave de acceso).
     */
    public function importar(int $salonId, array $f, ?string $categoria = null): bool
    {
        if ($categoria === null) {
            $categoria = $this->categoriaProveedor($salonId, (string) $f['ruc_proveedor']) ?? 'otros';
        } else {
            if (!isset(self::CATEGORIAS[$categoria])) {
                throw new RuntimeException("Categoría no válida: $categoria");
            }
            $this->recordarProveedor($salonId, (string) $f['ruc_proveedor'], $f['proveedor'] ?? null, $categoria);
        }

        $st = $this->db->prepare(
            'INSERT INTO facturas_recibidas (salon_id, clave_acceso, fecha_emision, ruc_proveedor, proveedor,
                                             categoria, subtotal, iva, total, archivo_xml, tipo, numero, detalle,
                                             id_comprador, origen, completa, xml, importada_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (salon_id, clave_acceso) DO NOTHING'
        );
        $st->execute([
            $salonId, $f['clave_acceso'], $f['fecha_emision'], $f['ruc_proveedor'], $f['proveedor'] ?? null,
            $categoria, $f['subtotal'], $f['iva'] ?? 0, $f['total'], $f['archivo_xml'] ?? null,
            $f['tipo'] ?? 'factura', $f['numero'] ?? null, isset($f['detalle']) && $f['detalle'] !== '' ? mb_substr((string) $f['detalle'], 0, 400) : null,
            isset($f['id_comprador']) && $f['id_comprador'] !== '' ? mb_substr((string) $f['id_comprador'], 0, 20) : null,
            $f['origen'] ?? 'xml', array_key_exists('completa', $f) ? ($f['completa'] ? 'true' : 'false') : 'true',
            $f['xml'] ?? null, $f['importada_por'] ?? null,
        ]);
        return $st->rowCount() === 1;
    }

    /**
     * Guarda un comprobante leído con Sri::leerComprobante() o Sri::leerTxt().
     * Devuelve 'nueva' | 'completada' (llegó del TXT y ahora trae el XML) | 'repetida'.
     */
    public function guardarComprobante(int $salonId, array $d, string $origen, ?int $usuarioId = null): string
    {
        $clave = (string) preg_replace('/\D/', '', (string) ($d['clave_acceso'] ?? ''));
        if (strlen($clave) !== 49) throw new RuntimeException('El comprobante no tiene clave de acceso.');
        if (!isset(Sri::TIPOS[$d['tipo'] ?? ''])) throw new RuntimeException('Ese comprobante no es una factura ni una nota de crédito o débito.');
        $conXml = ($d['xml'] ?? '') !== '';
        $st = $this->db->prepare('SELECT id, completa, origen FROM facturas_recibidas WHERE salon_id = ? AND clave_acceso = ?');
        $st->execute([$salonId, $clave]);
        $ya = $st->fetch();
        $campos = [
            'tipo' => $d['tipo'], 'fecha_emision' => $d['fecha'], 'ruc_proveedor' => mb_substr((string) $d['ruc_emisor'], 0, 13),
            'proveedor' => mb_substr((string) ($d['razon_social'] ?? ''), 0, 200) ?: null, 'numero' => mb_substr((string) ($d['numero'] ?? ''), 0, 20) ?: null,
            'subtotal' => round((float) ($d['subtotal'] ?? 0), 2), 'iva' => round((float) ($d['iva'] ?? 0), 2), 'total' => round((float) ($d['total'] ?? 0), 2),
            'detalle' => mb_substr((string) ($d['detalle'] ?? ''), 0, 400) ?: null, 'id_comprador' => mb_substr((string) ($d['id_comprador'] ?? ''), 0, 20) ?: null,
        ];
        if ($ya) {
            // Un TXT no pisa lo que vino del XML; el XML sí completa lo que vino del TXT (sin cambiar la categoría)
            if ($ya['completa'] || !$conXml) return 'repetida';
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            $this->db->prepare("UPDATE facturas_recibidas SET $sets, completa = true, xml = ?, origen = ? WHERE id = ?")
                     ->execute(array_merge(array_values($campos), [$d['xml'], $origen, $ya['id']]));
            return 'completada';
        }
        $this->importar($salonId, $campos + [
            'clave_acceso' => $clave, 'origen' => $origen, 'completa' => $conXml, 'xml' => $conXml ? $d['xml'] : null,
            'importada_por' => $usuarioId,
        ]);
        return 'nueva';
    }

    private static function resultado(): array
    {
        return ['nueva' => 0, 'completada' => 0, 'repetida' => 0, 'errores' => [], 'pendientes' => 0, 'ajenas' => 0];
    }

    /** RUC o cédula del salón (para avisar si una factura vino a nombre de otra persona). */
    private function rucSalon(int $salonId): string
    {
        $st = $this->db->prepare('SELECT ruc FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        return (string) preg_replace('/\D/', '', (string) $st->fetchColumn());
    }

    private function esAjena(string $rucSalon, array $d): bool
    {
        $comp = (string) preg_replace('/\D/', '', (string) ($d['id_comprador'] ?? ''));
        if ($rucSalon === '' || $comp === '') return false;
        // Una cédula (10) y el RUC de persona natural (cédula + 001) son la misma persona
        return substr($comp, 0, 10) !== substr($rucSalon, 0, 10);
    }

    /** Importa varios XML: [[nombre, contenido|null], ...]. */
    public function importarXmls(int $salonId, array $archivos, ?int $usuarioId = null): array
    {
        $r = self::resultado();
        $rucSalon = $this->rucSalon($salonId);
        foreach ($archivos as [$nombre, $contenido]) {
            if ($contenido === null) { $r['errores'][] = "$nombre: no se pudo leer (¿pesa más de 2 MB?)."; continue; }
            $d = Sri::leerComprobante($contenido);
            if (!$d['ok']) { $r['errores'][] = "$nombre: " . $d['error']; continue; }
            if (!isset(Sri::TIPOS[$d['tipo']])) { $r['errores'][] = "$nombre: ese comprobante no es un gasto."; continue; }
            try {
                $res = $this->guardarComprobante($salonId, $d, 'xml', $usuarioId);
                $r[$res]++;
                if ($res !== 'repetida' && $this->esAjena($rucSalon, $d)) $r['ajenas']++;
            } catch (RuntimeException $e) {
                if ($e instanceof \PDOException) throw $e;
                $r['errores'][] = "$nombre: " . $e->getMessage();
            }
        }
        return $r;
    }

    /** Importa el reporte TXT de "Comprobantes electrónicos recibidos" (quedan por completar con el SRI). */
    public function importarTxt(int $salonId, string $contenido, ?int $usuarioId = null): array
    {
        $r = self::resultado();
        $leido = Sri::leerTxt($contenido);
        $r['errores'] = $leido['errores'];
        foreach ($leido['filas'] as $d) {
            $r[$this->guardarComprobante($salonId, $d, 'txt', $usuarioId)]++;
        }
        return $r;
    }

    /**
     * Descarga del SRI el XML de claves pegadas (o de las que llegaron solo del TXT) y las guarda.
     * Máximo MAX_SRI por vez; las demás quedan en 'pendientes'.
     */
    public function importarClaves(int $salonId, array $claves, ?int $usuarioId = null, string $origen = 'clave'): array
    {
        $r = self::resultado();
        $rucSalon = $this->rucSalon($salonId);
        $claves = array_values(array_unique($claves));
        $lote = array_slice($claves, 0, self::MAX_SRI);
        $r['pendientes'] = count($claves) - count($lote);
        foreach ($lote as $i => $clave) {
            if (!Sri::claveValida($clave)) { $r['errores'][] = "Clave no válida: $clave"; continue; }
            $st = $this->db->prepare('SELECT completa FROM facturas_recibidas WHERE salon_id = ? AND clave_acceso = ?');
            $st->execute([$salonId, $clave]);
            if ($st->fetchColumn() === true) { $r['repetida']++; continue; }
            $des = Sri::descargar($clave);
            if (!$des['ok']) {
                $r['errores'][] = substr($clave, 0, 10) . '…: ' . $des['error'];
                if (str_starts_with($des['error'], 'Sin conexión')) {   // las demás quedan para después
                    $r['pendientes'] += count($lote) - $i - 1;
                    break;
                }
                continue;
            }
            $d = Sri::leerComprobante($des['xml']);
            if (!$d['ok']) { $r['errores'][] = substr($clave, 0, 10) . '…: ' . $d['error']; continue; }
            if (!isset(Sri::TIPOS[$d['tipo']])) { $r['errores'][] = substr($clave, 0, 10) . '…: ese comprobante no es un gasto.'; continue; }
            $d['clave_acceso'] = $clave;
            $res = $this->guardarComprobante($salonId, $d, $origen === 'txt' ? 'sri' : $origen, $usuarioId);
            $r[$res]++;
            if ($res !== 'repetida' && $this->esAjena($rucSalon, $d)) $r['ajenas']++;
        }
        $r['pendientes'] = max(0, $r['pendientes']);
        return $r;
    }

    /** Las que llegaron solo del TXT: se bajan sus XML del SRI. */
    public function completarDesdeSri(int $salonId, ?int $usuarioId = null): array
    {
        $st = $this->db->prepare('SELECT clave_acceso FROM facturas_recibidas WHERE salon_id = ? AND NOT completa ORDER BY fecha_emision DESC, id DESC');
        $st->execute([$salonId]);
        return $this->importarClaves($salonId, $st->fetchAll(PDO::FETCH_COLUMN), $usuarioId, 'txt');
    }

    public static function textoResultado(array $r): string
    {
        $p = [];
        if ($r['nueva']) $p[] = $r['nueva'] . ($r['nueva'] === 1 ? ' factura nueva' : ' facturas nuevas');
        if ($r['completada']) $p[] = $r['completada'] . ' ' . ($r['completada'] === 1 ? 'completada' : 'completadas') . ' con el detalle del SRI';
        if ($r['repetida']) $p[] = $r['repetida'] . ' ya ' . ($r['repetida'] === 1 ? 'estaba registrada' : 'estaban registradas');
        if (!empty($r['pendientes'])) $p[] = 'faltan ' . $r['pendientes'] . ': vuelve a tocar el botón para seguir';
        return $p ? implode(' · ', $p) . '.' : 'No se importó ninguna factura.';
    }

    /** Facturas de un periodo (con filtro opcional de categoría). */
    public function lista(int $salonId, string $desde, string $hasta, ?string $categoria = null): array
    {
        $sql = 'SELECT id, clave_acceso, fecha_emision, ruc_proveedor, proveedor, categoria, subtotal, iva, total,
                       tipo, numero, detalle, id_comprador, origen, completa
                  FROM facturas_recibidas WHERE salon_id = ? AND fecha_emision BETWEEN ? AND ?';
        $p = [$salonId, $desde, $hasta];
        if ($categoria !== null && isset(self::CATEGORIAS[$categoria])) { $sql .= ' AND categoria = ?'; $p[] = $categoria; }
        $st = $this->db->prepare($sql . ' ORDER BY fecha_emision DESC, id DESC LIMIT 500');
        $st->execute($p);
        return $st->fetchAll();
    }

    public function porCompletar(int $salonId): int
    {
        $st = $this->db->prepare('SELECT count(*) FROM facturas_recibidas WHERE salon_id = ? AND NOT completa');
        $st->execute([$salonId]);
        return (int) $st->fetchColumn();
    }

    /** Cambia la categoría de una factura; con $todasDelProveedor, también las demás de ese proveedor y las próximas. */
    public function cambiarCategoria(int $salonId, int $facturaId, string $categoria, bool $todasDelProveedor = true): void
    {
        if (!isset(self::CATEGORIAS[$categoria])) throw new RuntimeException('Elige una categoría.');
        $st = $this->db->prepare('SELECT ruc_proveedor, proveedor FROM facturas_recibidas WHERE id = ? AND salon_id = ?');
        $st->execute([$facturaId, $salonId]);
        $f = $st->fetch();
        if (!$f) throw new RuntimeException('No se encontró la factura.');
        $this->db->prepare('UPDATE facturas_recibidas SET categoria = ? WHERE id = ?')->execute([$categoria, $facturaId]);
        if ($todasDelProveedor) {
            $this->db->prepare('UPDATE facturas_recibidas SET categoria = ? WHERE salon_id = ? AND ruc_proveedor = ?')
                     ->execute([$categoria, $salonId, $f['ruc_proveedor']]);
            $this->recordarProveedor($salonId, (string) $f['ruc_proveedor'], $f['proveedor'], $categoria);
        }
    }

    /** Quita una factura de los gastos (por ejemplo, si era personal). Se puede volver a importar. */
    public function borrar(int $salonId, int $facturaId): void
    {
        $st = $this->db->prepare('DELETE FROM facturas_recibidas WHERE id = ? AND salon_id = ?');
        $st->execute([$facturaId, $salonId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró la factura.');
    }

    /** Total de gastos del periodo y desglose por categoría (las notas de crédito restan). */
    public function gastos(int $salonId, string $desde, string $hasta): array
    {
        $st = $this->db->prepare(
            "SELECT categoria, SUM(CASE WHEN tipo = 'nota_credito' THEN -total ELSE total END) AS total FROM facturas_recibidas
              WHERE salon_id = ? AND fecha_emision BETWEEN ? AND ?
              GROUP BY categoria ORDER BY total DESC"
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
