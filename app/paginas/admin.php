<?php
$panel = new TuSalon\PanelTukan(db());
$r = $panel->resumen();
$registro = $panel->registro(null, 12);
vista_admin('admin', compact('r', 'registro'), 'Resumen · Panel Tukán');
