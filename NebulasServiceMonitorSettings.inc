<?php

/*
 * This file stores the settings for the NebulasServiceMonitor.php script
 * Simply set the config below for your specific needs
 * Required php to be installed on server (sudo apt install php7.3-cli)
 * Required curl (sudo apt install curl)
 */

class NSMSettings
{
//Where should reports be sent
    protected const reportTo = '';

//File where to store the node status
    protected const storeStatus = 'NebulasServiceMonitor.status';

//If the node is behind in blocks and is not synced based on local rpc, when should it be restarted.
//For example:
//If set to 2, and the node is not in sync for two checks, it will be restarted (can be greater than 2). This is helpful for nodes booting up.
//If set to 1, it will be restarted every time it's not synced.
//If set to 0, the node will not be restarted
    protected const nodeBehindRestartCount = 2;

//Notify the server every 5 minutes about your nodes status. If using the service provided by @satoshiBytes, keep it set to 600
    protected const delayBetweenReports = 600;

//If the service is not found, should we restart the service?
    protected const  restartService = true;//true or false

//If there are multiple neb processes found, stop and restart them.
    protected const restartServiceIfMultipleProcFound = true;//true or false

//How long in seconds to wait to verify the process restarted
    protected const restartServiceDelayCheck = 10;

//What command to execute to restart the service? Be sure to use full directory location.
//Example of command: ./neb -c testnet/conf/config.conf
//Full directory where go-nebulas is located
    protected const goNebulasDirectory = '/home/neb/go-nebulas/';

//The startup command along with what config to use.
    //public $nebRestartServiceCommand = 'neb -c testnet/conf/config.conf';
    protected const nebStartServiceCommand = 'neb -c mainnet/conf/config.conf';
    // public $nebStartConfigFile = 'mainnet/conf/config.conf';
    // public $nebProcName = 'neb';

//How many times should we try to restart .neb before giving up.
    protected const maxRestartAttempts = 8;
//Set the neb listening port
    protected const  nebListenPort = '8685';

}