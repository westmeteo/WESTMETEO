<?php
// plan_cargar.php — devuelve un plan guardado por plan_guardar.php.
//
//   GET plan_cargar.php?id=<id>
//   -> { "ok": true, "gpx": "<...>", "inputs": {...} }
//
// Lee de la misma carpeta "planes/" fuera de public_html. Solo corre en Hostinger.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
// saneo del id: solo alfanumérico en minúscula (evita path traversal)
if (!preg_match('/^[a-z0-9]{1,32}$/', $id)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id no válido']); exit;
}

$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
$file  = $store . '/planes/' . $id . '.json';
if (!is_file($file)) {
    http_response_code(404); echo json_encode(['ok' => false, 'error' => 'plan no encontrado']); exit;
}

$raw  = @file_get_contents($file);
$plan = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($plan) || empty($plan['gpx'])) {
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'plan corrupto']); exit;
}

echo json_encode([
    'ok'     => true,
    'gpx'    => (string)$plan['gpx'],
    'inputs' => isset($plan['inputs']) && is_array($plan['inputs']) ? $plan['inputs'] : null,
], JSON_UNESCAPED_UNICODE);
