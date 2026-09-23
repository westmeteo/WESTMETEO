<?php
// plan_guardar.php — guarda un plan de "Planifica tu ruta" y devuelve un id para compartir.
//
//   POST (JSON): { "gpx": "<contenido .gpx>", "inputs": { ...formulario... } }
//   -> { "ok": true, "id": "<id alfanumérico>" }
//
// El GPX no es secreto, pero se guarda FUERA de public_html (igual patrón que estacion.php):
// carpeta "planes/" en el padre de public_html si es escribible; si no, el temporal del sistema.
// Solo corre en Hostinger (PHP); en GitHub Pages este endpoint no existe y el botón avisa.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'método no permitido']); exit;
}

// ---------- límite de tamaño (GPX + inputs) ----------
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) === 0) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'sin datos']); exit;
}
if (strlen($raw) > 2600000) { // ~2,6 MB de payload (GPX grande + inputs) — margen sobre los 2 MB de GPX
    http_response_code(413); echo json_encode(['ok' => false, 'error' => 'plan demasiado grande']); exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['gpx']) || !is_string($data['gpx'])) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'falta el gpx']); exit;
}
if (strpos($data['gpx'], '<') === false) { // saneo mínimo: tiene que parecer XML/GPX
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'gpx no válido']); exit;
}

// solo conservamos gpx + inputs + marca de tiempo (nada ejecutable)
$plan = [
    'gpx'    => (string)$data['gpx'],
    'inputs' => isset($data['inputs']) && is_array($data['inputs']) ? $data['inputs'] : null,
    'ts'     => time(),
];

// ---------- almacenamiento fuera de public_html ----------
$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
$dir   = $store . '/planes';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
if (!is_dir($dir) || !is_writable($dir)) {
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'almacenamiento no disponible']); exit;
}

// ---------- id aleatorio alfanumérico (sin colisión) ----------
function nuevo_id() {
    $abc = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}
$id = nuevo_id();
for ($i = 0; $i < 5 && file_exists("$dir/$id.json"); $i++) $id = nuevo_id();

$ok = @file_put_contents("$dir/$id.json", json_encode($plan, JSON_UNESCAPED_UNICODE), LOCK_EX);
if ($ok === false) {
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'no se pudo guardar']); exit;
}

echo json_encode(['ok' => true, 'id' => $id]);
