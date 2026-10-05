<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Fotos de servicios. Solo para clientes con cuenta que aceptaron que se guarden.
 * Las sube el dueño o el peluquero que atendió la cita. Se guardan fuera de la
 * carpeta pública, se achican a 1600 px y se les quita la información oculta (ubicación, etc.).
 */
final class Fotos
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_POR_CITA = 6;

    public function __construct(private PDO $db, private string $carpeta) {}

    public static function carpetaPorDefecto(): string
    {
        return rtrim((string) (getenv('TUSALON_UPLOADS') ?: dirname(__DIR__) . '/uploads'), '/') . '/fotos';
    }

    /** ¿Puede este usuario subir fotos a esta cita? Devuelve la cita o lanza el motivo. */
    public function verificarPermiso(int $salonId, int $citaId, array $usuario): array
    {
        $st = $this->db->prepare(
            'SELECT c.id, c.cliente_id, c.profesional_id, c.estado, cl.password_hash IS NOT NULL AS tiene_cuenta, cl.acepta_fotos
               FROM citas c LEFT JOIN clientes cl ON cl.id = c.cliente_id
              WHERE c.id = ? AND c.salon_id = ?'
        );
        $st->execute([$citaId, $salonId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('No se encontró la cita.');
        $esDueno = in_array($usuario['rol'], ['dueno', 'admin'], true);
        if (!$esDueno && (int) ($usuario['profesional_id'] ?? 0) !== (int) $c['profesional_id']) {
            throw new RuntimeException('Solo el peluquero que atendió (o el dueño) puede subir fotos.');
        }
        if (!$c['cliente_id'] || !$c['tiene_cuenta']) {
            throw new RuntimeException('El cliente no tiene cuenta. Las fotos se guardan solo para clientes con cuenta.');
        }
        if (!$c['acepta_fotos']) {
            throw new RuntimeException('El cliente no dio permiso para guardar fotos.');
        }
        return $c;
    }

    /** @param array $archivo un elemento de $_FILES */
    public function subir(int $salonId, int $citaId, array $usuario, array $archivo, string $momento = 'despues'): int
    {
        $c = $this->verificarPermiso($salonId, $citaId, $usuario);
        if (!in_array($momento, ['antes', 'despues'], true)) $momento = 'despues';
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No llegó la foto. Intenta de nuevo.');
        }
        if ($archivo['size'] > self::MAX_BYTES) {
            throw new RuntimeException('La foto pesa más de 10 MB.');
        }
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $img = match ($tipo) {
            'image/jpeg' => @imagecreatefromjpeg($archivo['tmp_name']),
            'image/png'  => @imagecreatefrompng($archivo['tmp_name']),
            'image/webp' => @imagecreatefromwebp($archivo['tmp_name']),
            default      => false,
        };
        if (!$img) throw new RuntimeException('El archivo debe ser una foto (JPG, PNG o WebP).');

        $st = $this->db->prepare('SELECT count(*) FROM fotos WHERE cita_id = ?');
        $st->execute([$citaId]);
        if ((int) $st->fetchColumn() >= self::MAX_POR_CITA) {
            imagedestroy($img);
            throw new RuntimeException('Esta cita ya tiene ' . self::MAX_POR_CITA . ' fotos.');
        }

        // Girar según la cámara del celular y achicar a máximo 1600 px
        if ($tipo === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($archivo['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($giro) $img = imagerotate($img, $giro, 0);
        }
        $w = imagesx($img); $h = imagesy($img);
        $escala = min(1, 1600 / max($w, $h));
        if ($escala < 1) {
            $nueva = imagescale($img, (int) round($w * $escala), (int) round($h * $escala));
            imagedestroy($img);
            $img = $nueva;
        }
        if (!is_dir($this->carpeta) && !mkdir($this->carpeta, 0750, true) && !is_dir($this->carpeta)) {
            throw new RuntimeException('No se pudo guardar la foto en el servidor.');
        }
        $nombre = bin2hex(random_bytes(16)) . '.jpg';
        // Volver a crear el JPG borra los datos ocultos (ubicación GPS, modelo de celular)
        if (!imagejpeg($img, $this->carpeta . '/' . $nombre, 82)) {
            imagedestroy($img);
            throw new RuntimeException('No se pudo guardar la foto en el servidor.');
        }
        imagedestroy($img);

        $st = $this->db->prepare('INSERT INTO fotos (salon_id, cliente_id, cita_id, profesional_id, momento, archivo)
                                  VALUES (?,?,?,?,?,?) RETURNING id');
        $st->execute([$salonId, $c['cliente_id'], $citaId, $c['profesional_id'], $momento, $nombre]);
        return (int) $st->fetchColumn();
    }

    public function deCita(int $salonId, int $citaId): array
    {
        $st = $this->db->prepare('SELECT id, momento, creada_en FROM fotos WHERE salon_id = ? AND cita_id = ? ORDER BY momento DESC, id');
        $st->execute([$salonId, $citaId]);
        return $st->fetchAll();
    }

    /**
     * Ruta del archivo si quien pide tiene derecho a verla:
     * el propio cliente, o el dueño/peluqueros de ese salón.
     */
    public function rutaSiPuedeVer(int $fotoId, ?array $usuario, array $clientesEnSesion): ?string
    {
        $st = $this->db->prepare('SELECT salon_id, cliente_id, archivo FROM fotos WHERE id = ?');
        $st->execute([$fotoId]);
        $f = $st->fetch();
        if (!$f) return null;
        $permitido = ($usuario && (int) $usuario['salon_id'] === (int) $f['salon_id'])
            || ((int) ($clientesEnSesion[(int) $f['salon_id']] ?? 0) === (int) $f['cliente_id']);
        if (!$permitido || !preg_match('/^[a-f0-9]{32}\.jpg$/', $f['archivo'])) return null;
        $ruta = $this->carpeta . '/' . $f['archivo'];
        return is_file($ruta) ? $ruta : null;
    }

    public function borrar(int $salonId, int $fotoId, array $usuario): void
    {
        $st = $this->db->prepare('SELECT archivo, cita_id FROM fotos WHERE id = ? AND salon_id = ?');
        $st->execute([$fotoId, $salonId]);
        $f = $st->fetch();
        if (!$f) throw new RuntimeException('No se encontró la foto.');
        $this->verificarPermisoBorrar($salonId, (int) $f['cita_id'], $usuario);
        $this->db->prepare('DELETE FROM fotos WHERE id = ?')->execute([$fotoId]);
        @unlink($this->carpeta . '/' . $f['archivo']);
    }

    private function verificarPermisoBorrar(int $salonId, int $citaId, array $usuario): void
    {
        if (in_array($usuario['rol'], ['dueno', 'admin'], true)) return;
        $st = $this->db->prepare('SELECT profesional_id FROM citas WHERE id = ? AND salon_id = ?');
        $st->execute([$citaId, $salonId]);
        if ((int) $st->fetchColumn() !== (int) ($usuario['profesional_id'] ?? 0)) {
            throw new RuntimeException('Solo el peluquero que atendió (o el dueño) puede borrar la foto.');
        }
    }
}
