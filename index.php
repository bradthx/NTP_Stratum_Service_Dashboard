<?php
// Copyright (c) 2026 Brad Boegler <bradthx@gmail.com>
// MIT License: https://opensource.org/licenses/MIT

Require_once __DIR__ . '/lib.php';

// Auto-refresh (seconds)
$refresh = 10;

// Collect data
$now = run_cmd('date');
$uptime = run_cmd('uptime');
$now_pretty = run_cmd('date_pretty');
$now_utc = run_cmd('date_utc');

// DST flag: 1 = DST in effect, 0 = not in effect
$is_dst = (date('I') === '1');



$ntpstat_raw = run_cmd('ntpstat');
$ntpstat = parse_ntpstat($ntpstat_raw['out']);

$ntpq_peers_raw = run_cmd('ntpq_peers');
$peers = parse_ntpq_peers($ntpq_peers_raw['out']);

$ntpq_rv_raw = run_cmd('ntpq_rv');
$rv = parse_ntpq_rv($ntpq_rv_raw['out']);

$ntpq_as_raw = run_cmd('ntpq_as');
$assocs = parse_ntpq_as($ntpq_as_raw['out']);


// Map associations by their "ind" so we can align 1:1 with peers by row order
$assocs_by_ind = array();
foreach ($assocs as $a) {
    $assocs_by_ind[$a['ind']] = $a;
}

// Merge assoc fields into peers
$peers_merged = array();
foreach ($peers as $p) {
    $a = isset($assocs_by_ind[$p['idx']]) ? $assocs_by_ind[$p['idx']] : null;

    $p['as_reach'] = ($a && isset($a['reach'])) ? $a['reach'] : '';
    $p['as_condition'] = ($a && isset($a['condition'])) ? $a['condition'] : '';
    $p['as_last_event'] = ($a && isset($a['last_event'])) ? $a['last_event'] : '';

    $peers_merged[] = $p;
}


// Services
$ntpd_active = run_cmd('systemctl_ntpd');
$ntpd_status = run_cmd('systemctl_ntpd_status');
$gpsd_active = run_cmd('systemctl_gpsd');
$gpsd_status = run_cmd('systemctl_gpsd_status');

$ps_ntpd = run_cmd('ps_ntpd');
$ps_gpsd = run_cmd('ps_gpsd');

$svc_ntpd = service_status_block('ntpd', $ntpd_active['out'], $ntpd_status['out'], $ps_ntpd['out']);
$svc_gpsd = service_status_block('gpsd', $gpsd_active['out'], $gpsd_status['out'], $ps_gpsd['out']);

$pps_devices = list_pps_devices();
$ppswatch_raw = run_cmd('ppswatch_sample');
$ppswatch_samples = parse_ppswatch($ppswatch_raw['out']);




// GPS data
$gpspipe_raw = run_cmd('gpspipe');
$gpsnmea_raw = run_cmd('gpspipe_nmea');
$nmea = parse_nmea_stream($gpsnmea_raw['out']);

$gps = parse_gpspipe_json($gpspipe_raw['out']);
$tpv = $gps['tpv'];
$sky = $gps['sky'];

// Top summary status
$top_state = $ntpstat['state'];
$top_badge = badge_class_for($top_state);


// --- Time card inputs ---
$now_pretty = run_cmd('date_pretty');
$now_utc    = run_cmd('date_utc');
$epoch      = run_cmd('date_epoch');
$iso        = run_cmd('date_iso');
$utcoff     = run_cmd('date_utcoff');

$is_dst = (date('I') === '1');

// NTP rv (needed for leap + last sync age + some nerd metrics)
$ntpq_rv_raw = run_cmd('ntpq_rv');
$rv = parse_ntpq_rv($ntpq_rv_raw['out']);

// Last sync age from reftime hex
$reftime_hex = isset($rv['kv']['reftime']) ? $rv['kv']['reftime'] : '';
$reftime_unix = ntp_hex_to_unix_seconds($reftime_hex);
$age = ($reftime_unix !== null) ? (time() - $reftime_unix) : null;
$age_str = fmt_age_seconds($age);

// Leap indicator badge from rv leap field
$leap_val = isset($rv['kv']['leap']) ? $rv['kv']['leap'] : '';
$leap_badge = leap_badge($leap_val);

// Leap schedule + TAI-UTC from leap-seconds.list
$leap_file = find_leap_seconds_file();
$leap_entries = parse_leap_seconds_list($leap_file);
$leap_list_info = leap_info_from_list($leap_entries, time());
$tai_utc = $leap_list_info['tai'];
$next_leap_text = $leap_list_info['next_text'];

// Kernel timekeeping
$adjt_raw = run_cmd('adjtimex_print');
$adjt = parse_adjtimex_print($adjt_raw['out']);
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="<?php echo (int)$refresh; ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NTP/GPS Time Server Status</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="header">
    <h1>NTP / GPS Time Server Status
      <span class="badge <?php echo $top_badge; ?>"><?php echo html(strtoupper($top_state)); ?></span>
    </h1>
    <div class="sub">
      Time: <?php echo html($now['out']); ?> · <?php echo html($uptime['out']); ?> · Auto-refresh: <?php echo (int)$refresh; ?>s
    </div>
  </div>

  <div class="wrap">


<div class="row">
  <div class="col col-12">
    <div class="card">
      <h2>NTP TIME
        <span class="badge <?php echo $leap_badge['class']; ?>"><?php echo html($leap_badge['text']); ?></span>
        <?php $dst_badge = $is_dst ? 'ok' : 'warn'; ?>
        <span class="badge <?php echo $dst_badge; ?>"><?php echo $is_dst ? 'DST: YES' : 'DST: NO'; ?></span>
<?php
  $stratum = isset($rv['kv']['stratum']) ? intval($rv['kv']['stratum']) : -1;

  if ($stratum === 1) {
      $stratum_class = 'ok';
      $stratum_text  = 'STRATUM 1';
  } elseif ($stratum > 1) {
      $stratum_class = 'bad';
      $stratum_text  = 'STRATUM ' . $stratum;
  } else {
      $stratum_class = 'warn';
      $stratum_text  = 'STRATUM ?';
  }
?>
<span class="badge <?php echo $stratum_class; ?>"><?php echo $stratum_text; ?></span>

      </h2>

      <div class="content">
        <div style="font-size:22px; font-weight:bold; line-height:1.2;">
          <?php echo html($now_pretty['out']); ?>
        </div>

        <?php if ($next_leap_text !== '') { ?>
          <div style="margin-top:10px;">
            <span class="badge warn">LEAP SCHEDULE</span>
            <span class="small"><?php echo html($next_leap_text); ?></span>
          </div>
        <?php } else { ?>
          <div style="margin-top:10px;">
            <span class="badge ok">LEAP SCHEDULE</span>
            <span class="small">
              <?php echo ($leap_file !== '') ? 'No future leap change found in leap-seconds.list.' : 'leap-seconds.list not found/readable.'; ?>
            </span>
          </div>
        <?php } ?>

        <div class="kv" style="margin-top:10px;">
          <div class="item"><b>UTC:</b> <?php echo html($now_utc['out']); ?></div>
          <div class="item"><b>UTC offset:</b> <?php echo html($utcoff['out']); ?></div>

          <div class="item"><b>ISO-8601:</b> <?php echo html($iso['out']); ?></div>
          <div class="item"><b>Epoch:</b> <?php echo html($epoch['out']); ?></div>

	<div class="item"><b>Time source (Stratum / RefID):</b>
	  <?php
	    $st = isset($rv['kv']['stratum']) ? $rv['kv']['stratum'] : '';
	    $rf = isset($rv['kv']['refid']) ? $rv['kv']['refid'] : '';
	    echo html($st . ($st !== '' && $rf !== '' ? ' / ' : '') . $rf);
	  ?>
	</div>

          <div class="item"><b>Last reftime update:</b> <?php echo html($age_str); ?></div>

          <div class="item"><b>Leap file:</b> <?php echo html($leap_file !== '' ? $leap_file : '(not found)'); ?></div>
          <div class="item"><b>Kernel raw time:</b>
            <?php
              $rt = isset($adjt['raw_time']) ? $adjt['raw_time'] : '';
              echo html($rt);
            ?>
          </div>

<div class="item"><b>NTP offset:</b>
  <?php echo html(isset($rv['kv']['offset']) ? ($rv['kv']['offset'] . ' ms') : ''); ?>
</div>
<div class="item"><b>Root dispersion:</b>
  <?php echo html(isset($rv['kv']['rootdisp']) ? ($rv['kv']['rootdisp'] . ' ms') : ''); ?>
</div>


        </div>


        <?php
          // Build a tidy 2-column table of the exact fields you requested
          $klist = array(
            'offset', 'frequency',
            'maxerror', 'esterror',
            'time_constant', 'status',
            'tick', 'raw_time'
          );

          // Pre-format frequency
          $freq_raw = isset($adjt['frequency']) ? $adjt['frequency'] : '';
          $freq_ppm = adjtimex_freq_ppm_estimate($freq_raw);

          $kv = array();
          $kv[] = array('kernel_offset', (isset($adjt['offset']) ? $adjt['offset'] . ' µs' : ''));
          $kv[] = array('kernel_frequency', ($freq_raw !== '' ? ($freq_raw . ' / ' . $freq_ppm) : ''));

          $kv[] = array('maxerror', (isset($adjt['maxerror']) ? $adjt['maxerror'] : ''));
          $kv[] = array('esterror', (isset($adjt['esterror']) ? $adjt['esterror'] : ''));

          $kv[] = array('time_constant', (isset($adjt['time_constant']) ? $adjt['time_constant'] : ''));
          $kv[] = array('status', (isset($adjt['status']) ? $adjt['status'] : ''));

          $kv[] = array('tick', (isset($adjt['tick']) ? $adjt['tick'] : ''));
          // raw_time already shown above, but include here too if you want it in the table:
          $kv[] = array('raw_time', (isset($adjt['raw_time']) ? $adjt['raw_time'] : ''));

          $half = (int)ceil(count($kv)/2);
          $left = array_slice($kv, 0, $half);
          $right = array_slice($kv, $half);
          $rows = max(count($left), count($right));
        ?>

        <table>
          <thead>
            <tr>
              <th style="width:20%;">Field</th>
              <th style="width:30%;">Value</th>
              <th style="width:20%;">Field</th>
              <th style="width:30%;">Value</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($i=0; $i<$rows; $i++) {
              $lk = isset($left[$i][0]) ? $left[$i][0] : '';
              $lv = isset($left[$i][1]) ? $left[$i][1] : '';
              $rk = isset($right[$i][0]) ? $right[$i][0] : '';
              $rvv = isset($right[$i][1]) ? $right[$i][1] : '';
            ?>
              <tr>
                <td><b><?php echo html($lk); ?></b></td>
                <td><?php echo html($lv); ?></td>
                <td><b><?php echo html($rk); ?></b></td>
                <td><?php echo html($rvv); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>

        <div class="small" style="margin-top:10px;">
        </div>
      </div>
    </div>
  </div>
</div>



    <div class="row">
      <div class="col col-6">
        <div class="card">
          <h2>NTP Sync Summary
	    <span class="badge <?php echo $top_badge; ?>"><?php echo html(strtoupper($top_state)); ?></span></h2>

          <div class="content">
            <div class="kv">
              <div class="item"><b>Synced:</b> <?php echo $ntpstat['synced'] ? 'Yes' : 'No'; ?></div>
              <div class="item"><b>State:</b> <?php echo html($ntpstat['state']); ?></div>
              <div class="item"><b>Upstream:</b> <?php echo html($ntpstat['server']); ?></div>
              <div class="item"><b>Stratum:</b> <?php echo html($ntpstat['stratum']); ?></div>
              <div class="item"><b>Offset (ms):</b> <?php echo html($ntpstat['offset_ms']); ?></div>
              <div class="item"><b>Poll (s):</b> <?php echo html($ntpstat['poll_s']); ?></div>
            </div>
          </div>
        </div>
      </div>



<div class="col col-6">
  <div class="card">
    <h2>PPS Discipline
      <?php
        $pps_ok = has_flag($rv['flags'], 'sync_pps') || (isset($rv['kv']['refid']) && $rv['kv']['refid'] === 'PPS');
        $pps_state = $pps_ok ? 'locked' : 'not_locked';
      ?>
      <span class="badge <?php echo badge_class_for($pps_ok ? 'synced' : 'unsynced'); ?>">
        <?php echo html($pps_ok ? 'LOCKED' : 'NO LOCK'); ?>
      </span>
    </h2>
    <div class="content">
      <div class="kv">
        <div class="item"><b>Configured PPS device:</b> <?php echo html($GLOBALS['PPS_DEVICE']); ?></div>
        <div class="item"><b>/dev/pps* present:</b> <?php echo count($pps_devices) ? 'Yes' : 'No'; ?></div>


        <div class="item"><b>RefID:</b> <?php echo html(isset($rv['kv']['refid']) ? $rv['kv']['refid'] : ''); ?></div>
        <div class="item"><b>Stratum:</b> <?php echo html(isset($rv['kv']['stratum']) ? $rv['kv']['stratum'] : ''); ?></div>

        <div class="item"><b>Offset (ms):</b> <?php echo html(isset($rv['kv']['offset']) ? $rv['kv']['offset'] : ''); ?></div>
        <div class="item"><b>Frequency (ppm):</b> <?php echo html(isset($rv['kv']['frequency']) ? $rv['kv']['frequency'] : ''); ?></div>

        <div class="item"><b>sys_jitter (ms):</b> <?php echo html(isset($rv['kv']['sys_jitter']) ? $rv['kv']['sys_jitter'] : ''); ?></div>
        <div class="item"><b>clk_jitter (ms):</b> <?php echo html(isset($rv['kv']['clk_jitter']) ? $rv['kv']['clk_jitter'] : ''); ?></div>

        <div class="item"><b>clk_wander (ppm):</b> <?php echo html(isset($rv['kv']['clk_wander']) ? $rv['kv']['clk_wander'] : ''); ?></div>
	<div class="item"><b>Satellites:</b> <?php echo html(isset($nmea['GGA']['num_sats']) ? $nmea['GGA']['num_sats'] : ''); ?></div>

      </div>

    </div>
  </div>
</div>

        <?php
        $sats = isset($nmea['GGA']['num_sats']) ? $nmea['GGA']['num_sats'] : '';
        $fix = gps_fix_from_sats($sats);
        ?>



<div class="col col-12">
  <div class="card">
    <h2>GPSD Status <span class="badge <?php echo $fix['class']; ?>"><?php echo $fix['text']; ?></span> </h2>
    <div class="content">

      <div class="kv">
        <div class="item"><b>Fix quality:</b>
          <?php
            $fq = isset($nmea['GGA']['fix_quality']) ? $nmea['GGA']['fix_quality'] : '';
            echo html(gga_fix_quality_text($fq));
          ?>
        </div>

        <div class="item">
          <b>Satellites:</b> <?php echo html($sats); ?>
        </div>



        <div class="item"><b>HDOP:</b>
          <?php
            // Prefer GGA hdop; if missing, fallback to GSA hdop
            $hdop = '';
            if (isset($nmea['GGA']['hdop']) && $nmea['GGA']['hdop'] !== '') $hdop = $nmea['GGA']['hdop'];
            else if (isset($nmea['GSA']['hdop'])) $hdop = $nmea['GSA']['hdop'];
            echo html($hdop);
          ?>
        </div>
        <div class="item"><b>PDOP/VDOP:</b>
          <?php
            $pdop = isset($nmea['GSA']['pdop']) ? $nmea['GSA']['pdop'] : '';
            $vdop = isset($nmea['GSA']['vdop']) ? $nmea['GSA']['vdop'] : '';
            echo html($pdop . ' / ' . $vdop);
          ?>
        </div>

        <div class="item"><b>Lat/Lon:</b>
          <?php
            $lat = '';
            $lon = '';
            if (isset($nmea['GGA']['lat']) && $nmea['GGA']['lat'] !== '') { $lat = $nmea['GGA']['lat']; $lon = $nmea['GGA']['lon']; }
            else if (isset($nmea['RMC']['lat']) && $nmea['RMC']['lat'] !== '') { $lat = $nmea['RMC']['lat']; $lon = $nmea['RMC']['lon']; }
            else if (isset($nmea['GLL']['lat']) && $nmea['GLL']['lat'] !== '') { $lat = $nmea['GLL']['lat']; $lon = $nmea['GLL']['lon']; }
            echo html($lat . ' / ' . $lon);
          ?>
        </div>

        <div class="item"><b>Altitude:</b>
          <?php
            $alt = isset($nmea['GGA']['alt_m']) ? $nmea['GGA']['alt_m'] : '';
            $u = isset($nmea['GGA']['alt_units']) ? $nmea['GGA']['alt_units'] : 'M';
            echo html($alt . ' ' . $u);
          ?>
        </div>

        <div class="item"><b>Speed:</b>
          <?php
            $kn = '';
            $kph = '';
            if (isset($nmea['VTG']['speed_knots']) && $nmea['VTG']['speed_knots'] !== '') $kn = $nmea['VTG']['speed_knots'];
            else if (isset($nmea['RMC']['speed_knots'])) $kn = $nmea['RMC']['speed_knots'];
            if (isset($nmea['VTG']['speed_kph'])) $kph = $nmea['VTG']['speed_kph'];
            echo html($kn . ' kn / ' . $kph . ' km/h');
          ?>
        </div>

        <div class="item"><b>Track:</b>
          <?php
            $trk = '';
            if (isset($nmea['VTG']['course_true']) && $nmea['VTG']['course_true'] !== '') $trk = $nmea['VTG']['course_true'];
            else if (isset($nmea['RMC']['course_true'])) $trk = $nmea['RMC']['course_true'];
            echo html($trk);
          ?>
        </div>

        <div class="item"><b>NMEA UTC time:</b>
          <?php
            $t = '';
            if (isset($nmea['ZDA']['utc_time']) && $nmea['ZDA']['utc_time'] !== '') $t = $nmea['ZDA']['utc_time'];
            else if (isset($nmea['GGA']['utc_time'])) $t = $nmea['GGA']['utc_time'];
            echo html($t);
          ?>
        </div>

        <div class="item"><b>NMEA date:</b>
          <?php
            $d = '';
            if (isset($nmea['ZDA']['year']) && $nmea['ZDA']['year'] !== '') {
              $d = $nmea['ZDA']['year'] . '-' . $nmea['ZDA']['month'] . '-' . $nmea['ZDA']['day'];
            } else if (isset($nmea['RMC']['date'])) {
              $d = $nmea['RMC']['date'];
            }
            echo html($d);
          ?>
        </div>
      </div>

      <?php
        // Highlight the "your GPS says it's 2006" issue
        $gps_year = '';
        if (isset($nmea['ZDA']['year']) && $nmea['ZDA']['year'] !== '') $gps_year = $nmea['ZDA']['year'];
        else if (isset($nmea['RMC']['date']) && $nmea['RMC']['date'] !== '') $gps_year = substr($nmea['RMC']['date'], 0, 4);

        if ($gps_year !== '' && $gps_year !== date('Y')) {
      ?>
        <div style="margin-top:12px;">
          <span class="badge warn">GPS TIME WARNING</span>
          <span class="small">NMEA date appears to be <?php echo html($gps_year); ?> (system year is <?php echo html(date('Y')); ?>). This can happen if the receiver lacks valid date, has no almanac, or is outputting stale sentences.</span>
        </div>
      <?php } ?>

      <div class="row" style="margin-top:10px;">
        <div class="col col-6">
        </div>
        <div class="col col-6">
        </div>
      </div>

    </div>
  </div>
</div>





      <div class="col col-12">
        <div class="card">
          <h2>Services</h2>
          <div class="content">
            <div style="margin-bottom:10px;">
              <b>ntpd</b>
              <span class="badge <?php echo badge_class_for($svc_ntpd['active']); ?>"><?php echo html($svc_ntpd['active']); ?></span>
            </div>
            <pre><?php echo html($svc_ntpd['status_raw'] !== '' ? $svc_ntpd['status_raw'] : $svc_ntpd['ps_raw']); ?></pre>

            <div style="margin:14px 0 10px;">
              <b>gpsd</b>
              <span class="badge <?php echo badge_class_for($svc_gpsd['active']); ?>"><?php echo html($svc_gpsd['active']); ?></span>
            </div>
            <pre><?php echo html($svc_gpsd['status_raw'] !== '' ? $svc_gpsd['status_raw'] : $svc_gpsd['ps_raw']); ?></pre>
          </div>
        </div>
	</div>

      <div class="col col-12">
        <div class="card">
          <h2>NTP Peers Status</h2>
          <div class="content">
            <?php if (count($peers_merged) === 0) { ?>
              <div class="small">No peers parsed. Raw output:</div>
              <pre><?php echo html($ntpq_peers_raw['out']); ?></pre>
            <?php } else { ?>
              <table>
                <thead>
                  <tr>
                    <th>Sel</th>
                    <th>Remote</th>
                    <th>RefID</th>
                    <th>St</th>
                    <th>T</th>
                    <th>When</th>
                    <th>Poll</th>
                    <th>Reach</th>
                    <th>Delay</th>
                    <th>Offset</th>
                    <th>Jitter</th>
	 	    <th>As Reach</th>
		    <th>Condition</th>
		    <th>Last Event</th>

                  </tr>
                </thead>
                <tbody>
                <?php foreach ($peers_merged as $p) { ?>
		  <tr>
		    <td><?php echo html($p['sel']); ?></td>
		    <td><?php echo html($p['remote']); ?></td>
		    <td><?php echo html($p['refid']); ?></td>
		    <td><?php echo html($p['st']); ?></td>
		    <td><?php echo html($p['t']); ?></td>
		    <td><?php echo html($p['when']); ?></td>
		    <td><?php echo html($p['poll']); ?></td>
		    <td><?php echo html($p['reach']); ?></td>
		    <td><?php echo html($p['delay']); ?></td>
		    <td><?php echo html($p['offset']); ?></td>
		    <td><?php echo html($p['jitter']); ?></td>

		    <td><?php echo html($p['as_reach']); ?></td>
		    <td>
		      <?php if ($p['as_condition'] !== '') { ?>
		        <span class="badge <?php echo assoc_condition_badge_class($p['as_condition']); ?>">
		          <?php echo html($p['as_condition']); ?>
		        </span>
		      <?php } ?>
		    </td>
		    <td><?php echo html($p['as_last_event']); ?></td>
		  </tr>
		<?php } ?>
		</tbody>
              </table>

              <div class="small" style="margin-top:10px;">
                Legend: <b>*</b>=selected sync, <b>+</b>=candidate, <b>o</b>=PPS/locked (often), <b>x</b>=falseticker, <b>.</b>=discarded
              </div>
            <?php } ?>
          </div>
        </div>
      </div>



<div class="col col-12">
  <div class="card">
    <h2>NTPD Variables
      <?php
        $synced = has_flag($rv['flags'], 'sync_pps') || has_flag($rv['flags'], 'sync_ntp') ||
                  (isset($rv['kv']['leap']) && $rv['kv']['leap'] === '00');
      ?>
      <span class="badge <?php echo badge_class_for($synced ? 'synced' : 'unsynced'); ?>">
        <?php echo html($synced ? 'OK' : 'CHECK'); ?>
      </span>
    </h2>

    <div class="content">
      <?php
        // Important keys first
        $top_keys = array(
          'status','leap','stratum','refid','reftime','clock','peer','tc','mintc',
          'rootdelay','rootdisp','offset','frequency','sys_jitter','clk_jitter','clk_wander',
          'precision','version','processor','system'
        );

        $ordered = array();
        foreach ($top_keys as $k) {
          if (isset($rv['kv'][$k])) $ordered[$k] = $rv['kv'][$k];
        }

        $rest = $rv['kv'];
        foreach ($top_keys as $k) { if (isset($rest[$k])) unset($rest[$k]); }
        ksort($rest);
        foreach ($rest as $k => $v) $ordered[$k] = $v;

        // Format with light units
        function pretty_value($k, $v) {
          if ($k === 'offset') return fmt_num($v, ' ms');
          if ($k === 'rootdelay') return fmt_num($v, ' s');
          if ($k === 'rootdisp') return fmt_num($v, ' s');
          if ($k === 'sys_jitter') return fmt_num($v, ' ms');
          if ($k === 'clk_jitter') return fmt_num($v, ' ms');
          if ($k === 'clk_wander') return fmt_num($v, ' ppm');
          if ($k === 'frequency') return fmt_num($v, ' ppm');
          return $v;
        }

        // Split into two columns (pairs of key/value)
        $pairs = array();
        foreach ($ordered as $k => $v) {
          $pairs[] = array($k, pretty_value($k, $v));
        }
        $half = (int)ceil(count($pairs) / 2);
        $left = array_slice($pairs, 0, $half);
        $right = array_slice($pairs, $half);
      ?>

      <table>
        <thead>
          <tr>
            <th style="width:18%;">Key</th>
            <th style="width:32%;">Value</th>
            <th style="width:18%;">Key</th>
            <th style="width:32%;">Value</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $rows = max(count($left), count($right));
            for ($i = 0; $i < $rows; $i++) {
              $lk = isset($left[$i][0]) ? $left[$i][0] : '';
              $lv = isset($left[$i][1]) ? $left[$i][1] : '';
              $rk = isset($right[$i][0]) ? $right[$i][0] : '';
              $rvv = isset($right[$i][1]) ? $right[$i][1] : '';
          ?>
            <tr>
              <td><b><?php echo html($lk); ?></b></td>
              <td><?php echo html($lv); ?></td>
              <td><b><?php echo html($rk); ?></b></td>
              <td><?php echo html($rvv); ?></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>

    </div>
  </div>
</div>


    </div>
  </div>
</body>
</html>



