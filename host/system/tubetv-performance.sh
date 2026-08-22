#!/bin/sh
set -eu

install -d -o tubetv -g tubetv -m 0750 /var/cache/tubetv-segments

for governor in /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor; do
    [ -w "$governor" ] && printf '%s\n' performance > "$governor" || true
done

[ -w /sys/module/r8723bs/parameters/rtw_power_mgnt ] && printf '%s\n' 0 > /sys/module/r8723bs/parameters/rtw_power_mgnt || true
[ -w /proc/sys/vm/swappiness ] && printf '%s\n' 20 > /proc/sys/vm/swappiness || true
[ -w /proc/sys/net/core/rmem_max ] && printf '%s\n' 8388608 > /proc/sys/net/core/rmem_max || true
[ -w /proc/sys/net/core/wmem_max ] && printf '%s\n' 8388608 > /proc/sys/net/core/wmem_max || true
