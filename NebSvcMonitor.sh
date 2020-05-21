#!/bin/sh

#Execution options:
## Start the program without storing additional logs:
#  ./NebSvcMonitor.sh > /dev/null &
#
#   ## Start the program and store logs:
#  ./NebSvcMonitor.sh >> NebSvcMonitor.log &
#   Logs stored on server within the go-nebulas directory with the name NebSvcMonitor.log

##Config section
#set the home directory - change "neb" to the user that owns neb (root is acceptable)
. /home/neb/.bashrc

#Set the go-nebulas directory with no trailing slash
goNebulasDir=/home/neb/go-nebulas

#Set the config file to use
nebConfigFile=mainnet/conf/config.conf

#Set how often the status of .neb should be checked in seconds (default is 300, every 5 minutes).
#This must also match the setting in the NebSvcMonitorSettings.inc config file for the variable $NSMSettings['delayBetweenReports']
checkIntervals=300

##On autocheck, where and if to log data
#suggested options:
# /dev/null
# NebSvcMonLog_sh.txt
##restartLogHandle="NebSvcMonLogRestarts.log"
messageLog="NebSvcMonitor.log"
##End of config
printf "Entered NebSvcMonitor.sh \n"
##Check to see if any variables were passed to the script
if [ $1 ]; then

  if [ "$1" = 'checkStatus' ]; then
    #check to see if the shell script is running
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | wc -l)
    printf "Entered checkStatus. Process found: %s\n" "$PROCESS_NUM"
    if [ "$PROCESS_NUM" = 1 ]; then
      #The shell script is running
      printf "Process is running.\n"
    else
      #The shell script is not running and needs to be started back up
      timeDate=$(date)
      printf "Process not running - restarting. Current server time and date %s\n" "$timeDate"
      exec "$goNebulasDir"/NebSvcMonitor.sh >>"$goNebulasDir"/"$messageLog" &
      exit
    fi
    exit
  elif [ "$1" = 'kill' ]; then
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | grep -v "kill" | wc -l)
    printf "Kill process - total found %s\n" "$PROCESS_NUM"
    if [ "$PROCESS_NUM" -gt 0 ]; then
      pkill NebSvcMonitor
    fi
    PROCESS_NUM=$(ps -ef | grep "NebSvcMonitor.sh" | grep -v "grep" | grep -v "checkStatus" | grep -v "tail" | grep -v "kill" | wc -l)
    printf "Killed - processes remaining: %s \n" "$PROCESS_NUM"
    exit
  elif [ "$1" = 'startNeb' ]; then
    printf "Entered startNeb\n"
    printf "%s/neb -c nebConfigFile\n" "$goNebulasDir"
    "$goNebulasDir"/neb -c $nebConfigFile > /dev/null &
  exit
  fi

fi

#Check to see if directory exists
if [ ! -d "$goNebulasDir" ]; then
  printf "Directory %s/ is not set correctly.\n
    Please edit this file and set your go-nebulas directory.\n" "$goNebulasDir"
  exit
elif [ "$1" = 'install' ]; then
  printf "Setting up required symbolic links.\n
To start the NebSvcMonitor, go to your go-nebulas directoy and execute the command:\n
./NebSvcMonitor.sh > /dev/null &\n"
  ln -s "$goNebulasDir"/NebulasNodeMonitor/NebSvcMonitor.sh "$goNebulasDir"/NebSvcMonitor.sh
  ln -s "$goNebulasDir"/NebulasNodeMonitor/NebSvcMonitor.php "$goNebulasDir"/NebSvcMonitor.php
  ln -s "$goNebulasDir"/NebulasNodeMonitor/NebSvcMonitorSettings.inc "$goNebulasDir"/NebSvcMonitorSettings.inc
  exit
elif ! [ -f 'neb' ]; then
  printf "Please run this command from the go-nebulas directory.\n"
  exit
fi

PHP=$(which php)
printf "Begin NebSvcMonitor.sh\n
PHP execution location: %s\n
The set go-nebulas directory: %s/\n" "$PHP" "$goNebulasDir"
while true; do
  printf "Running NebSvcMonitor\n"
  begin=$(date +%s)
  "$PHP" "$goNebulasDir"/NebSvcMonitor.php >>"$goNebulasDir"/"$messageLog" &
  end=$(date +%s)
  opTime=$((end - begin))
  printf "Completed. Operation took: %s seconds. Next check will occur in %s seconds\n\n" "$opTime" "$checkIntervals"
  if [ $(($end - $begin)) -lt $checkIntervals ]; then
    sleep $(($begin + $checkIntervals - $end))
  fi
done