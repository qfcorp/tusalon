<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * El corazón de TuSalón: decide a quién le toca cada dólar.
 *
 * Cada cobro genera movimientos en la "cuenta corriente" del profesional:
 *   monto POSITIVO = el local le debe al profesional
 *   monto NEGATIVO = el profesional le debe al local
 *
 * Reglas por tipo:
 *   dueno      -> no genera movimientos (solo cuenta como producción del dueño)
 *   empleado   -> + comisión de servicio y de producto; + propina si la cobró el local
 *   porcentaje -> si cobra el local: + su % ; si cobra él: - la parte del local
 *   alquiler   -> sus servicios son suyos (nada); si el local cobró por él: + todo;
 *                 si vende productos del local: - (precio - su comisión); arriendo: - monto
 */
final class Liquidacion
{
    public function __construct(private PDO $db) {}

    private static function r(float $v): float
    {
        return round($v, 2, PHP_ROUND_HALF_UP);
    }

    /** Regla de pago vigente en una fecha. */
    public function reglaVigente(int $profesionalId, string $fecha): array
    {
        $st = $this->db->prepare(
            'SELECT r.*, p.tipo, p.salon_id, p.fecha_ingreso
               FROM reglas_pago r JOIN profesionales p ON p.id = r.profesional_id
              WHERE r.profesional_id = ? AND r.vigente_desde <= ?
                AND (r.vigente_hasta IS NULL OR r.vigente_hasta >= ?)
              ORDER BY r.vigente_desde DESC LIMIT 1'
        );
        $st->execute([$profesionalId, $fecha, $fecha]);
        $r = $st->fetch();
        if (!$r) {
            throw new RuntimeException("El profesional $profesionalId no tiene regla de pago vigente al $fecha");
        }
        return $r;
    }

    /**
     * Registra un cobro y genera los movimientos de cada profesional.
     *
     * @param array $venta ['salon_id','fecha','cobrado_por'=>'local'|'profesional',
     *                      'cobrado_por_profesional_id'?, 'metodo_pago', 'propina'?, 'cliente_id'?]
     * @param array $items [['tipo'=>'servicio'|'producto','servicio_id'|'producto_id',
     *                       'profesional_id','cantidad'?,'precio_unitario'], ...]
     * @return int id de la venta
     */
    public function registrarVenta(array $venta, array $items): int
    {
        if (!$items) {
            throw new RuntimeException('La venta no tiene servicios ni productos.');
        }
        $fecha = substr($venta['fecha'], 0, 10);
        $cobradoPor = $venta['cobrado_por'] ?? 'local';
        $propina = self::r((float) ($venta['propina'] ?? 0));

        $total = 0.0;
        foreach ($items as &$it) {
            $it['cantidad'] = $it['cantidad'] ?? 1;
            $it['subtotal'] = self::r($it['precio_unitario'] * $it['cantidad']);
            $total += $it['subtotal'];
        }
        unset($it);
        $total = self::r($total);

        // Si quien llama ya abrió una transacción (ej. cobrar una cita), se usa esa.
        $propia = !$this->db->inTransaction();
        if ($propia) {
            $this->db->beginTransaction();
        }
        try {
            $st = $this->db->prepare(
                'INSERT INTO ventas (salon_id, cliente_id, fecha, cobrado_por, cobrado_por_profesional_id,
                                     metodo_pago, total, propina)
                 VALUES (?,?,?,?,?,?,?,?) RETURNING id'
            );
            $st->execute([
                $venta['salon_id'], $venta['cliente_id'] ?? null, $venta['fecha'], $cobradoPor,
                $venta['cobrado_por_profesional_id'] ?? null, $venta['metodo_pago'] ?? 'efectivo',
                $total, $propina,
            ]);
            $ventaId = (int) $st->fetchColumn();

            $insItem = $this->db->prepare(
                'INSERT INTO venta_items (venta_id, tipo, servicio_id, producto_id, profesional_id,
                                          cantidad, precio_unitario, subtotal, ganancia_profesional)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($items as $it) {
                $calc = $this->calcularItem($it, $cobradoPor, $fecha);
                $insItem->execute([
                    $ventaId, $it['tipo'], $it['servicio_id'] ?? null, $it['producto_id'] ?? null,
                    $it['profesional_id'], $it['cantidad'], $it['precio_unitario'], $it['subtotal'],
                    $calc['ganancia'],
                ]);
                if ($it['tipo'] === 'producto') {
                    $this->db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?')
                             ->execute([$it['cantidad'], $it['producto_id']]);
                }
                foreach ($calc['movimientos'] as [$tipo, $monto, $desc]) {
                    $this->insertarMovimiento((int) $venta['salon_id'], (int) $it['profesional_id'],
                                              $fecha, $tipo, $monto, $ventaId, $desc);
                }
            }

            // Propina: va al profesional del primer servicio. Si la cobró él mismo, ya la tiene.
            if ($propina > 0 && $cobradoPor === 'local') {
                $profPropina = (int) $items[0]['profesional_id'];
                $tipoProf = $this->reglaVigente($profPropina, $fecha)['tipo'];
                if ($tipoProf !== 'dueno') {
                    $this->insertarMovimiento((int) $venta['salon_id'], $profPropina, $fecha,
                                              'propina', $propina, $ventaId, 'Propina');
                }
            }
            if ($propia) {
                $this->db->commit();
            }
            return $ventaId;
        } catch (\Throwable $e) {
            if ($propia) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Pago fijo que el dueño definió para este servicio (o null si se usa el %). */
    private function pagoFijoDelServicio(array $it): ?float
    {
        if (array_key_exists('pago_profesional', $it)) {
            return $it['pago_profesional'] === null ? null : (float) $it['pago_profesional'];
        }
        if ($it['tipo'] !== 'servicio' || empty($it['servicio_id'])) {
            return null;
        }
        $st = $this->db->prepare('SELECT pago_profesional FROM servicios WHERE id = ?');
        $st->execute([$it['servicio_id']]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (float) $v;
    }

    /**
     * Calcula, para un ítem de venta:
     *  - ganancia:    lo que gana el profesional por ese ítem (para su portal)
     *  - movimientos: [[tipo, monto, descripción], ...] para su cuenta corriente
     */
    private function calcularItem(array $it, string $cobradoPor, string $fecha): array
    {
        $r = $this->reglaVigente((int) $it['profesional_id'], $fecha);
        $sub = (float) $it['subtotal'];
        $esServicio = $it['tipo'] === 'servicio';
        $comProd = self::r($sub * (float) $r['comision_producto_pct'] / 100);

        switch ($r['tipo']) {
            case 'dueno':
                return ['ganancia' => 0.0, 'movimientos' => []];

            case 'empleado':
                if (!$esServicio) {
                    return ['ganancia' => $comProd, 'movimientos' => [['comision_producto', $comProd, 'Comisión producto']]];
                }
                $fijo = $this->pagoFijoDelServicio($it);
                if ($fijo !== null) {   // el dueño definió cuánto paga por este servicio
                    $g = self::r($fijo * (int) $it['cantidad']);
                    return ['ganancia' => $g, 'movimientos' => [['comision_servicio', $g, 'Pago por servicio']]];
                }
                $g = self::r($sub * (float) $r['comision_servicio_pct'] / 100);
                return ['ganancia' => $g, 'movimientos' => [['comision_servicio', $g, 'Comisión servicio']]];

            case 'porcentaje':
                if ($esServicio) {
                    $pct = (float) $r['pct_profesional'];
                    $g = self::r($sub * $pct / 100);
                    return ['ganancia' => $g, 'movimientos' => $cobradoPor === 'local'
                        ? [['porcentaje', $g, "Su $pct %"]]
                        : [['parte_local', -self::r($sub - $g), 'Parte del local']]];
                }
                return ['ganancia' => $comProd, 'movimientos' => $this->productoDelLocal($sub, $comProd, $cobradoPor)];

            case 'alquiler':
                if ($esServicio) {   // el servicio es todo suyo
                    return ['ganancia' => $sub, 'movimientos' => $cobradoPor === 'local'
                        ? [['cobro_recibido_local', $sub, 'El local cobró un servicio suyo']]
                        : []];
                }
                return ['ganancia' => $comProd, 'movimientos' => $this->productoDelLocal($sub, $comProd, $cobradoPor)];
        }
        throw new RuntimeException('Tipo de profesional desconocido: ' . $r['tipo']);
    }

    /** Un producto del local vendido por alguien que no es empleado. */
    private function productoDelLocal(float $sub, float $comision, string $cobradoPor): array
    {
        return $cobradoPor === 'local'
            ? [['comision_producto', $comision, 'Comisión producto']]
            : [['venta_producto_local', -self::r($sub - $comision), 'Producto del local cobrado por él']];
    }

    private function insertarMovimiento(int $salonId, int $profId, string $fecha, string $tipo,
                                        float $monto, ?int $ventaId, string $desc): void
    {
        if (abs($monto) < 0.005) {
            return;
        }
        $this->db->prepare(
            'INSERT INTO movimientos_profesional (salon_id, profesional_id, fecha, tipo, monto, venta_id, descripcion)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$salonId, $profId, $fecha, $tipo, $monto, $ventaId, $desc]);
    }

    /** Carga el arriendo de un arrendatario (en el plan Completa lo hace un proceso automático). */
    public function cargarArriendo(int $profesionalId, string $fecha): float
    {
        $r = $this->reglaVigente($profesionalId, $fecha);
        if ($r['tipo'] !== 'alquiler') {
            throw new RuntimeException('Solo los arrendatarios pagan arriendo.');
        }
        $monto = (float) $r['monto_arriendo'];
        $this->insertarMovimiento((int) $r['salon_id'], $profesionalId, $fecha, 'arriendo', -$monto, null,
                                  'Arriendo ' . $r['frecuencia_arriendo']);
        return $monto;
    }

    /** Anticipo o préstamo entregado al profesional (se descuenta). */
    public function registrarAnticipo(int $profesionalId, string $fecha, float $monto, string $tipo = 'anticipo'): void
    {
        $r = $this->reglaVigente($profesionalId, $fecha);
        $this->insertarMovimiento((int) $r['salon_id'], $profesionalId, $fecha, $tipo, -abs($monto), null,
                                  ucfirst($tipo));
    }

    /** Saldo de la cuenta corriente (pendiente de liquidar) hasta una fecha. */
    public function saldo(int $profesionalId, ?string $hasta = null): float
    {
        $st = $this->db->prepare(
            'SELECT COALESCE(SUM(monto),0) FROM movimientos_profesional
              WHERE profesional_id = ? AND liquidacion_id IS NULL AND (?::date IS NULL OR fecha <= ?::date)'
        );
        $st->execute([$profesionalId, $hasta, $hasta]);
        return self::r((float) $st->fetchColumn());
    }

    /**
     * Ajuste por escalas (solo plan Completa): si el total de servicios del periodo
     * alcanza una escala, se paga la diferencia entre el % de la escala y el % base.
     */
    public function aplicarEscalas(int $profesionalId, string $desde, string $hasta, bool $planCompleta): float
    {
        if (!$planCompleta) {
            return 0.0;
        }
        $r = $this->reglaVigente($profesionalId, $hasta);
        if ($r['tipo'] !== 'empleado') {
            return 0.0;
        }
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(vi.subtotal),0) FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
              WHERE vi.profesional_id = ? AND vi.tipo = 'servicio' AND NOT v.anulada
                AND v.fecha::date BETWEEN ? AND ?"
        );
        $st->execute([$profesionalId, $desde, $hasta]);
        $ventas = (float) $st->fetchColumn();

        $st = $this->db->prepare(
            'SELECT pct FROM escalas_comision WHERE regla_id = ? AND desde_monto <= ? ORDER BY desde_monto DESC LIMIT 1'
        );
        $st->execute([$r['id'], $ventas]);
        $pctEscala = $st->fetchColumn();
        if ($pctEscala === false) {
            return 0.0;
        }
        $ajuste = self::r($ventas * ((float) $pctEscala - (float) $r['comision_servicio_pct']) / 100);
        if ($ajuste > 0) {
            $this->insertarMovimiento((int) $r['salon_id'], $profesionalId, $hasta, 'escala_comision', $ajuste,
                                      null, "Escala: $pctEscala % sobre $ventas");
        }
        return $ajuste;
    }

    /**
     * Rol de pagos mensual de un empleado (plan Completa).
     * Las comisiones SÍ suman a la base del IESS y del décimo tercero.
     * Las propinas se entregan aparte y no aportan.
     */
    public function rolDePagos(int $profesionalId, int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = (new \DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
        $r = $this->reglaVigente($profesionalId, $hasta);
        if ($r['tipo'] !== 'empleado') {
            throw new RuntimeException('El rol de pagos es solo para empleados.');
        }
        $par = $this->db->prepare('SELECT * FROM parametros_anuales WHERE anio = ?');
        $par->execute([$anio]);
        $p = $par->fetch();
        if (!$p) {
            throw new RuntimeException("Faltan los parámetros legales del año $anio");
        }

        $suma = fn(array $tipos) => $this->sumaMovimientos($profesionalId, $desde, $hasta, $tipos);
        $sueldo = (float) $r['sueldo_mensual'];
        $comisiones = $suma(['comision_servicio', 'comision_producto', 'escala_comision']);
        $propinas = $suma(['propina']);
        $anticipos = -$suma(['anticipo', 'prestamo']);

        $sbu = (float) $p['salario_basico'];
        $ajusteBasico = 0.0;
        if ($r['garantizar_basico'] && ($sueldo + $comisiones) < $sbu) {
            $ajusteBasico = self::r($sbu - $sueldo - $comisiones);
        }
        $ingresos = self::r($sueldo + $comisiones + $ajusteBasico);

        $afiliado = (bool) $r['afiliado_iess'];
        $aportePersonal = $afiliado ? self::r($ingresos * (float) $p['iess_personal_pct'] / 100) : 0.0;
        $aportePatronal = $afiliado ? self::r($ingresos * (float) $p['iess_patronal_pct'] / 100) : 0.0;

        $antiguedad = (new \DateTimeImmutable($r['fecha_ingreso']))->diff(new \DateTimeImmutable($hasta));
        $mesesTrabajados = $antiguedad->y * 12 + $antiguedad->m;
        $fondosReserva = ($afiliado && $mesesTrabajados >= 12)
            ? self::r($ingresos * (float) $p['fondos_reserva_pct'] / 100) : 0.0;

        $neto = self::r($ingresos - $aportePersonal - $anticipos + $propinas + $fondosReserva);

        return [
            'sueldo'              => self::r($sueldo),
            'comisiones'          => $comisiones,
            'ajuste_basico'       => $ajusteBasico,
            'total_ingresos'      => $ingresos,
            'aporte_iess_personal'=> $aportePersonal,
            'anticipos'           => self::r($anticipos),
            'propinas'            => $propinas,
            'fondos_reserva'      => $fondosReserva,
            'neto_a_recibir'      => $neto,
            // Costos para el local (no se descuentan al empleado)
            'aporte_iess_patronal'=> $aportePatronal,
            'provision_decimo_tercero' => self::r($ingresos / 12),
            'provision_decimo_cuarto'  => self::r($sbu / 12),
        ];
    }

    private function sumaMovimientos(int $profId, string $desde, string $hasta, array $tipos): float
    {
        $in = implode(',', array_fill(0, count($tipos), '?'));
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(monto),0) FROM movimientos_profesional
              WHERE profesional_id = ? AND fecha BETWEEN ? AND ? AND tipo IN ($in)"
        );
        $st->execute(array_merge([$profId, $desde, $hasta], $tipos));
        return self::r((float) $st->fetchColumn());
    }

    /** Cierra el periodo: agrupa los movimientos pendientes en una liquidación. */
    public function liquidar(int $profesionalId, string $desde, string $hasta): array
    {
        $r = $this->reglaVigente($profesionalId, $hasta);
        $this->db->beginTransaction();
        $st = $this->db->prepare(
            'SELECT COALESCE(SUM(monto),0) FROM movimientos_profesional
              WHERE profesional_id = ? AND liquidacion_id IS NULL AND fecha BETWEEN ? AND ?'
        );
        $st->execute([$profesionalId, $desde, $hasta]);
        $total = self::r((float) $st->fetchColumn());

        $st = $this->db->prepare(
            'INSERT INTO liquidaciones (salon_id, profesional_id, desde, hasta, total) VALUES (?,?,?,?,?) RETURNING id'
        );
        $st->execute([$r['salon_id'], $profesionalId, $desde, $hasta, $total]);
        $liqId = (int) $st->fetchColumn();

        $this->db->prepare(
            'UPDATE movimientos_profesional SET liquidacion_id = ?
              WHERE profesional_id = ? AND liquidacion_id IS NULL AND fecha BETWEEN ? AND ?'
        )->execute([$liqId, $profesionalId, $desde, $hasta]);
        $this->db->commit();

        return [
            'liquidacion_id' => $liqId,
            'total'          => $total,
            'texto'          => $total >= 0
                ? sprintf('El local le paga $%.2f', $total)
                : sprintf('El profesional le paga al local $%.2f', -$total),
        ];
    }

    /**
     * Vista doble del dueño: lo que produjo en la silla y lo que ganó el local.
     * Ganancia del local = lo que entró a la caja del local - lo que le debe a los profesionales
     * + lo que los profesionales le deben (arriendos, partes del local).
     * No incluye sueldos fijos, IESS ni gastos (eso va en el rol de pagos y gastos).
     */
    public function resumenDueno(int $salonId, string $desde, string $hasta): array
    {
        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(vi.subtotal),0)
               FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
               JOIN profesionales p ON p.id = vi.profesional_id
              WHERE v.salon_id = ? AND p.tipo = 'dueno' AND NOT v.anulada AND v.fecha::date BETWEEN ? AND ?"
        );
        $st->execute([$salonId, $desde, $hasta]);
        $produccionDueno = self::r((float) $st->fetchColumn());

        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(total + propina),0) FROM ventas
              WHERE salon_id = ? AND cobrado_por = 'local' AND NOT anulada AND fecha::date BETWEEN ? AND ?"
        );
        $st->execute([$salonId, $desde, $hasta]);
        $entroCaja = self::r((float) $st->fetchColumn());

        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(monto),0) FROM movimientos_profesional
              WHERE salon_id = ? AND fecha BETWEEN ? AND ? AND tipo NOT IN ('sueldo','anticipo','prestamo','pago')"
        );
        $st->execute([$salonId, $desde, $hasta]);
        $netoProfesionales = (float) $st->fetchColumn();

        $ganancia = self::r($entroCaja - $netoProfesionales);
        // Gastos: facturas que el salón RECIBIÓ de sus proveedores (importadas del SRI)
        $gastos = (new FacturasRecibidas($this->db))->gastos($salonId, $desde, $hasta);

        return [
            'produccion_dueno'          => $produccionDueno,
            'entro_a_caja'              => $entroCaja,
            'ganancia_local'            => $ganancia,
            'gastos_facturas'           => $gastos['total'],
            'gastos_por_categoria'      => $gastos['por_categoria'],
            'ganancia_despues_de_gastos'=> self::r($ganancia - $gastos['total']),
        ];
    }
}
