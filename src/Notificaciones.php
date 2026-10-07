<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;

/**
 * Avisos dentro del sistema (campana). A quién se avisa lo programa el dueño:
 * 'dueno', 'peluquero' o 'ambos'. (WhatsApp automático: pendiente, con la API oficial; hoy hay WhatsApp con un toque y Telegram.)
 */
final class Notificaciones
{
    public function __construct(private PDO $db) {}

    /** Usuarios avisados en la última llamada a nuevaReserva (para enviarles Telegram después de guardar). */
    public array $ultimosDestinos = [];

    /** Avisa de una reserva hecha en línea. Devuelve cuántos avisos creó. */
    public function nuevaReserva(int $citaId): int
    {
        $st = $this->db->prepare(
            "SELECT c.salon_id, c.profesional_id, c.inicio, c.estado, s.avisar_a, s.acepta_reservas, s.minutos_para_aceptar,
                    cl.nombre AS cliente, p.nombre AS profesional,
                    (SELECT string_agg(sv.nombre, ' + ') FROM cita_servicios cs JOIN servicios sv ON sv.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios
               FROM citas c JOIN salones s ON s.id = c.salon_id JOIN profesionales p ON p.id = c.profesional_id
          LEFT JOIN clientes cl ON cl.id = c.cliente_id WHERE c.id = ?"
        );
        $st->execute([$citaId]);
        $c = $st->fetch();
        if (!$c) return 0;

        $ini = new \DateTimeImmutable($c['inicio']);
        $cuando = Agenda::DIAS[(int) $ini->format('w')] . ' ' . $ini->format('j/n') . ' a las ' . $ini->format('H:i');
        $texto = $c['estado'] === 'pendiente'
            ? "Solicitud de cita: {$c['cliente']} quiere {$c['servicios']} con {$c['profesional']} el $cuando. Acéptala o recházala."
            : "Nueva cita en línea: {$c['cliente']}, {$c['servicios']} con {$c['profesional']} el $cuando.";

        $destinos = $this->destinos((int) $c['salon_id'], (int) $c['profesional_id'], $c['avisar_a']);
        $ins = $this->db->prepare('INSERT INTO notificaciones (salon_id, usuario_id, cita_id, texto) VALUES (?,?,?,?)');
        $this->ultimosDestinos = $destinos;
        foreach ($this->ultimosDestinos as $uid) {
            $ins->execute([$c['salon_id'], $uid, $citaId, $texto]);
        }
        return count($this->ultimosDestinos);
    }

    /** Usuarios a avisar según lo que programó el dueño (si nadie, el dueño). */
    public function destinos(int $salonId, int $profId, ?string $avisarA = null): array
    {
        if ($avisarA === null) {
            $st = $this->db->prepare('SELECT avisar_a FROM salones WHERE id = ?');
            $st->execute([$salonId]);
            $avisarA = (string) $st->fetchColumn();
        }
        $destinos = [];
        if (in_array($avisarA, ['dueno', 'ambos'], true)) {
            $st = $this->db->prepare("SELECT id FROM usuarios WHERE salon_id = ? AND rol IN ('dueno','admin') AND activo");
            $st->execute([$salonId]);
            $destinos = array_merge($destinos, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if (in_array($avisarA, ['peluquero', 'ambos'], true)) {
            $st = $this->db->prepare("SELECT id FROM usuarios WHERE salon_id = ? AND profesional_id = ? AND activo");
            $st->execute([$salonId, $profId]);
            $destinos = array_merge($destinos, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!$destinos) {
            $st = $this->db->prepare("SELECT id FROM usuarios WHERE salon_id = ? AND rol IN ('dueno','admin') AND activo");
            $st->execute([$salonId]);
            $destinos = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        return array_values(array_unique(array_map('intval', $destinos)));
    }

    /** Aviso de texto (campana + Telegram de los conectados). Ej.: el cliente confirmó o canceló. */
    public function avisoSimple(int $salonId, int $profId, ?int $citaId, string $texto): int
    {
        $destinos = $this->destinos($salonId, $profId);
        $ins = $this->db->prepare('INSERT INTO notificaciones (salon_id, usuario_id, cita_id, texto) VALUES (?,?,?,?)');
        foreach ($destinos as $uid) $ins->execute([$salonId, $uid, $citaId, mb_substr($texto, 0, 300)]);
        if (!$this->db->inTransaction()) (new Telegram($this->db))->textoAUsuarios($destinos, $texto);
        return count($destinos);
    }

    /** Aviso solo para el dueño (reportes, cumpleaños). */
    public function alDueno(int $salonId, string $texto, bool $telegram = true): array
    {
        $st = $this->db->prepare("SELECT id FROM usuarios WHERE salon_id = ? AND rol IN ('dueno','admin') AND activo");
        $st->execute([$salonId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $ins = $this->db->prepare('INSERT INTO notificaciones (salon_id, usuario_id, texto) VALUES (?,?,?)');
        foreach ($ids as $uid) $ins->execute([$salonId, $uid, mb_substr($texto, 0, 300)]);
        if ($telegram) (new Telegram($this->db))->textoAUsuarios($ids, $texto);
        return $ids;
    }

    public function sinLeer(int $usuarioId): int
    {
        $st = $this->db->prepare('SELECT count(*) FROM notificaciones WHERE usuario_id = ? AND NOT leida');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    public function recientes(int $usuarioId, int $limite = 30): array
    {
        $st = $this->db->prepare('SELECT * FROM notificaciones WHERE usuario_id = ? ORDER BY creada_en DESC LIMIT ' . (int) $limite);
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    public function marcarLeidas(int $usuarioId): void
    {
        $this->db->prepare('UPDATE notificaciones SET leida = true WHERE usuario_id = ? AND NOT leida')->execute([$usuarioId]);
    }
}
