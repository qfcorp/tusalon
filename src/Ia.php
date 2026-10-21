<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use TukanIaFaqPdo;
use TukanIaGuardianPdo;

require_once __DIR__ . '/tukan_ia.php';

/**
 * IA de Tukán en TuSalón (compartido/tukan_ia.php del Panel Tukán Central; guía: compartido/LEEME_IA.md).
 *
 *  - Ayuda («¿En qué le ayudamos?») para todos los planes: FAQ guardadas → IA con el manual → el manual tal cual.
 *  - Módulo extra «Asistente IA» (asistente_ia): agendar desde un mensaje y mensaje para clientes que no vuelven (src/Asistente.php).
 *  - Guardián por salón (tabla tukan_ia_gasto, negocio_id = id del salón) con el tope del mes:
 *    sin el módulo $0,50 y 30 respuestas nuevas de la Ayuda; con el módulo el que se cotizó (salones.ia_tope_usd) o $3,00.
 *  - La configuración (clave, modelos, pausa, tope del programa) está en /etc/tukan-ia.env; sin ese archivo la IA queda
 *    apagada y la Ayuda responde solo con las FAQ y el manual. Al cliente nunca se le dice por qué (mensajes neutros).
 */
final class Ia
{
    public const PROGRAMA = 'tusalon';
    public const NOMBRE = 'TuSalón';
    public const MODULO = 'asistente_ia';
    public const TOPE_SIN_MODULO = 0.50;
    public const TOPE_CON_MODULO = 3.00;
    public const NUEVAS_SIN_MODULO = 30;
    public const PREGUNTAS_POR_MINUTO = 10;

    /** Tope del mes del salón en USD: el que puso Tukán (ia_tope_usd) o el sugerido según tenga o no el módulo. */
    public static function tope(PDO $db, int $salonId): float
    {
        $st = $db->prepare('SELECT ia_tope_usd FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $t = $st->fetchColumn();
        if ($t !== false && $t !== null) return round((float) $t, 4);
        return self::conModulo($db, $salonId) ? self::TOPE_CON_MODULO : self::TOPE_SIN_MODULO;
    }

    /** ¿Tiene encendido el módulo «Asistente IA»? */
    public static function conModulo(PDO $db, int $salonId): bool
    {
        return (new Modulos($db))->activo($salonId, self::MODULO);
    }

    /** Tope propio guardado por Tukán (null = el sugerido). */
    public static function topePropio(PDO $db, int $salonId): ?float
    {
        $st = $db->prepare('SELECT ia_tope_usd FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $t = $st->fetchColumn();
        return $t === false || $t === null ? null : round((float) $t, 4);
    }

    /** Guarda el tope propio del salón (null = volver al sugerido). */
    public static function guardarTope(PDO $db, int $salonId, ?float $tope): void
    {
        if ($tope !== null && ($tope < 0 || $tope > 1000)) throw new \RuntimeException('El tope debe estar entre 0 y 1.000 dólares.');
        $db->prepare('UPDATE salones SET ia_tope_usd = ? WHERE id = ?')->execute([$tope === null ? null : round($tope, 4), $salonId]);
    }

    public static function guardian(PDO $db, int $salonId): TukanIaGuardianPdo
    {
        return new TukanIaGuardianPdo($db, (string) $salonId, self::tope($db, $salonId));
    }

    public static function faq(PDO $db): TukanIaFaqPdo
    {
        return new TukanIaFaqPdo($db);
    }

    /** Gasto del mes del salón (para el panel de Tukán). */
    public static function gastoMes(PDO $db, int $salonId, ?string $mes = null): array
    {
        $st = $db->prepare('SELECT costo_usd, llamadas, ahorradas, ayudas_nuevas FROM tukan_ia_gasto WHERE negocio_id = ? AND mes = ?');
        $st->execute([(string) $salonId, $mes ?? date('Y-m')]);
        $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['costo' => round((float) ($f['costo_usd'] ?? 0), 6), 'llamadas' => (int) ($f['llamadas'] ?? 0),
                'ahorradas' => (int) ($f['ahorradas'] ?? 0), 'ayudas_nuevas' => (int) ($f['ayudas_nuevas'] ?? 0)];
    }

    /** El manual en texto: docs/MANUAL.md si es el manual para clientes, si no docs/manual/manual.html convertido. */
    public static function manual(): string
    {
        static $m = null;
        if ($m !== null) return $m;
        $html = dirname(__DIR__) . '/docs/manual/manual.html';
        $texto = is_file($html) ? tukan_ia_html_a_texto((string) file_get_contents($html)) : '';
        if ($texto === '' && is_file($md = dirname(__DIR__) . '/docs/MANUAL.md')) $texto = (string) file_get_contents($md);
        return $m = $texto;
    }

    /** ¿Puede preguntar otra vez? Máximo PREGUNTAS_POR_MINUTO por usuario (en la sesión). */
    public static function puedePreguntar(array &$sesion, ?int $ahora = null): bool
    {
        $ahora ??= time();
        $l = array_values(array_filter((array) ($sesion['ia_preguntas'] ?? []), fn($t) => is_int($t) && $t > $ahora - 60));
        if (count($l) >= self::PREGUNTAS_POR_MINUTO) { $sesion['ia_preguntas'] = $l; return false; }
        $l[] = $ahora;
        $sesion['ia_preguntas'] = $l;
        return true;
    }

    /** La Ayuda: responde con FAQ, IA o el manual. Nunca dice por qué no usó la IA. */
    public static function ayuda(PDO $db, int $salonId, string $pregunta): array
    {
        $a = tukan_ia_ayuda($pregunta, self::manual(), self::faq($db), [
            'guardian' => self::guardian($db, $salonId), 'programa' => self::PROGRAMA, 'nombre' => self::NOMBRE,
            'max_nuevas_mes' => self::conModulo($db, $salonId) ? null : self::NUEVAS_SIN_MODULO,
        ]);
        $a['respuesta'] = self::sinPalabras((string) $a['respuesta']);
        return $a;
    }

    /**
     * En la Ayuda nunca aparecen «límite», «cupo» ni «saldo» (LEEME_IA.md §4.1), ni siquiera cuando el manual o una FAQ
     * los usa con otro sentido: se cambian por «máximo», «turno» y «monto».
     */
    public static function sinPalabras(string $t): string
    {
        $mapa = [
            '/\bcupo(s)? de (\d)/iu' => 'máximo de $2',
            '/\bl[ií]mite(s)?\b/u' => 'máximo$1', '/\bL[ií]mite(s)?\b/u' => 'Máximo$1', '/\bL[IÍ]MITE(S)?\b/u' => 'MÁXIMO$1',
            '/\bcupos\b/u' => 'turnos', '/\bcupo\b/u' => 'turno', '/\bCupos\b/u' => 'Turnos', '/\bCupo\b/u' => 'Turno', '/\bCUPOS?\b/u' => 'TURNOS',
            '/\bsaldo(s)?\b/u' => 'monto$1', '/\bSaldo(s)?\b/u' => 'Monto$1', '/\bSALDOS?\b/u' => 'MONTOS',
        ];
        $t = (string) preg_replace(array_keys($mapa), array_values($mapa), $t);
        return (string) preg_replace('/\b(l[ií]mite|cupo|saldo)/iu', 'máximo', $t);   // cualquier otra forma
    }

    /** Respuesta de la Ayuda en HTML seguro (**negritas** y saltos de línea). */
    public static function html(string $texto): string
    {
        $h = htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $h);
        return nl2br($h);
    }

    /**
     * El mensaje neutro de la librería en HTML, con el enlace para hacerlo a mano ($ruta: '/?r=cita_nueva').
     * Es el MISMO texto sea cual sea el motivo (tope, pausa, sin clave o error).
     */
    public static function neutroHtml(string $tipo, string $ruta = ''): string
    {
        $h = fn(string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($ruta === '') return $h(tukan_ia_mensaje_neutro($tipo));
        $enlace = rtrim(Agenda::urlBase(), '/') . $ruta;
        [$antes, $despues] = explode("\x01", tukan_ia_mensaje_neutro($tipo, "\x01"), 2) + [1 => ''];
        return $h($antes) . '<a href="' . $h($ruta) . '">' . $h($enlace) . '</a>' . $h($despues);
    }

    /** Acción v2 «ia_uso» (Anexo C): gasto del mes por salón, totales y resumen de las FAQ. */
    public static function uso(PDO $db, string $mes): array
    {
        $mods = new Modulos($db);
        $nombres = function (array $ids) use ($db, $mods): array {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) return [];
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT id, nombre FROM salones WHERE id IN ($in)");
            $st->execute($ids);
            $r = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $id = (int) $f['id'];
                $r[(string) $id] = ['nombre' => (string) $f['nombre'], 'tope' => self::tope($db, $id), 'modulo' => $mods->activo($id, self::MODULO)];
            }
            return $r;
        };
        return tukan_ia_uso_pdo($db, $mes, $nombres, 'tukan_ia_gasto', self::faq($db), self::PROGRAMA);
    }
}
