<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Calificación del servicio (1 a 5 estrellas) con el enlace de la cita.
 * Solo citas atendidas, una vez por cita. Con 4 o 5 estrellas se invita a dejar reseña en Google.
 */
final class Calificaciones
{
    public function __construct(private PDO $db) {}

    public function deCita(int $citaId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM calificaciones WHERE cita_id = ?');
        $st->execute([$citaId]);
        return $st->fetch() ?: null;
    }

    /** Guarda la calificación. Devuelve el enlace de Google si corresponde invitar a reseñar. */
    public function calificar(array $cita, int $estrellas, string $comentario = ''): ?string
    {
        if ($cita['estado'] !== 'atendida') throw new RuntimeException('Podrás calificar cuando te hayan atendido.');
        if ($estrellas < 1 || $estrellas > 5) throw new RuntimeException('Elige de 1 a 5 estrellas.');
        if ($this->deCita((int) $cita['id'])) throw new RuntimeException('Ya calificaste esta cita. ¡Gracias!');
        $comentario = mb_substr(trim($comentario), 0, 500);
        $this->db->prepare('INSERT INTO calificaciones (salon_id, cita_id, profesional_id, cliente_id, estrellas, comentario)
                            VALUES (?,?,?,?,?,?) ON CONFLICT (cita_id) DO NOTHING')
                 ->execute([$cita['salon_id'], $cita['id'], $cita['profesional_id'], $cita['cliente_id'], $estrellas, $comentario ?: null]);

        $txt = str_repeat('★', $estrellas) . str_repeat('☆', 5 - $estrellas) . " {$cita['cliente']} calificó a {$cita['profesional']}"
             . ($comentario !== '' ? ": \"$comentario\"" : '.');
        (new Notificaciones($this->db))->avisoSimple((int) $cita['salon_id'], (int) $cita['profesional_id'], (int) $cita['id'], $txt);

        if ($estrellas >= 4) {
            $st = $this->db->prepare('SELECT google_resenas_url FROM salones WHERE id = ?');
            $st->execute([$cita['salon_id']]);
            $url = (string) $st->fetchColumn();
            return $url !== '' ? $url : null;
        }
        return null;
    }

    /** Promedio y cantidad por peluquero en un rango [desde, hasta). */
    public function resumen(int $salonId, string $desde, string $hasta): array
    {
        $st = $this->db->prepare('SELECT profesional_id, round(avg(estrellas)::numeric, 1) AS promedio, count(*) AS cantidad
                                    FROM calificaciones WHERE salon_id = ? AND creada_en >= ? AND creada_en < ?
                                   GROUP BY profesional_id');
        $st->execute([$salonId, $desde, $hasta]);
        $r = [];
        foreach ($st->fetchAll() as $f) $r[(int) $f['profesional_id']] = $f;
        return $r;
    }

    public function recientes(int $salonId, int $limite = 20): array
    {
        $st = $this->db->prepare('SELECT ca.*, cl.nombre AS cliente, p.nombre AS profesional
                                    FROM calificaciones ca LEFT JOIN clientes cl ON cl.id = ca.cliente_id
                               LEFT JOIN profesionales p ON p.id = ca.profesional_id
                                   WHERE ca.salon_id = ? ORDER BY ca.creada_en DESC LIMIT ' . (int) $limite);
        $st->execute([$salonId]);
        return $st->fetchAll();
    }
}
