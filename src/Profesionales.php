<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/** Alta de profesionales (los 4 tipos) y su regla de pago. */
final class Profesionales
{
    public const TIPOS = ['dueno', 'empleado', 'porcentaje', 'alquiler'];

    public function __construct(private PDO $db, private Planes $planes) {}

    /**
     * @param array $regla Campos de reglas_pago según el tipo:
     *   empleado:   sueldo_mensual, comision_servicio_pct, comision_producto_pct, afiliado_iess, garantizar_basico
     *   porcentaje: pct_profesional, quien_cobra ('local'|'profesional'), comision_producto_pct
     *   alquiler:   monto_arriendo, frecuencia_arriendo, comision_producto_pct
     *   dueno:      (nada)
     */
    public function agregar(int $salonId, string $nombre, string $tipo, array $regla = [],
                            string $fechaIngreso = 'today'): int
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException("Tipo de profesional no válido: $tipo");
        }
        if (!$this->planes->puedeAgregarProfesional($salonId)) {
            throw new RuntimeException('Tu plan Básica permite hasta 3 profesionales. Pasa al plan Completa para agregar más.');
        }
        $this->validarRegla($tipo, $regla);

        $fecha = (new \DateTimeImmutable($fechaIngreso))->format('Y-m-d');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        $st = $this->db->prepare(
            'INSERT INTO profesionales (salon_id, nombre, tipo, fecha_ingreso) VALUES (?,?,?,?) RETURNING id'
        );
        $st->execute([$salonId, $nombre, $tipo, $fecha]);
        $id = (int) $st->fetchColumn();

        $st = $this->db->prepare(
            'INSERT INTO reglas_pago (profesional_id, vigente_desde, sueldo_mensual, comision_servicio_pct,
                 comision_producto_pct, afiliado_iess, garantizar_basico, pct_profesional, quien_cobra,
                 monto_arriendo, frecuencia_arriendo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $id, $fecha,
            $regla['sueldo_mensual'] ?? 0,
            $regla['comision_servicio_pct'] ?? 0,
            $regla['comision_producto_pct'] ?? 0,
            !empty($regla['afiliado_iess']) ? 'true' : 'false',
            ($regla['garantizar_basico'] ?? true) ? 'true' : 'false',
            $regla['pct_profesional'] ?? null,
            $regla['quien_cobra'] ?? 'local',
            $regla['monto_arriendo'] ?? null,
            $regla['frecuencia_arriendo'] ?? null,
        ]);
        if ($propia) $this->db->commit();
        return $id;
    }

    private function validarRegla(string $tipo, array $r): void
    {
        if ($tipo === 'porcentaje') {
            $pct = $r['pct_profesional'] ?? null;
            if ($pct === null || $pct <= 0 || $pct >= 100) {
                throw new RuntimeException('El porcentaje del profesional debe estar entre 1 y 99.');
            }
        }
        if ($tipo === 'alquiler') {
            if (empty($r['monto_arriendo']) || $r['monto_arriendo'] <= 0) {
                throw new RuntimeException('Falta el valor del arriendo.');
            }
            if (!in_array($r['frecuencia_arriendo'] ?? '', ['semanal', 'quincenal', 'mensual'], true)) {
                throw new RuntimeException('La frecuencia del arriendo debe ser semanal, quincenal o mensual.');
            }
        }
    }

    /** Agrega una escala de comisión (solo tiene efecto en el plan Completa). */
    public function agregarEscala(int $profesionalId, float $desdeMonto, float $pct): void
    {
        $this->db->prepare(
            'INSERT INTO escalas_comision (regla_id, desde_monto, pct)
             SELECT id, ?, ? FROM reglas_pago WHERE profesional_id = ? AND vigente_hasta IS NULL'
        )->execute([$desdeMonto, $pct, $profesionalId]);
    }

    /**
     * Clientes que puede ver un usuario.
     *  - Arrendatario: solo sus clientes privados.
     *  - Resto (dueño, admin, recepción, empleados): los clientes del local, nunca los privados de un arrendatario.
     */
    public function clientesVisibles(int $salonId, ?int $profesionalId): array
    {
        $tipo = null;
        if ($profesionalId !== null) {
            $st = $this->db->prepare('SELECT tipo FROM profesionales WHERE id = ? AND salon_id = ?');
            $st->execute([$profesionalId, $salonId]);
            $tipo = $st->fetchColumn();
        }
        if ($tipo === 'alquiler') {
            $st = $this->db->prepare(
                'SELECT * FROM clientes WHERE salon_id = ? AND profesional_privado_id = ? ORDER BY nombre'
            );
            $st->execute([$salonId, $profesionalId]);
        } else {
            $st = $this->db->prepare(
                'SELECT * FROM clientes WHERE salon_id = ? AND profesional_privado_id IS NULL ORDER BY nombre'
            );
            $st->execute([$salonId]);
        }
        return $st->fetchAll();
    }
}
