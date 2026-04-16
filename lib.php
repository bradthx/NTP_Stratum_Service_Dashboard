<?php

// Copyright (c) 2026 Brad Boegler <bradthx@gmail.com>
// MIT License: https://opensource.org/licenses/MIT

// ---- Local config ----
$GLOBALS['PPS_DEVICE'] = '/dev/pps1';   // change if needed
$GLOBALS['PPS_SAMPLES'] = 3;            // how many lines to keep from ppswatch output



function allowed_commands() {
    return array(
        'ntpstat' => '/usr/bin/ntpstat 2>&1',
        'ntpq_peers' => '/usr/sbin/ntpq -pn 2>&1',
        'ntpq_rv' => '/usr/sbin/ntpq -c rv 2>&1',
        'ntpq_as' => '/usr/sbin/ntpq -c as 2>&1',
        'date' => '/bin/date "+%Y-%m-%d %H:%M:%S %Z (%s)" 2>&1',
        'uptime' => '/usr/bin/uptime 2>&1',
        // gpspipe gives JSON from gpsd; far easier than gpsmon (interactive).
        'gpspipe' => '/usr/bin/gpspipe -w -n 12 2>&1',
        // Service status (systemd); if your distro lacks systemctl, we fall back elsewhere.
        'systemctl_ntpd' => '/bin/systemctl is-active ntpd 2>&1',
        'systemctl_ntpd_status' => '/bin/systemctl status ntpd --no-pager 2>&1',
        'systemctl_gpsd' => '/bin/systemctl is-active gpsd 2>&1',
        'systemctl_gpsd_status' => '/bin/systemctl status gpsd --no-pager 2>&1',
        // SysV fallback checks
        'ps_ntpd' => '/bin/ps -eo pid,cmd | /bin/grep -E "[n]tpd" 2>&1',
        'ps_gpsd' => '/bin/ps -eo pid,cmd | /bin/grep -E "[g]psd" 2>&1',

	'which_timeout' => '/usr/bin/which timeout 2>&1',
	'ppswatch_sample' => '/usr/bin/timeout 2 /usr/bin/ppswatch ' . escapeshellarg($GLOBALS['PPS_DEVICE']) . ' 2>&1',
	'ls_dev_pps' => '/bin/ls -1 /dev/pps* 2>/dev/null || true',
	'gpspipe_nmea' => '/usr/bin/gpspipe -r -n 25 2>&1',
	'date_pretty' => '/bin/date "+%A, %B %e, %Y  %H:%M:%S %Z" 2>&1',
	'date_utc' => '/bin/date -u "+%Y-%m-%d %H:%M:%S UTC" 2>&1',
	'date_pretty'   => '/bin/date "+%A, %B %e, %Y  %H:%M:%S %Z" 2>&1',
	'date_utc'      => '/bin/date -u "+%Y-%m-%d %H:%M:%S UTC" 2>&1',
	'date_epoch'    => '/bin/date "+%s" 2>&1',
	'date_iso'      => '/bin/date "+%Y-%m-%dT%H:%M:%S%z" 2>&1',
	'date_utcoff'   => '/bin/date "+%:z" 2>&1',

// Kernel timekeeping (CentOS/RHEL 7 typically)
'adjtimex_print' => '/sbin/adjtimex --print 2>&1',


    );
}

function run_cmd($key) {
    $map = allowed_commands();
    if (!isset($map[$key])) {
        return array('ok' => false, 'out' => "Command not allowed: ".$key);
    }

    // Use shell_exec for simplicity. (exec() is fine too; this is v1.)
    $out = shell_exec($map[$key]);
    if ($out === null) $out = "";
    return array('ok' => true, 'out' => trim($out));
}

function html($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Parse ntpstat output into a status summary.
function parse_ntpstat($text) {
    $r = array(
        'synced' => false,
        'state' => 'unknown',
        'server' => '',
        'stratum' => '',
        'offset_ms' => '',
        'poll_s' => '',
        'raw' => $text,
    );

    if ($text === '') return $r;

    // Normalize (ntpstat output is multi-line)
    $t = trim($text);

    // Detect synced phrases (covers NTP server AND PPS/atomic clock styles)
    $is_synced =
        (stripos($t, 'synchronised') !== false || stripos($t, 'synchronized') !== false) &&
        (stripos($t, 'unsynchronised') === false && stripos($t, 'unsynchronized') === false) &&
        (stripos($t, 'no server suitable') === false);

    if ($is_synced) {
        $r['synced'] = true;
        $r['state'] = 'synced';

        // Case 1: "synchronised to NTP server (10.0.0.1) at stratum 2"
        if (preg_match('/server\s+\(([^)]+)\)\s+at\s+stratum\s+(\d+)/i', $t, $m)) {
            $r['server'] = $m[1];
            $r['stratum'] = $m[2];
        }

        // Case 2: "synchronised to atomic clock (PPS) at stratum 1"
        if ($r['server'] === '' && preg_match('/to\s+atomic\s+clock\s+\(([^)]+)\)\s+at\s+stratum\s+(\d+)/i', $t, $m)) {
            $r['server'] = $m[1];   // PPS
            $r['stratum'] = $m[2];
        }

        // Fallback: try to at least grab stratum if present
        if ($r['stratum'] === '' && preg_match('/at\s+stratum\s+(\d+)/i', $t, $m)) {
            $r['stratum'] = $m[1];
        }

        // Example: "time correct to within 1 ms"
        if (preg_match('/within\s+([0-9.]+)\s*ms/i', $t, $m)) {
            $r['offset_ms'] = $m[1];
        }

        // Example: "polling server every 16 s"
        if (preg_match('/every\s+([0-9.]+)\s*s/i', $t, $m)) {
            $r['poll_s'] = $m[1];
        }

        return $r;
    }

    // Unsynced cases
    if (stripos($t, 'unsynchronised') !== false || stripos($t, 'unsynchronized') !== false) {
        $r['synced'] = false;
        $r['state'] = 'unsynced';
    } else if (stripos($t, 'no server suitable') !== false) {
        $r['synced'] = false;
        $r['state'] = 'no_server';
    }

    return $r;
}


// Parse "ntpq -pn" peers into an array of rows.
function parse_ntpq_peers($text) {
    $lines = preg_split("/\r?\n/", $text);
    $rows = array();
    $idx = 0;

    // NTP tally codes (RFC-ish + common ntpq output)
    // * sys.peer, + candidate, o pps.peer, - outlyer, x falsetick, # selected, ~ too variable
    $tally = array('*'=>1,'+'=>1,'o'=>1,'-'=>1,'x'=>1,'#'=>1,'~'=>1);

    foreach ($lines as $line) {
        // IMPORTANT: don't trim() here (it would remove leading "no tally" space)
        $line = rtrim($line);
        if ($line === '') continue;

        $ltrim = ltrim($line);
        if (stripos($ltrim, 'remote') === 0) continue;
        if (strpos($ltrim, '====') === 0) continue;

        $first = substr($line, 0, 1);

        if (isset($tally[$first])) {
            $sel = $first;
            $rest = trim(substr($line, 1));
        } else {
            // no tally char; the line starts with the remote itself (often a digit for IPs)
            $sel = '';               // or ' ' if you prefer
            $rest = trim($line);
        }

        $parts = preg_split('/\s+/', $rest);
        if (count($parts) < 9) continue;

        $idx++;

        $rows[] = array(
            'idx' => (string)$idx,
            'sel' => $sel,
            'remote' => $parts[0],
            'refid' => $parts[1],
            'st' => $parts[2],
            't' => $parts[3],
            'when' => $parts[4],
            'poll' => $parts[5],
            'reach' => $parts[6],
            'delay' => $parts[7],
            'offset' => $parts[8],
            'jitter' => isset($parts[9]) ? $parts[9] : '',
        );
    }

    return $rows;
}



// Parse a gpspipe JSON stream and find the latest TPV + SKY blocks.
function parse_gpspipe_json($text) {
    $lines = preg_split("/\r?\n/", $text);
    $tpv = null;
    $sky = null;

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] !== '{') continue;
        $obj = json_decode($ln, true);
        if (!is_array($obj) || !isset($obj['class'])) continue;

        if ($obj['class'] === 'TPV') $tpv = $obj;
        if ($obj['class'] === 'SKY') $sky = $obj;
    }

    return array('tpv' => $tpv, 'sky' => $sky);
}

function service_status_block($name, $is_active_text, $status_text, $ps_text) {
    // Determine "active" best-effort.
    $active = 'unknown';
    if ($is_active_text !== '') {
        $t = trim($is_active_text);
        if ($t === 'active') $active = 'active';
        else if ($t === 'inactive' || $t === 'failed') $active = $t;
    } else if ($ps_text !== '') {
        $active = 'running';
    }

    return array(
        'name' => $name,
        'active' => $active,
        'is_active_raw' => $is_active_text,
        'status_raw' => $status_text,
        'ps_raw' => $ps_text,
    );
}

function badge_class_for($state) {
    if ($state === 'active' || $state === 'running' || $state === 'synced') return 'ok';
    if ($state === 'inactive' || $state === 'failed' || $state === 'unsynced' || $state === 'no_server') return 'bad';
    return 'warn';
}


// Parse "associd=0 status=.... leap_none, sync_pps, ..." into flags + key/values
function parse_ntpq_rv($text) {
    $r = array(
        'flags' => array(),
        'kv' => array(),
        'raw' => $text,
    );

    if ($text === '') return $r;

    // Collapse to one line for easier parsing
    $one = preg_replace("/\r?\n/", " ", $text);

    // status flags are like: status=011d leap_none, sync_pps, 1 event, kern,
    if (preg_match('/status=[0-9a-fA-F]+\\s+([^,]+(?:,\\s*[^,]+)*)/i', $one, $m)) {
        $flag_blob = $m[1];
        $parts = preg_split('/\\s*,\\s*/', trim($flag_blob, " ,"));
        $flags = array();
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $flags[] = $p;
        }
        $r['flags'] = $flags;
    }

    // Parse key=value pairs (very simple but works well for rv output)
    // Example: stratum=1, precision=-23, refid=PPS, offset=-0.319, sys_jitter=0.029, ...
    preg_match_all('/\\b([a-zA-Z_]+)=("([^"]*)"|[^,\\s]+)\\b/', $one, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $k = $m[1];
        $v = $m[2];
        // Strip quotes if present
        if (strlen($v) >= 2 && $v[0] === '"' && $v[strlen($v)-1] === '"') {
            $v = substr($v, 1, -1);
        }
        $r['kv'][$k] = $v;
    }

    return $r;
}

function has_flag($flags, $needle) {
    foreach ($flags as $f) {
        if (trim($f) === $needle) return true;
    }
    return false;
}

// Parse a short ppswatch run into offsets and last sequence seen
function parse_ppswatch($text) {
    $lines = preg_split("/\r?\n/", $text);
    $samples = array();
    foreach ($lines as $ln) {
        // timestamp: 1771347778, sequence: 2845, offset:  329729
        if (preg_match('/sequence:\\s*(\\d+),\\s*offset:\\s*([\\-0-9]+)/i', $ln, $m)) {
            $samples[] = array('sequence' => $m[1], 'offset' => $m[2]);
        }
    }
    return $samples;
}

// List PPS devices using PHP glob (no shell), but keep ls output too if you want.
function list_pps_devices() {
    $devs = glob('/dev/pps*');
    if ($devs === false) $devs = array();
    sort($devs);
    return $devs;
}


// --- NMEA parsing helpers (simple, handles your sentence set) ---

function nmea_checksum_ok($line) {
    $line = trim($line);
    if ($line === '' || $line[0] !== '$') return false;
    $star = strrpos($line, '*');
    if ($star === false) return false;

    $data = substr($line, 1, $star - 1);
    $chk = strtoupper(substr($line, $star + 1));
    $calc = 0;
    for ($i = 0; $i < strlen($data); $i++) {
        $calc ^= ord($data[$i]);
    }
    $calc_hex = strtoupper(str_pad(dechex($calc), 2, '0', STR_PAD_LEFT));
    return ($calc_hex === $chk);
}

function nmea_split($line) {
    $line = trim($line);
    $star = strrpos($line, '*');
    if ($star !== false) $line = substr($line, 0, $star);
    $line = ltrim($line, '$');
    return explode(',', $line);
}

function nmea_degmin_to_decimal($dm, $hem) {
    if ($dm === '' || $hem === '') return '';
    // Latitude: ddmm.mmmm, Longitude: dddmm.mmmm
    $dot = strpos($dm, '.');
    if ($dot === false) return '';
    $head = substr($dm, 0, $dot);
    $deg_len = (strlen($head) > 4) ? 3 : 2;

    $deg = floatval(substr($dm, 0, $deg_len));
    $min = floatval(substr($dm, $deg_len));
    $val = $deg + ($min / 60.0);

    if ($hem === 'S' || $hem === 'W') $val = -$val;
    return sprintf('%.8f', $val);
}

function nmea_hhmmss_to_hms($hhmmss) {
    if ($hhmmss === '') return '';
    $hhmmss = preg_replace('/[^0-9.]/', '', $hhmmss);
    if (strlen($hhmmss) < 6) return $hhmmss;
    $hh = substr($hhmmss, 0, 2);
    $mm = substr($hhmmss, 2, 2);
    $ss = substr($hhmmss, 4);
    return $hh . ':' . $mm . ':' . $ss;
}

function nmea_ddmmyy_to_date($ddmmyy) {
    if ($ddmmyy === '' || strlen($ddmmyy) < 6) return '';
    $dd = substr($ddmmyy, 0, 2);
    $mm = substr($ddmmyy, 2, 2);
    $yy = substr($ddmmyy, 4, 2);
    // NMEA is 2-digit year; assume 2000-2099 for this dashboard
    $yyyy = '20' . $yy;
    return $yyyy . '-' . $mm . '-' . $dd;
}

function parse_nmea_stream($text) {
    $lines = preg_split("/\r?\n/", trim($text));
    $out = array(
        'raw_lines' => array(),
        'bad_checksum' => 0,
        'GGA' => array(),
        'GSA' => array(),
        'RMC' => array(),
        'ZDA' => array(),
        'VTG' => array(),
        'GLL' => array(),
    );

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] !== '$') continue;

        $out['raw_lines'][] = $ln;

        // If checksum present, validate (but don’t drop data if invalid; just note)
        if (strpos($ln, '*') !== false && !nmea_checksum_ok($ln)) {
            $out['bad_checksum']++;
        }

        $p = nmea_split($ln);
        if (count($p) < 1) continue;

        $type = $p[0]; // e.g., GPZDA, GPGGA
        // Use suffix for matching: ZDA/GGA/GSA/RMC/VTG/GLL
        $suffix = substr($type, -3);

        if ($suffix === 'ZDA') {
            // $GPZDA,hhmmss.sss,dd,mm,yyyy,ltzh,ltzm
            $out['ZDA'] = array(
                'utc_time' => isset($p[1]) ? nmea_hhmmss_to_hms($p[1]) : '',
                'day' => isset($p[2]) ? $p[2] : '',
                'month' => isset($p[3]) ? $p[3] : '',
                'year' => isset($p[4]) ? $p[4] : '',
                'tz_h' => isset($p[5]) ? $p[5] : '',
                'tz_m' => isset($p[6]) ? $p[6] : '',
            );
        } else if ($suffix === 'GGA') {
            // $GPGGA,time,lat,N,lon,W,fix,nsat,hdop,alt,M,...
            $out['GGA'] = array(
                'utc_time' => isset($p[1]) ? nmea_hhmmss_to_hms($p[1]) : '',
                'lat_dm' => isset($p[2]) ? $p[2] : '',
                'lat_hem' => isset($p[3]) ? $p[3] : '',
                'lon_dm' => isset($p[4]) ? $p[4] : '',
                'lon_hem' => isset($p[5]) ? $p[5] : '',
                'lat' => (isset($p[2], $p[3]) ? nmea_degmin_to_decimal($p[2], $p[3]) : ''),
                'lon' => (isset($p[4], $p[5]) ? nmea_degmin_to_decimal($p[4], $p[5]) : ''),
                'fix_quality' => isset($p[6]) ? $p[6] : '',
                'num_sats' => isset($p[7]) ? $p[7] : '',
                'hdop' => isset($p[8]) ? $p[8] : '',
                'alt_m' => isset($p[9]) ? $p[9] : '',
                'alt_units' => isset($p[10]) ? $p[10] : '',
            );
        } else if ($suffix === 'GSA') {
            // $GPGSA,mode1,mode2,sv1..sv12,PDOP,HDOP,VDOP
            $out['GSA'] = array(
                'mode1' => isset($p[1]) ? $p[1] : '',
                'mode2' => isset($p[2]) ? $p[2] : '',
                'pdop' => isset($p[15]) ? $p[15] : '',
                'hdop' => isset($p[16]) ? $p[16] : '',
                'vdop' => isset($p[17]) ? $p[17] : '',
            );
        } else if ($suffix === 'VTG') {
            // $GPVTG,true,T,mag,M,knots,N,kph,K
            $out['VTG'] = array(
                'course_true' => isset($p[1]) ? $p[1] : '',
                'speed_knots' => isset($p[5]) ? $p[5] : '',
                'speed_kph' => isset($p[7]) ? $p[7] : '',
            );
        } else if ($suffix === 'RMC') {
            // $GPRMC,hhmmss,A,lat,N,lon,W,sog,cog,ddmmyy,...
            $out['RMC'] = array(
                'utc_time' => isset($p[1]) ? nmea_hhmmss_to_hms($p[1]) : '',
                'status' => isset($p[2]) ? $p[2] : '',
                'lat' => (isset($p[3], $p[4]) ? nmea_degmin_to_decimal($p[3], $p[4]) : ''),
                'lon' => (isset($p[5], $p[6]) ? nmea_degmin_to_decimal($p[5], $p[6]) : ''),
                'speed_knots' => isset($p[7]) ? $p[7] : '',
                'course_true' => isset($p[8]) ? $p[8] : '',
                'date' => isset($p[9]) ? nmea_ddmmyy_to_date($p[9]) : '',
            );
        } else if ($suffix === 'GLL') {
            // $GPGLL,lat,N,lon,W,hhmmss,A
            $out['GLL'] = array(
                'lat' => (isset($p[1], $p[2]) ? nmea_degmin_to_decimal($p[1], $p[2]) : ''),
                'lon' => (isset($p[3], $p[4]) ? nmea_degmin_to_decimal($p[3], $p[4]) : ''),
                'utc_time' => isset($p[5]) ? nmea_hhmmss_to_hms($p[5]) : '',
                'status' => isset($p[6]) ? $p[6] : '',
            );
        }
    }

    return $out;
}

function gga_fix_quality_text($q) {
    // Common fix quality values
    // 0 invalid, 1 GPS fix, 2 DGPS, 4 RTK fixed, 5 RTK float, 6 dead reckoning
    if ($q === '') return '';
    $map = array(
        '0' => 'Invalid',
        '1' => 'GPS fix',
        '2' => 'DGPS fix',
        '3' => 'PPS fix',
        '4' => 'RTK fixed',
        '5' => 'RTK float',
        '6' => 'Dead reckoning',
    );
    return isset($map[$q]) ? $map[$q] : ('Quality ' . $q);
}

function gps_fix_from_sats($num_sats) {
    $n = intval($num_sats);

    if ($n <= 2) {
        return array('text' => 'NO FIX', 'class' => 'bad');
    } else if ($n == 3) {
        return array('text' => '2D FIX', 'class' => 'warn');
    } else if ($n >= 4) {
        return array('text' => '3D FIX', 'class' => 'ok');
    }

    return array('text' => 'UNKNOWN', 'class' => 'warn');
}

function fmt_num($v, $suffix) {
    if ($v === '' || $v === null) return '';
    // leave non-numeric strings alone
    if (!is_numeric($v)) return $v;
    return rtrim(rtrim(sprintf('%.3f', floatval($v)), '0'), '.') . $suffix;
}

function parse_ntpq_as($text) {
    $lines = preg_split("/\r?\n/", trim($text));
    $rows = array();

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;

        // Skip header + separators
        if (stripos($ln, 'ind assid status') === 0) continue;
        if (preg_match('/^=+$/', $ln)) continue;

        // Data lines start with an index number
        if (!preg_match('/^\d+/', $ln)) continue;

        // Split into columns
        $parts = preg_split('/\s+/', $ln);
        // Expect exactly 9 columns for your output
        if (count($parts) < 9) continue;

        $rows[] = array(
            'ind' => $parts[0],
            'assid' => $parts[1],
            'status' => $parts[2],
            'conf' => $parts[3],
            'reach' => $parts[4],
            'auth' => $parts[5],
            'condition' => $parts[6],
            'last_event' => $parts[7],
            'cnt' => $parts[8],
        );
    }

    return $rows;
}


function assoc_condition_badge_class($cond) {
    // "best" / selected types
    if ($cond === 'sys.peer' || $cond === 'pps.peer') return 'ok';

    // reasonable/usable candidates
    if ($cond === 'candidate' || $cond === 'selectable') return 'warn';

    // clearly bad
    if ($cond === 'reject' || $cond === 'falsetick' || $cond === 'outlyer' || $cond === 'excess' || $cond === 'bad') return 'bad';

    return 'warn';
}


function leap_badge($leap) {
    // leap from ntpq rv: 00,01,10,11
    if ($leap === '00') return array('text' => 'LEAP: NONE', 'class' => 'ok');
    if ($leap === '01') return array('text' => 'LEAP: INSERT PENDING', 'class' => 'warn');
    if ($leap === '10') return array('text' => 'LEAP: DELETE PENDING', 'class' => 'warn');
    if ($leap === '11') return array('text' => 'LEAP: ALARM', 'class' => 'bad');
    return array('text' => 'LEAP: ? (' . $leap . ')', 'class' => 'warn');
}

function ntp_hex_to_unix_seconds($ntp_hex) {
    // ntp_hex like: ed3f1da9.61657c45  (32-bit seconds since 1900 + 32-bit fraction)
    $ntp_hex = trim($ntp_hex);
    if ($ntp_hex === '') return null;

    $parts = explode('.', $ntp_hex);
    if (count($parts) !== 2) return null;

    $sec_hex = $parts[0];
    $frac_hex = $parts[1];

    if (!ctype_xdigit($sec_hex) || !ctype_xdigit($frac_hex)) return null;

    $ntp_sec = hexdec($sec_hex);
    $ntp_frac = hexdec($frac_hex);

    $unix_sec = $ntp_sec - 2208988800;
    $frac = $ntp_frac / 4294967296.0;

    return $unix_sec + $frac;
}

function fmt_age_seconds($age) {
    if ($age === null) return '';
    if ($age < 0) return 'in future?';
    if ($age < 60) return intval($age) . 's ago';
    if ($age < 3600) return intval($age/60) . 'm ' . intval($age%60) . 's ago';
    if ($age < 86400) return intval($age/3600) . 'h ' . intval(($age%3600)/60) . 'm ago';
    return intval($age/86400) . 'd ' . intval(($age%86400)/3600) . 'h ago';
}

function parse_adjtimex_print($text) {
    $out = array();
    $lines = preg_split("/\r?\n/", trim($text));

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;

        // "raw time:  1771424723s 21203774us = 1771424723.21203774"
        if (stripos($ln, 'raw time:') === 0) {
            $v = trim(substr($ln, strlen('raw time:')));
            $out['raw_time'] = $v;
            if (preg_match('/(\d+)s\s+(\d+)us\s+=\s+([0-9.]+)/', $v, $mm)) {
                $out['raw_epoch_s'] = $mm[1];
                $out['raw_usec'] = $mm[2];
                $out['raw_epoch_float'] = $mm[3];
            }
            continue;
        }

        // Generic "key: value"
        if (preg_match('/^([a-zA-Z_ ]+):\s*(.+)$/', $ln, $m)) {
            $k = trim(str_replace(' ', '_', strtolower($m[1])));
            $v = trim($m[2]);
            $out[$k] = $v;
        }
    }

    return $out;
}

function adjtimex_freq_ppm_estimate($frequency_raw) {
    // On many Linux builds: ppm ≈ frequency / 65536
    if ($frequency_raw === '' || !is_numeric($frequency_raw)) return '';
    $ppm = floatval($frequency_raw) / 65536.0;
    return rtrim(rtrim(sprintf('%.3f', $ppm), '0'), '.') . ' ppm (est)';
}

/**
 * Leap seconds list parsing
 * We prefer reading directly from disk (no shell).
 * Common locations on EL7:
 *  - /etc/ntp/leap-seconds.list
 *  - /usr/share/ntp/leap-seconds.list
 */
function find_leap_seconds_file() {
    $candidates = array(
        '/etc/ntp/leap-seconds.list',
        '/usr/share/ntp/leap-seconds.list',
	'/usr/share/zoneinfo/leapseconds',
    );

    // Try a simple glob for doc location (safe, no shell)
    $g = glob('/usr/share/doc/ntp*/leap-seconds.list');
    if (is_array($g)) {
        foreach ($g as $p) $candidates[] = $p;
    }

    foreach ($candidates as $p) {
        if (is_readable($p)) return $p;
    }
    return '';
}

function parse_leap_seconds_list($path) {
    // Returns array of entries: array(array('ntp'=>int,'tai'=>int,'raw'=>string), ...)
    $entries = array();
    if ($path === '' || !is_readable($path)) return $entries;

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return $entries;

    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;

        // typical: "3870720000 37 # 1 Jan 2022"
        $parts = preg_split('/\s+/', $ln);
        if (count($parts) < 2) continue;
        if (!ctype_digit($parts[0]) || !ctype_digit($parts[1])) continue;

        $entries[] = array(
            'ntp' => intval($parts[0]),
            'tai' => intval($parts[1]),
            'raw' => $ln,
        );
    }

    // ensure sorted by ntp ascending
    usort($entries, function($a, $b) {
        return ($a['ntp'] < $b['ntp']) ? -1 : (($a['ntp'] > $b['ntp']) ? 1 : 0);
    });

    return $entries;
}

function unix_from_ntp_seconds($ntp_sec) {
    return $ntp_sec - 2208988800;
}

function format_utc($unix_sec) {
    // Always format in UTC
    return gmdate('Y-m-d H:i:s', $unix_sec) . ' UTC';
}

function leap_info_from_list($entries, $now_unix) {
    // Determine current TAI-UTC and next scheduled change (if any)
    $now_ntp = $now_unix + 2208988800;

    $current_tai = '';
    $next = null;

    foreach ($entries as $e) {
        if ($e['ntp'] <= $now_ntp) {
            $current_tai = (string)$e['tai'];
        } else {
            $next = $e;
            break;
        }
    }

    // Build friendly next-leap text:
    // The list timestamp is the moment the new TAI-UTC offset becomes effective at 00:00:00 UTC.
    // The leap second occurs at the end of the previous UTC day.
    $next_text = '';
    if ($next) {
        $effective_unix = unix_from_ntp_seconds($next['ntp']);          // 00:00:00 UTC
        $leap_day_end_unix = $effective_unix - 1;                      // end of prior day (represents 23:59:59; leap is "23:59:60")
        $next_text = 'Next change effective: ' . format_utc($effective_unix) .
                     ' (leap second at end of ' . gmdate('Y-m-d', $leap_day_end_unix) . ' UTC)';
    }

    return array(
        'tai' => $current_tai,
        'next_text' => $next_text,
    );
}


