<?php
// estacion.php — lee estaciones Ecowitt (login web, cuenta del usuario que las ve todas)
// y sirve el dato YA corregido. Soporta VARIAS estaciones (Béjar + amigos).
//
//   estacion.php?list=1        -> lista de estaciones {id, nombre} (para el desplegable)
//   estacion.php?est=<id>      -> datos en vivo de esa estación, con sus sesgos aplicados
//   (&nocache=1 fuerza recarga)
//
// Flujo por estación: login web -> cookie de sesión -> index/home?device_id ->
//                     selecciona sensores -> aplica sesgos (factor+offset, franjas horarias) -> JSON.
//
// Las credenciales NO van aquí (repo público): se leen de estacion_config.php,
// que debe estar FUERA de public_html (o, si no, junto a este fichero).
// Los sesgos (no son secretos) van en estacion_sesgos.json, organizados por estación.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

// ---------- config con credenciales + registro de estaciones ----------
$cfg = @include __DIR__ . '/../estacion_config.php';
if (!is_array($cfg)) $cfg = @include __DIR__ . '/estacion_config.php';
if (!is_array($cfg) || empty($cfg['account']) || empty($cfg['password'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config',
        'msg' => 'Falta estacion_config.php con account y password']);
    exit;
}
$ACCOUNT  = $cfg['account'];
$PASSWORD = $cfg['password'];
$BASE     = 'https://www.ecowitt.net';

// registro de estaciones: id => {nombre, device_id, [account, password]}
// La cuenta por defecto ve todas; una estación puede traer credenciales propias si hiciera falta.
if (!empty($cfg['estaciones']) && is_array($cfg['estaciones'])) {
    $ESTACIONES = $cfg['estaciones'];
} else {
    $ESTACIONES = ['bejar' => ['nombre' => 'Béjar', 'device_id' => (string)($cfg['device_id'] ?? '120852')]];
}

// ---------- ?list=1 : solo la lista (sin login) ----------
if (isset($_GET['list'])) {
    $lst = [];
    foreach ($ESTACIONES as $id => $e) {
        $lst[] = ['id' => (string)$id, 'nombre' => (string)($e['nombre'] ?? $id)];
    }
    echo json_encode(['ok' => true, 'estaciones' => $lst], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- estación seleccionada ----------
$ids   = array_keys($ESTACIONES);
$estId = isset($_GET['est']) ? (string)$_GET['est'] : $ids[0];
if (!isset($ESTACIONES[$estId])) $estId = $ids[0];
$ST        = $ESTACIONES[$estId];
$DEVICE_ID = (string)($ST['device_id'] ?? '120852');
$ACCT      = (string)($ST['account']  ?? $ACCOUNT);
$PASS      = (string)($ST['password'] ?? $PASSWORD);
$NOMBRE    = (string)($ST['nombre']   ?? $estId);

// ---------- almacenamiento (parent de public_html si es escribible, si no /tmp) ----------
$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
$safeId     = preg_replace('/[^a-z0-9_-]/i', '', $estId);
$cacheFile  = $store . '/estacion_cache_' . $safeId . '.json';        // caché por estación
$cookieFile = $store . '/estacion_cookie_' . md5($ACCT) . '.txt';     // cookie por cuenta

// ---------- caché de 5 min (salvo ?nocache=1) ----------
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 300) && !isset($_GET['nocache'])) {
    echo file_get_contents($cacheFile);
    exit;
}

// ---------- sesgos de esta estación (admite formato antiguo de una sola estación) ----------
$sesgosAll = [];
$sf = @file_get_contents(__DIR__ . '/estacion_sesgos.json');
if ($sf !== false) { $j = json_decode($sf, true); if (is_array($j)) $sesgosAll = $j; }
if (isset($sesgosAll[$estId]) && is_array($sesgosAll[$estId])) {
    $sesgos = $sesgosAll[$estId];
} elseif (isset($sesgosAll['temp']) || isset($sesgosAll['rain'])) {
    $sesgos = $sesgosAll; // formato antiguo: mapa de sensores plano
} else {
    $sesgos = [];
}

// hora local actual (para las franjas horarias), en minutos desde medianoche
try { $tz = new DateTimeZone('Europe/Madrid'); $nowdt = new DateTime('now', $tz); }
catch (Exception $e) { $nowdt = new DateTime('now'); }
$GLOBALS['WM_NOWMIN'] = (int)$nowdt->format('H') * 60 + (int)$nowdt->format('i');

function wm_hm($s) { $p = explode(':', (string)$s); return ((int)$p[0]) * 60 + (int)($p[1] ?? 0); }

// corregido = bruto * factor + offset. Si el sensor define "windows", la franja horaria
// que contiene la hora actual manda (si ninguna coincide, se usa el factor/offset por defecto).
function corr($val, $key, $sesgos) {
    if ($val === null || $val === '' || !is_numeric($val)) return null;
    $s = $sesgos[$key] ?? [];
    if (!is_array($s)) return (float)$val;
    $f = isset($s['factor']) ? (float)$s['factor'] : 1.0;
    $o = isset($s['offset']) ? (float)$s['offset'] : 0.0;
    if (!empty($s['windows']) && is_array($s['windows'])) {
        $nm = $GLOBALS['WM_NOWMIN'];
        foreach ($s['windows'] as $w) {
            $from = wm_hm($w['from'] ?? '00:00');
            $to   = wm_hm($w['to'] ?? '24:00');
            $in = ($from <= $to) ? ($nm >= $from && $nm < $to) : ($nm >= $from || $nm < $to);
            if ($in) {
                if (isset($w['factor'])) $f = (float)$w['factor'];
                if (isset($w['offset'])) $o = (float)$w['offset'];
                break;
            }
        }
    }
    return (float)$val * $f + $o;
}

// ---------- HTTP ----------
function ec_curl($url, $post = null, $cookieFile = null) {
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'westmeteo-estacion/1.0',
    ];
    if ($cookieFile) { $opt[CURLOPT_COOKIEJAR] = $cookieFile; $opt[CURLOPT_COOKIEFILE] = $cookieFile; }
    if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $opt);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

function ec_login($BASE, $ACCOUNT, $PASSWORD, $cookieFile) {
    $r = ec_curl($BASE . '/user/site/login', http_build_query([
        'account'  => $ACCOUNT,
        'password' => $PASSWORD,
    ]), $cookieFile);
    $j = json_decode($r, true);
    return (is_array($j) && ($j['errcode'] ?? '') === '0');
}

function ec_home($BASE, $DEVICE_ID, $cookieFile) {
    $r = ec_curl($BASE . '/index/home', http_build_query(['device_id' => $DEVICE_ID]), $cookieFile);
    return json_decode($r, true);
}

// ---------- obtener datos (reintenta con login si la sesión caducó) ----------
$home = is_file($cookieFile) ? ec_home($BASE, $DEVICE_ID, $cookieFile) : null;
if (!is_array($home) || ($home['errcode'] ?? '') !== '0') {
    if (!ec_login($BASE, $ACCT, $PASS, $cookieFile)) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'login', 'msg' => 'No se pudo iniciar sesión en Ecowitt']);
        exit;
    }
    $home = ec_home($BASE, $DEVICE_ID, $cookieFile);
}
if (!is_array($home) || ($home['errcode'] ?? '') !== '0' || empty($home['data'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'upstream', 'msg' => ($home['errmsg'] ?? 'sin datos')]);
    exit;
}
$D = $home['data'];

// ---------- helpers de lectura ----------
function raw($D, $block, $field) {
    return $D[$block]['data'][$field]['value'] ?? null;
}
function unit_of($D, $block, $field, $fallback = '') {
    $u = $D[$block]['data'][$field]['unit'] ?? $fallback;
    return str_replace("\u{2103}", "\u{00B0}C", $u); // ℃ -> °C
}
function clean_time($D, $block, $field) {
    $t = $D[$block]['data'][$field]['value'] ?? '';
    return trim(str_replace('Today', '', $t));
}
function fmt($v, $dec) { return $v === null ? '–' : number_format($v, $dec, '.', ''); }

$RUMBOS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSO','SO','OSO','O','ONO','NO','NNO'];

// ---------- construir salida por grupos, aplicando sesgos ----------
$uT = unit_of($D, 'temp', 'tempf', "\u{00B0}C");
$uW = unit_of($D, 'wind', 'windspeedmph', 'km/h');
$uP = unit_of($D, 'pressure', 'baromrelin', 'hPa');
$uS = unit_of($D, 'so_uv', 'solarradiation', 'W/m2');

$temp  = corr(raw($D,'temp','tempf'),        'temp',     $sesgos);
$feels = corr(raw($D,'temp','sendible_temp'),'feels',    $sesgos);
$hum   = corr(raw($D,'temp','humidity'),     'humidity', $sesgos);
$dew   = corr(raw($D,'temp','drew_temp'),    'dew',      $sesgos);
$tmax  = corr(raw($D,'temp','max_daily_tempf'),'temp',   $sesgos);
$tmin  = corr(raw($D,'temp','min_daily_tempf'),'temp',   $sesgos);
if ($hum !== null) $hum = max(0, min(100, $hum));

$wind  = corr(raw($D,'wind','windspeedmph'),   'wind', $sesgos);
$gust  = corr(raw($D,'wind','windgustmph'),    'gust', $sesgos);
$gmax  = corr(raw($D,'wind','max_daily_windgustmph'),'gust', $sesgos);
$wdir  = raw($D,'wind','winddir');
$rumbo = ($wdir !== null && is_numeric($wdir)) ? $RUMBOS[((int)round($wdir/22.5))%16] : null;
if ($wind !== null) $wind = max(0, $wind);
if ($gust !== null) $gust = max(0, $gust);

$rrate = corr(raw($D,'rain','rainratein'),   'rain', $sesgos);
$rday  = corr(raw($D,'rain','dailyrainin'),  'rain', $sesgos);
$rmon  = corr(raw($D,'rain','monthlyrainin'),'rain', $sesgos);
$ryear = corr(raw($D,'rain','yearlyrainin'), 'rain', $sesgos);

$pres  = corr(raw($D,'pressure','baromrelin'),'pressure', $sesgos);
$ptrV  = raw($D,'pressure','baromrelin_increment');
$ptrS  = $D['pressure']['data']['baromrelin_increment']['symbol'] ?? 0;
$ptr   = ($ptrV !== null && is_numeric($ptrV)) ? (($ptrS < 0 ? '-' : '+') . number_format((float)$ptrV,1,'.','')) : null;

$solar = corr(raw($D,'so_uv','solarradiation'),'solar', $sesgos);
$uv    = corr(raw($D,'so_uv','uv'),            'uv',    $sesgos);
$sunr  = clean_time($D,'so_uv','sunrise_time');
$suns  = clean_time($D,'so_uv','sunset_time');

// ¿se ha aplicado alguna corrección real?
$corr_on = false;
foreach ($sesgos as $s) {
    if (!is_array($s)) continue;
    if ((isset($s['factor']) && (float)$s['factor'] != 1.0) || (isset($s['offset']) && (float)$s['offset'] != 0.0)) { $corr_on = true; break; }
    if (!empty($s['windows'])) { $corr_on = true; break; }
}

$out = [
    'ok'        => true,
    'id'        => $estId,
    'station'   => $NOMBRE,
    'time'      => clean_time($D, 'temp', 'tempf'),
    'corrected' => $corr_on,
    'groups'    => [
        ['key'=>'exterior','title'=>'Exterior','items'=>[
            ['label'=>'Temperatura','value'=>fmt($temp,1),'unit'=>$uT,'big'=>true],
            ['label'=>'Sensación','value'=>fmt($feels,1),'unit'=>$uT],
            ['label'=>'Humedad','value'=>fmt($hum,0),'unit'=>'%'],
            ['label'=>'Punto de rocío','value'=>fmt($dew,1),'unit'=>$uT],
            ['label'=>'Máx / Mín hoy','value'=>fmt($tmax,1).' / '.fmt($tmin,1),'unit'=>$uT],
        ]],
        ['key'=>'viento','title'=>'Viento','items'=>[
            ['label'=>'Velocidad','value'=>fmt($wind,1),'unit'=>$uW,'big'=>true],
            ['label'=>'Racha','value'=>fmt($gust,1),'unit'=>$uW],
            ['label'=>'Dirección','value'=>($rumbo? $rumbo.' ('.((int)round($wdir)).'°)':'–'),'unit'=>''],
            ['label'=>'Racha máx hoy','value'=>fmt($gmax,1),'unit'=>$uW],
        ]],
        ['key'=>'lluvia','title'=>'Lluvia','items'=>[
            ['label'=>'Ritmo','value'=>fmt($rrate,1),'unit'=>'mm/h','big'=>true],
            ['label'=>'Hoy','value'=>fmt($rday,1),'unit'=>'mm'],
            ['label'=>'Mes','value'=>fmt($rmon,1),'unit'=>'mm'],
            ['label'=>'Año','value'=>fmt($ryear,1),'unit'=>'mm'],
        ]],
        ['key'=>'presion','title'=>'Presión y solar','items'=>[
            ['label'=>'Presión','value'=>fmt($pres,1),'unit'=>$uP,'big'=>true],
            ['label'=>'Tendencia 3 h','value'=>($ptr ?? '–'),'unit'=>$uP],
            ['label'=>'Radiación','value'=>fmt($solar,0),'unit'=>$uS],
            ['label'=>'Índice UV','value'=>fmt($uv,0),'unit'=>''],
            ['label'=>'Orto / Ocaso','value'=>($sunr && $suns? $sunr.' / '.$suns : '–'),'unit'=>''],
        ]],
    ],
];

$json = json_encode($out, JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
echo $json;
