<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Cobro completo de una atención: servicios, productos y lo que el cliente ya tenía pagado
 * (anticipo, sesión de un paquete, tarjeta de regalo o puntos). Lo que falta es lo que paga hoy.
 * Las comisiones se calculan sobre el valor de cada servicio, sin importar cómo se pagó.
 */
final class Cobro
{
    public function __construct(private PDO $db) {}

    private static function r(float $v): float
    {
        return round($v + 0.0000001, 2);
    }

    /**
     * @param array $d salon_id, profesional_id, cliente_id?, cita? (array de Agenda::cita), fecha?,
     *                 servicios [servicio_id => precio], productos [producto_id => cantidad],
     *                 metodo_pago, cobrado_por, propina,
     *                 paquetes [servicio_id => paquete_cliente_id], tarjeta_codigo, tarjeta_monto, puntos,
     *                 (productos, paquetes, tarjetas y puntos solo si el salón tiene encendido su módulo: Modulos)
     * @return array venta_id, total, ya_pagado, a_cobrar, puntos_ganados, detalle
     */
    public function registrar(array $d): array
    {
        $sid = (int) $d['salon_id'];
        $profId = (int) $d['profesional_id'];
        $cita = $d['cita'] ?? null;
        $clienteId = (int) ($cita['cliente_id'] ?? $d['cliente_id'] ?? 0) ?: null;
        $fecha = $d['fecha'] ?? date('Y-m-d H:i:s');
        $hoy = substr($fecha, 0, 10);
        $mods = (new Modulos($this->db))->activos($sid);
        $tiene = fn(string $m) => in_array($m, $mods, true);
        $cobradoPor = ($d['cobrado_por'] ?? 'local') === 'profesional' ? 'profesional' : 'local';
        $metodo = in_array($d['metodo_pago'] ?? '', Fidelidad::METODOS, true) ? $d['metodo_pago'] : 'efectivo';
        $propina = max(0.0, self::r((float) ($d['propina'] ?? 0)));
        $usarPaquete = array_filter(array_map('intval', (array) ($d['paquetes'] ?? [])));
        $productosPedidos = array_filter(array_map('intval', (array) ($d['productos'] ?? [])), fn($c) => $c > 0);
        $tarjetaCodigo = trim((string) ($d['tarjeta_codigo'] ?? ''));
        $puntos = max(0, (int) ($d['puntos'] ?? 0));

        if ($productosPedidos && !$tiene('inventario')) throw new RuntimeException('La venta de productos no está en tu plan.');
        if (($usarPaquete || $tarjetaCodigo !== '') && !$tiene('paquetes_regalo')) throw new RuntimeException('Los paquetes y las tarjetas de regalo no están en tu plan.');
        if ($puntos > 0 && !$tiene('puntos')) throw new RuntimeException('Los puntos no están en tu plan.');
        if (($usarPaquete || $puntos > 0) && !$clienteId) {
            throw new RuntimeException('Para usar un paquete o puntos, elige el cliente.');
        }

        $fid = new Fidelidad($this->db);
        $liq = new Liquidacion($this->db);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            // 1) Servicios (los que usan paquete valen lo que costó cada sesión)
            $items = [];
            $pagadoPaquete = 0.0;
            $usos = [];
            $st = $this->db->prepare('SELECT id, nombre, precio FROM servicios WHERE id = ? AND salon_id = ?');
            foreach ((array) ($d['servicios'] ?? []) as $servId => $precio) {
                $servId = (int) $servId;
                $st->execute([$servId, $sid]);
                $sv = $st->fetch();
                if (!$sv) continue;
                $precio = self::r((float) str_replace(',', '.', (string) $precio));
                if ($precio < 0) throw new RuntimeException('Un precio no puede ser negativo.');
                if (isset($usarPaquete[$servId])) {
                    $pc = $fid->usarSesion($sid, $usarPaquete[$servId], (int) $clienteId, $servId, $hoy);
                    $precio = (float) $pc['valor_sesion'];
                    $pagadoPaquete += $precio;
                    $usos[] = (int) $pc['id'];
                }
                $items[] = ['tipo' => 'servicio', 'servicio_id' => $servId, 'profesional_id' => $profId, 'precio_unitario' => $precio];
            }
            foreach ($usarPaquete as $servId => $_) {
                if (!in_array($servId, array_column($items, 'servicio_id'), true)) {
                    throw new RuntimeException('Marca el servicio del paquete que vas a usar.');
                }
            }

            // 2) Productos vendidos (se revisa que haya en el estante)
            $stp = $this->db->prepare('SELECT id, nombre, precio, stock FROM productos WHERE id = ? AND salon_id = ? AND activo AND para_venta FOR UPDATE');
            foreach ($productosPedidos as $prodId => $cant) {
                $stp->execute([(int) $prodId, $sid]);
                $p = $stp->fetch();
                if (!$p) throw new RuntimeException('Ese producto no está a la venta.');
                if ($cant > (int) $p['stock']) throw new RuntimeException("Solo quedan {$p['stock']} de {$p['nombre']}.");
                $items[] = ['tipo' => 'producto', 'producto_id' => (int) $prodId, 'profesional_id' => $profId,
                            'cantidad' => $cant, 'precio_unitario' => (float) $p['precio']];
            }
            if (!$items) throw new RuntimeException('Marca al menos un servicio o un producto.');

            $total = self::r(array_sum(array_map(fn($i) => $i['precio_unitario'] * ($i['cantidad'] ?? 1), $items)));
            $falta = self::r($total - $pagadoPaquete);

            // 3) Anticipo ya pagado de la cita
            $pagadoAnticipo = 0.0;
            if ($cita) {
                $stc = $this->db->prepare('SELECT anticipo, anticipo_estado FROM citas WHERE id = ? FOR UPDATE');
                $stc->execute([$cita['id']]);
                $ca = $stc->fetch();
                if ($ca && $ca['anticipo_estado'] === 'pagado') {
                    $pagadoAnticipo = min((float) $ca['anticipo'], $falta);
                    $falta = self::r($falta - $pagadoAnticipo);
                }
            }

            // 4) Tarjeta de regalo
            $pagadoTarjeta = 0.0;
            $tarjeta = null;
            if ($tarjetaCodigo !== '') {
                $pedido = trim((string) ($d['tarjeta_monto'] ?? '')) === '' ? null : (float) str_replace(',', '.', (string) $d['tarjeta_monto']);
                $t = $fid->tarjeta($sid, $tarjetaCodigo);
                if (!$t) throw new RuntimeException('No existe una tarjeta de regalo con ese código.');
                $usar = self::r(min($pedido ?? (float) $t['saldo'], (float) $t['saldo'], $falta));
                if ($usar > 0) {
                    $tarjeta = $fid->usarTarjeta($sid, $tarjetaCodigo, $usar, $hoy);
                    $pagadoTarjeta = $usar;
                    $falta = self::r($falta - $usar);
                }
            }

            // Lo prepagado lo recibió el local: el cobro debe ir por la caja del local
            if ($cobradoPor === 'profesional' && ($pagadoPaquete + $pagadoAnticipo + $pagadoTarjeta + $puntos) > 0) {
                throw new RuntimeException('Con anticipo, paquete, tarjeta de regalo o puntos, el cobro va por la caja del local.');
            }

            // 5) Registrar la venta (comisiones y cuentas del equipo)
            $ventaId = $liq->registrarVenta([
                'salon_id' => $sid, 'fecha' => $fecha, 'cobrado_por' => $cobradoPor,
                'cobrado_por_profesional_id' => $cobradoPor === 'profesional' ? $profId : null,
                'metodo_pago' => $metodo, 'propina' => $propina, 'cliente_id' => $clienteId,
            ], $items);

            // 6) Puntos canjeados (descuento)
            $pagadoPuntos = 0.0;
            $puntosUsados = 0;
            if ($puntos > 0) {
                $canje = $fid->canjearPuntos($sid, (int) $clienteId, $puntos, $falta, $ventaId);
                $pagadoPuntos = $canje['dolares'];
                $puntosUsados = $canje['puntos'];
                $falta = self::r($falta - $pagadoPuntos);
            }

            $this->db->prepare('UPDATE ventas SET cita_id = ?, pagado_paquete = ?, pagado_tarjeta = ?, pagado_puntos = ?, pagado_anticipo = ? WHERE id = ?')
                     ->execute([$cita['id'] ?? null, $pagadoPaquete, $pagadoTarjeta, $pagadoPuntos, $pagadoAnticipo, $ventaId]);
            $insUso = $this->db->prepare('INSERT INTO paquete_usos (paquete_cliente_id, venta_id, fecha) VALUES (?,?,?)');
            foreach ($usos as $pcId) $insUso->execute([$pcId, $ventaId, $fecha]);
            if ($tarjeta) {
                $this->db->prepare('INSERT INTO tarjeta_usos (tarjeta_id, venta_id, monto, fecha) VALUES (?,?,?,?)')
                         ->execute([$tarjeta['id'], $ventaId, $pagadoTarjeta, $fecha]);
            }
            if ($pagadoAnticipo > 0) {
                $this->db->prepare("UPDATE citas SET anticipo_estado = 'usado' WHERE id = ?")->execute([$cita['id']]);
            }

            // 7) Puntos ganados por lo que pagó hoy (sin propina)
            $ganados = $clienteId && $tiene('puntos') && $falta > 0 ? $fid->ganarPuntos($sid, (int) $clienteId, $falta, $ventaId) : 0;

            if ($cita) (new Agenda($this->db))->cambiarEstado($sid, (int) $cita['id'], 'atendida');
            if ($propia) $this->db->commit();
            return [
                'venta_id'       => $ventaId,
                'total'          => $total,
                'ya_pagado'      => self::r($pagadoPaquete + $pagadoAnticipo + $pagadoTarjeta + $pagadoPuntos),
                'a_cobrar'       => $falta,
                'propina'        => $propina,
                'puntos_ganados' => $ganados,
                'puntos_usados'  => $puntosUsados,
                'detalle'        => ['paquete' => $pagadoPaquete, 'anticipo' => $pagadoAnticipo, 'tarjeta' => $pagadoTarjeta,
                                     'puntos' => $pagadoPuntos, 'tarjeta_saldo' => $tarjeta['saldo'] ?? null],
            ];
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }
}
