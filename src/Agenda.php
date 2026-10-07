<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/** Citas: horario, horas libres, crear (sin choques), listar el día y cambiar estado. */
final class Agenda
{
    public const ESTADOS = ['pendiente', 'reservada', 'confirmada', 'atendida', 'no_asistio', 'cancelada', 'rechazada'];

    /** Citas que ocupan la hora: todas menos canceladas/rechazadas y solicitudes ya vencidas. */
    public const SQL_OCUPA = "estado NOT IN ('cancelada','no_asistio','rechazada')
                              AND NOT (estado = 'pendiente' AND expira_en IS NOT NULL AND expira_en < now())";
    public const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    public function __construct(private PDO $db) {}

    /** Dirección pública del sistema (para enlaces en mensajes). */
    public static function urlBase(): string
    {
        $env = (string) getenv('TUSALON_URL');
        if ($env !== '') return rtrim($env, '/');
        if (!empty($_SERVER['HTTP_HOST'])) {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        }
        return 'https://tusalon.tukanec.com';
    }

    // ------------------------------------------------------------------
    // Horario del salón
    // ------------------------------------------------------------------

    /** Horario de lunes a sábado 9:00–19:00 para salones nuevos. */
    public function horarioPorDefecto(int $salonId): void
    {
        $st = $this->db->prepare(
            "INSERT INTO horarios (salon_id, dia_semana, abre, cierra) VALUES (?, ?, '09:00', '19:00')
             ON CONFLICT DO NOTHING"
        );
        foreach ([1, 2, 3, 4, 5, 6] as $d) {
            $st->execute([$salonId, $d]);
        }
    }

    /** @return array<int, array{abre:string, cierra:string}> indexado por día (0 = domingo) */
    public function horario(int $salonId): array
    {
        $st = $this->db->prepare("SELECT dia_semana, to_char(abre,'HH24:MI') AS abre, to_char(cierra,'HH24:MI') AS cierra
                                    FROM horarios WHERE salon_id = ? ORDER BY dia_semana");
        $st->execute([$salonId]);
        $h = [];
        foreach ($st->fetchAll() as $r) {
            $h[(int) $r['dia_semana']] = ['abre' => $r['abre'], 'cierra' => $r['cierra']];
        }
        return $h;
    }

    /** @param array<int, ?array{abre:string, cierra:string}> $dias  null = cerrado */
    public function guardarHorario(int $salonId, array $dias): void
    {
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM horarios WHERE salon_id = ?')->execute([$salonId]);
        $ins = $this->db->prepare('INSERT INTO horarios (salon_id, dia_semana, abre, cierra) VALUES (?,?,?,?)');
        foreach ($dias as $d => $h) {
            if ($h === null) continue;
            if (!preg_match('/^\d{2}:\d{2}$/', $h['abre']) || !preg_match('/^\d{2}:\d{2}$/', $h['cierra']) || $h['cierra'] <= $h['abre']) {
                $this->db->rollBack();
                throw new RuntimeException('Revisa el horario del ' . strtolower(self::DIAS[$d]) . ': la hora de cierre debe ser después de la de apertura.');
            }
            $ins->execute([$salonId, $d, $h['abre'], $h['cierra']]);
        }
        $this->db->commit();
    }

    // ------------------------------------------------------------------
    // Horas libres (portal de reservas)
    // ------------------------------------------------------------------

    /**
     * Horas libres de un peluquero en un día para un servicio de $minutos.
     * Considera el horario del salón, las citas tomadas y la anticipación mínima.
     * @return string[] lista de 'HH:MM'
     */
    public function horasLibres(int $salonId, int $profesionalId, string $fecha, int $minutos, ?\DateTimeImmutable $ahora = null): array
    {
        return $this->horasDelDia($salonId, $profesionalId, $fecha, $minutos, $ahora)['libres'];
    }

    /**
     * @return array{libres: string[], en_confirmacion: string[]}
     *   en_confirmacion = horas tomadas por una solicitud en línea que todavía no se acepta
     */
    public function horasDelDia(int $salonId, int $profesionalId, string $fecha, int $minutos, ?\DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new \DateTimeImmutable();
        $horario = $this->horarioDelDia($salonId, $profesionalId, $fecha);
        if ($horario === null || $minutos <= 0) {
            return ['libres' => [], 'en_confirmacion' => []];
        }
        $st = $this->db->prepare('SELECT intervalo_reservas, anticipacion_minutos FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $conf = $st->fetch();
        $paso = max(5, (int) $conf['intervalo_reservas']);
        $limite = $ahora->modify('+' . (int) $conf['anticipacion_minutos'] . ' minutes');

        $st = $this->db->prepare(
            "SELECT inicio, fin, estado FROM citas WHERE profesional_id = ? AND salon_id = ?
                AND " . self::SQL_OCUPA . " AND inicio::date = ?"
        );
        $st->execute([$profesionalId, $salonId, $fecha]);
        $ocupado = array_map(fn($c) => [new \DateTimeImmutable($c['inicio']), new \DateTimeImmutable($c['fin']), $c['estado']], $st->fetchAll());
        // Almuerzo y vacaciones/permisos del peluquero también ocupan
        if (!empty($horario['almuerzo_desde'])) {
            $ocupado[] = [new \DateTimeImmutable("$fecha {$horario['almuerzo_desde']}"), new \DateTimeImmutable("$fecha {$horario['almuerzo_hasta']}"), 'bloqueo'];
        }
        $st = $this->db->prepare("SELECT desde, hasta FROM bloqueos WHERE profesional_id = ? AND desde < (?::date + 1) AND hasta > ?::date");
        $st->execute([$profesionalId, $fecha, $fecha]);
        foreach ($st->fetchAll() as $b) {
            $ocupado[] = [new \DateTimeImmutable($b['desde']), new \DateTimeImmutable($b['hasta']), 'bloqueo'];
        }

        $libres = [];
        $enConfirmacion = [];
        $t = new \DateTimeImmutable("$fecha {$horario['abre']}");
        $cierre = new \DateTimeImmutable("$fecha {$horario['cierra']}");
        while (true) {
            $fin = $t->modify("+$minutos minutes");
            if ($fin > $cierre) break;
            if ($t >= $limite) {
                $choca = null;
                foreach ($ocupado as [$i, $f, $estado]) {
                    if ($t < $f && $fin > $i) {
                        $choca = $estado;
                        if ($estado !== 'pendiente') break;   // una cita firme pesa más que una solicitud
                    }
                }
                if ($choca === null) {
                    $libres[] = $t->format('H:i');
                } elseif ($choca === 'pendiente') {
                    $enConfirmacion[] = $t->format('H:i');
                }
            }
            $t = $t->modify("+$paso minutes");
        }
        return ['libres' => $libres, 'en_confirmacion' => $enConfirmacion];
    }

    /**
     * Horario de un peluquero en una fecha: el suyo propio si lo tiene, si no el del salón.
     * @return ?array{abre:string, cierra:string, almuerzo_desde:?string, almuerzo_hasta:?string}  null = no atiende
     */
    public function horarioDelDia(int $salonId, int $profesionalId, string $fecha): ?array
    {
        $dia = (int) (new \DateTimeImmutable($fecha))->format('w');
        $st = $this->db->prepare('SELECT count(*) FROM horarios_profesional WHERE profesional_id = ?');
        $st->execute([$profesionalId]);
        if ((int) $st->fetchColumn() > 0) {
            $st = $this->db->prepare("SELECT to_char(abre,'HH24:MI') AS abre, to_char(cierra,'HH24:MI') AS cierra,
                                             to_char(almuerzo_desde,'HH24:MI') AS almuerzo_desde, to_char(almuerzo_hasta,'HH24:MI') AS almuerzo_hasta
                                        FROM horarios_profesional WHERE profesional_id = ? AND dia_semana = ?");
            $st->execute([$profesionalId, $dia]);
            return $st->fetch() ?: null;
        }
        $h = $this->horario($salonId)[$dia] ?? null;
        return $h ? $h + ['almuerzo_desde' => null, 'almuerzo_hasta' => null] : null;
    }

    /** @return array<int, ?array> horario semanal propio del peluquero (vacío = usa el del salón) */
    public function horarioProfesional(int $profesionalId): array
    {
        $st = $this->db->prepare("SELECT dia_semana, to_char(abre,'HH24:MI') AS abre, to_char(cierra,'HH24:MI') AS cierra,
                                         to_char(almuerzo_desde,'HH24:MI') AS almuerzo_desde, to_char(almuerzo_hasta,'HH24:MI') AS almuerzo_hasta
                                    FROM horarios_profesional WHERE profesional_id = ? ORDER BY dia_semana");
        $st->execute([$profesionalId]);
        $h = [];
        foreach ($st->fetchAll() as $r) $h[(int) $r['dia_semana']] = $r;
        return $h;
    }

    /**
     * Guarda el horario propio del peluquero. $usarSalon = true borra el propio y usa el del salón.
     * @param array<int, ?array{abre:string, cierra:string, almuerzo_desde?:string, almuerzo_hasta?:string}> $dias
     */
    public function guardarHorarioProfesional(int $salonId, int $profesionalId, array $dias, bool $usarSalon): void
    {
        $this->verificarDelSalon('profesionales', $profesionalId, $salonId);
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM horarios_profesional WHERE profesional_id = ?')->execute([$profesionalId]);
        if (!$usarSalon) {
            $ins = $this->db->prepare('INSERT INTO horarios_profesional (profesional_id, dia_semana, abre, cierra, almuerzo_desde, almuerzo_hasta)
                                       VALUES (?,?,?,?,?,?)');
            $alguno = false;
            foreach ($dias as $d => $h) {
                if ($h === null) continue;
                $ad = ($h['almuerzo_desde'] ?? '') ?: null;
                $ah = ($h['almuerzo_hasta'] ?? '') ?: null;
                $ok = preg_match('/^\d{2}:\d{2}$/', $h['abre']) && preg_match('/^\d{2}:\d{2}$/', $h['cierra']) && $h['cierra'] > $h['abre'];
                if ($ok && ($ad || $ah)) {
                    $ok = $ad && $ah && $ah > $ad && $ad >= $h['abre'] && $ah <= $h['cierra'];
                }
                if (!$ok) {
                    $this->db->rollBack();
                    throw new RuntimeException('Revisa el ' . strtolower(self::DIAS[$d]) . ': las horas o el almuerzo no cuadran.');
                }
                $ins->execute([$profesionalId, $d, $h['abre'], $h['cierra'], $ad, $ah]);
                $alguno = true;
            }
            if (!$alguno) {
                $this->db->rollBack();
                throw new RuntimeException('Marca al menos un día de trabajo, o elige "Usa el horario del salón".');
            }
        }
        $this->db->commit();
    }

    public function agregarBloqueo(int $salonId, int $profesionalId, string $desde, string $hasta, string $motivo): void
    {
        $this->verificarDelSalon('profesionales', $profesionalId, $salonId);
        $d = new \DateTimeImmutable($desde);
        $h = new \DateTimeImmutable($hasta);
        if ($h <= $d) throw new RuntimeException('La fecha final debe ser después de la inicial.');
        $this->db->prepare('INSERT INTO bloqueos (salon_id, profesional_id, desde, hasta, motivo) VALUES (?,?,?,?,?)')
                 ->execute([$salonId, $profesionalId, $d->format('Y-m-d H:i'), $h->format('Y-m-d H:i'), mb_substr(trim($motivo), 0, 80) ?: null]);
    }

    public function bloqueos(int $profesionalId): array
    {
        $st = $this->db->prepare('SELECT * FROM bloqueos WHERE profesional_id = ? AND hasta >= now() ORDER BY desde');
        $st->execute([$profesionalId]);
        return $st->fetchAll();
    }

    public function borrarBloqueo(int $salonId, int $bloqueoId): void
    {
        $this->db->prepare('DELETE FROM bloqueos WHERE id = ? AND salon_id = ?')->execute([$bloqueoId, $salonId]);
    }

    // ------------------------------------------------------------------
    // Servicios por peluquero
    // ------------------------------------------------------------------

    /** Servicios que hace un peluquero, con su precio (el propio o el normal). */
    public function serviciosDe(int $salonId, int $profesionalId, bool $soloOnline = false): array
    {
        $st = $this->db->prepare(
            "SELECT s.id, s.nombre, s.descripcion, s.duracion_minutos, COALESCE(sp.precio, s.precio) AS precio
               FROM servicios s
          LEFT JOIN servicio_profesional sp ON sp.servicio_id = s.id AND sp.profesional_id = ?
              WHERE s.salon_id = ? AND s.activo AND s.profesional_id IS NULL" . ($soloOnline ? ' AND s.reserva_online' : '') . "
                AND (sp.profesional_id IS NOT NULL
                     OR NOT EXISTS (SELECT 1 FROM servicio_profesional x WHERE x.servicio_id = s.id))
              ORDER BY s.nombre"
        );
        $st->execute([$profesionalId, $salonId]);
        return $st->fetchAll();
    }

    /** Guarda quién hace un servicio y a qué precio. $porProfesional = [prof_id => precio|null]; vacío = lo hacen todos. */
    public function guardarServicioProfesionales(int $salonId, int $servicioId, array $porProfesional): void
    {
        $this->verificarDelSalon('servicios', $servicioId, $salonId);
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM servicio_profesional WHERE servicio_id = ?')->execute([$servicioId]);
        $ins = $this->db->prepare('INSERT INTO servicio_profesional (servicio_id, profesional_id, precio) VALUES (?,?,?)');
        foreach ($porProfesional as $pid => $precio) {
            $this->verificarDelSalon('profesionales', (int) $pid, $salonId);
            if ($precio !== null && $precio < 0) { $this->db->rollBack(); throw new RuntimeException('Un precio no puede ser negativo.'); }
            $ins->execute([$servicioId, (int) $pid, $precio]);
        }
        $this->db->commit();
    }

    // ------------------------------------------------------------------
    // Faltas del cliente
    // ------------------------------------------------------------------

    public function faltas(int $salonId, int $clienteId): int
    {
        $st = $this->db->prepare("SELECT count(*) FROM citas c JOIN clientes cl ON cl.id = c.cliente_id
                                   WHERE c.salon_id = ? AND c.cliente_id = ? AND c.estado = 'no_asistio'
                                     AND (cl.faltas_desde IS NULL OR c.inicio > cl.faltas_desde)");
        $st->execute([$salonId, $clienteId]);
        return (int) $st->fetchColumn();
    }

    public function perdonarFaltas(int $salonId, int $clienteId): void
    {
        $this->db->prepare("UPDATE clientes SET faltas_desde = GREATEST(now(), (SELECT max(inicio) FROM citas WHERE cliente_id = ? AND estado = 'no_asistio'))
                             WHERE id = ? AND salon_id = ?")->execute([$clienteId, $clienteId, $salonId]);
    }

    private function verificarFaltas(int $salonId, int $clienteId): void
    {
        $st = $this->db->prepare('SELECT max_faltas FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $max = (int) $st->fetchColumn();
        $n = $this->faltas($salonId, $clienteId);
        if ($max > 0 && $n >= $max) {
            throw new RuntimeException("Tienes $n " . ($n === 1 ? 'cita' : 'citas') . ' a las que no llegaste. Para reservar, escribe al salón por WhatsApp.');
        }
    }

    // ------------------------------------------------------------------
    // El cliente confirma, cancela o cambia (con su enlace o su cuenta)
    // ------------------------------------------------------------------

    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $st = $this->db->prepare('SELECT id, salon_id FROM citas WHERE token = ?');
        $st->execute([$token]);
        $r = $st->fetch();
        return $r ? $this->cita((int) $r['salon_id'], (int) $r['id']) : null;
    }

    /** ¿Puede el cliente cancelar/cambiar todavía? Devuelve null si sí, o el motivo si no. */
    public function motivoNoCancelar(array $cita, ?\DateTimeImmutable $ahora = null): ?string
    {
        $ahora ??= new \DateTimeImmutable();
        if (!in_array($cita['estado'], ['pendiente', 'reservada', 'confirmada'], true)) {
            return 'Esta cita ya no está activa.';
        }
        $st = $this->db->prepare('SELECT horas_cancelacion FROM salones WHERE id = ?');
        $st->execute([$cita['salon_id']]);
        $horas = (int) $st->fetchColumn();
        if (new \DateTimeImmutable($cita['inicio']) < $ahora->modify("+$horas hours")) {
            return "Faltan menos de $horas " . ($horas === 1 ? 'hora' : 'horas') . ' para tu cita. Para cancelar o cambiar, escribe al salón.';
        }
        return null;
    }

    public function cancelarPorCliente(int $citaId, ?\DateTimeImmutable $ahora = null): void
    {
        $st = $this->db->prepare('SELECT salon_id FROM citas WHERE id = ?');
        $st->execute([$citaId]);
        $cita = $this->cita((int) $st->fetchColumn(), $citaId);
        if (!$cita) throw new RuntimeException('No se encontró la cita.');
        if ($motivo = $this->motivoNoCancelar($cita, $ahora)) throw new RuntimeException($motivo);
        $this->db->prepare("UPDATE citas SET estado = 'cancelada', cancelada_por = 'cliente', expira_en = NULL WHERE id = ?")->execute([$citaId]);
        (new Notificaciones($this->db))->avisoSimple((int) $cita['salon_id'], (int) $cita['profesional_id'], $citaId,
            "{$cita['cliente']} canceló su cita del " . (new \DateTimeImmutable($cita['inicio']))->format('d/m H:i') . " con {$cita['profesional']}. La hora quedó libre.");
    }

    public function confirmarPorCliente(int $citaId): void
    {
        $st = $this->db->prepare("UPDATE citas SET estado = CASE WHEN estado = 'reservada' THEN 'confirmada' ELSE estado END,
                                         confirmada_cliente_en = now()
                                   WHERE id = ? AND estado IN ('reservada','confirmada') RETURNING salon_id, profesional_id");
        $st->execute([$citaId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Esta cita ya no se puede confirmar.');
        $c = $this->cita((int) $r['salon_id'], $citaId);
        (new Notificaciones($this->db))->avisoSimple((int) $r['salon_id'], (int) $r['profesional_id'], $citaId,
            "{$c['cliente']} confirmó que vendrá el " . (new \DateTimeImmutable($c['inicio']))->format('d/m H:i') . " con {$c['profesional']}.");
    }

    /** Mensaje para el cliente con el valor a cancelar y su enlace. */
    public function mensajeConfirmacion(array $cita, string $salonNombre, string $base): string
    {
        $ini = new \DateTimeImmutable($cita['inicio']);
        $valor = array_sum(array_map(fn($s) => (float) $s['precio'], $cita['lista_servicios']));
        return 'Hola ' . explode(' ', trim((string) $cita['cliente']))[0] . ", tu cita en $salonNombre quedó confirmada:\n"
             . self::DIAS[(int) $ini->format('w')] . ' ' . $ini->format('j/n') . ' a las ' . $ini->format('H:i') . ' con ' . $cita['profesional'] . ".\n"
             . 'Servicio: ' . implode(' + ', array_column($cita['lista_servicios'], 'nombre')) . ".\n"
             . 'Valor a cancelar: $' . number_format($valor, 2, ',', '.') . ".\n"
             . "Para ver o cancelar tu cita: $base/?r=confirmar&t={$cita['token']}";
    }

    /** Minutos totales de una lista de servicios del salón. */
    public function duracion(int $salonId, array $servicioIds): int
    {
        if (!$servicioIds) return 0;
        $in = implode(',', array_fill(0, count($servicioIds), '?'));
        $st = $this->db->prepare("SELECT COALESCE(SUM(duracion_minutos),0) FROM servicios WHERE salon_id = ? AND activo AND id IN ($in)");
        $st->execute(array_merge([$salonId], array_values($servicioIds)));
        return (int) $st->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Citas
    // ------------------------------------------------------------------

    /**
     * @param int[] $servicioIds
     * @param array<int,float> $precios precio acordado por servicio (solo el dueño puede cambiarlo)
     * @return int id de la cita
     */
    public function crearCita(int $salonId, int $profesionalId, ?int $clienteId, string $inicio,
                              array $servicioIds, ?string $notas = null, array $precios = [], string $origen = 'local'): int
    {
        $servicioIds = array_values(array_unique(array_map('intval', $servicioIds)));
        if (!$servicioIds) {
            throw new RuntimeException('Elige al menos un servicio.');
        }
        $this->verificarDelSalon('profesionales', $profesionalId, $salonId);
        if ($clienteId !== null) {
            $this->verificarDelSalon('clientes', $clienteId, $salonId);
        }
        $in = implode(',', array_fill(0, count($servicioIds), '?'));
        $st = $this->db->prepare("SELECT s.id, COALESCE(sp.precio, s.precio) AS precio, s.duracion_minutos,
                                         (sp.profesional_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM servicio_profesional x WHERE x.servicio_id = s.id)) AS lo_hace
                                    FROM servicios s
                               LEFT JOIN servicio_profesional sp ON sp.servicio_id = s.id AND sp.profesional_id = ?
                                   WHERE s.salon_id = ? AND s.activo AND s.id IN ($in)");
        $st->execute(array_merge([$profesionalId, $salonId], $servicioIds));
        $servicios = $st->fetchAll();
        if (count($servicios) !== count($servicioIds)) {
            throw new RuntimeException('Hay un servicio que no existe en este salón.');
        }
        if ($origen === 'online' && in_array(false, array_map(fn($s) => (bool) $s['lo_hace'], $servicios), true)) {
            throw new RuntimeException('Ese peluquero no hace ese servicio. Elige otro peluquero o servicio.');
        }
        $minutos = array_sum(array_column($servicios, 'duracion_minutos'));
        $ini = new \DateTimeImmutable($inicio);
        $fin = $ini->modify('+' . max(15, (int) $minutos) . ' minutes');

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            // Bloquea al peluquero: si dos clientes reservan a la vez, el segundo espera y ve el choque.
            $this->db->prepare('SELECT id FROM profesionales WHERE id = ? FOR UPDATE')->execute([$profesionalId]);

            $st = $this->db->prepare(
                "SELECT c.inicio, c.estado, cl.nombre FROM citas c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                  WHERE c.profesional_id = ? AND " . str_replace('estado', 'c.estado', str_replace('expira_en', 'c.expira_en', self::SQL_OCUPA)) . "
                    AND c.inicio < ? AND c.fin > ? ORDER BY c.estado = 'pendiente' LIMIT 1"
            );
            $st->execute([$profesionalId, $fin->format('Y-m-d H:i'), $ini->format('Y-m-d H:i')]);
            if ($choque = $st->fetch()) {
                $hora = (new \DateTimeImmutable($choque['inicio']))->format('H:i');
                if ($origen === 'online') {
                    throw new RuntimeException($choque['estado'] === 'pendiente'
                        ? 'Estamos confirmando esa hora para otro cliente. Elige otra hora o vuelve a intentar en unos minutos.'
                        : 'Esa hora se acaba de ocupar. Elige otra, por favor.');
                }
                throw new RuntimeException("Ese horario choca con " . ($choque['estado'] === 'pendiente' ? 'una solicitud en línea sin aceptar' : 'otra cita')
                    . " de las $hora" . ($choque['nombre'] ? " ({$choque['nombre']})" : '') . '.');
            }

            // En línea: según lo que programó el dueño, queda pendiente de aceptar o se reserva sola
            $estado = 'reservada';
            $expira = null;
            if ($origen === 'online') {
                $st = $this->db->prepare('SELECT acepta_reservas, minutos_para_aceptar FROM salones WHERE id = ?');
                $st->execute([$salonId]);
                $conf = $st->fetch();
                if ($conf['acepta_reservas'] !== 'automatico') {
                    $estado = 'pendiente';
                    $expira = (new \DateTimeImmutable())->modify('+' . (int) $conf['minutos_para_aceptar'] . ' minutes')->format('Y-m-d H:i:s');
                }
            }

            $st = $this->db->prepare(
                'INSERT INTO citas (salon_id, cliente_id, profesional_id, inicio, fin, notas, origen, estado, expira_en, token)
                 VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id'
            );
            $st->execute([$salonId, $clienteId, $profesionalId, $ini->format('Y-m-d H:i'), $fin->format('Y-m-d H:i'),
                          $notas, $origen, $estado, $expira, bin2hex(random_bytes(16))]);
            $id = (int) $st->fetchColumn();
            $ins = $this->db->prepare('INSERT INTO cita_servicios (cita_id, servicio_id, precio) VALUES (?,?,?)');
            foreach ($servicios as $s) {
                $precio = array_key_exists((int) $s['id'], $precios) ? round((float) $precios[(int) $s['id']], 2) : (float) $s['precio'];
                if ($precio < 0) throw new RuntimeException('Un precio no puede ser negativo.');
                $ins->execute([$id, $s['id'], $precio]);
            }
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reserva desde el portal público. Invitado: se busca al cliente por celular (o se crea).
     * Con cuenta: se usa directamente su ficha ($clienteConCuenta).
     */
    public function reservarOnline(int $salonId, int $profesionalId, string $fecha, string $hora, array $servicioIds,
                                   string $nombre, string $telefono, ?\DateTimeImmutable $ahora = null,
                                   ?int $clienteConCuenta = null): int
    {
        $nombre = trim($nombre);
        $tel = WhatsApp::normalizarTelefono($telefono);
        if ($clienteConCuenta === null) {
            if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe tu nombre.');
            if ($tel === null) throw new RuntimeException('Escribe un celular válido, por ejemplo 0991234567.');
        }

        // Solo horas que realmente están libres (horario, anticipación y citas)
        $minutos = $this->duracion($salonId, $servicioIds);
        $horas = $this->horasDelDia($salonId, $profesionalId, $fecha, $minutos, $ahora);
        if (in_array($hora, $horas['en_confirmacion'], true)) {
            throw new RuntimeException('Estamos confirmando esa hora para otro cliente. Elige otra hora o vuelve a intentar en unos minutos.');
        }
        if (!in_array($hora, $horas['libres'], true)) {
            throw new RuntimeException('Esa hora ya no está disponible. Elige otra, por favor.');
        }
        // Solo servicios marcados para reservas en línea
        $in = implode(',', array_fill(0, count($servicioIds), '?'));
        $st = $this->db->prepare("SELECT count(*) FROM servicios WHERE salon_id = ? AND activo AND reserva_online AND id IN ($in)");
        $st->execute(array_merge([$salonId], array_values(array_map('intval', $servicioIds))));
        if ((int) $st->fetchColumn() !== count(array_unique($servicioIds))) {
            throw new RuntimeException('Ese servicio no se puede reservar en línea.');
        }

        $this->db->beginTransaction();
        try {
            if ($clienteConCuenta !== null) {
                $clienteId = $clienteConCuenta;
                $this->verificarFaltas($salonId, (int) $clienteId);
            } else {
                $st = $this->db->prepare("SELECT id FROM clientes WHERE salon_id = ? AND profesional_privado_id IS NULL
                                           AND regexp_replace(COALESCE(telefono,''), '\\D', '', 'g') IN (?, ?) LIMIT 1");
                $st->execute([$salonId, $tel, '0' . substr($tel, 3)]);
                $clienteId = $st->fetchColumn();
                if ($clienteId) {
                    $this->verificarFaltas($salonId, (int) $clienteId);
                }
                if (!$clienteId) {
                    $st = $this->db->prepare('INSERT INTO clientes (salon_id, nombre, telefono) VALUES (?,?,?) RETURNING id');
                    $st->execute([$salonId, $nombre, '0' . substr($tel, 3)]);
                    $clienteId = $st->fetchColumn();
                }
            }
            $id = $this->crearCita($salonId, $profesionalId, (int) $clienteId, "$fecha $hora", $servicioIds,
                                   'Reservada en línea', [], 'online');
            $notif = new Notificaciones($this->db);
            $notif->nuevaReserva($id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        // Telegram se envía después de guardar, para no hacer esperar a otros clientes
        $tg = new Telegram($this->db);
        $tg->avisarReserva($id, $notif->ultimosDestinos);
        $st = $this->db->prepare('SELECT estado FROM citas WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === 'reservada') $tg->confirmacionAlCliente($salonId, $id);   // reserva automática
        return $id;
    }

    /** El cliente que entró con su cuenta reserva sin volver a escribir sus datos. */
    public function reservarConCuenta(int $salonId, int $clienteId, int $profesionalId, string $fecha, string $hora,
                                      array $servicioIds, ?\DateTimeImmutable $ahora = null): int
    {
        $st = $this->db->prepare('SELECT nombre, telefono FROM clientes WHERE id = ? AND salon_id = ? AND password_hash IS NOT NULL');
        $st->execute([$clienteId, $salonId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Tu sesión terminó. Vuelve a entrar con tu cuenta.');
        return $this->reservarOnline($salonId, $profesionalId, $fecha, $hora, $servicioIds, $c['nombre'],
                                     (string) $c['telefono'], $ahora, $clienteId);
    }

    /**
     * Aceptar o rechazar una solicitud en línea. Quién puede hacerlo lo programa el dueño:
     * 'dueno' (dueño o admin), 'peluquero' (el peluquero de la cita; el dueño siempre puede), 'cualquiera'.
     */
    public function responderSolicitud(int $salonId, int $citaId, array $usuario, bool $aceptar, array $precios = []): void
    {
        $st = $this->db->prepare('SELECT c.estado, c.expira_en, c.profesional_id, s.acepta_reservas
                                    FROM citas c JOIN salones s ON s.id = c.salon_id WHERE c.id = ? AND c.salon_id = ?');
        $st->execute([$citaId, $salonId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('No se encontró la solicitud.');
        if ($c['estado'] !== 'pendiente') throw new RuntimeException('Esta solicitud ya fue respondida.');
        if (!self::puedeResponder($c['acepta_reservas'], $usuario, (int) $c['profesional_id'])) {
            throw new RuntimeException('El dueño configuró que otra persona acepta las reservas.');
        }
        if ($aceptar && $c['expira_en'] && new \DateTimeImmutable($c['expira_en']) < new \DateTimeImmutable()) {
            // venció: solo se acepta si la hora sigue libre
            $st = $this->db->prepare("SELECT 1 FROM citas c2 JOIN citas c ON c.id = ?
                                       WHERE c2.id <> c.id AND c2.profesional_id = c.profesional_id
                                         AND c2.inicio < c.fin AND c2.fin > c.inicio
                                         AND c2.estado NOT IN ('cancelada','no_asistio','rechazada','pendiente')");
            $st->execute([$citaId]);
            if ($st->fetchColumn()) throw new RuntimeException('La solicitud venció y esa hora ya la tomó otra persona.');
        }
        // Al aceptar, el dueño puede poner el valor que pagará el cliente
        foreach ($precios as $servId => $precio) {
            if ($precio === null || $precio === '') continue;
            $precio = round((float) $precio, 2);
            if ($precio < 0) throw new RuntimeException('El valor no puede ser negativo.');
            $this->db->prepare('UPDATE cita_servicios SET precio = ? WHERE cita_id = ? AND servicio_id = ?')
                     ->execute([$precio, $citaId, (int) $servId]);
        }
        $this->db->prepare('UPDATE citas SET estado = ?, expira_en = NULL WHERE id = ?')
                 ->execute([$aceptar ? 'reservada' : 'rechazada', $citaId]);
        $this->db->prepare('UPDATE notificaciones SET leida = true WHERE cita_id = ?')->execute([$citaId]);
    }

    public static function puedeResponder(string $regla, array $usuario, int $profesionalCita): bool
    {
        $esDueno = in_array($usuario['rol'], ['dueno', 'admin'], true);
        $esSuPeluquero = (int) ($usuario['profesional_id'] ?? 0) === $profesionalCita;
        return match ($regla) {
            'dueno'      => $esDueno,
            'peluquero'  => $esDueno || $esSuPeluquero,
            'cualquiera' => $esDueno || $esSuPeluquero,
            default      => $esDueno,
        };
    }

    /** Solicitudes pendientes que este usuario puede ver/responder. */
    public function solicitudes(int $salonId, array $usuario): array
    {
        $sql = "SELECT c.*, cl.nombre AS cliente, cl.telefono, p.nombre AS profesional, s.acepta_reservas,
                       (SELECT string_agg(sv.nombre, ' + ') FROM cita_servicios cs JOIN servicios sv ON sv.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios,
                       (SELECT COALESCE(SUM(cs.precio),0) FROM cita_servicios cs WHERE cs.cita_id = c.id) AS precio
                  FROM citas c JOIN profesionales p ON p.id = c.profesional_id JOIN salones s ON s.id = c.salon_id
             LEFT JOIN clientes cl ON cl.id = c.cliente_id
                 WHERE c.salon_id = ? AND c.estado = 'pendiente' AND c.fin >= now()";
        $params = [$salonId];
        if (!in_array($usuario['rol'], ['dueno', 'admin'], true)) {
            $sql .= ' AND c.profesional_id = ?';
            $params[] = (int) $usuario['profesional_id'];
        }
        $st = $this->db->prepare($sql . ' ORDER BY c.inicio');
        $st->execute($params);
        return $st->fetchAll();
    }

    public function cambiarEstado(int $salonId, int $citaId, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new RuntimeException('Estado no válido.');
        }
        $st = $this->db->prepare("UPDATE citas SET estado = ?, cancelada_por = CASE WHEN ? = 'cancelada' THEN 'salon' ELSE NULL END
                                   WHERE id = ? AND salon_id = ?");
        $st->execute([$estado, $estado, $citaId, $salonId]);
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
                       (SELECT COALESCE(SUM(cs.precio),0) FROM cita_servicios cs WHERE cs.cita_id = c.id) AS precio
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
            'SELECT c.*, cl.nombre AS cliente, cl.telefono, cl.email AS cliente_email, cl.telegram_chat_id AS cliente_telegram,
                    p.nombre AS profesional, sa.nombre AS salon, sa.slug AS salon_slug
               FROM citas c JOIN profesionales p ON p.id = c.profesional_id JOIN salones sa ON sa.id = c.salon_id
          LEFT JOIN clientes cl ON cl.id = c.cliente_id
              WHERE c.id = ? AND c.salon_id = ?'
        );
        $st->execute([$citaId, $salonId]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        // precio = el acordado en la cita; precio_lista = el del catálogo
        $st = $this->db->prepare('SELECT s.id, s.nombre, s.pago_profesional, s.precio AS precio_lista, cs.precio
                                    FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id
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
