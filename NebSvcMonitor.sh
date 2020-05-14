#!/bin/sh

#Execution options:
# ./NebSvcMonitor.sh > /dev/null &
#   No logs stored
# ./NebSvcMonitor.sh > NebSvcMonLog_sh.log &
#   Logs stored on server within the go-nebulas directory with the name NebSvcMonLog_sh.log

##Config section
#set the home directory
. /home/neb/.bashrc

#Set the go-nebulas directory with a trailing slash
goNebulasDir=/home/neb/go-nebulas/

#Set how often the status of .neb should be checked in seconds (default is 300, every 5 minutes).
#This must also match the setting in the NebSvcMonitorSettings.inc config file for the variable $NSMSettings['delayBetweenReports']
checkIntervals=300

##On autocheck, where and if to log data
#suggested options:
# /dev/null
# NebSvcMonLog_sh.txt
restartLogHandle="NebSvcMonLog_sh.log"
##End of config


printf "Entered NebSvcMonitor.sh \n"
##Check to see if any variables were passed to the script

if [ $1 ]; then
  if [ "$1" = 'checkStatus' ]; then
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | wc -l)
    printf "Entered checkStatus. Process found: %s\n" "$PROCESS_NUM"
    if [ "$PROCESS_NUM" = 1 ]; then
      printf "Process is running.\n"
    else
      printf "Process not running - restarting.\n"
      exec "$goNebulasDir"NebSvcMonitor.sh >"$goNebulasDir""$restartLogHandle" &
      exit
    fi
    exit
  fi
  if [ "$1" = 'kill' ]; then
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | grep -v "kill" | wc -l)
    printf "Kill process - total found %s\n" "$PROCESS_NUM"
    if [ "$PROCESS_NUM" -gt 0 ]; then
      pkill NebSvcMonitor
    fi
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | grep -v "kill" | wc -l)
    printf "Killed - processes remaining: %s \n" "$PROCESS_NUM"
    exit
  fi

  #Check to see if directory exists
  if [ ! -d "$goNebulasDir" ]; then
    printf "Directory %s is not set correctly.\n
    Please edit this file and set your go-nebulas directory.\n" "$goNebulasDir"
    exit
  elif [ "$1" = 'install' ]; then
    printf "Setting up required symbolic links.\n
To start the NebSvcMonitor, go to your go-nebulas directoy and execute the command:\n
./NebSvcMonitor.sh > /dev/null &\n"
    ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.sh "$goNebulasDir"NebSvcMonitor.sh
    ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitor.php "$goNebulasDir"NebSvcMonitor.php
    ln -s "$goNebulasDir"NebulasNodeMonitor/NebSvcMonitorSettings.inc "$goNebulasDir"NebSvcMonitorSettings.inc
    exit
  elif ! [ -f 'neb' ]; then
    printf "Please run this command from the go-nebulas directory.\n"
    exit
  fi
fi

PHP=$(which php)
printf "Begin NebSvcMonitor.sh\n
PHP execution location: %s\n
The set go-nebulas directory: %s\n" "$PHP" "$goNebulasDir"
while true; do
  printf "Running NebSvcMonitor\n"
  begin=$(date +%s)
  "$PHP" "$goNebulasDir"NebSvcMonitor.php &
  end=$(date +%s)
  opTime=$((end - begin))
  printf "Completed. Operation took: %s seconds. Next check will occur in %s seconds\n\n" "$opTime" "$checkIntervals"
  if [ $(($end - $begin)) -lt $checkIntervals ]; then
    sleep $(($begin + $checkIntervals - $end))
  fi
done