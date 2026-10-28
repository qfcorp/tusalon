<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuenta para que Google revise la app de Android de TuSalón sin ver datos reales.
 *
 * Arma un SALÓN DE EJEMPLO aparte («Salón de Ejemplo Tukán», plan Completa, activo un año, SIN pagos de suscripción)
 * con datos inventados: 4 personas en el equipo (los 4 tipos), 8 servicios, 12 clientes, 2 semanas de cobros, citas de hoy y
 * de mañana, 3 productos y un paquete. La dueña es revisor.google@tukanec.com con una contraseña al azar que se muestra UNA vez.
 * No toca ningún salón real: todo lo que crea cuelga de ese salón y `borrar()` lo quita entero.
 *
 *   php deploy/demo_google.php crear      (o volver a crearlo con fechas de hoy y contraseña nueva)
 *   php deploy/demo_google.php borrar
 */
final class DemoGoogle
{
    public const CORREO = 'revisor.google@tukanec.com';
    public const NOMBRE = 'Salón de Ejemplo Tukán';

    public function __construct(private PDO $db) {}

    /** Id del salón de ejemplo (el de la cuenta del revisor) o null si no existe. */
    public function salonId(): ?int
    {
        $st = $this->db->prepare('SELECT salon_id FROM usuarios WHERE email = ?');
        $st->execute([self::CORREO]);
        $id = $st->fetchColumn();
        return $id === false || $id === null ? null : (int) $id;
    }

    /** Contraseña nueva al azar, fácil de dictar: Revisor- + 6 números. */
    public static function claveNueva(): string
    {
        return 'Revisor-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Borra el salón de ejemplo (si existe) y lo vuelve a crear con las fechas de hoy.
     * @return array{salon_id:int, clave:string, citas:int, clientes:int}
     */
    public function crear(?string $clave = null, ?DateTimeImmutable $hoy = null): array
    {
        $clave ??= self::claveNueva();
        $hoy ??= new DateTimeImmutable('today');
        $this->borrar();

        $a = (new Altas($this->db))->crearSalonConDueno(self::NOMBRE, 'Revisión Google', '0990000000', self::CORREO, $clave, 'completa', 'super');
        $sid = (int) $a['salon_id'];
        $q = function (string $sql, array $p = []) { $st = $this->db->prepare($sql); $st->execute($p); return $st; };
        // Activo un año, sin pagos de suscripción (no es un cliente) y sin la marca de demo
        $q("UPDATE salones SET estado = 'activo', prueba_hasta = NULL, activo_hasta = CURRENT_DATE + 365, acepta_reservas = 'dueno',
                   creado_en = now() - interval '60 days' WHERE id = ?", [$sid]);
        $agenda = new Agenda($this->db);
        $dias = [];
        for ($d = 0; $d <= 6; $d++) $dias[$d] = $d === 0 ? ['abre' => '09:00', 'cierra' => '14:00'] : ['abre' => '09:00', 'cierra' => '20:00'];
        $agenda->guardarHorario($sid, $dias);

        // Equipo: los 4 tipos (dueña, empleado, porcentaje y alquiler de puesto)
        $planes = new Planes($this->db);
        $prof = new Profesionales($this->db, $planes);
        $ingreso = $hoy->modify('-60 days')->format('Y-m-d');
        $dueno = (int) $q("SELECT id FROM profesionales WHERE salon_id = ? AND tipo = 'dueno'", [$sid])->fetchColumn();
        $q('UPDATE profesionales SET fecha_ingreso = ? WHERE id = ?', [$ingreso, $dueno]);
        $q('UPDATE reglas_pago SET vigente_desde = ? WHERE profesional_id = ?', [$ingreso, $dueno]);
        $carlos = $prof->agregar($sid, 'Carlos Mena', 'empleado', ['sueldo_mensual' => 470, 'comision_servicio_pct' => 30, 'comision_producto_pct' => 10], $ingreso);
        $ana = $prof->agregar($sid, 'Ana Vera', 'porcentaje', ['pct_profesional' => 50, 'quien_cobra' => 'local'], $ingreso);
        $jorge = $prof->agregar($sid, 'Jorge Paz', 'alquiler', ['monto_arriendo' => 60, 'frecuencia_arriendo' => 'semanal'], $ingreso);

        // Servicios del salón (sus precios son del salón, no del software)
        $q('DELETE FROM servicios WHERE salon_id = ?', [$sid]);
        $serv = [];
        $ins = $this->db->prepare('INSERT INTO servicios (salon_id, nombre, precio, duracion_minutos, descripcion) VALUES (?,?,?,?,?) RETURNING id');
        foreach ([
            'corte' => ['Corte de caballero', 7, 30, 'Lavado, corte con máquina y tijera, y peinado.'],
            'barba' => ['Barba', 5, 20, 'Perfilado con navaja y toalla caliente.'],
            'combo' => ['Corte y barba', 11, 45, 'Corte de caballero más perfilado de barba.'],
            'dama' => ['Corte de dama', 12, 45, 'Lavado, corte y secado con cepillo.'],
            'tinte' => ['Tinte completo', 30, 90, 'Color de raíz a puntas. Incluye lavado y secado.'],
            'raiz' => ['Retoque de raíz', 20, 60, 'Cubre las canas o el crecimiento de la raíz.'],
            'keratina' => ['Keratina', 40, 120, 'Alisado con keratina, sin frizz por varias semanas.'],
            'peinado' => ['Peinado para evento', 15, 40, 'Ondas, recogido o planchado.'],
        ] as $k => [$n, $p, $min, $desc]) {
            $ins->execute([$sid, $n, $p, $min, $desc]);
            $serv[$k] = (int) $ins->fetchColumn();
        }

        // Productos y un paquete
        $inv = new Inventario($this->db);
        $prod = [];
        foreach (['cera' => ['Cera mate 100 g', 1, 12, 6.5, 14, 3], 'shampoo' => ['Shampoo keratina 500 ml', 1, 14, 8, 9, 3],
                  'tinte' => ['Tinte 7.1 (uso interno)', 0, 0, 6.2, 10, 4]] as $k => [$n, $venta, $precio, $costo, $stock, $min]) {
            $prod[$k] = $inv->guardar($sid, null, ['nombre' => $n, 'marca' => 'Marca de ejemplo', 'para_venta' => $venta, 'precio' => $precio,
                                                    'costo' => $costo, 'stock' => $stock, 'stock_minimo' => $min]);
        }
        (new Fidelidad($this->db))->guardarPaquete($sid, null, ['nombre' => '4 cortes de caballero', 'servicio_id' => $serv['corte'], 'sesiones' => 4,
                                                               'precio' => 24, 'vigencia_dias' => 90]);

        // Clientes inventados (teléfonos de ejemplo 09900001xx)
        $cli = [];
        $insC = $this->db->prepare('INSERT INTO clientes (salon_id, nombre, telefono, notas, creado_en) VALUES (?,?,?,?,?) RETURNING id');
        foreach (['María José Andrade', 'Pedro Ortiz', 'Sofía Paredes', 'Diego Montalvo', 'Carla Benítez', 'Mateo Villacís', 'Gabriela Ruiz',
                  'Fernando Lara', 'Valeria Chávez', 'Luis Cevallos', 'Andrea Salazar', 'Javier Suárez'] as $i => $n) {
            $insC->execute([$sid, $n, sprintf('09900001%02d', $i + 1), $i === 0 ? 'Prefiere tinte sin amoniaco.' : null,
                            $hoy->modify('-50 days')->format('Y-m-d H:i')]);
            $cli[] = (int) $insC->fetchColumn();
        }

        // Dos semanas de citas cobradas (caja, cuentas y reporte con datos)
        $cobro = new Cobro($this->db);
        $cobrar = function (int $citaId, string $cuando, string $metodo, array $productos = []) use ($agenda, $cobro, $sid, $jorge) {
            $c = $agenda->cita($sid, $citaId);
            $s = [];
            foreach ($c['lista_servicios'] as $x) $s[(int) $x['id']] = (float) $x['precio'];
            $cobro->registrar(['salon_id' => $sid, 'profesional_id' => (int) $c['profesional_id'], 'cita' => $c, 'servicios' => $s,
                               'productos' => $productos, 'paquetes' => [], 'metodo_pago' => $metodo,
                               'cobrado_por' => (int) $c['profesional_id'] === $jorge ? 'profesional' : 'local', 'propina' => 0, 'fecha' => $cuando]);
        };
        $mezcla = [[$dueno, 'corte'], [$carlos, 'combo'], [$ana, 'dama'], [$jorge, 'corte'], [$carlos, 'barba'], [$ana, 'tinte'],
                   [$dueno, 'peinado'], [$jorge, 'combo'], [$ana, 'raiz'], [$carlos, 'corte']];
        $metodos = ['efectivo', 'transferencia', 'deuna', 'efectivo', 'tarjeta'];
        $n = 0;
        for ($dd = 14; $dd >= 1; $dd--) {
            $dia = $hoy->modify("-$dd days");
            if ((int) $dia->format('w') === 0) continue;
            for ($i = 0; $i < 3 + $dd % 2; $i++, $n++) {
                [$p, $sk] = $mezcla[$n % count($mezcla)];
                $hora = 10 + $i * 2;
                $cid = $agenda->crearCita($sid, $p, $cli[$n % count($cli)], $dia->format('Y-m-d') . sprintf(' %02d:00', $hora), [$serv[$sk]]);
                $cobrar($cid, $dia->format('Y-m-d') . sprintf(' %02d:50', $hora), $metodos[$n % count($metodos)], $n % 7 === 0 ? [$prod['cera'] => 1] : []);
            }
        }
        // Hoy y mañana: citas por atender (lo de hoy que ya pasó queda cobrado)
        $ahora = new DateTimeImmutable();
        $manana = $hoy->modify('+1 day');
        if ((int) $manana->format('w') === 0) $manana = $manana->modify('+1 day');
        foreach ([[$hoy, ['09:30' => [$dueno, 'corte'], '11:00' => [$ana, 'tinte'], '13:00' => [$carlos, 'combo'], '15:30' => [$jorge, 'corte'],
                          '17:00' => [$ana, 'peinado'], '18:30' => [$carlos, 'barba']]],
                  [$manana, ['09:00' => [$dueno, 'dama'], '10:00' => [$carlos, 'corte'], '11:30' => [$ana, 'keratina'], '12:00' => [$jorge, 'combo'],
                             '16:00' => [$dueno, 'raiz'], '17:30' => [$carlos, 'combo']]]] as [$dia, $plan]) {
            foreach ($plan as $h => [$p, $sk]) {
                if ((int) $dia->format('w') === 0 && $h >= '13:00') continue;
                $inicio = new DateTimeImmutable($dia->format('Y-m-d') . " $h");
                try {
                    $cid = $agenda->crearCita($sid, $p, $cli[$n++ % count($cli)], $inicio->format('Y-m-d H:i'), [$serv[$sk]]);
                } catch (RuntimeException) {
                    continue;   // fuera del horario de esa persona
                }
                if ($inicio->modify('+40 minutes') < $ahora) $cobrar($cid, $inicio->modify('+35 minutes')->format('Y-m-d H:i'), 'efectivo');
            }
        }
        // Avisos viejos marcados como leídos (no tapan la pantalla de inicio)
        $q('UPDATE notificaciones SET leida = true WHERE salon_id = ?', [$sid]);
        $q('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)', [$sid, 'Cuenta para la revisión de Google Play (' . self::CORREO . ')']);

        return ['salon_id' => $sid, 'clave' => $clave,
                'citas' => (int) $q('SELECT count(*) FROM citas WHERE salon_id = ?', [$sid])->fetchColumn(),
                'clientes' => count($cli)];
    }

    /**
     * Borra el salón de ejemplo entero (como el borrado definitivo de la papelera, sin esperar los 30 días).
     * Devuelve cuántas citas tenía (0 si no existía).
     */
    public function borrar(): int
    {
        $sid = $this->salonId();
        if ($sid === null) return 0;
        $st = $this->db->prepare('SELECT count(*) FROM citas WHERE salon_id = ?');
        $st->execute([$sid]);
        $citas = (int) $st->fetchColumn();
        $this->db->prepare("UPDATE salones SET eliminado_en = now() - interval '" . (Papelera::DIAS_PAPELERA + 1) . " days',
                                   eliminado_motivo = 'Cuenta de revisión de Google Play' WHERE id = ?")->execute([$sid]);
        (new Papelera($this->db))->purgar(new DateTimeImmutable());
        if ($this->salonId() !== null) throw new RuntimeException('No se pudo borrar el salón de ejemplo.');
        return $citas;
    }
}
