<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Administradores del salón: hasta 4 cuentas con el MISMO nivel que el dueño (menú, Equipo, Configuración, Mi plan,
 * Caja, reportes…). Son cuentas de acceso, no personas que atienden: no tienen profesional_id, no cuentan en
 * Modulos::maxPersonas y no dependen de ningún módulo. Si además atienden clientes, se crean aparte en Equipo.
 *
 * Reglas (se validan aquí, en el servidor):
 *  - Solo el dueño o un administrador del mismo salón puede crear, editar, activar o desactivar.
 *  - Máximo 4 administradores ACTIVOS sin contar al dueño (al crear, al activar y al pasar una cuenta a administrador).
 *  - Nadie se desactiva ni se cambia el rol a sí mismo. La cuenta del dueño no se toca desde aquí.
 *  - Rol permitido: 'admin' o 'recepcion' (cuentas sin persona). Cualquier otro: error y no se guarda nada.
 *  - Correo único en todo el sistema y contraseña de al menos 8 caracteres (igual que las demás cuentas).
 */
final class Administradores
{
    public const MAXIMO = 4;
    public const MENSAJE_LIMITE = 'Puede tener hasta 4 administradores además del dueño.';
    /** Roles que se manejan en esta pantalla (cuentas de acceso sin persona). */
    public const ROLES = ['admin' => 'Administrador', 'recepcion' => 'Recepción'];

    public function __construct(private PDO $db) {}

    /** ¿Este usuario tiene el nivel del dueño? (dueño o administrador) */
    public static function nivelDueno(?array $u): bool
    {
        return $u !== null && in_array($u['rol'] ?? '', ['dueno', 'admin'], true);
    }

    /** El dueño y los administradores del salón (el dueño primero). */
    public function lista(int $salonId): array
    {
        $st = $this->db->prepare("SELECT id, nombre, email, rol, activo, creado_en FROM usuarios
                                   WHERE salon_id = ? AND rol IN ('dueno','admin')
                                   ORDER BY rol = 'dueno' DESC, activo DESC, nombre, id");
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    /** Administradores activos (sin contar al dueño). */
    public function activos(int $salonId, ?int $sinContar = null): int
    {
        $st = $this->db->prepare("SELECT count(*) FROM usuarios WHERE salon_id = ? AND rol = 'admin' AND activo AND id <> ?");
        $st->execute([$salonId, $sinContar ?? 0]);
        return (int) $st->fetchColumn();
    }

    public function crear(int $salonId, array $actor, string $nombre, string $email, string $clave, string $rol = 'admin'): int
    {
        $this->exigirNivel($salonId, $actor);
        $this->validarRol($rol);
        $nombre = trim($nombre);
        $email = $this->validarEmail($email);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre.');
        $this->validarClave($clave);
        return $this->enTransaccion($salonId, function () use ($salonId, $nombre, $email, $clave, $rol): int {
            $this->correoLibre($email);
            if ($rol === 'admin' && $this->activos($salonId) >= self::MAXIMO) throw new RuntimeException(self::MENSAJE_LIMITE);
            $st = $this->db->prepare('INSERT INTO usuarios (salon_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?) RETURNING id');
            $st->execute([$salonId, mb_substr($nombre, 0, 100), $email, password_hash($clave, PASSWORD_DEFAULT), $rol]);
            return (int) $st->fetchColumn();
        });
    }

    /** Cambia nombre, correo y rol (admin ↔ recepción). Uno mismo puede cambiar su nombre y correo, pero no su rol. */
    public function editar(int $salonId, array $actor, int $id, string $nombre, string $email, string $rol): void
    {
        $this->exigirNivel($salonId, $actor);
        $this->validarRol($rol);
        $c = $this->cuenta($salonId, $id);
        if ((int) $c['id'] === (int) $actor['id'] && $rol !== $c['rol']) throw new RuntimeException('No puedes cambiarte el rol a ti mismo.');
        $nombre = trim($nombre);
        $email = $this->validarEmail($email);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre.');
        $this->enTransaccion($salonId, function () use ($salonId, $c, $nombre, $email, $rol): int {
            $this->correoLibre($email, (int) $c['id']);
            if ($rol === 'admin' && $c['activo'] && $c['rol'] !== 'admin' && $this->activos($salonId) >= self::MAXIMO) {
                throw new RuntimeException(self::MENSAJE_LIMITE);
            }
            $this->db->prepare('UPDATE usuarios SET nombre = ?, email = ?, rol = ? WHERE id = ?')
                ->execute([mb_substr($nombre, 0, 100), $email, $rol, $c['id']]);
            return 0;
        });
    }

    public function cambiarClave(int $salonId, array $actor, int $id, string $clave): void
    {
        $this->exigirNivel($salonId, $actor);
        $c = $this->cuenta($salonId, $id);
        $this->validarClave($clave);
        $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $c['id']]);
    }

    public function activar(int $salonId, array $actor, int $id): void
    {
        $this->exigirNivel($salonId, $actor);
        $c = $this->cuenta($salonId, $id);
        $this->enTransaccion($salonId, function () use ($salonId, $c): int {
            if ($c['rol'] === 'admin' && !$c['activo'] && $this->activos($salonId) >= self::MAXIMO) throw new RuntimeException(self::MENSAJE_LIMITE);
            $this->db->prepare('UPDATE usuarios SET activo = true WHERE id = ?')->execute([$c['id']]);
            return 0;
        });
    }

    public function desactivar(int $salonId, array $actor, int $id): void
    {
        $this->exigirNivel($salonId, $actor);
        $c = $this->cuenta($salonId, $id);
        if ((int) $c['id'] === (int) $actor['id']) throw new RuntimeException('No puedes desactivarte a ti mismo.');
        $this->db->prepare('UPDATE usuarios SET activo = false WHERE id = ?')->execute([$c['id']]);
    }

    // ------------------------------------------------------------------

    private function exigirNivel(int $salonId, array $actor): void
    {
        if (!self::nivelDueno($actor) || (int) ($actor['salon_id'] ?? 0) !== $salonId) {
            throw new RuntimeException('Solo el dueño o un administrador puede manejar estas cuentas.');
        }
    }

    private function validarRol(string $rol): void
    {
        if (!isset(self::ROLES[$rol])) throw new RuntimeException('Rol no válido.');
    }

    private function validarEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) throw new RuntimeException('El correo no es válido.');
        return $email;
    }

    private function validarClave(string $clave): void
    {
        if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
    }

    private function correoLibre(string $email, int $menos = 0): void
    {
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ? AND id <> ?');
        $st->execute([$email, $menos]);
        if ($st->fetchColumn()) throw new RuntimeException('Ese correo ya se usa en otra cuenta.');
    }

    /** Una cuenta de administrador o recepción del salón. La del dueño (y las de los peluqueros) no se tocan aquí. */
    private function cuenta(int $salonId, int $id): array
    {
        $st = $this->db->prepare('SELECT id, nombre, email, rol, activo FROM usuarios WHERE id = ? AND salon_id = ?');
        $st->execute([$id, $salonId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('No se encontró esa cuenta.');
        if ($c['rol'] === 'dueno') throw new RuntimeException('La cuenta del dueño no se puede cambiar.');
        if (!isset(self::ROLES[$c['rol']])) throw new RuntimeException('Esa cuenta se maneja en Equipo.');
        return $c;
    }

    /** Corre $f con el salón bloqueado, para que dos pedidos a la vez no pasen el límite de 4. */
    private function enTransaccion(int $salonId, callable $f): int
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM salones WHERE id = ? FOR UPDATE')->execute([$salonId]);
            $r = $f();
            if ($propia) $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
