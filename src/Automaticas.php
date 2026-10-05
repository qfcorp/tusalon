<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;

/**
 * Tareas automáticas (las corre bin/tareas.php cada hora):
 *  - Recordatorio el día anterior (Telegram del cliente con botones; WhatsApp con un toque en la página del dueño)
 *  - Feliz cumpleaños a clientes (mes y día, sin año)
 *  - Pedir calificación después del servicio
 *  - Reporte del mes al dueño (día 1)
 * Y listas para el dueño: clientes por recuperar.
 */
final class Automaticas
{
    public const HORA_RECORDATORIOS = 9;   // desde esta hora se envían los recordatorios de mañana
    public const HORA_CUMPLEANOS = 8;
    public const HORA_REPORTE = 7;

    public function __construct(private PDO $db) {}

    private function salon(int $salonId): array
    {
        $st = $this->db->prepare('SELECT * FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        return $st->fetch() ?: [];
    }

    // ------------------------------------------------------------------
    // 1) Recordatorios del día anterior
    // ------------------------------------------------------------------

    /** Citas de mañana con el mensaje y enlace de WhatsApp listos. */
    public function recordatoriosManana(int $salonId, DateTimeImmutable $ahora): array
    {
        $manana = $ahora->modify('+1 day')->format('Y-m-d');
        $st = $this->db->prepare(
            "SELECT c.id FROM citas c WHERE c.salon_id = ? AND c.inicio::date = ? AND c.estado IN ('reservada','confirmada')
                AND c.cliente_id IS NOT NULL ORDER BY c.inicio"
        );
        $st->execute([$salonId, $manana]);
        $agenda = new Agenda($this->db);
        $base = Agenda::urlBase();
        $lista = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $c = $agenda->cita($salonId, (int) $id);
            $c['mensaje'] = $this->textoRecordatorio($c, $base);
            $c['wa'] = WhatsApp::enlace($c['telefono'], $c['mensaje']);
            $lista[] = $c;
        }
        return $lista;
    }

    public function textoRecordatorio(array $c, string $base): string
    {
        $ini = new DateTimeImmutable($c['inicio']);
        $valor = array_sum(array_map(fn($s) => (float) $s['precio'], $c['lista_servicios']));
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        return 'Hola ' . explode(' ', trim((string) $c['cliente']))[0] . ", te recordamos tu cita en {$c['salon']} mañana "
             . $dias[(int) $ini->format('w')] . ' ' . $ini->format('j/n') . ' a las ' . $ini->format('H:i')
             . " con {$c['profesional']}.\n"
             . 'Servicio: ' . implode(' + ', array_column($c['lista_servicios'], 'nombre'))
             . ' · Valor: $' . number_format($valor, 2, ',', '.') . ".\n"
             . "Confirma o cancela aquí: $base/?r=confirmar&t={$c['token']}";
    }

    /** Envía por Telegram a los clientes que lo conectaron. Marca recordatorio_en. */
    public function enviarRecordatorios(int $salonId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA_RECORDATORIOS) return 0;
        $tg = new Telegram($this->db);
        $n = 0;
        foreach ($this->recordatoriosManana($salonId, $ahora) as $c) {
            if ($c['recordatorio_en'] || !$c['cliente_telegram']) continue;
            if ($tg->enviarACliente((int) $c['cliente_telegram'], '⏰ ' . $c['mensaje'], (int) $c['id'])) {
                $this->db->prepare('UPDATE citas SET recordatorio_en = now() WHERE id = ?')->execute([$c['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** El dueño marca que ya envió el recordatorio por WhatsApp. */
    public function marcarRecordado(int $salonId, int $citaId): void
    {
        $this->db->prepare('UPDATE citas SET recordatorio_en = now() WHERE id = ? AND salon_id = ?')->execute([$citaId, $salonId]);
    }

    // ------------------------------------------------------------------
    // 2) Cumpleaños (solo mes y día)
    // ------------------------------------------------------------------

    public function cumpleanosHoy(int $salonId, DateTimeImmutable $hoy): array
    {
        $mes = (int) $hoy->format('n');
        $dia = (int) $hoy->format('j');
        // Los del 29 de febrero se saludan el 28 cuando el año no es bisiesto
        $extra = ($mes === 2 && $dia === 28 && !$hoy->format('L')) ? ' OR (cumple_mes = 2 AND cumple_dia = 29)' : '';
        $st = $this->db->prepare("SELECT id, nombre, telefono, telegram_chat_id, cumple_saludo_anio FROM clientes
                                   WHERE salon_id = ? AND ((cumple_mes = ? AND cumple_dia = ?)$extra) ORDER BY nombre");
        $st->execute([$salonId, $mes, $dia]);
        $salon = $this->salon($salonId);
        $lista = [];
        foreach ($st->fetchAll() as $c) {
            $c['mensaje'] = $this->textoCumple($c['nombre'], $salon['nombre'] ?? '');
            $c['wa'] = WhatsApp::enlace($c['telefono'], $c['mensaje']);
            $c['saludado'] = (int) $c['cumple_saludo_anio'] === (int) $hoy->format('Y');
            $lista[] = $c;
        }
        return $lista;
    }

    public function textoCumple(string $nombre, string $salon): string
    {
        return '¡Feliz cumpleaños, ' . explode(' ', trim($nombre))[0] . "! 🎉 Todo el equipo de $salon te desea un día espectacular. "
             . 'Cuando quieras, te esperamos para consentirte.';
    }

    /** Felicita por Telegram a quien lo conectó y avisa al dueño quién cumple hoy (una vez al año por cliente). */
    public function saludarCumpleanos(int $salonId, DateTimeImmutable $hoy): int
    {
        if ((int) $hoy->format('G') < self::HORA_CUMPLEANOS) return 0;
        $anio = (int) $hoy->format('Y');
        $tg = new Telegram($this->db);
        $nuevos = [];
        foreach ($this->cumpleanosHoy($salonId, $hoy) as $c) {
            if ($c['saludado']) continue;
            $st = $this->db->prepare('UPDATE clientes SET cumple_saludo_anio = ? WHERE id = ? AND cumple_saludo_anio IS DISTINCT FROM ?');
            $st->execute([$anio, $c['id'], $anio]);
            if ($st->rowCount() !== 1) continue;   // otro proceso ya lo saludó
            if ($c['telegram_chat_id']) $tg->enviarACliente((int) $c['telegram_chat_id'], $c['mensaje']);
            $nuevos[] = $c['nombre'];
        }
        if ($nuevos) {
            (new Notificaciones($this->db))->alDueno($salonId, '🎂 Hoy cumplen años: ' . implode(', ', $nuevos)
                . '. Salúdalos con un toque en "Avisos a clientes".');
        }
        return count($nuevos);
    }

    // ------------------------------------------------------------------
    // 3) Clientes por recuperar
    // ------------------------------------------------------------------

    public function porRecuperar(int $salonId, DateTimeImmutable $hoy, int $limite = 60): array
    {
        $salon = $this->salon($salonId);
        $semanas = max(1, (int) ($salon['semanas_sin_volver'] ?? 6));
        $st = $this->db->prepare(
            "SELECT cl.id, cl.nombre, cl.telefono, max(c.inicio) AS ultima,
                    count(*) AS visitas
               FROM clientes cl JOIN citas c ON c.cliente_id = cl.id AND c.estado = 'atendida'
              WHERE cl.salon_id = ?
                AND NOT EXISTS (SELECT 1 FROM citas f WHERE f.cliente_id = cl.id AND f.inicio > ?
                                   AND f.estado IN ('pendiente','reservada','confirmada'))
              GROUP BY cl.id
             HAVING max(c.inicio) < ? AND max(c.inicio) > ?
              ORDER BY count(*) DESC, max(c.inicio) DESC LIMIT " . (int) $limite
        );
        $st->execute([$salonId, $hoy->format('Y-m-d H:i:s'),
                      $hoy->modify("-$semanas weeks")->format('Y-m-d H:i:s'),
                      $hoy->modify('-1 year')->format('Y-m-d H:i:s')]);
        $enlace = Agenda::urlBase() . '/?r=reservar&s=' . rawurlencode((string) ($salon['slug'] ?? ''));
        $lista = [];
        foreach ($st->fetchAll() as $c) {
            $c['semanas'] = intdiv((int) (new DateTimeImmutable($c['ultima']))->diff($hoy)->days, 7);
            $c['mensaje'] = 'Hola ' . explode(' ', trim($c['nombre']))[0] . ", ¡te extrañamos en {$salon['nombre']}! "
                          . "Hace {$c['semanas']} semanas que no te vemos. Reserva tu próxima cita aquí: $enlace";
            $c['wa'] = WhatsApp::enlace($c['telefono'], $c['mensaje']);
            $lista[] = $c;
        }
        return $lista;
    }

    // ------------------------------------------------------------------
    // 4) Pedir calificación después del servicio (Telegram del cliente)
    // ------------------------------------------------------------------

    public function pedirCalificaciones(int $salonId, DateTimeImmutable $ahora): int
    {
        $st = $this->db->prepare(
            "SELECT c.id, c.token, cl.nombre, cl.telegram_chat_id, s.nombre AS salon
               FROM citas c JOIN clientes cl ON cl.id = c.cliente_id JOIN salones s ON s.id = c.salon_id
              WHERE c.salon_id = ? AND c.estado = 'atendida' AND c.calificacion_pedida_en IS NULL
                AND cl.telegram_chat_id IS NOT NULL AND c.fin < ? AND c.fin > ?
                AND NOT EXISTS (SELECT 1 FROM calificaciones ca WHERE ca.cita_id = c.id)"
        );
        $st->execute([$salonId, $ahora->format('Y-m-d H:i:s'), $ahora->modify('-2 days')->format('Y-m-d H:i:s')]);
        $tg = new Telegram($this->db);
        $base = Agenda::urlBase();
        $n = 0;
        foreach ($st->fetchAll() as $c) {
            $this->db->prepare('UPDATE citas SET calificacion_pedida_en = now() WHERE id = ?')->execute([$c['id']]);
            $ok = $tg->enviarACliente((int) $c['telegram_chat_id'],
                '¿Cómo te fue en ' . $c['salon'] . ', ' . explode(' ', trim($c['nombre']))[0] . "? ⭐\n"
                . "Califica tu servicio en 10 segundos: $base/?r=confirmar&t={$c['token']}");
            if ($ok) $n++;
        }
        return $n;
    }

    public static function textoPedirCalificacion(array $c, string $base): string
    {
        return 'Gracias por visitarnos en ' . $c['salon'] . ', ' . explode(' ', trim((string) $c['cliente']))[0]
             . ". ¿Cómo te fue con {$c['profesional']}? Califícanos aquí: $base/?r=confirmar&t={$c['token']}";
    }

    // ------------------------------------------------------------------
    // 5) Reporte del mes
    // ------------------------------------------------------------------

    /** @param string $mes 'AAAA-MM' */
    public function reporteMes(int $salonId, string $mes): array
    {
        $desde = $mes . '-01';
        $hasta = (new DateTimeImmutable($desde))->modify('+1 month')->format('Y-m-d');
        $q = function (string $sql, array $p) {
            $st = $this->db->prepare($sql);
            $st->execute($p);
            return $st;
        };
        $p = [$salonId, $desde, $hasta];

        $v = $q("SELECT count(*) AS ventas, COALESCE(sum(total),0) AS total, COALESCE(sum(propina),0) AS propinas
                   FROM ventas WHERE salon_id = ? AND NOT anulada AND fecha >= ? AND fecha < ?", $p)->fetch();

        $servicios = $q("SELECT s.nombre, sum(vi.cantidad) AS cantidad, sum(vi.subtotal) AS total
                           FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id JOIN servicios s ON s.id = vi.servicio_id
                          WHERE v.salon_id = ? AND NOT v.anulada AND v.fecha >= ? AND v.fecha < ? AND vi.tipo = 'servicio'
                          GROUP BY s.nombre ORDER BY sum(vi.cantidad) DESC, sum(vi.subtotal) DESC LIMIT 5", $p)->fetchAll();

        $equipo = $q("SELECT p.id, p.nombre,
                             COALESCE((SELECT sum(vi.subtotal) FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
                                        WHERE vi.profesional_id = p.id AND NOT v.anulada AND v.fecha >= ? AND v.fecha < ?), 0) AS vendido,
                             (SELECT count(DISTINCT v.id) FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
                               WHERE vi.profesional_id = p.id AND NOT v.anulada AND v.fecha >= ? AND v.fecha < ?) AS atenciones
                        FROM profesionales p WHERE p.salon_id = ? AND p.activo ORDER BY 3 DESC, p.nombre",
                     [$desde, $hasta, $desde, $hasta, $salonId])->fetchAll();
        $notas = (new Calificaciones($this->db))->resumen($salonId, $desde, $hasta);
        foreach ($equipo as &$e) {
            $e['estrellas'] = $notas[(int) $e['id']]['promedio'] ?? null;
            $e['calificaciones'] = (int) ($notas[(int) $e['id']]['cantidad'] ?? 0);
        }
        unset($e);

        // Horas muertas: horas de atención con menos citas en el mes
        $horario = (new Agenda($this->db))->horario($salonId);
        $usoHora = [];
        foreach ($horario as $h) {
            for ($hh = (int) substr($h['abre'], 0, 2); $hh < (int) ceil(((int) substr($h['cierra'], 0, 2) * 60 + (int) substr($h['cierra'], 3, 2)) / 60); $hh++) {
                $usoHora[$hh] = 0;
            }
        }
        $usoDia = array_fill_keys(array_keys($horario), 0);
        $st = $q("SELECT extract(hour FROM inicio)::int AS h, extract(dow FROM inicio)::int AS d, count(*) AS n
                    FROM citas WHERE salon_id = ? AND inicio >= ? AND inicio < ?
                     AND estado IN ('atendida','reservada','confirmada') GROUP BY 1, 2", $p);
        foreach ($st->fetchAll() as $r) {
            if (isset($usoHora[(int) $r['h']])) $usoHora[(int) $r['h']] += (int) $r['n'];
            if (isset($usoDia[(int) $r['d']])) $usoDia[(int) $r['d']] += (int) $r['n'];
        }
        asort($usoHora);
        asort($usoDia);
        $horasMuertas = [];
        foreach (array_slice($usoHora, 0, 3, true) as $h => $n) {
            $horasMuertas[] = ['hora' => sprintf('%02d:00–%02d:00', $h, $h + 1), 'citas' => $n];
        }
        $diaFlojo = $usoDia ? ['dia' => Agenda::DIAS[array_key_first($usoDia)], 'citas' => reset($usoDia)] : null;

        $nuevos = (int) $q('SELECT count(*) FROM clientes WHERE salon_id = ? AND creado_en >= ? AND creado_en < ?', $p)->fetchColumn();
        $faltas = (int) $q("SELECT count(*) FROM citas WHERE salon_id = ? AND estado = 'no_asistio' AND inicio >= ? AND inicio < ?", $p)->fetchColumn();
        $cancel = (int) $q("SELECT count(*) FROM citas WHERE salon_id = ? AND estado = 'cancelada' AND cancelada_por = 'cliente' AND inicio >= ? AND inicio < ?", $p)->fetchColumn();
        $online = (int) $q("SELECT count(*) FROM citas WHERE salon_id = ? AND origen = 'online' AND inicio >= ? AND inicio < ? AND estado <> 'rechazada'", $p)->fetchColumn();
        $prom = $q('SELECT round(avg(estrellas)::numeric,1) AS p, count(*) AS n FROM calificaciones WHERE salon_id = ? AND creada_en >= ? AND creada_en < ?', $p)->fetch();
        $gastos = (float) $q('SELECT COALESCE(sum(total),0) FROM facturas_recibidas WHERE salon_id = ? AND fecha_emision >= ? AND fecha_emision < ?', $p)->fetchColumn();

        return [
            'mes' => $mes,
            'ventas' => (int) $v['ventas'], 'total' => (float) $v['total'], 'propinas' => (float) $v['propinas'],
            'servicios' => $servicios, 'equipo' => $equipo,
            'horas_muertas' => $horasMuertas, 'dia_flojo' => $diaFlojo,
            'clientes_nuevos' => $nuevos, 'faltas' => $faltas, 'cancelaciones' => $cancel, 'reservas_online' => $online,
            'estrellas' => $prom['p'] !== null ? (float) $prom['p'] : null, 'calificaciones' => (int) $prom['n'],
            'gastos' => $gastos,
        ];
    }

    public const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                          'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function nombreMes(string $mes): string
    {
        return self::MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
    }

    public static function textoReporte(array $r, string $salon): string
    {
        $d = fn($x) => '$' . number_format((float) $x, 2, ',', '.');
        $t = "📊 Reporte de " . self::nombreMes($r['mes']) . " — $salon\n\n"
           . "Ventas: {$d($r['total'])} en {$r['ventas']} cobros (propinas {$d($r['propinas'])})\n";
        if ($r['gastos'] > 0) $t .= "Gastos (facturas recibidas): {$d($r['gastos'])}\n";
        $t .= "Clientes nuevos: {$r['clientes_nuevos']} · Reservas en línea: {$r['reservas_online']}\n"
            . "Faltas: {$r['faltas']} · Cancelaciones del cliente: {$r['cancelaciones']}\n";
        if ($r['estrellas'] !== null) $t .= "Calificación promedio: {$r['estrellas']} ★ ({$r['calificaciones']} " . ($r['calificaciones'] === 1 ? 'opinión' : 'opiniones') . ")\n";
        if ($r['servicios']) {
            $t .= "\nLo más vendido:\n";
            foreach ($r['servicios'] as $i => $s) $t .= ($i + 1) . ". {$s['nombre']}: {$s['cantidad']} ({$d($s['total'])})\n";
        }
        if ($r['equipo']) {
            $t .= "\nEquipo:\n";
            foreach ($r['equipo'] as $i => $e) {
                $t .= ($i === 0 && $e['vendido'] > 0 ? '🏆 ' : '• ') . "{$e['nombre']}: {$d($e['vendido'])}"
                    . ($e['estrellas'] !== null ? " · {$e['estrellas']} ★" : '') . "\n";
            }
        }
        if ($r['horas_muertas']) {
            $t .= "\nHoras más vacías: " . implode(', ', array_map(fn($h) => "{$h['hora']} ({$h['citas']})", $r['horas_muertas'])) . "\n";
            if ($r['dia_flojo']) $t .= "Día más flojo: {$r['dia_flojo']['dia']} ({$r['dia_flojo']['citas']} citas)\n";
            $t .= "Idea: una promoción en esas horas para llenarlas.";
        }
        return $t;
    }

    /** El día 1 (desde las 7:00) envía el reporte del mes anterior al dueño, una sola vez. */
    public function enviarReporteMensual(int $salonId, DateTimeImmutable $ahora, bool $forzar = false): bool
    {
        if (!$forzar && ((int) $ahora->format('j') !== 1 || (int) $ahora->format('G') < self::HORA_REPORTE)) return false;
        $mes = $ahora->modify('first day of last month')->format('Y-m');
        if (!$forzar) {
            $st = $this->db->prepare('UPDATE salones SET reporte_enviado_mes = ? WHERE id = ? AND reporte_enviado_mes IS DISTINCT FROM ?');
            $st->execute([$mes, $salonId, $mes]);
            if ($st->rowCount() !== 1) return false;
        }
        $salon = $this->salon($salonId);
        (new Notificaciones($this->db))->alDueno($salonId, self::textoReporte($this->reporteMes($salonId, $mes), $salon['nombre']));
        return true;
    }

    // ------------------------------------------------------------------
    // Todo junto (lo llama bin/tareas.php)
    // ------------------------------------------------------------------

    public function correr(DateTimeImmutable $ahora): array
    {
        $st = $this->db->query("SELECT id, plan_codigo FROM salones
                                 WHERE estado = 'activo' OR (estado = 'prueba' AND prueba_hasta >= CURRENT_DATE - 1)");
        $total = ['salones' => 0, 'recordatorios' => 0, 'cumpleanos' => 0, 'calificaciones' => 0, 'reportes' => 0];
        foreach ($st->fetchAll() as $s) {
            $id = (int) $s['id'];
            $total['salones']++;
            try {
                // Básica: recordatorio y cumpleaños por WhatsApp con un toque (en la página). Completa: además automático.
                if ($s['plan_codigo'] === 'completa') {
                    $total['recordatorios'] += $this->enviarRecordatorios($id, $ahora);
                    $total['calificaciones'] += $this->pedirCalificaciones($id, $ahora);
                    $total['reportes'] += $this->enviarReporteMensual($id, $ahora) ? 1 : 0;
                }
                $total['cumpleanos'] += $this->saludarCumpleanos($id, $ahora);
            } catch (\Throwable $e) {
                error_log("TuSalón tareas salón $id: " . $e->getMessage());
            }
        }
        return $total;
    }
}
