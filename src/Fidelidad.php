<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Paquetes (ej. "5 cortes por $40"), tarjetas de regalo y puntos de fidelidad.
 * Lo que se vende por adelantado (paquetes y tarjetas) entra a la caja el día de la venta;
 * cuando el cliente lo usa, el servicio queda "ya pagado" y no vuelve a sumar en la caja.
 */
final class Fidelidad
{
    private const LETRAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin 0/O ni 1/I para no confundir
    public const METODOS = ['efectivo', 'transferencia', 'deuna', 'tarjeta', 'payphone', 'plux'];

    public function __construct(private PDO $db) {}

    private static function r(float $v): float
    {
        return round($v + 0.0000001, 2);
    }

    private function metodo(string $m): string
    {
        return in_array($m, self::METODOS, true) ? $m : 'efectivo';
    }

    private function ingreso(int $salonId, string $concepto, int $refId, ?int $clienteId, string $desc, string $metodo, float $monto, string $fecha): void
    {
        $this->db->prepare('INSERT INTO ingresos (salon_id, fecha, concepto, referencia_id, cliente_id, descripcion, metodo_pago, monto)
                            VALUES (?,?,?,?,?,?,?,?)')
                 ->execute([$salonId, $fecha, $concepto, $refId, $clienteId, mb_substr($desc, 0, 200), $metodo, self::r($monto)]);
    }

    private function clienteDelSalon(int $salonId, int $clienteId): array
    {
        $st = $this->db->prepare('SELECT id, nombre, telefono, puntos FROM clientes WHERE id = ? AND salon_id = ?');
        $st->execute([$clienteId, $salonId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Elige un cliente de la lista.');
        return $c;
    }

    // ==================================================================
    // Paquetes
    // ==================================================================

    public function paquetes(int $salonId, bool $soloActivos = false): array
    {
        $st = $this->db->prepare('SELECT p.*, s.nombre AS servicio, s.precio AS precio_servicio,
                                         (SELECT count(*) FROM paquetes_cliente pc WHERE pc.paquete_id = p.id AND NOT pc.anulado) AS vendidos
                                    FROM paquetes p JOIN servicios s ON s.id = p.servicio_id
                                   WHERE p.salon_id = ?' . ($soloActivos ? ' AND p.activo' : '') . ' ORDER BY p.activo DESC, p.nombre');
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    public function guardarPaquete(int $salonId, ?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $servicioId = (int) ($d['servicio_id'] ?? 0);
        $sesiones = (int) ($d['sesiones'] ?? 0);
        $precio = self::r((float) ($d['precio'] ?? 0));
        $vigencia = (int) ($d['vigencia_dias'] ?? 0) ?: null;
        $st = $this->db->prepare('SELECT nombre FROM servicios WHERE id = ? AND salon_id = ? AND activo');
        $st->execute([$servicioId, $salonId]);
        $servicio = $st->fetchColumn();
        if (!$servicio) throw new RuntimeException('Elige el servicio que incluye el paquete.');
        if ($sesiones < 2 || $sesiones > 100) throw new RuntimeException('El paquete debe tener de 2 a 100 sesiones.');
        if ($precio <= 0) throw new RuntimeException('Pon el precio del paquete.');
        if ($vigencia !== null && ($vigencia < 7 || $vigencia > 730)) throw new RuntimeException('La vigencia debe ser de 7 a 730 días (o vacía si no vence).');
        if ($nombre === '') $nombre = "$sesiones × $servicio";
        if ($id) {
            $st = $this->db->prepare('UPDATE paquetes SET nombre = ?, servicio_id = ?, sesiones = ?, precio = ?, vigencia_dias = ?, activo = ?
                                       WHERE id = ? AND salon_id = ?');
            $st->execute([$nombre, $servicioId, $sesiones, $precio, $vigencia, !array_key_exists('activo', $d) || !empty($d['activo']) ? 't' : 'f', $id, $salonId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el paquete.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO paquetes (salon_id, nombre, servicio_id, sesiones, precio, vigencia_dias) VALUES (?,?,?,?,?,?) RETURNING id');
        $st->execute([$salonId, $nombre, $servicioId, $sesiones, $precio, $vigencia]);
        return (int) $st->fetchColumn();
    }

    /** Un cliente compra un paquete. El dinero entra hoy a la caja. */
    public function venderPaquete(int $salonId, int $paqueteId, int $clienteId, string $metodo, ?string $fecha = null): int
    {
        $fecha ??= date('Y-m-d H:i:s');
        $st = $this->db->prepare('SELECT * FROM paquetes WHERE id = ? AND salon_id = ? AND activo');
        $st->execute([$paqueteId, $salonId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException('Elige un paquete.');
        $cliente = $this->clienteDelSalon($salonId, $clienteId);
        $vence = $p['vigencia_dias'] ? (new \DateTimeImmutable(substr($fecha, 0, 10)))->modify('+' . (int) $p['vigencia_dias'] . ' days')->format('Y-m-d') : null;
        $valorSesion = self::r((float) $p['precio'] / (int) $p['sesiones']);

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('INSERT INTO paquetes_cliente (salon_id, paquete_id, cliente_id, servicio_id, nombre, sesiones_total,
                                             precio_pagado, valor_sesion, comprado_en, vence_en)
                                      VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$salonId, $paqueteId, $clienteId, $p['servicio_id'], $p['nombre'], $p['sesiones'], $p['precio'], $valorSesion, $fecha, $vence]);
            $id = (int) $st->fetchColumn();
            $this->ingreso($salonId, 'paquete', $id, $clienteId, "Paquete {$p['nombre']} · {$cliente['nombre']}", $this->metodo($metodo), (float) $p['precio'], $fecha);
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }

    /** Paquetes de un cliente. Vigente = no anulado, con sesiones y sin vencer. */
    public function paquetesDeCliente(int $salonId, int $clienteId, bool $soloVigentes = true, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $sql = 'SELECT pc.*, s.nombre AS servicio, (pc.sesiones_total - pc.sesiones_usadas) AS quedan,
                       (NOT pc.anulado AND pc.sesiones_usadas < pc.sesiones_total AND (pc.vence_en IS NULL OR pc.vence_en >= ?)) AS vigente
                  FROM paquetes_cliente pc JOIN servicios s ON s.id = pc.servicio_id
                 WHERE pc.salon_id = ? AND pc.cliente_id = ?';
        if ($soloVigentes) $sql .= ' AND NOT pc.anulado AND pc.sesiones_usadas < pc.sesiones_total AND (pc.vence_en IS NULL OR pc.vence_en >= ?)';
        $st = $this->db->prepare($sql . ' ORDER BY pc.comprado_en DESC');
        $st->execute($soloVigentes ? [$hoy, $salonId, $clienteId, $hoy] : [$hoy, $salonId, $clienteId]);
        return $st->fetchAll();
    }

    public function paquetesVendidos(int $salonId, int $limite = 100): array
    {
        $st = $this->db->prepare('SELECT pc.*, cl.nombre AS cliente, cl.telefono, (pc.sesiones_total - pc.sesiones_usadas) AS quedan
                                    FROM paquetes_cliente pc JOIN clientes cl ON cl.id = pc.cliente_id
                                   WHERE pc.salon_id = ? ORDER BY pc.anulado, pc.comprado_en DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    /** Descuenta una sesión (lo llama el cobro). Devuelve lo que vale esa sesión. */
    public function usarSesion(int $salonId, int $paqueteClienteId, int $clienteId, int $servicioId, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $st = $this->db->prepare('SELECT * FROM paquetes_cliente WHERE id = ? AND salon_id = ? FOR UPDATE');
        $st->execute([$paqueteClienteId, $salonId]);
        $pc = $st->fetch();
        if (!$pc || (int) $pc['cliente_id'] !== $clienteId) throw new RuntimeException('Ese paquete no es de este cliente.');
        if ($pc['anulado']) throw new RuntimeException('Ese paquete fue anulado.');
        if ((int) $pc['servicio_id'] !== $servicioId) throw new RuntimeException("El paquete \"{$pc['nombre']}\" no incluye ese servicio.");
        if ((int) $pc['sesiones_usadas'] >= (int) $pc['sesiones_total']) throw new RuntimeException("El paquete \"{$pc['nombre']}\" ya no tiene sesiones.");
        if ($pc['vence_en'] && $pc['vence_en'] < $hoy) throw new RuntimeException("El paquete \"{$pc['nombre']}\" venció el " . (new \DateTimeImmutable($pc['vence_en']))->format('d/m/Y') . '.');
        $this->db->prepare('UPDATE paquetes_cliente SET sesiones_usadas = sesiones_usadas + 1 WHERE id = ?')->execute([$paqueteClienteId]);
        $pc['sesiones_usadas'] = (int) $pc['sesiones_usadas'] + 1;
        return $pc;
    }

    /** Si se vendió por error y no se ha usado: se anula y sale de la caja de ese día. */
    public function anularPaqueteVendido(int $salonId, int $paqueteClienteId): void
    {
        $st = $this->db->prepare('UPDATE paquetes_cliente SET anulado = true WHERE id = ? AND salon_id = ? AND sesiones_usadas = 0 AND NOT anulado');
        $st->execute([$paqueteClienteId, $salonId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Solo se puede anular un paquete que todavía no se ha usado.');
        $this->db->prepare("UPDATE ingresos SET anulado = true WHERE salon_id = ? AND concepto = 'paquete' AND referencia_id = ?")
                 ->execute([$salonId, $paqueteClienteId]);
    }

    // ==================================================================
    // Tarjetas de regalo
    // ==================================================================

    public static function normalizarCodigo(string $codigo): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo) ?? '');
        return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : $c;
    }

    private function codigoNuevo(int $salonId): string
    {
        for ($i = 0; $i < 20; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) $c .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            $c = substr($c, 0, 4) . '-' . substr($c, 4);
            $st = $this->db->prepare('SELECT 1 FROM tarjetas_regalo WHERE salon_id = ? AND codigo = ?');
            $st->execute([$salonId, $c]);
            if (!$st->fetchColumn()) return $c;
        }
        throw new RuntimeException('No se pudo crear el código. Intenta de nuevo.');
    }

    /** Vende una tarjeta de regalo. El dinero entra hoy a la caja. */
    public function venderTarjeta(int $salonId, float $monto, array $d, string $metodo, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d H:i:s');
        $monto = self::r($monto);
        if ($monto < 1 || $monto > 1000) throw new RuntimeException('El valor de la tarjeta debe ser de $1 a $1.000.');
        $vence = trim((string) ($d['vence_en'] ?? ''));
        if ($vence !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) || $vence < substr($fecha, 0, 10))) {
            throw new RuntimeException('La fecha de vencimiento no es válida.');
        }
        $tel = trim((string) ($d['para_telefono'] ?? ''));
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $codigo = $this->codigoNuevo($salonId);
            $st = $this->db->prepare('INSERT INTO tarjetas_regalo (salon_id, codigo, monto, saldo, de_nombre, para_nombre, para_telefono, mensaje, vence_en, creada_en)
                                      VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$salonId, $codigo, $monto, $monto,
                          mb_substr(trim((string) ($d['de_nombre'] ?? '')), 0, 100) ?: null,
                          mb_substr(trim((string) ($d['para_nombre'] ?? '')), 0, 100) ?: null,
                          $tel !== '' ? mb_substr($tel, 0, 20) : null,
                          mb_substr(trim((string) ($d['mensaje'] ?? '')), 0, 200) ?: null,
                          $vence ?: null, $fecha]);
            $id = (int) $st->fetchColumn();
            $para = trim((string) ($d['para_nombre'] ?? ''));
            $this->ingreso($salonId, 'tarjeta_regalo', $id, null, "Tarjeta de regalo $codigo" . ($para ? " para $para" : ''), $this->metodo($metodo), $monto, $fecha);
            if ($propia) $this->db->commit();
            return ['id' => $id, 'codigo' => $codigo];
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }

    public function tarjeta(int $salonId, string $codigo): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tarjetas_regalo WHERE salon_id = ? AND codigo = ?');
        $st->execute([$salonId, self::normalizarCodigo($codigo)]);
        return $st->fetch() ?: null;
    }

    public function tarjetaPorId(int $salonId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM tarjetas_regalo WHERE salon_id = ? AND id = ?');
        $st->execute([$salonId, $id]);
        return $st->fetch() ?: null;
    }

    public function tarjetas(int $salonId, int $limite = 100): array
    {
        $st = $this->db->prepare('SELECT * FROM tarjetas_regalo WHERE salon_id = ? ORDER BY anulada, (saldo > 0) DESC, creada_en DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    public function usosTarjeta(int $tarjetaId): array
    {
        $st = $this->db->prepare('SELECT * FROM tarjeta_usos WHERE tarjeta_id = ? ORDER BY fecha');
        $st->execute([$tarjetaId]);
        return $st->fetchAll();
    }

    /** Usa saldo de la tarjeta (lo llama el cobro). */
    public function usarTarjeta(int $salonId, string $codigo, float $monto, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $monto = self::r($monto);
        $st = $this->db->prepare('SELECT * FROM tarjetas_regalo WHERE salon_id = ? AND codigo = ? FOR UPDATE');
        $st->execute([$salonId, self::normalizarCodigo($codigo)]);
        $t = $st->fetch();
        if (!$t) throw new RuntimeException('No existe una tarjeta de regalo con ese código.');
        if ($t['anulada']) throw new RuntimeException('Esa tarjeta de regalo fue anulada.');
        if ($t['vence_en'] && $t['vence_en'] < $hoy) throw new RuntimeException('Esa tarjeta de regalo venció el ' . (new \DateTimeImmutable($t['vence_en']))->format('d/m/Y') . '.');
        if ($monto <= 0) throw new RuntimeException('El valor a usar de la tarjeta debe ser mayor a cero.');
        if ($monto > (float) $t['saldo'] + 0.001) throw new RuntimeException('A la tarjeta solo le quedan ' . number_format((float) $t['saldo'], 2, ',', '.') . ' dólares.');
        $this->db->prepare('UPDATE tarjetas_regalo SET saldo = saldo - ? WHERE id = ?')->execute([$monto, $t['id']]);
        $t['saldo'] = self::r((float) $t['saldo'] - $monto);
        return $t;
    }

    public function anularTarjeta(int $salonId, int $id): void
    {
        $st = $this->db->prepare('UPDATE tarjetas_regalo SET anulada = true WHERE id = ? AND salon_id = ? AND saldo = monto AND NOT anulada');
        $st->execute([$id, $salonId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Solo se puede anular una tarjeta que todavía no se ha usado.');
        $this->db->prepare("UPDATE ingresos SET anulado = true WHERE salon_id = ? AND concepto = 'tarjeta_regalo' AND referencia_id = ?")
                 ->execute([$salonId, $id]);
    }

    // ==================================================================
    // Puntos
    // ==================================================================

    public function configPuntos(int $salonId): array
    {
        $st = $this->db->prepare('SELECT puntos_activo, puntos_por_dolar, puntos_valor, puntos_minimo_canje FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $c = $st->fetch();
        return [
            'activo'        => (bool) $c['puntos_activo'],
            'por_dolar'     => (float) $c['puntos_por_dolar'],
            'valor'         => (float) $c['puntos_valor'],
            'minimo_canje'  => (int) $c['puntos_minimo_canje'],
        ];
    }

    public function guardarConfigPuntos(int $salonId, bool $activo, float $porDolar, float $valor, int $minimo): void
    {
        if ($porDolar <= 0 || $porDolar > 100) throw new RuntimeException('Los puntos por cada dólar deben estar entre 0,1 y 100.');
        if ($valor <= 0 || $valor > 10) throw new RuntimeException('El valor de cada punto debe estar entre $0,0001 y $10.');
        if ($minimo < 1 || $minimo > 100000) throw new RuntimeException('El mínimo para canjear debe estar entre 1 y 100.000 puntos.');
        $this->db->prepare('UPDATE salones SET puntos_activo = ?, puntos_por_dolar = ?, puntos_valor = ?, puntos_minimo_canje = ? WHERE id = ?')
                 ->execute([$activo ? 't' : 'f', $porDolar, $valor, $minimo, $salonId]);
    }

    private function moverPuntos(int $salonId, int $clienteId, int $puntos, string $motivo, ?int $ventaId): void
    {
        $st = $this->db->prepare('UPDATE clientes SET puntos = puntos + ? WHERE id = ? AND salon_id = ? AND puntos + ? >= 0');
        $st->execute([$puntos, $clienteId, $salonId, $puntos]);
        if ($st->rowCount() !== 1) throw new RuntimeException('El cliente no tiene tantos puntos.');
        $this->db->prepare('INSERT INTO puntos_movimientos (salon_id, cliente_id, venta_id, puntos, motivo) VALUES (?,?,?,?,?)')
                 ->execute([$salonId, $clienteId, $ventaId, $puntos, mb_substr($motivo, 0, 120)]);
    }

    /** Suma puntos por lo que pagó (lo llama el cobro). Devuelve los puntos ganados. */
    public function ganarPuntos(int $salonId, int $clienteId, float $montoPagado, ?int $ventaId): int
    {
        $c = $this->configPuntos($salonId);
        if (!$c['activo'] || $montoPagado <= 0) return 0;
        $pts = (int) floor($montoPagado * $c['por_dolar'] + 0.0001);
        if ($pts > 0) $this->moverPuntos($salonId, $clienteId, $pts, 'Ganó por su compra', $ventaId);
        return $pts;
    }

    /**
     * Canjea puntos como descuento (lo llama el cobro). No pasa de $tope.
     * Devuelve ['puntos' => usados, 'dolares' => descuento].
     */
    public function canjearPuntos(int $salonId, int $clienteId, int $puntos, float $tope, ?int $ventaId): array
    {
        $c = $this->configPuntos($salonId);
        if (!$c['activo']) throw new RuntimeException('Los puntos no están activados en este salón.');
        $cliente = $this->clienteDelSalon($salonId, $clienteId);
        if ($puntos < $c['minimo_canje']) throw new RuntimeException("Se canjean desde {$c['minimo_canje']} puntos.");
        if ($puntos > (int) $cliente['puntos']) throw new RuntimeException("{$cliente['nombre']} solo tiene {$cliente['puntos']} puntos.");
        $maximo = (int) floor($tope / $c['valor'] + 0.0001);
        $puntos = min($puntos, $maximo);
        if ($puntos <= 0) return ['puntos' => 0, 'dolares' => 0.0];
        $dolares = self::r($puntos * $c['valor']);
        $this->moverPuntos($salonId, $clienteId, -$puntos, 'Canjeó como descuento', $ventaId);
        return ['puntos' => $puntos, 'dolares' => $dolares];
    }

    /** Regalo o corrección manual del dueño. */
    public function ajustarPuntos(int $salonId, int $clienteId, int $puntos, string $motivo): void
    {
        $this->clienteDelSalon($salonId, $clienteId);
        if ($puntos === 0) throw new RuntimeException('Escribe cuántos puntos sumar o restar.');
        if (trim($motivo) === '') throw new RuntimeException('Escribe el motivo.');
        $this->moverPuntos($salonId, $clienteId, $puntos, trim($motivo), null);
    }

    public function movimientosPuntos(int $salonId, int $clienteId, int $limite = 30): array
    {
        $st = $this->db->prepare('SELECT * FROM puntos_movimientos WHERE salon_id = ? AND cliente_id = ? ORDER BY fecha DESC, id DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId, $clienteId]);
        return $st->fetchAll();
    }

    public function mejoresClientes(int $salonId, int $limite = 20): array
    {
        $st = $this->db->prepare('SELECT id, nombre, telefono, puntos FROM clientes WHERE salon_id = ? AND puntos > 0 AND profesional_privado_id IS NULL
                                   ORDER BY puntos DESC, nombre LIMIT ' . (int) $limite);
        $st->execute([$salonId]);
        return $st->fetchAll();
    }
}
