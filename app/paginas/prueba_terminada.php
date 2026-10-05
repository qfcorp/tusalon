<?php
if ($u['estado'] === 'activo' && dias_prueba($u) === null) {
    redirigir('inicio');
}
vista('prueba_terminada', [], 'Tu prueba terminó · TuSalón');
