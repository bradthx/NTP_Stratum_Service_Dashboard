# NTP Monitor

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-5.4%2B-8892be.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Platform](https://img.shields.io/badge/Platform-Linux-fcc624.svg?logo=linux&logoColor=black)](https://kernel.org/)
[![NTP](https://img.shields.io/badge/NTP-Stratum%201-blue.svg)](https://www.ntp.org/)
[![GPS](https://img.shields.io/badge/GPS-PPS%20Disciplined-red.svg)]()
[![Auto Refresh](https://img.shields.io/badge/Auto%20Refresh-10s-orange.svg)]()

A real-time, self-contained PHP dashboard for monitoring a GPS/PPS-disciplined **Stratum 1** NTP server running on Linux. It interrogates `ntpd`, `gpsd`, the kernel clock, and PPS devices directly on each page load and renders status dashboard. No database, no JavaScript framework, no external dependencies.

---

## Overview

This dashboard was built specifically to monitor the health and precision of a Stratum 1 NTP server that derives its time from a GPS receiver with a 1 PPS (pulse-per-second) output. It surfaces everything you need to know at a glance: NTP synchronization state, PPS lock status, GPS satellite fix quality, peer association health, kernel clock discipline metrics, and upcoming leap second events.

The page auto-refreshes every 10 seconds.

---

## Features

- **NTP sync summary** parsed from `ntpstat` with colored status badges (synced / unsynced / no server)
- **NTP peer table** from `ntpq -pn` with tally codes, offset, jitter, reach, and association condition badges
- **Full `ntpq -c rv` variable dump** with priority-ordered fields and light unit labels
- **PPS discipline card** showing lock state, refid, offset, frequency, jitter, and wander
- **GPS/GPSD status card** from live `gpspipe` JSON (TPV + SKY) and NMEA sentence streams: fix quality, satellite count, HDOP/PDOP/VDOP, lat/lon, altitude, speed, and UTC time
- **Kernel timekeeping block** from `adjtimex --print` : offset (µs), frequency (raw + estimated ppm), max error, estimated error, time constant, and status word
- **Leap second awareness**: parses `leap-seconds.list` from disk to show the current TAI-UTC offset and any scheduled future leap second
- **Leap indicator badge** decoded from `ntpq -c rv` (none / insert pending / delete pending / alarm)
- **Service status cards** for both `ntpd` and `gpsd` via `systemctl` with `ps` fallback for non-systemd hosts
- **PPS sample viewer** via a 2-second `ppswatch` capture showing recent sequence numbers and nanosecond offsets
- **DST and stratum badges** in the page header so the most critical state is immediately visible
- **GPS year rollover warning** when NMEA sentences report an implausible year (common with old receivers that have a rollover bug)
- **Responsive layout**: two-column cards collapse to single-column on narrow screens

---

### lib.php — Core Library

All executable commands are defined in a single `allowed_commands()` array. The `run_cmd()` function accepts only a string key from that map; it is impossible to execute an arbitrary command through this interface. The PPS device path, the only runtime-variable component of any command, is passed through `escapeshellarg()`.

Each daemon output is handled by a dedicated parser:

| Function | Input | Output |
|---|---|---|
| `parse_ntpstat()` | `ntpstat` text | sync state, stratum, server, offset, poll interval |
| `parse_ntpq_peers()` | `ntpq -pn` table | array of peer rows with tally code |
| `parse_ntpq_rv()` | `ntpq -c rv` blob | status flags array + key/value map |
| `parse_ntpq_as()` | `ntpq -c as` table | association rows with condition fields |
| `parse_gpspipe_json()` | `gpspipe -w` JSON stream | latest TPV and SKY objects |
| `parse_nmea_stream()` | `gpspipe -r` NMEA stream | decoded GGA / GSA / RMC / VTG / ZDA / GLL fields |
| `parse_ppswatch()` | `ppswatch` output | sequence numbers and nanosecond offsets |
| `parse_adjtimex_print()` | `adjtimex --print` text | kernel clock fields (offset, freq, errors, etc.) |
| `parse_leap_seconds_list()` | file path | sorted NTP-epoch entries with TAI offset |

Additional helpers:

- `ntp_hex_to_unix_seconds()` — converts NTP hexadecimal timestamps (e.g. `reftime`) to Unix epoch, enabling human-readable "last sync age" display
- `adjtimex_freq_ppm_estimate()` — converts the raw kernel frequency integer to a parts-per-million estimate (divides by 65536 per Linux kernel convention)
- `leap_info_from_list()` — walks the leap-seconds.list entries to determine the current TAI-UTC offset and format any upcoming leap second event
- `badge_class_for()` / `leap_badge()` / `assoc_condition_badge_class()` — map state strings to CSS badge classes (`ok` / `warn` / `bad`)
- `nmea_degmin_to_decimal()` — converts NMEA degree-minute notation to signed decimal degrees
- `nmea_checksum_ok()` — validates NMEA sentence XOR checksums


## Dashboard Sections

| Card | Source | Key Metrics |
|---|---|---|
| **NTP Time** | `ntpq -c rv`, `adjtimex`, `leap-seconds.list`, system `date` | Current time, UTC offset, ISO-8601, epoch, stratum/refid, last reftime age, leap schedule, kernel clock fields |
| **NTP Sync Summary** | `ntpstat` | Sync state, upstream server, stratum, offset, poll interval |
| **PPS Discipline** | `ntpq -c rv` flags + `gpspipe` NMEA | PPS lock state, refid, stratum, offset, frequency, sys/clk jitter, clk wander |
| **GPSD Status** | `gpspipe -w` JSON + `gpspipe -r` NMEA | Fix type, satellite count, HDOP/PDOP/VDOP, lat/lon, altitude, speed, track, NMEA UTC time |
| **Services** | `systemctl` + `ps` fallback | Active state and full status output for `ntpd` and `gpsd` |
| **NTP Peers** | `ntpq -pn` merged with `ntpq -c as` | All peers with tally code, refid, stratum, reach, delay, offset, jitter, association condition |
| **NTPD Variables** | `ntpq -c rv` | Full key/value dump, priority-sorted, with unit labels |

---

## Requirements

### System

- Linux (tested on RHEL/CentOS 7+; compatible with any systemd-based distro)
- A GPS receiver attached via serial or USB, outputting NMEA sentences
- A 1 PPS signal wired to a PPS-capable serial port or GPIO, exposed as a `/dev/pps*` device via the `pps-ldisc` or `pps-gpio` kernel module
- `ntpd` (NTP Reference Implementation, not `chrony` or `systemd-timesyncd`)
- `gpsd` with `gpspipe` available
- `ppswatch` utility
- `adjtimex` command-line tool
- `systemctl` (or `ps` as fallback for SysV init)

### PHP

- PHP 5.4 or later (no composer dependencies; no extensions beyond the standard `pcre` and file I/O functions)
- `shell_exec()` must not be disabled in `php.ini`

### Web Server

- Any standard PHP-capable web server
- The PHP worker process must have execute permission for the binaries listed in `allowed_commands()` in `lib.php`

---

## Configuration

Open `lib.php` and edit the two constants near the top:

```php
$GLOBALS['PPS_DEVICE']  = '/dev/pps1';   // path to your PPS device
$GLOBALS['PPS_SAMPLES'] = 3;             // number of ppswatch lines to retain
```

The auto-refresh interval is set in `index.php`:

```php
$refresh = 10;   // seconds
```

If any binary path differs on your system (e.g. `ntpq` lives at `/usr/bin/ntpq` instead of `/usr/sbin/ntpq`), update the corresponding entry in the `allowed_commands()` array in `lib.php`.

---

## Security Notes

- **No user input reaches the shell.** Every command is looked up by a static key; the only dynamic value (`PPS_DEVICE`) is escaped with `escapeshellarg()`.
- **All rendered output is HTML-escaped** via `htmlspecialchars()` before being written to the page.
- **No authentication is included.** This dashboard is intended for deployment on a private or firewalled network. Do not expose it to the public internet without adding an authentication layer (HTTP Basic Auth, a reverse-proxy auth gateway, etc.).
- **`shell_exec()` is required.** If your hosting environment disables `shell_exec`, the dashboard will not function. This is expected behavior for a system monitoring tool running on a dedicated server.

---

## License

This project is released under the [MIT License](LICENSE).
