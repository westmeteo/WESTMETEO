<?php
// estacion.php — proxy a la API Ecowitt v3 para westmeteo.com
// Las claves NO van aqui (repo publico): se leen de estacion_config.php,
// que debe estar FUERA de public_html (o, si no, junto a este fichero).
// Devuelve el JSON de la estacion tal cual lo da Ecowitt.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

// --- config (busca primero fuera de public_html, luego al lado) ---
$cfg = @include __DIR__ . '/../estacion_config.php';
if (!is_array($cfg)) $cfg = @include __DIR__ . '/estacion_config.php';
if (!is_array($cfg) || empty($cfg['app_key']) || empty($cfg['api_key'])) {
    http_response_code(500);
    echo json_encode(['error' => 'config', 'msg' => 'Falta estacion_config.php con app_key y api_key']);
    exit;
}
$APP = $cfg['app_key'];
$API = $cfg['api_key'];
$MAC = $cfg['mac'] ?? '';

// --- almacenamiento de caché (parent de public_html si es escribible, si no /tmp) ---
$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
$cacheFile = $store . '/estacion_cache.json';
$macFile   = $store . '/estacion_mac.txt';

// sirve caché de <55 s (salvo ?nocache=1)
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 55) && !isset($_GET['nocache'])) {
    echo file_get_contents($cacheFile);
    exit;
}

function ecowitt_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'westmeteo/1.0',
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r;
    }
    return @file_get_contents($url);
}

// --- resuelve el MAC/IMEI si no esta configurado (se cachea) ---
if (!$MAC && is_file($macFile)) $MAC = trim(file_get_contents($macFile));
$list = null;
if (!$MAC) {
    $list = json_decode(ecowitt_get(
        'https://api.ecowitt.net/api/v3/device/list?application_key=' . urlencode($APP) .
        '&api_key=' . urlencode($API) . '&limit=1'), true);
    if (isset($list['data']['list'][0]['mac']))       { $MAC = $list['data']['list'][0]['mac']; }
    elseif (isset($list['data']['list'][0]['imei']))  { $MAC = $list['data']['list'][0]['imei']; }
    if ($MAC) @file_put_contents($macFile, $MAC);
}
if (!$MAC) {
    http_response_code(502);
    echo json_encode(['error' => 'no_device', 'device_list' => $list]);
    exit;
}

// --- lectura en tiempo real (unidades metricas) ---
$q = http_build_query([
    'application_key'         => $APP,
    'api_key'                 => $API,
    'mac'                     => $MAC,
    'call_back'               => 'all',
    'temp_unitid'             => 1,   // Celsius
    'pressure_unitid'         => 3,   // hPa
    'wind_speed_unitid'       => 7,   // km/h
    'rainfall_unitid'         => 12,  // mm
    'solar_irradiance_unitid' => 16,  // W/m2
]);
$data = ecowitt_get('https://api.ecowitt.net/api/v3/device/real_time?' . $q);
if ($data === false || $data === '') {
    http_response_code(502);
    echo json_encode(['error' => 'upstream']);
    exit;
}

@file_put_contents($cacheFile, $data);
echo $data;
