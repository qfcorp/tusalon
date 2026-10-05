<?php
$filtro = (string) ($_GET['f'] ?? '');
if ($filtro !== '' && !isset(TuSalon\PanelTukan::ESTADOS[$filtro])) $filtro = '';
$buscar = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$salones = (new TuSalon\PanelTukan(db()))->salones($filtro, $buscar);
vista_admin('admin_salones', compact('salones', 'filtro', 'buscar'), 'Salones · Panel Tukán');
