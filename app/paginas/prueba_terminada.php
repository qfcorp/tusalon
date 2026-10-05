<?php
if (planes()->alDia($u)) {
    redirigir('inicio');
}
$estadoCuenta = planes()->estadoCuenta($u);
vista('prueba_terminada', compact('estadoCuenta'), 'Tu cuenta · TuSalón');
