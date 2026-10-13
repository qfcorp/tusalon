<?php
declare(strict_types=1);

namespace TuSalon;

use RuntimeException;

/** Token del Panel Tukán Central rechazado (el mensaje se le muestra a quien entra o va en el JSON de error). */
final class TukanCentralRechazo extends RuntimeException {}
