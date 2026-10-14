<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Ficha técnica de color: qué se le puso al cliente (marca, tono, oxidante, fórmula, tiempo)
 * con foto de antes y de después. Así la próxima vez se repite igual, la haga quien la haga.
 * Las fotos solo se guardan si el cliente lo autorizó, y solo las ve el personal del salón.
 */
final class FichaTecnica
{
    public function __construct(private PDO $db, private ?string $carpeta = null) {}

    private function carpeta(): string
    {
        return $this->carpeta ?? Fotos::carpeta('fichas');
    }

    /** ¿Puede este usuario ver y escribir fichas de este cliente? Dueño: siempre. Peluquero: si lo ha atendido o lo tiene agendado. */
    public function puedeVer(int $salonId, int $clienteId, array $usuario): bool
    {
        $st = $this->db->prepare('SELECT profesional_privado_id FROM clientes WHERE id = ? AND salon_id = ?');
        $st->execute([$clienteId, $salonId]);
        $privadoDe = $st->fetchColumn();
        if ($privadoDe === false) return false;
        // Cliente privado de quien alquila el puesto: solo lo ve él
        if ($privadoDe !== null) return (int) $privadoDe === (int) ($usuario['profesional_id'] ?? 0);
        if (in_array($usuario['rol'], ['dueno', 'admin'], true)) return true;
        $pid = (int) ($usuario['profesional_id'] ?? 0);
        $st = $this->db->prepare("SELECT 1 FROM citas WHERE salon_id = ? AND cliente_id = ? AND profesional_id = ? AND estado <> 'rechazada'
                                  UNION ALL
                                  SELECT 1 FROM ventas v JOIN venta_items vi ON vi.venta_id = v.id
                                   WHERE v.salon_id = ? AND v.cliente_id = ? AND vi.profesional_id = ? AND NOT v.anulada LIMIT 1");
        $st->execute([$salonId, $clienteId, $pid, $salonId, $clienteId, $pid]);
        return (bool) $st->fetchColumn();
    }

    public function deCliente(int $salonId, int $clienteId): array
    {
        $st = $this->db->prepare('SELECT f.*, p.nombre AS profesional FROM fichas_tecnicas f
                               LEFT JOIN profesionales p ON p.id = f.profesional_id
                                   WHERE f.salon_id = ? AND f.cliente_id = ? ORDER BY f.fecha DESC, f.id DESC');
        $st->execute([$salonId, $clienteId]);
        return $st->fetchAll();
    }

    /**
     * Guarda una ficha nueva. $fotos = ['antes' => $_FILES[...], 'despues' => $_FILES[...]] (opcionales).
     */
    public function guardar(int $salonId, int $clienteId, array $usuario, array $d, array $fotos = []): int
    {
        if (!$this->puedeVer($salonId, $clienteId, $usuario)) throw new RuntimeException('No puedes escribir fichas de este cliente.');
        $fecha = (string) ($d['fecha'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) throw new RuntimeException('La fecha no es válida.');
        $profId = in_array($usuario['rol'], ['dueno', 'admin'], true)
            ? ((int) ($d['profesional_id'] ?? 0) ?: null)
            : (int) $usuario['profesional_id'];
        if ($profId) {
            $st = $this->db->prepare('SELECT 1 FROM profesionales WHERE id = ? AND salon_id = ?');
            $st->execute([$profId, $salonId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Elige quién hizo el trabajo.');
        }
        $campos = [];
        foreach (['servicio' => 100, 'marca_tinte' => 60, 'tono' => 40, 'oxidante' => 20, 'resultado' => 200] as $k => $max) {
            $campos[$k] = mb_substr(trim((string) ($d[$k] ?? '')), 0, $max) ?: null;
        }
        $formula = trim((string) ($d['formula'] ?? '')) ?: null;
        $notas = trim((string) ($d['notas'] ?? '')) ?: null;
        $tiempo = (int) ($d['tiempo_minutos'] ?? 0) ?: null;
        if ($tiempo !== null && ($tiempo < 1 || $tiempo > 600)) throw new RuntimeException('El tiempo de exposición debe estar entre 1 y 600 minutos.');
        if (!$campos['servicio'] && !$campos['tono'] && !$formula) throw new RuntimeException('Escribe al menos el trabajo, el tono o la fórmula.');
        $autoriza = !empty($d['autoriza_fotos']);
        $hayFotos = false;
        foreach (['antes', 'despues'] as $m) {
            if (($fotos[$m]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $hayFotos = true;
        }
        if ($hayFotos && !$autoriza) throw new RuntimeException('Para guardar fotos, marca que el cliente lo autorizó.');

        $guardadas = [];
        foreach (['antes', 'despues'] as $m) {
            if (($fotos[$m]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $guardadas[$m] = Fotos::guardarImagen($fotos[$m], $this->carpeta());
            }
        }
        $st = $this->db->prepare('INSERT INTO fichas_tecnicas (salon_id, cliente_id, profesional_id, fecha, servicio, marca_tinte, tono, oxidante,
                                         formula, tiempo_minutos, resultado, notas, foto_antes, foto_despues, autoriza_fotos)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$salonId, $clienteId, $profId, $fecha, $campos['servicio'], $campos['marca_tinte'], $campos['tono'], $campos['oxidante'],
                      $formula, $tiempo, $campos['resultado'], $notas, $guardadas['antes'] ?? null, $guardadas['despues'] ?? null, $autoriza ? 't' : 'f']);
        return (int) $st->fetchColumn();
    }

    public function borrar(int $salonId, int $fichaId, array $usuario): int
    {
        $st = $this->db->prepare('SELECT * FROM fichas_tecnicas WHERE id = ? AND salon_id = ?');
        $st->execute([$fichaId, $salonId]);
        $f = $st->fetch();
        if (!$f) throw new RuntimeException('No se encontró la ficha.');
        $esDueno = in_array($usuario['rol'], ['dueno', 'admin'], true);
        if (!$esDueno && (int) $f['profesional_id'] !== (int) ($usuario['profesional_id'] ?? 0)) {
            throw new RuntimeException('Solo quien hizo la ficha (o el dueño) puede borrarla.');
        }
        $this->db->prepare('DELETE FROM fichas_tecnicas WHERE id = ?')->execute([$fichaId]);
        foreach (['foto_antes', 'foto_despues'] as $k) {
            if ($f[$k] && preg_match('/^[a-f0-9]{32}\.jpg$/', $f[$k])) @unlink($this->carpeta() . '/' . $f[$k]);
        }
        return (int) $f['cliente_id'];
    }

    /** Ruta de la foto si el usuario puede verla. */
    public function rutaFoto(int $fichaId, string $momento, ?array $usuario): ?string
    {
        if (!$usuario || !in_array($momento, ['antes', 'despues'], true)) return null;
        $st = $this->db->prepare('SELECT salon_id, cliente_id, foto_antes, foto_despues FROM fichas_tecnicas WHERE id = ?');
        $st->execute([$fichaId]);
        $f = $st->fetch();
        if (!$f || (int) $f['salon_id'] !== (int) $usuario['salon_id']) return null;
        if (!$this->puedeVer((int) $f['salon_id'], (int) $f['cliente_id'], $usuario)) return null;
        $archivo = (string) $f['foto_' . $momento];
        if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $archivo)) return null;
        $ruta = $this->carpeta() . '/' . $archivo;
        return is_file($ruta) ? $ruta : null;
    }
}
