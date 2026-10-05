<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/** Citas: crear (sin choques de horario), listar el día y cambiar estado. */
final class Agenda
{
    public const ESTADOS = ['reservada', 'confirmada', 'atendida', 'no_asistio', 'cancelada'];

    public function __construct(private PDO $db) {}

    /**
     * @param int[] $servicioIds
     * @return int id de la cita
     */
    public function crearCita(int $salonId, int $profesionalId, ?int $clienteId, string $inicio,
                              array $servicioIds, ?string $notas = null): int
    {
        if (!$servicioIds) {
            throw new RuntimeException('Elige al menos un servicio.');
        }
        $this->verificarDelSalon('profesionales', $profesionalId, $salonId);
        if ($clienteId !== null) {
            $this->verificarDelSalon('clientes', $clienteId, $salonId);
        }
        $in = implode(',', array_fill(0, count($servicioIds), '?'));
        $st = $this->db->prepare("SELECT COALESCE(SUM(duracion_minutos),0), COUNT(*) FROM servicios
                                   WHERE salon_id = ? AND id IN ($in)");
        $st->execute(array_merge([$salonId], $servicioIds));
        [$minutos, $encontrados] = $st->fetch(PDO::FETCH_NUM);
        if ((int) $encontrados !== count(array_unique($servicioIds))) {
            throw new RuntimeException('Hay un servicio que no existe en este salón.');
        }
        $ini = new \DateTimeImmutable($inicio);
        $fin = $ini->modify('+' . max(15, (int) $minutos) . ' minutes');

        $st = $this->db->prepare(
            "SELECT c.inicio, cl.nombre FROM citas c LEFT JOIN clientes cl ON cl.id = c.cliente_id
              WHERE c.profesional_id = ? AND c.estado NOT IN ('cancelada','no_asistio')
                AND c.inicio < ? AND c.fin > ? LIMIT 1"
        );
        $st->execute([$profesionalId, $fin->format('Y-m-d H:i'), $ini->format('Y-m-d H:i')]);
        if ($choque = $st->fetch()) {
            $hora = (new \DateTimeImmutable($choque['inicio']))->format('H:i');
            throw new RuntimeException("Ese horario choca con otra cita de las $hora"
                . ($choque['nombre'] ? " ({$choque['nombre']})" : '') . '.');
        }

        $this->db->beginTransaction();
        $st = $this->db->prepare(
            'INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, notas) VALUES (?,?,?,?,?,?) RETURNING id'
        );
        $st->execute([$salonId, $clienteId, $profesionalId, $ini->format('Y-m-d H:i'), $fin->format('Y-m-d H:i'), $notas]);
        $id = (int) $st->fetchColumn();
        $ins = $this->db->prepare('INSERT INTO cita_servicios (cita_id, servicio_id) VALUES (?,?)');
        foreach (array_unique($servicioIds) as $s) {
            $ins->execute([$id, $s]);
        }
        $this->db->commit();
        return $id;
    }

    public function cambiarEstado(int $salonId, int $citaId, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new RuntimeException('Estado no válido.');
        }
        $st = $this->db->prepare('UPDATE citas SET estado = ? WHERE id = ? AND salon_id = ?');
        $st->execute([$estado, $citaId, $salonId]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('No se encontró la cita.');
        }
    }

    /** Citas de un día con cliente, profesional y servicios. */
    public function delDia(int $salonId, string $fecha, ?int $soloProfesional = null): array
    {
        $sql = "SELECT c.*, cl.nombre AS cliente, cl.telefono, p.nombre AS profesional,
                       (SELECT string_agg(s.nombre, ' + ' ORDER BY s.nombre) FROM cita_servicios cs
                          JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios,
                       (SELECT COALESCE(SUM(s.precio),0) FROM cita_servicios cs
                          JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS precio
                  FROM citas c
                  JOIN profesionales p ON p.id = c.profesional_id
             LEFT JOIN clientes cl ON cl.id = c.cliente_id
                 WHERE c.salon_id = ? AND c.inicio::date = ?";
        $params = [$salonId, $fecha];
        if ($soloProfesional !== null) {
            $sql .= ' AND c.profesional_id = ?';
            $params[] = $soloProfesional;
        }
        $st = $this->db->prepare($sql . ' ORDER BY c.inicio');
        $st->execute($params);
        return $st->fetchAll();
    }

    public function cita(int $salonId, int $citaId): ?array
    {
        $st = $this->db->prepare(
            'SELECT c.*, cl.nombre AS cliente, cl.telefono, p.nombre AS profesional
               FROM citas c JOIN profesionales p ON p.id = c.profesional_id
          LEFT JOIN clientes cl ON cl.id = c.cliente_id
              WHERE c.id = ? AND c.salon_id = ?'
        );
        $st->execute([$citaId, $salonId]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $st = $this->db->prepare('SELECT s.* FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id
                                   WHERE cs.cita_id = ? ORDER BY s.nombre');
        $st->execute([$citaId]);
        $c['lista_servicios'] = $st->fetchAll();
        return $c;
    }

    private function verificarDelSalon(string $tabla, int $id, int $salonId): void
    {
        $st = $this->db->prepare("SELECT 1 FROM $tabla WHERE id = ? AND salon_id = ?");
        $st->execute([$id, $salonId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Dato no encontrado en este salón.');
        }
    }
}
