<?php

/*
 * This file stores the settings for the NebSvcMonitor.php program
 * Simply set the config below for your specific needs
 * Required php 7.3 and php7.3-curl (may work with older versions)to be installed on serve.
 *      Example install: sudo apt install php7.3-cli php7.3-curl
 * Depending on your environment, there may be additional steps to install PHP 7.3+
 *
 * Be sure to set your configuration in this file (NebulasServiceMonitorSettings.inc) as well as a few environment variables in the NebSvcMonitor.sh file.
 *
 *  Set the permission for the files NebSvcMonitor.sh and NebSvcMonitor.php via chmod +x NebSvcMonitor.sh && chmod +x NebSvcMonitor.php
 */

/*
 * Where should reports be sent?
 * Note: The message will probably go to your spam folder so you must whitelist the email address with the from address
 * TODO Add additional features in the future such as website and telegram bot
 */
$NSMSettings['reportToEmail'] = 'to@email.com';//Leave this empty to not have messages sent.

//Where should we say the email came from?
$NSMSettings['reportEmailFrom'] = 'from@email.com';

/*
 * Message logs can differ with the following levels:
 * 0 => 'success', 1 => 'info', 2 => 'notify', 3 => 'warn', 4 => 'error'
 * Set the integer below of when you should receive notification about a problem.
 * Default is '2'
 */
$NSMSettings['severityLevelMessageSend'] = '3';

//What is the name of the node
$NSMSettings['nodeName'] = 'Your-Node-Name';

//If the service is not found, should we restart the service?
$NSMSettings['restartServiceIfNotFound'] = true; //true or false

//Do you want to allow the program to restart the node - if set to false, you will still receive notifications/logs about it being down but it will not be restarted. Good for testing.
$NSMSettings['enableRestartService'] = true;

//Server limits before triggering errors
//The minimum amount of free ram available before triggering a error. For example if set to 20, the minimum amount of free ram must be 20%.
$NSMSettings['minFreeMemoryPercent'] = '15';

//The minimum amount of free swap space available before triggering a error. For example if set to 20, the minimum amount of free ram must be 20%.
$NSMSettings['minFreeSwapPercent'] = '15';

//Max 5 minute load before triggering a error. Basically, max load should be under your core count. For example with 8 cores, this should be a max of 7 but is not set in stone. Can be a decimal such as 4.5   //todo implement improved features with cat /proc/cpuinfo
$NSMSettings['restartMaxLoad5MinuteAvg'] = '3.5';

//Restart node if max load is reached
$NSMSettings['restartIfMaxLoadExceeded'] = true;

//When to restart node
//Restart node if we run out of minimum ram
$NSMSettings['restartMinFreeMemoryPercent'] = true;

//Restart node if we run out of swap space
$NSMSettings['restartMinFreeSwapPercent'] = true;

/*
 * If the node is behind in blocks and is not synced based on local rpc, when should it be restarted.
 * For example:
 * If set to 2, and the node is not in sync for two checks, it will be restarted (can be greater than 2). This is helpful for nodes booting up.
 * If set to 1, it will be restarted every time it's not synced.
 * If set to 0, the node will not be restarted
*/
$NSMSettings['nodeBehindRestartCount'] = 6;

/*
 * Set how many concurrent restart attempts should be performed. With the default of 3, the node will have to encounter an error 5 checks in a row before restarting. This is to stop continuous restart attempts every check. This is helpful for restart attempts due to performance issues(memory,cpu utilization). There is also a built in override to restart the node if it's found down regardless of this check
 * This can also be set to 0 to restart on all restart requests.
 */
$NSMSettings['repeatedRestartRequests'] = 3;
/*
 * If the node is not synced, how many blocks are acceptable per check to consider the node actively proceeding the sync process.
 * Note: If the node is being checked every 5 minutes(600 sec.), there would be about 20~ blocks generated in the cycle.
 * So, the node must complete a minimum of 20 blocks otherwise it will never complete the sync process.
 * Default 10 - looks at delayBetweenReports divided by block time of 15 seconds plus 10%
 */
$NSMSettings['nodeSyncMinBlockCountIncreasePercentage'] = 10;

/*
 * Restart node if behind but block height increasing at proper rate?
 * If a node is not synchronized, but the block height is increasing at a proper rate (as specified above), should it be restarted?
 * Default is false
 */
$NSMSettings['nodeRestartIfSyncSpeedFast'] = false;

/*
 * If the local node height is falling behind the external api block height, restart the node (after the config nodeBehindRestartCount is reached)
 */
$NSMSettings['nodeRestartIfLocalHeightNotEqualToExternal'] = true;


//Notify the server every 5 minutes about your nodes status. If using the service provided by @satoshiBytes, keep it set to 300 - future feature and currently not available
//Should be set to the cron job time (e.g. 5 minutes = 300 seconds).
$NSMSettings['delayBetweenReports'] = 300; //Default 300;

//If there are multiple neb processes found, stop and restart them.
$NSMSettings['restartServiceIfMultipleProcFound'] = true; //true or false

//How long in seconds to wait to verify the process restarted
$NSMSettings['restartServiceDelayCheck'] = 10; //Default 30

/*
 * What command to execute to restart the service? Be sure to use full directory location.
 * Example of command: ./neb -c testnet/conf/config.conf
 * Full directory where go-nebulas is located
 */
$NSMSettings['goNebulasDirectory'] = '/home/neb/go-nebulas/';

//Select the network to use this service as - it modifies the startup config.
$NSMSettings['selectedNetwork'] = 'mainnet'; //testnet or mainnet - is used to set options in the config

//Get current block height from external source
$NSMSettings['externalApiURL'] = 'https://' . $NSMSettings['selectedNetwork'] . '.nebulas.io';//'https://mainnet.nebulas.io';

//How many times should we try to restart .neb before giving up.
$NSMSettings['maxRestartAttempts'] = 5;

//Set the neb listening port
$NSMSettings['nebListenPort'] = '8685';

//We are going to store some log information locally for proper operation
$NSMSettings['localDataFile'] = $NSMSettings['goNebulasDirectory'] . 'NebSvcMonitor.data';

//How many events to store locally. This includes messages, block height, etc... in a JSON array.
// Minimum is 1 so to check that block height is increasing.
$NSMSettings['eventsToStoreLocally'] = 300;//Default is 300 - 288 is one days worth when being checked every 300 seconds

/*
 * Display via echo or add to log entries.
 * $NSMSettings['verbose'] = $NSMSettings['goNebulasDirectory'] . 'NebSvcMonitor.log';//echo or logFile.log
 * Since the PHP script is executed via a bash script (for numerous reasons), it's best to keep this set to echo and by using the config recommended in the readme, the log is stored in the file "NebSvcMonitor.log"
 */
$NSMSettings['verbose'] = 'echo';//echo or logFile.log

//Set max line count for the log - each run can as few at 5 lines to as many as 30+. Default is 1,000
$NSMSettings['logMaxLines']='1000';

//The name of the log file. Should match the same name as entered in the cron jobs and the 'NebSvcMonitor.sh' settings.
$NSMSettings['logName']='NebSvcMonitor.log';

//Stop here - UNUSED items below

/*The startup command along with what config to use. Please note - due to how the nebulas service is designed, the full directory of your configuration file must be included. If you clone this file into a sub-directory of go-nebulas such as NebulasNodeMonitor if the repo is cloned, the following config would be used with the user neb executing nebulas.
 * Example:  /home/neb/go-nebulas/neb -c /home/neb/go-nebulas/mainnet/conf/config.conf
*/
//$NSMSettings['nebStartServiceCommand'] = '/home/neb/go-nebulas/neb -c ' . $NSMSettings['selectedNetwork'] . '/conf/config.conf';
//    /home/neb/go-nebulas/neb -c /home/neb/go-nebulas/mainnet/conf/config.conf
//    /home/neb/go-nebulas/neb -c mainnet/conf/config.conf
