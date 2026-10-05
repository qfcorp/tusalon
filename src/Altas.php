<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Alta de un salón nuevo con su dueño: la usan el registro público (prueba gratis)
 * y el panel del super administrador.
 */
final class Altas
{
    public function __construct(private PDO $db) {}

    /** Dirección corta y única del salón (para su enlace de reservas). */
    public function slugLibre(string $nombre): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) : $nombre;
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-') ?: 'salon';
        $base = substr($base, 0, 50);
        $slug = $base;
        $n = 1;
        $chk = $this->db->prepare('SELECT 1 FROM salones WHERE slug = ?');
        while (true) {
            $chk->execute([$slug]);
            if (!$chk->fetchColumn()) return $slug;
            $slug = substr($base, 0, 46) . '-' . (++$n);
        }
    }

    /**
     * Crea el salón (en prueba gratis), su dueño como peluquero, su usuario, 5 servicios de ejemplo y el horario.
     * @return array{salon_id:int, usuario_id:int, slug:string}
     */
    public function crearSalonConDueno(string $salon, string $nombre, string $telefono, string $email,
                                       string $clave, string $plan): array
    {
        $salon = trim($salon);
        $nombre = trim($nombre);
        $email = strtolower(trim($email));
        if (mb_strlen($salon) < 3) throw new RuntimeException('Escribe el nombre del salón o barbería.');
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del dueño.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        $st = $this->db->prepare('SELECT 1 FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        if (!$st->fetchColumn()) throw new RuntimeException('Elige un plan.');
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe una cuenta con ese correo. Entra con tu contraseña.');

        $planes = new Planes($this->db);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $slug = $this->slugLibre($salon);
            $salonId = $planes->crearSalon($salon, $slug, $plan);
            $this->db->prepare('UPDATE salones SET telefono = ? WHERE id = ?')->execute([trim($telefono) ?: null, $salonId]);
            $profId = (new Profesionales($this->db, $planes))->agregar($salonId, $nombre, 'dueno');
            $this->db->prepare('UPDATE profesionales SET telefono = ? WHERE id = ?')->execute([trim($telefono) ?: null, $profId]);
            $st = $this->db->prepare("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol)
                                      VALUES (?,?,?,?,?, 'dueno') RETURNING id");
            $st->execute([$salonId, $profId, $nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
            $usuarioId = (int) $st->fetchColumn();
            // Servicios de ejemplo para empezar (se pueden cambiar)
            $ins = $this->db->prepare('INSERT INTO servicios (salon_id, nombre, precio, duracion_minutos) VALUES (?,?,?,?)');
            foreach ([['Corte de caballero', 6, 30], ['Barba', 4, 20], ['Corte y barba', 9, 45],
                      ['Corte de dama', 10, 45], ['Tinte', 25, 90]] as [$nom, $precio, $min]) {
                $ins->execute([$salonId, $nom, $precio, $min]);
            }
            (new Agenda($this->db))->horarioPorDefecto($salonId);   // lunes a sábado 9:00–19:00
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['salon_id' => $salonId, 'usuario_id' => $usuarioId, 'slug' => $slug];
    }
}
