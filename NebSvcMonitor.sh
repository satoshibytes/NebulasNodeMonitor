#!/bin/sh
#Running options:
# NebSvcMonitor.sh > /dev/null &
# NebSvcMonitor.sh > NebSvcMonLog.txt &
#Set the go-nebulas directory with a trailing slash
goNebulasDir=/home/neb/go-nebulas/
#Set how often the status of .neb should be checked in seconds (default is 300, every 5 minutes).
#This must also match the setting in the NebSvcMonitorSettings.inc config file for the variable $NSMSettings['delayBetweenReports']
checkIntervals=60
PHP=$(which php)

createSymbolicLinks() {
  printf "Creating symbolic links\n"
  ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.sh "$goNebulasDir"NebSvcMonitor.sh
  ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.php "$goNebulasDir"NebSvcMonitor.php
  ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitorSettings.inc "$goNebulasDir"NebSvcMonitorSettings.inc
}
file="$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.sh
#Check to see if directory exists
if [ ! -d "$goNebulasDir" ]; then
  printf "Directory %s is not set correctly.\n
    Please edit this file and set your go-nebulas directory.\n" "$goNebulasDir"
  exit
elif [ "$1" = 'install' ]; then
  printf "Setting up required symbolic links.\n
To start the NebSvcMonitor, go to your go-nebulas directoy and execute the command:\n
./NebSvcMonitor.sh > /dev/null &\n"
  createSymbolicLinks
  ##ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.sh "$goNebulasDir"NebSvcMonitor.sh
  ##ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.php "$goNebulasDir"NebSvcMonitor.php
  ##ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitorSettings.inc "$goNebulasDir"NebSvcMonitorSettings.inc
  exit
elif ! [ -f 'neb' ]; then
  printf "Please run this command from the go-nebulas directory.\n"
  exit
fi

printf "Begin NebSvcMonitor.sh
PHP execution location: %s
eSet go-nebulas directory: %s" "$PHP" "$goNebulasDir"

while true; do
  echo Running NebSvcMonitor
  begin=$(date +%s)
  $PHP "$goNebulasDir"NebSvcMonitor.php
  end=$(date +%s)
  if [ $(($end - $begin)) -lt $checkIntervals ]; then
    sleep $(($begin + $checkIntervals - $end))
  fi
done
