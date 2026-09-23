<?php
// verif_captura.php — CRON HORARIO. Archiva la previsión de AROME (Open-Meteo) y la
// observación de la estación de Béjar (via estacion.php) para verificar el modelo.
//
// Modelo de datos (lo pedido por el usuario): cada valor se guarda bajo su HORA OBJETIVO
// con la etiqueta del horizonte con que se capturó:
//   - la previsión para "25 sep 10:00" capturada el 23 sep ~10:00 -> [25 sep 10:00].f48
//   - la misma capturada el 24 sep ~10:00                          -> [25 sep 10:00].f24
//   - la observación real de "25 sep 10:00"                         -> [25 sep 10:00].obs
//
// NO corrige nada ni toca estacion_sesgos.json: solo mide y almacena. El cruce y las
// estadísticas los calcula verif.php al leer.
//
// Ejecución:
//   - CLI (cron):  php verif_captura.php
//   - Web (manual): verif_captura.php?run=1   (desde el móvil, fuera del firewall)
//
// Almacenamiento: verif_data_YYYYMM.json (uno por mes) en el padre de public_html
// (mismo patrón que estacion.php), o /tmp si no es escribible.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ---- guard: en web exige ?run=1; en CLI corre siempre ----
$esCli = (PHP_SAPI === 'cli');
if (!$esCli && !isset($_GET['run'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Usa ?run=1 para disparo manual (o ejecútalo por cron con php-cli).']);
    exit;
}

// ---- parámetros ----
$LAT = 40.38520882243841;
$LON = -5.764519810615559;
$EST_URL  = 'https://westmeteo.com/estacion.php?est=bejar&nocache=1';
$TZ       = 'Europe/Madrid';
$VARS_AROME = 'temperature_2m,relative_humidity_2m,precipitation,wind_speed_10m,wind_gusts_10m,wind_direction_10m';

// ---- almacenamiento (padre de public_html si es escribible, si no /tmp) ----
$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
function mesFile($store, $targetISO) {   // "2026-09-25T10:00" -> ".../verif_data_202609.json"
    $ym = substr($targetISO, 0, 7);      // "2026-09"
    return $store . '/verif_data_' . str_replace('-', '', $ym) . '.json';
}

// caché de ficheros de mes cargados en esta ejecución (para escribir cada uno una sola vez)
$MESES = [];
function cargarMes(&$MESES, $store, $targetISO) {
    $f = mesFile($store, $targetISO);
    if (!isset($MESES[$f])) {
        $MESES[$f] = ['data' => [], 'dirty' => false];
        if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) $MESES[$f]['data'] = $j; }
    }
    return $f;
}
function guardarEn(&$MESES, $store, $targetISO, $slot, $valores) {
    if (empty($valores)) return;
    $f = cargarMes($MESES, $store, $targetISO);
    if (!isset($MESES[$f]['data'][$targetISO])) $MESES[$f]['data'][$targetISO] = [];
    $MESES[$f]['data'][$targetISO][$slot] = $valores;
    $MESES[$f]['dirty'] = true;
}

// ---- HTTP GET simple ----
function http_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'westmeteo-verif/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? $r : null;
}

// ---- horas objetivo, en hora local ----
try { $tz = new DateTimeZone($TZ); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
$t0 = new DateTime('now', $tz);
$t0->setTime((int)$t0->format('H'), 0, 0);      // suelo a la hora en punto
$fmt = 'Y-m-d\TH:i';                             // igual que Open-Meteo (hora local, sin offset)
$k0  = $t0->format($fmt);
$k24 = (clone $t0)->modify('+24 hours')->format($fmt);
$k48 = (clone $t0)->modify('+48 hours')->format($fmt);

$res = ['ok' => true, 'now' => (new DateTime('now', $tz))->format('Y-m-d H:i'),
        't0' => $k0, 'guardado' => []];

// ============ 1) AROME (Open-Meteo) ============
$url = 'https://api.open-meteo.com/v1/forecast'
     . '?latitude=' . $LAT . '&longitude=' . $LON
     . '&hourly=' . $VARS_AROME
     . '&models=meteofrance_arome_france_hd'
     . '&timezone=' . rawurlencode($TZ)
     . '&windspeed_unit=kmh&forecast_days=3';
$raw = http_get($url);
$a = $raw ? json_decode($raw, true) : null;
if (is_array($a) && !empty($a['hourly']['time'])) {
    $H = $a['hourly'];
    $idx = array_flip($H['time']);               // hora -> índice
    $leer = function ($k) use ($H, $idx) {
        if (!isset($idx[$k])) return null;
        $i = $idx[$k];
        $g = function ($arr) use ($i) { return isset($arr[$i]) ? $arr[$i] : null; };
        $v = [
            'temp'   => $g($H['temperature_2m'] ?? []),
            'hum'    => $g($H['relative_humidity_2m'] ?? []),
            'precip' => $g($H['precipitation'] ?? []),
            'wind'   => $g($H['wind_speed_10m'] ?? []),
            'gust'   => $g($H['wind_gusts_10m'] ?? []),
            'dir'    => $g($H['wind_direction_10m'] ?? []),
        ];
        // si todo es null, la hora está fuera del alcance de AROME
        $hay = false; foreach (['temp','hum','precip','wind','gust'] as $kk) if ($v[$kk] !== null) { $hay = true; break; }
        if (!$hay) return null;
        $v['captured'] = date('Y-m-d\TH:i');
        return $v;
    };
    $f48 = $leer($k48);
    $f24 = $leer($k24);
    if ($f48) { guardarEn($MESES, $store, $k48, 'f48', $f48); $res['guardado'][] = "f48 -> $k48"; }
    if ($f24) { guardarEn($MESES, $store, $k24, 'f24', $f24); $res['guardado'][] = "f24 -> $k24"; }
    if (!$f48) $res['aviso_arome48'] = "AROME aún no cubre $k48 (se cogerá más adelante)";
} else {
    $res['error_arome'] = 'Open-Meteo no devolvió datos de AROME';
}

// ============ 2) Estación (reutiliza estacion.php) ============
$raw = http_get($EST_URL);
$e = $raw ? json_decode($raw, true) : null;
if (is_array($e) && !empty($e['ok']) && !empty($e['groups'])) {
    // indexar items por (group-key, label)
    $pick = function ($groupKey, $label) use ($e) {
        foreach ($e['groups'] as $g) {
            if (($g['key'] ?? '') !== $groupKey) continue;
            foreach ($g['items'] as $it) {
                if (($it['label'] ?? '') === $label) {
                    $val = trim((string)($it['value'] ?? ''));
                    if ($val === '' || $val === '–' || $val === '-') return null;
                    // el valor puede venir como "12.3" o "N (123°)": nos quedamos con el número inicial
                    if (preg_match('/-?\d+(\.\d+)?/', $val, $m)) return (float)$m[0];
                    return null;
                }
            }
        }
        return null;
    };
    $obs = [];
    $map = [
        'temp'       => ['exterior', 'Temperatura'],
        'hum'        => ['exterior', 'Humedad'],
        'wind'       => ['viento',   'Velocidad'],
        'gust'       => ['viento',   'Racha'],
        'rain_daily' => ['lluvia',   'Hoy'],
    ];
    foreach ($map as $k => $gl) { $v = $pick($gl[0], $gl[1]); if ($v !== null) $obs[$k] = $v; }
    if (!empty($obs)) {
        $obs['captured'] = date('Y-m-d\TH:i');
        guardarEn($MESES, $store, $k0, 'obs', $obs);
        $res['guardado'][] = "obs -> $k0";
    } else {
        $res['error_estacion'] = 'estacion.php respondió pero no se pudo parsear ningún valor';
    }
} else {
    $res['error_estacion'] = 'estacion.php no respondió o sin datos';
}

// ============ 3) escribir ficheros de mes tocados ============
foreach ($MESES as $f => $m) {
    if (!empty($m['dirty'])) {
        ksort($m['data']);   // ordenado por hora objetivo
        @file_put_contents($f, json_encode($m['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

echo json_encode($res, JSON_UNESCAPED_UNICODE);
