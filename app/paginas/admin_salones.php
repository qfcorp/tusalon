<?php
$filtro = (string) ($_GET['f'] ?? '');
if ($filtro !== '' && $filtro !== 'papelera' && !isset(TuSalon\PanelTukan::ESTADOS[$filtro])) $filtro = '';
$buscar = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
// "papelera" (Anexo A): los salones borrados que todavía se pueden restaurar
$salones = (new TuSalon\PanelTukan(db()))->salones($filtro === 'papelera' ? '' : $filtro, $buscar, null, $filtro === 'papelera');
vista_admin('admin_salones', compact('salones', 'filtro', 'buscar'), 'Salones · Panel Tukán');
