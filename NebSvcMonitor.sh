#!/bin/sh
#Running options:
# NebSvcMonitor.sh > /dev/null &
# NebSvcMonitor.sh > NebSvcMonLog.txt &
#Set the go-nebulas directory with a trailing slash
goNebulasDir=/home/neb/go-nebulas/
PHP=$(which php)

echo Begin NebSvcMonitor.sh
echo PHP execution location: "$PHP"
echo Set go-nebulas directory: "$goNebulasDir"

echo Setting symbolic links
ln -s NebSvcMonitor.php "$goNebulasDir"NebSvcMonitor.php
ln -s NebSvcMonitorSettings.inc "$goNebulasDir"NebSvcMonitorSettings.inc

while true; do
  echo Running NebSvcMonitor
  begin=$(date +%s)
  $PHP "$goNebulasDir"NebSvcMonitor.php
  end=$(date +%s)
  if [ $(($end - $begin)) -lt 60 ]; then
    sleep $(($begin + 60 - $end))
  fi
done