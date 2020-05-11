<?php

/*
 * This file stores the settings for the NebulasServiceMonitor.php script
 * Simply set the config below for your specific needs
 * Required php to be installed on server (sudo apt install php7.3-cli)
 * Required curl (sudo apt install curl)
 */

class NSMSettings extends NebulasServiceMonitor
{
	//
	/*
	 * Where should reports be sent?
	 * Note: The message will probably go to your spam folder so you must whitelist the email address with the from address
	 * TODO Add additional features in the future such as website and telegram bot
	 */
	protected const reportToEmail = '';//Leave this empty to not have messages sent.

	//Where should we say the email came from?
	protected const reportEmailFrom = '';

	/*
	 * Message logs can differ with the following levels:
	 * 0 => 'success', 1 => 'info', 2 => 'notify', 3 => 'warn', 4 => 'error'
	 * Set the integer below of when you should receive notification about a problem.
	 * Default is '2'
	 */
	protected const severityLevelMessageSend = '2';

	//What is the name of the node
	protected const nodeName = 'My_node_1';

	//If the service is not found, should we restart the service?
	protected const  restartServiceIfNotFound = true; //true or false

	//Do you want to allow the program to restart the node - if set to false, you will still receive notifications/logs about it being down but it will not be restarted. Good for testing.
	protected const enableRestartService = true;

	//Server limits before triggering errors
	//The minimum amount of free ram available before triggering a error. For example if set to 20, the minimum amount of free ram must be 20%.
	protected const minFreeMemoryPercent = '20';

	//The minimum amount of free swap space available before triggering a error. For example if set to 20, the minimum amount of free ram must be 20%.
	protected const minFreeSwapPercent = '20';

	//Max 5 minute load before triggering a error. Basically, max load should be under your core count. For example with 8 cores, this should be a max of 7 but is not set in stone. Can be a decimal such as 4.5   //todo implement improved features with cat /proc/cpuinfo
	protected const restartMaxLoad5MinuteAvg = '3.5';

	//Restart node if max load is reached
	protected const restartIfMaxLoadExceeded = true;

	//When to restart node
	//Restart node if we run out of minimum ram
	protected const restartMinFreeMemoryPercent = true;

	//Restart node if we run out of swap space
	protected const restartMinFreeSwapPercent = true;

	/*
	 * If the node is behind in blocks and is not synced based on local rpc, when should it be restarted.
	 * For example:
	 * If set to 2, and the node is not in sync for two checks, it will be restarted (can be greater than 2). This is helpful for nodes booting up.
	 * If set to 1, it will be restarted every time it's not synced.
	 * If set to 0, the node will not be restarted
	*/
	protected const nodeBehindRestartCount = 3;

	/*
	 * If the node is not synced, how many blocks are acceptable per check to consider the node actively proceeding the sync process.
	 * Note: If the node is being checked every 5 minutes(600 sec.), there would be about 20~ blocks generated in the cycle.
	 * So, the node must complete a minimum of 20 blocks otherwise it will never complete the sync process.
	 * Default 10 - looks at delayBetweenReports divided by block time of 15 seconds plus 10%
	 */
	protected const nodeSyncMinBlockCountIncreasePercentage = 10;

	//Notify the server every 5 minutes about your nodes status. If using the service provided by @satoshiBytes, keep it set to 600 - future feature and currently not available
	protected const delayBetweenReports = 600;//Should be set to the cron job time (e.g. 5 minutes = 600 seconds).

	//If there are multiple neb processes found, stop and restart them.
	protected const restartServiceIfMultipleProcFound = true; //true or false

	//How long in seconds to wait to verify the process restarted
	protected const restartServiceDelayCheck = 10;

	/*
	 * What command to execute to restart the service? Be sure to use full directory location.
	 * Example of command: ./neb -c testnet/conf/config.conf
	 * Full directory where go-nebulas is located
	 */
	protected const goNebulasDirectory = '/home/neb/go-nebulas/';

	//Select the network to use this service as - it modifies the startup config.
	protected const selectedNetwork = 'testnet';

	//Get current block height from external source
	protected const externalApiURL = 'https://testnet.nebulas.io';//'https://mainnet.nebulas.io';

	/*The startup command along with what config to use. Please note - due to how the nebulas service is designed, the full directory of your configuration file must be included. If you clone this file into a sub-directory of go-nebulas such as NebulasNodeMonitor if the repo is cloned, the following config would be used with the user neb executing nebulas.
	 * Example:  /home/neb/go-nebulas/neb -c /home/neb/go-nebulas/mainnet/conf/config.conf
	*/
	//protected const nebStartServiceCommand = NSMSettings::goNebulasDirectory . 'neb -c ' . NSMSettings::goNebulasDirectory . NSMSettings::selectedNetwork . '/conf/config.conf';
	protected const nebStartServiceCommand = '/home/neb/go-nebulas/neb -c mainnet/conf/config.conf';
	//    /home/neb/go-nebulas/neb -c /home/neb/go-nebulas/mainnet/conf/config.conf
	//    /home/neb/go-nebulas/neb -c mainnet/conf/config.conf

	//How many times should we try to restart .neb before giving up.
	protected const maxRestartAttempts = 8;

	//Set the neb listening port
	protected const  nebListenPort = '8685';

	//We are going to store some log information locally for proper operation
	protected const  localLogFile = 'NebulasServiceMonitor.log';

	//How many events to store locally. This includes messages, block height, etc... in a JSON array.
	// Minimum is 1 so to check that block height is increasing.
	protected const  eventsToStoreLocally = 20;
}