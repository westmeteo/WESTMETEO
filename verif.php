<?php
// verif.php — endpoint de LECTURA (solo lectura, sin login). Cruza previsión AROME
// archivada vs observación de la estación y devuelve desviaciones del MODELO.
//
// NO toca sesgos ni corrige nada: solo mide. Recalcula la estadística al vuelo
// (el volumen es pequeño, ~720 horas/mes).
//
// Salida JSON:
//   { ok, updated, rango:{desde,hasta}, vars:[{key,label,unit,h24:{n,bias,mae,rmse},h48:{...}}],
//     temp_franjas:[{label,h24,h48}], recientes:[{t,obs:{...},f24:{...},f48:{...}}] }
//
// Convención de signo del sesgo (bias) = previsto − observado.
//   bias > 0  -> AROME sobreestima ;  bias < 0 -> AROME subestima.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$TZ = 'Europe/Madrid';
try { $tz = new DateTimeZone($TZ); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }

// ---- localizar ficheros de datos (mismo store que la captura) ----
$store = @is_writable(__DIR__ . '/..') ? __DIR__ . '/..' : sys_get_temp_dir();
$now = new DateTime('now', $tz);
$meses = [ $now->format('Ym'), (clone $now)->modify('-1 month')->format('Ym') ];

$data = [];
foreach ($meses as $ym) {
    $f = $store . '/verif_data_' . $ym . '.json';
    if (is_file($f)) {
        $j = json_decode(@file_get_contents($f), true);
        if (is_array($j)) $data += $j;   // union (claves = horas objetivo, no colisionan entre meses)
    }
}
ksort($data);

// ---- definición de variables a evaluar ----
$VARS = [
    ['key' => 'temp',   'label' => 'Temperatura',   'unit' => '°C'],
    ['key' => 'wind',   'label' => 'Viento',        'unit' => 'km/h'],
    ['key' => 'gust',   'label' => 'Racha',         'unit' => 'km/h'],
    ['key' => 'precip', 'label' => 'Precipitación', 'unit' => 'mm'],
    ['key' => 'hum',    'label' => 'Humedad',       'unit' => '%'],
];

// ---- precipitación observada horaria = delta del acumulado diario ----
// obs[t].rain_daily es acumulado del día; el observado de la hora t es
// max(0, daily(t) − daily(t−1h)). En el reset de medianoche el delta es negativo
// (daily baja a ~0): se usa daily(t) para no perder por completo esa hora.
$keys = array_keys($data);
$obsPrecip = [];   // hora objetivo -> mm observados en esa hora
foreach ($keys as $i => $k) {
    $cur = $data[$k]['obs']['rain_daily'] ?? null;
    if ($cur === null) continue;
    $prevK = null;
    if ($i > 0) {
        // hora anterior = k − 1h; comprobar que el key previo es realmente la hora anterior
        try {
            $dt = new DateTime($k, $tz);
            $want = (clone $dt)->modify('-1 hour')->format('Y-m-d\TH:i');
            if (isset($data[$want]['obs']['rain_daily'])) $prevK = $want;
        } catch (Exception $e) {}
    }
    if ($prevK === null) { $obsPrecip[$k] = null; continue; }   // sin hora previa no se puede derivar
    $prev = $data[$prevK]['obs']['rain_daily'];
    $d = $cur - $prev;
    $obsPrecip[$k] = ($d < 0) ? max(0.0, (float)$cur) : round($d, 2);
}

// observado de una variable en una hora (precip via delta)
function obsVal($data, $obsPrecip, $k, $var) {
    if ($var === 'precip') return $obsPrecip[$k] ?? null;
    return $data[$k]['obs'][$var] ?? null;
}

// ---- acumuladores de estadística ----
function nuevoAcc() { return ['n' => 0, 'sum' => 0.0, 'sumabs' => 0.0, 'sumsq' => 0.0]; }
function acumular(&$acc, $err) { $acc['n']++; $acc['sum'] += $err; $acc['sumabs'] += abs($err); $acc['sumsq'] += $err * $err; }
function cerrar($acc) {
    if ($acc['n'] === 0) return ['n' => 0, 'bias' => null, 'mae' => null, 'rmse' => null];
    return [
        'n'    => $acc['n'],
        'bias' => round($acc['sum'] / $acc['n'], 2),
        'mae'  => round($acc['sumabs'] / $acc['n'], 2),
        'rmse' => round(sqrt($acc['sumsq'] / $acc['n']), 2),
    ];
}

$stats = [];                     // key -> ['h24'=>acc,'h48'=>acc]
foreach ($VARS as $v) $stats[$v['key']] = ['h24' => nuevoAcc(), 'h48' => nuevoAcc()];
$franjas = [                     // desglose de temperatura por franja horaria
    'madrugada' => ['label' => 'Madrugada 00-08h', 'h24' => nuevoAcc(), 'h48' => nuevoAcc()],
    'dia'       => ['label' => 'Día 08-20h',       'h24' => nuevoAcc(), 'h48' => nuevoAcc()],
    'noche'     => ['label' => 'Noche 20-24h',     'h24' => nuevoAcc(), 'h48' => nuevoAcc()],
];
function franjaDe($hora) { return $hora < 8 ? 'madrugada' : ($hora < 20 ? 'dia' : 'noche'); }

$recientes = [];
foreach ($keys as $k) {
    $tieneObs = isset($data[$k]['obs']);
    // recopilar observado para la tabla
    $obsRow = [];
    foreach ($VARS as $v) { $ov = obsVal($data, $obsPrecip, $k, $v['key']); if ($ov !== null) $obsRow[$v['key']] = $ov; }

    $hora = (int)substr($k, 11, 2);
    foreach (['f24' => 'h24', 'f48' => 'h48'] as $slot => $hkey) {
        if (!isset($data[$k][$slot])) continue;
        foreach ($VARS as $v) {
            $pv = $data[$k][$slot][$v['key']] ?? null;
            $ov = obsVal($data, $obsPrecip, $k, $v['key']);
            if ($pv === null || $ov === null) continue;
            $err = (float)$pv - (float)$ov;
            acumular($stats[$v['key']][$hkey], $err);
            if ($v['key'] === 'temp') acumular($franjas[franjaDe($hora)][$hkey], $err);
        }
    }

    // fila de casos recientes: solo horas con observación
    if ($tieneObs && !empty($obsRow)) {
        $row = ['t' => $k, 'obs' => $obsRow];
        foreach (['f24', 'f48'] as $slot) {
            if (isset($data[$k][$slot])) {
                $fr = [];
                foreach ($VARS as $v) { $pv = $data[$k][$slot][$v['key']] ?? null; if ($pv !== null) $fr[$v['key']] = $pv; }
                $row[$slot] = $fr;
            }
        }
        $recientes[] = $row;
    }
}

// salida de variables
$outVars = [];
foreach ($VARS as $v) {
    $outVars[] = [
        'key'   => $v['key'],
        'label' => $v['label'],
        'unit'  => $v['unit'],
        'h24'   => cerrar($stats[$v['key']]['h24']),
        'h48'   => cerrar($stats[$v['key']]['h48']),
    ];
}
$outFranjas = [];
foreach ($franjas as $fr) {
    $outFranjas[] = ['label' => $fr['label'], 'h24' => cerrar($fr['h24']), 'h48' => cerrar($fr['h48'])];
}

// últimas 48 horas objetivo con observación, más recientes primero
$recientes = array_slice($recientes, -48);
$recientes = array_reverse($recientes);

echo json_encode([
    'ok'      => true,
    'updated' => $now->format('Y-m-d H:i'),
    'unit'    => ['temp' => '°C', 'wind' => 'km/h', 'gust' => 'km/h', 'precip' => 'mm', 'hum' => '%'],
    'rango'   => ['desde' => $keys ? $keys[0] : null, 'hasta' => $keys ? end($keys) : null],
    'total'   => count($data),
    'vars'    => $outVars,
    'temp_franjas' => $outFranjas,
    'recientes'    => $recientes,
], JSON_UNESCAPED_UNICODE);
