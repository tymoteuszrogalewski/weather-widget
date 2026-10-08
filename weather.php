<?php
/**
 * weather.php — a tiny 48-hour weather widget (today + tomorrow) in one PHP file.
 *
 * Data: Open-Meteo (free, no API key). The forecast is cached in a file for 30 minutes, so the page
 * draws instantly and survives a missing internet connection (it then shows the last forecast and
 * its age).
 *
 *   weather.php          -> HTML page with the widget (put it in an <iframe> or open it directly)
 *   weather.php?svg=1    -> only the SVG image (use it as <img src="weather.php?svg=1">)
 *
 * The chart is made to be read FROM A DISTANCE (e.g. a tablet on the wall). Every piece of
 * information has its own visual channel, so they do not drown each other out:
 *   top bar    — cloud cover (light blue = sun, dark grey = clouds)
 *   red line   — temperature, with sunrise / daytime max / sunset values on the curve
 *   green bars — precipitation mm/h, from the bottom; the snow part of a bar is blue, ❄ over
 *                the biggest snowfall of the day
 *   bottom bar — wind speed, coloured like a meteogram (light blue calm ... red storm)
 *   arrows     — wind direction every 2 hours
 *   blue line  — now
 * The chart always starts at today's midnight and does not move with the hour.
 */

// ---------- SETTINGS ----------
$LAT = 52.23;              // your location
$LON = 21.01;
$CACHE_MIN = 30;           // how often to download a new forecast

// Temperature, clouds and wind come from ONE model. UKMO (UK Met Office) is close to what the
// best local high-resolution forecasts show in Central Europe. Anywhere else 'best_match' is a
// safe choice — Open-Meteo then picks the best model for your location by itself.
$BASE_MODEL = 'ukmo_seamless';

// Precipitation is the MEDIAN of several models. Rain is very local, a single model often misses
// it or moves it by a few hours — the median is not fooled by one model and catches what most of
// them see. Use an odd number of models. Regional models (icon_d2, dmi) only cover Europe;
// outside their area they return nothing and are simply skipped.
$RAIN_MODELS = ['ukmo_seamless', 'icon_eu', 'icon_d2', 'ecmwf_ifs025', 'dmi_harmonie_arome_europe'];
// ------------------------------


// ---------- DOWNLOAD (with file cache) ----------
$models = array_values(array_unique(array_merge([$BASE_MODEL], $RAIN_MODELS)));
$url = 'https://api.open-meteo.com/v1/forecast'
     . '?latitude=' . $LAT . '&longitude=' . $LON
     . '&hourly=temperature_2m,cloud_cover,wind_speed_10m,wind_direction_10m,precipitation,snowfall'
     . '&daily=sunrise,sunset'
     . '&timezone=auto&forecast_days=2&models=' . implode(',', $models);

$cache = sys_get_temp_dir() . '/weather_widget_' . md5($url) . '.json';
$fresh = is_file($cache) && time() - filemtime($cache) < $CACHE_MIN * 60;
if (!$fresh) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // No connection = keep the old forecast. An old forecast is much better than an empty widget.
    if ($raw !== false && $code === 200 && strpos($raw, '"hourly"') !== false) file_put_contents($cache, $raw);
}
$d = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;

if (!$d || empty($d['hourly']['time'])) {
    if (isset($_GET['svg'])) header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 680 40"><text x="10" y="25" fill="#777" '
       . 'font-family="sans-serif" font-size="14">No weather data yet — check the internet connection.</text></svg>';
    exit;
}
date_default_timezone_set($d['timezone'] ?? 'UTC');   // the times from Open-Meteo are local times
$age = time() - filemtime($cache);                    // forecast age in seconds


// ---------- DATA: one row per hour ----------
function median($v) {
    if (!$v) return null;
    sort($v);
    $c = count($v);
    return ($c % 2) ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
}
// With several models Open-Meteo adds the model name to every field: temperature_2m_ukmo_seamless.
function field($h, $name, $model, $i) {
    return $h[$name . '_' . $model][$i] ?? null;
}

$h = $d['hourly'];
$rows = [];
foreach ($h['time'] as $i => $t) {
    $rain = []; $snow = [];
    foreach ($RAIN_MODELS as $m) {
        $p = field($h, 'precipitation', $m, $i);
        $s = field($h, 'snowfall', $m, $i);
        if ($p !== null) $rain[] = (float)$p;
        if ($s !== null) $snow[] = (float)$s;
    }
    $rows[] = [
        'ts'     => strtotime($t),
        'temp'   => (float)field($h, 'temperature_2m', $BASE_MODEL, $i),
        'clouds' => (int)field($h, 'cloud_cover', $BASE_MODEL, $i),
        'wind'   => (float)field($h, 'wind_speed_10m', $BASE_MODEL, $i),
        'wdir'   => (int)field($h, 'wind_direction_10m', $BASE_MODEL, $i),
        'precip' => round((float)median($rain), 1),   // mm of water (rain + snow)
        'snow'   => round((float)median($snow), 1),   // cm of fresh snow
    ];
}
$days = [];
foreach ($d['daily']['time'] as $i => $day) {
    $days[] = [
        'd'       => $day,
        'sunrise' => strtotime($d['daily']['sunrise_' . $BASE_MODEL][$i] ?? $day . 'T06:00'),   // timestamps
        'sunset'  => strtotime($d['daily']['sunset_'  . $BASE_MODEL][$i] ?? $day . 'T18:00'),
    ];
}


// ---------- SVG geometry. Bands from the top: hours, clouds, chart, wind, arrows ----------
$W = 680;
$L = 32;  $R = 6;                            // left margin = just the scale
$Y_HRS   = 0;   $H_HRS   = 18;
$Y_CLOUD = 19;  $H_CLOUD = 14;
$Y_PLOT  = 36;  $H_PLOT  = 108;
$Y_WIND  = 147; $H_WIND  = 14;
$Y_ARR   = 164; $H_ARR   = 17;
$H = $Y_ARR + $H_ARR;

$n = count($rows);
$colW = ($W - $L - $R) / $n;

// Temperature scale with symmetric headroom (10% of the day's range, at least 2 degrees),
// so the value labels above the peak and below the dip stay inside the chart.
$temps = array_column($rows, 'temp');
$pad  = max(2.0, (max($temps) - min($temps)) * 0.10);
$tMin = floor(min($temps) - $pad);
$tMax = ceil(max($temps) + $pad);
if ($tMax - $tMin < 6) $tMax = $tMin + 6;

$xOf = fn($i) => $L + $i * $colW;
$yOf = fn($t) => $Y_PLOT + $H_PLOT - ($t - $tMin) / ($tMax - $tMin) * $H_PLOT;
$hourOf = fn($row) => (int)date('G', $row['ts']);

// Wind colours like a classic meteogram (thresholds in m/s, converted to km/h).
// Light = calm, then darker blue, green, yellow and red for a storm.
function windColor($kmh) {
    $s = [
        [ 7.2, '#bfe3f5'], [10.8, '#8fcdec'], [14.4, '#57ace0'], [18.0, '#2f7fc4'], [21.6, '#1b558f'],
        [25.2, '#2e9e57'], [28.8, '#49bd63'], [32.4, '#7ed957'], [39.6, '#c3e04a'],
        [46.8, '#ffc107'], [57.6, '#ff8c00'], [72.0, '#ff5722'],
    ];
    foreach ($s as [$max, $c]) if ($kmh < $max) return $c;
    return '#e53935';
}
// Clear sky = light blue, full cloud cover = dark grey.
function cloudColor($pct) {
    $f = max(0, min(100, $pct)) / 100;
    return sprintf('#%02x%02x%02x', 158 - $f * 122, 208 - $f * 164, 246 - $f * 192);
}

ob_start();
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="xMidYMid meet">
  <style>
    .gl{stroke:rgba(255,255,255,0.30);stroke-width:1;stroke-dasharray:2 3}
    .ax{fill:#b0b0b0;font:700 18px sans-serif}
    .hr{fill:#9a9a9a;font:600 14px sans-serif}
  </style>
  <defs>
    <?php // Clouds and wind as ONE gradient each: smooth changes show when it clears up or picks up. ?>
    <linearGradient id="wxCloud" x1="0" y1="0" x2="1" y2="0">
    <?php foreach ($rows as $i => $x): ?>
      <stop offset="<?= round(($i + 0.5) / $n * 100, 2) ?>%" stop-color="<?= cloudColor($x['clouds']) ?>"/>
    <?php endforeach; ?>
    </linearGradient>
    <linearGradient id="wxWind" x1="0" y1="0" x2="1" y2="0">
    <?php foreach ($rows as $i => $x): ?>
      <stop offset="<?= round(($i + 0.5) / $n * 100, 2) ?>%" stop-color="<?= windColor($x['wind']) ?>"/>
    <?php endforeach; ?>
    </linearGradient>
    <?php // Arrow drawn in SVG, not a Unicode character — it looks the same on every system. ?>
    <g id="wxArrow">
      <path d="M0 7.8 V-6.5 M-4.2 -2.3 L0 -7.3 L4.2 -2.3" fill="none" stroke="#7fb0ff"
            stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
    </g>
  </defs>

  <?php // HOURS on top, every 4 h
  foreach ($rows as $i => $x): if ($hourOf($x) % 4) continue; ?>
    <text class="hr" x="<?= round($xOf($i) + $colW / 2, 1) ?>" y="<?= $Y_HRS + 14 ?>" text-anchor="middle"><?= $hourOf($x) ?></text>
  <?php endforeach; ?>

  <rect x="<?= $L ?>" y="<?= $Y_CLOUD ?>" width="<?= $W - $L - $R ?>" height="<?= $H_CLOUD ?>" fill="url(#wxCloud)"/>

  <?php // NIGHT slightly darker — from the exact sunset to the exact sunrise of each day.
  // $xAt = position of any moment on the X axis.
  $t0  = $rows[0]['ts'];
  $xAt = fn($ts) => $L + ($ts - $t0) / 3600 * $colW;
  $clipX = fn($x) => max($L, min($W - $R, $x));
  foreach ($days as $dd):
      $d0 = strtotime($dd['d'] . ' 00:00:00');
      foreach ([[$d0, $dd['sunrise']], [$dd['sunset'], $d0 + 86400]] as [$a, $b]):
          $x1 = $clipX($xAt($a)); $x2 = $clipX($xAt($b));
          if ($x2 - $x1 < 0.5) continue; ?>
    <rect x="<?= round($x1, 1) ?>" y="<?= $Y_PLOT ?>" width="<?= round($x2 - $x1, 1) ?>" height="<?= $H_PLOT ?>" fill="#3a3a41"/>
  <?php endforeach; endforeach; ?>

  <?php // horizontal grid, 4-6 lines
  $span = $tMax - $tMin;
  $step = $span > 34 ? 10 : ($span > 20 ? 5 : ($span > 10 ? 4 : ($span > 5 ? 2 : 1)));
  for ($t = $tMin; $t <= $tMax; $t += $step): $y = round($yOf($t), 1); ?>
    <line class="gl" x1="<?= $L ?>" y1="<?= $y ?>" x2="<?= $W - $R ?>" y2="<?= $y ?>"/>
    <text class="ax" x="<?= $L - 5 ?>" y="<?= $y + 6 ?>" text-anchor="end"><?= $t ?></text>
  <?php endfor; ?>

  <?php // vertical grid every 4 h
  foreach ($rows as $i => $x): if ($hourOf($x) % 4) continue; $xx = round($xOf($i), 1); ?>
    <line class="gl" x1="<?= $xx ?>" y1="<?= $Y_PLOT ?>" x2="<?= $xx ?>" y2="<?= $Y_PLOT + $H_PLOT ?>"/>
  <?php endforeach; ?>

  <?php // PRECIPITATION — green bars on a SQUARE-ROOT scale (4 mm/h = 62% of the height).
  // On a linear scale drizzle (0.1-0.4 mm/h, the most common rain) is invisible; the square root
  // lifts 0.2 mm to a clearly visible bar, while heavy rain still does not go off the chart.
  // SNOW: `snow` is cm of fresh snow, `precip` all precipitation in mm of water (7 cm snow = 10 mm
  // water). That share of the bar, from the top, is blue — rain with snow gives a split bar.
  $snowTop = [];
  foreach ($rows as $i => $x) {
      if ($x['snow'] <= 0) continue;
      $day = date('Y-m-d', $x['ts']);
      if (!isset($snowTop[$day]) || $x['snow'] > $snowTop[$day][1]) $snowTop[$day] = [$i, $x['snow']];
  }
  $snowIdx = array_column($snowTop, 0);
  foreach ($rows as $i => $x):
      $p = $x['precip']; if ($p <= 0) continue;
      $bh = max(4, sqrt(min(1.0, $p / 4.0)) * ($H_PLOT * 0.62));
      $sh = $bh * min(1.0, ($x['snow'] / 0.7) / $p);
      $bx = round($xOf($i) + 1, 1); $bw = max(2, round($colW - 2, 1)); $by = $Y_PLOT + $H_PLOT - $bh; ?>
    <rect x="<?= $bx ?>" y="<?= round($by, 1) ?>" width="<?= $bw ?>" height="<?= round($bh, 1) ?>" fill="#22c55e" opacity=".92"/>
    <?php if ($sh > 0): ?>
    <rect x="<?= $bx ?>" y="<?= round($by, 1) ?>" width="<?= $bw ?>" height="<?= round($sh, 1) ?>" fill="#60a5fa" opacity=".95"/>
    <?php endif; if (in_array($i, $snowIdx, true)): ?>
    <text x="<?= round($bx + $bw / 2, 1) ?>" y="<?= round($by - 3, 1) ?>" text-anchor="middle" font-size="11" fill="#60a5fa">❄</text>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php // midnight line between the days
  foreach ($rows as $i => $x): if ($i === 0 || $hourOf($x) !== 0) continue; ?>
    <line x1="<?= round($xOf($i), 1) ?>" y1="<?= $Y_HRS ?>" x2="<?= round($xOf($i), 1) ?>" y2="<?= $Y_PLOT + $H_PLOT ?>"
          stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>
  <?php endforeach; ?>

  <?php // TEMPERATURE
  $pts = [];
  foreach ($rows as $i => $x) $pts[] = round($xOf($i) + $colW / 2, 1) . ',' . round($yOf($x['temp']), 1); ?>
  <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="#ff5f45" stroke-width="2.6"
            stroke-linecap="round" stroke-linejoin="round"/>

  <?php // LABELS ON THE CURVE: sunrise, daytime max (bigger) and sunset of each day. Three numbers say
  // more than min/max — is it cold in the morning, does the evening stay warm.
  // Sunrise and sunset sit at their exact minute, right on the day/night edge; the temperature
  // there is read from the line (the line points are in the middle of each hour).
  $tempAt = function ($ts) use ($rows, $t0) {
      $f = ($ts - $t0) / 3600 - 0.5;
      $i = max(0, min(count($rows) - 2, (int)floor($f)));
      $k = max(0.0, min(1.0, $f - $i));
      return $rows[$i]['temp'] + ($rows[$i + 1]['temp'] - $rows[$i]['temp']) * $k;
  };
  $marks = [];   // [x, temperature, is max]
  foreach ($days as $dd) {
      $marks[] = [$xAt($dd['sunrise']), $tempAt($dd['sunrise']), false];
      $marks[] = [$xAt($dd['sunset']),  $tempAt($dd['sunset']),  false];
      // The max only from DAYTIME hours — over the whole day it sometimes falls at midnight
      // (warm evening, then cooling), and that is not "how warm will it be during the day".
      $best = null; $bestT = -99;
      foreach ($rows as $i => $x) {
          $mid = $x['ts'] + 1800;
          if ($mid < $dd['sunrise'] || $mid > $dd['sunset']) continue;
          if ($x['temp'] > $bestT) { $bestT = $x['temp']; $best = $i; }
      }
      if ($best !== null) $marks[] = [$xOf($best) + $colW / 2, $bestT, true];
  }
  // A sunrise / sunset label close to a daily max would overlap it: within 2 hours it moves to the
  // OUTSIDE (sunset to the right of its dot, sunrise to the left); if the dots almost touch, only
  // the max stays.
  $maxX = array_map(fn($m) => $m[0], array_filter($marks, fn($m) => $m[2]));
  $marks = array_filter($marks, function ($m) use ($maxX, $colW, $L, $W, $R) {
      if ($m[0] < $L || $m[0] > $W - $R) return false;
      if ($m[2]) return true;
      foreach ($maxX as $mx) if (abs($m[0] - $mx) < 0.8 * $colW) return false;
      return true;
  });
  foreach ($marks as [$cx, $t, $isMax]):
      $anchor = $cx < $L + 26 ? 'start' : ($cx > $W - $R - 26 ? 'end' : 'middle');
      $tx = $anchor === 'start' ? $L + 2 : ($anchor === 'end' ? $W - $R - 2 : $cx);
      if (!$isMax) foreach ($maxX as $mx) {
          if (abs($cx - $mx) >= 2 * $colW) continue;
          if ($cx > $mx) { $anchor = 'start'; $tx = $cx + 3; } else { $anchor = 'end'; $tx = $cx - 3; }
      } ?>
    <circle cx="<?= round($cx, 1) ?>" cy="<?= round($yOf($t), 1) ?>" r="2.6" fill="#ff5f45"/>
    <text x="<?= round($tx, 1) ?>" y="<?= round($yOf($t) - 7, 1) ?>" text-anchor="<?= $anchor ?>" fill="#e8e8e8"
          style="font:700 <?= $isMax ? 22 : 17 ?>px sans-serif"><?= round($t) ?>°</text>
  <?php endforeach; ?>

  <?php // NOW — vertical blue line at the current hour and minute
  $nowX = $L + (time() - $rows[0]['ts']) / 3600 * $colW;
  if ($nowX >= $L && $nowX <= $W - $R): ?>
    <line x1="<?= round($nowX, 1) ?>" y1="<?= $Y_CLOUD ?>" x2="<?= round($nowX, 1) ?>" y2="<?= $Y_WIND + $H_WIND ?>"
          stroke="#007bff" stroke-width="3" opacity=".95"/>
  <?php endif; ?>

  <rect x="<?= $L ?>" y="<?= $Y_WIND ?>" width="<?= $W - $L - $R ?>" height="<?= $H_WIND ?>" fill="url(#wxWind)"/>

  <?php // WIND DIRECTION every 2 h. The API gives where the wind comes FROM; the arrow shows where it GOES.
  foreach ($rows as $i => $x): if ($hourOf($x) % 2) continue;
      $rot = ($x['wdir'] + 180) % 360; ?>
    <use href="#wxArrow" transform="translate(<?= round($xOf($i) + $colW / 2, 1) ?>,<?= $Y_ARR + 9 ?>) rotate(<?= $rot ?>)"/>
  <?php endforeach; ?>

  <?php if ($age > 3 * 3600): ?>
    <text x="<?= $W - $R ?>" y="<?= $Y_HRS + 11 ?>" text-anchor="end" style="font:600 10px sans-serif" fill="#7a6a3a">forecast from <?= round($age / 3600) ?> h ago</text>
  <?php endif; ?>
</svg>
<?php
$svg = ob_get_clean();

if (isset($_GET['svg'])) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: max-age=300');
    echo $svg;
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="900">
<title>Weather</title>
<style>
  body{margin:0;background:#1e1e22}
  .wx{max-width:900px;margin:0 auto;padding:8px}
  .wx svg{width:100%;height:auto;display:block}
</style>
</head>
<body>
<div class="wx"><?= $svg ?></div>
</body>
</html>
