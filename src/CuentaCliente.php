<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Cuenta opcional del cliente: puede reservar como invitado o crear una cuenta
 * para ver su historial de servicios y sus fotos (si las aceptó).
 *
 * Privacidad: una cuenta nueva NO se une sola a una ficha que tenga el mismo celular,
 * porque cualquiera que sepa un número vería el historial ajeno. Solo se une si el
 * salón ya tenía guardado ese mismo correo en la ficha del cliente.
 */
final class CuentaCliente
{
    public function __construct(private PDO $db) {}

    public function crear(int $salonId, string $nombre, string $telefono, string $email, string $clave, bool $aceptaFotos): int
    {
        $nombre = trim($nombre);
        $email = strtolower(trim($email));
        $tel = WhatsApp::normalizarTelefono($telefono);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe tu nombre.');
        if ($tel === null) throw new RuntimeException('Escribe un celular válido, por ejemplo 0991234567.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');

        $st = $this->db->prepare('SELECT id, password_hash FROM clientes WHERE salon_id = ? AND lower(email) = ? ORDER BY password_hash IS NOT NULL DESC LIMIT 1');
        $st->execute([$salonId, $email]);
        $existe = $st->fetch();
        if ($existe && $existe['password_hash']) {
            throw new RuntimeException('Ya tienes una cuenta con ese correo. Entra con tu contraseña.');
        }
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $telLocal = '0' . substr($tel, 3);
        if ($existe) {   // el salón ya tenía su correo: se une a su ficha y conserva su historial
            $this->db->prepare('UPDATE clientes SET nombre = ?, telefono = COALESCE(telefono, ?), password_hash = ?,
                                       acepta_fotos = ?, cuenta_creada_en = now() WHERE id = ?')
                     ->execute([$nombre, $telLocal, $hash, $aceptaFotos ? 'true' : 'false', $existe['id']]);
            return (int) $existe['id'];
        }
        $st = $this->db->prepare('INSERT INTO clientes (salon_id, nombre, telefono, email, password_hash, acepta_fotos, cuenta_creada_en)
                                  VALUES (?,?,?,?,?,?, now()) RETURNING id');
        $st->execute([$salonId, $nombre, $telLocal, $email, $hash, $aceptaFotos ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }

    public function entrar(int $salonId, string $email, string $clave): ?int
    {
        $st = $this->db->prepare('SELECT id, password_hash FROM clientes WHERE salon_id = ? AND lower(email) = ? AND password_hash IS NOT NULL');
        $st->execute([$salonId, strtolower(trim($email))]);
        $c = $st->fetch();
        return ($c && password_verify($clave, $c['password_hash'])) ? (int) $c['id'] : null;
    }

    public function datos(int $salonId, int $clienteId): ?array
    {
        $st = $this->db->prepare('SELECT id, nombre, telefono, email, acepta_fotos FROM clientes
                                   WHERE id = ? AND salon_id = ? AND password_hash IS NOT NULL');
        $st->execute([$clienteId, $salonId]);
        return $st->fetch() ?: null;
    }

    public function cambiarPermisoFotos(int $salonId, int $clienteId, bool $acepta): void
    {
        $this->db->prepare('UPDATE clientes SET acepta_fotos = ? WHERE id = ? AND salon_id = ?')
                 ->execute([$acepta ? 'true' : 'false', $clienteId, $salonId]);
    }

    /** Historial: citas atendidas con servicios, peluquero, valor y fotos. */
    public function historial(int $salonId, int $clienteId): array
    {
        $st = $this->db->prepare(
            "SELECT c.id, c.inicio, c.estado, p.nombre AS profesional,
                    (SELECT string_agg(s.nombre, ' + ') FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios,
                    COALESCE((SELECT total FROM ventas v WHERE v.cita_id = c.id AND NOT v.anulada LIMIT 1),
                             (SELECT SUM(cs.precio) FROM cita_servicios cs WHERE cs.cita_id = c.id)) AS valor
               FROM citas c JOIN profesionales p ON p.id = c.profesional_id
              WHERE c.salon_id = ? AND c.cliente_id = ? AND c.estado = 'atendida'
              ORDER BY c.inicio DESC LIMIT 100"
        );
        $st->execute([$salonId, $clienteId]);
        $citas = $st->fetchAll();
        $fotos = $this->db->prepare('SELECT id, cita_id, momento FROM fotos WHERE cliente_id = ? AND salon_id = ? ORDER BY momento DESC, id');
        $fotos->execute([$clienteId, $salonId]);
        $porCita = [];
        foreach ($fotos->fetchAll() as $f) {
            $porCita[(int) $f['cita_id']][] = $f;
        }
        foreach ($citas as &$c) {
            $c['fotos'] = $porCita[(int) $c['id']] ?? [];
        }
        return $citas;
    }

    public function proximas(int $salonId, int $clienteId): array
    {
        $st = $this->db->prepare(
            "SELECT c.id, c.inicio, c.estado, p.nombre AS profesional,
                    (SELECT string_agg(s.nombre, ' + ') FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios
               FROM citas c JOIN profesionales p ON p.id = c.profesional_id
              WHERE c.salon_id = ? AND c.cliente_id = ? AND c.fin >= now()
                AND (c.estado IN ('reservada','confirmada') OR (c.estado = 'pendiente' AND (c.expira_en IS NULL OR c.expira_en >= now())))
              ORDER BY c.inicio"
        );
        $st->execute([$salonId, $clienteId]);
        return $st->fetchAll();
    }
}
