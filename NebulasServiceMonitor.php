<?php
/*
 * This script was created to monitor the status of a Nebulas node.
 * It should work as-is on all modern Debian (Ubuntu, etc...) based systems (have not tested on RHEL (should work) or BSD (probably needs some modifications) as of yet).
 *
 * Be sure to set your configuration in the NebulasServiceMonitorSettings.inc file.
 * Script requirements
 * ->Server must have PHP 7 (possibly 5.6) or later installed (apt install php-cli php-curl)
 * ->the file NebulasServiceMonitor.php and neb need to be owned by the same users (for some systems, it may be required to change who php runs as or give sudo access)//TODO check permission services
 * ->Server must have curl installed
 * ->chmod +x NebulasServiceMonitor.php
 * execution: php NebulasServiceMonitor.php REQ
 * ->php NebulasServiceMonitor.php stopNeb
 * ->php NebulasServiceMonitor.php startNeb
 * ->php NebulasServiceMonitor.php status
 *
 *
 *
 *
 */

if (isset($argv[1])) { //&& $argv[2] == 'fromBash'
	$doProcess = $argv[1];
} else {
	$doProcess = 'statusCheck';
}
//Include the settings
require_once "NSMSettings.inc";
//Call the class
$NebulasServiceMonitor = new NebulasServiceMonitor();
//Do a process as defined via command line4
$NebulasServiceMonitor->doProcess($doProcess);

class NebulasServiceMonitor
{
	//Define initial variables
	private $restart = false; //does the node need to be restarted - set initial to false
	private $restartAttempts = 0; //count how many restart attempts have been made
	private $messages = []; //store any messages from the processes. All messages contain a result field which can be either success, warn, fail.
	private $nodeStatus; //Current node status - Can be online or offline
	private $nodeProcStatus; //The status of the nodes process id - can be live, killed, zombie
	private $synchronized; //Current sync status - can be true or false
	private $nodeBlockHeight; //Current node block height
	//   private $serverLoad; //Current server load stats as array
	private $serverHWUtilization; //Current server hardware utilization as array
	private $localLogHistory; //Store the local log in a array
	private $localLogLastCall; //Store the local log in a array
	private $synchronizedBehindCount; //Variable to store how long a node has been behind
	private $externalNebState; //Store the nebstate from external API
	private $localLogLatest;//
	private $severityMessageArray = [0 => 'success', 1 => 'info', 2 => 'notify', 3 => 'warn', 4 => 'error'];
	private $severityMessageMax = 0;
	public $about = [//About
	                 'version'            => '0.1',
	                 'name'               => 'Nebulas Service Monitor',
	                 'creator'            => '@SatoshiBytes',
	                 'warning'            => 'Work in progress and experimental - do not use on a live node server',
	                 'github'             => 'https://github.com/satoshibytes/NebulasNodeMonitor/',
	                 'available commands' => ['serverStatus' => 'Displays the information about the node and resource usage',
	                                          'showSettings' => 'Shows all the settings as set in the NSMSettings.inc file',
	                                          'statusCheck'  => 'Check the status of the node and intervene if necessary - This is the default action',
	                                          'showStatus'   => 'Check the status of the node but do not intervene',
	                                          'killNeb'      => 'Kills the process - cal alternatively use stopNeb',
	                                          'startNeb'     => 'Checks to see if the node is running then starts the node up if not.',
	                                          'nodeStatus'   => 'Check to see if the node is synchronized',
	                                          'readLog'      => 'Read the local log message',
	                                          'readLastLog'  => 'Read just the last log',
	                                          'eraseLog'     => 'Delete the log file',
	                                          'testEmail'    => 'Send a test email to the address listed in the config.']
	];

	public function doProcess($doThis) //This are the available actions.
	{//readLog
		switch ($doThis) {
			case 'testEmail':
				$this->readWriteLog('read');
				$this->reportData('testEmail');
				break;
			case 'readLog':
				$this->readWriteLog('read');
				print_r($this->localLogHistory);//maybe set it to read the entire log
				break;
			case 'readLastLog':
				$this->readWriteLog('readLastLog');
				print_r($this->localLogLastCall);//maybe set it to read the entire log
				break;
			case 'eraseLog':
				$this->readWriteLog('erase');
				print_r($this->localLogHistory);//Erase the log
				break;
			case'showSettings':
				print_r(get_defined_constants(true));
				break;
			case'statusCheck':
				$this->statusCheck();
				break;
			case'showStatus':
				$this->showStatus(); //TODO Make content pretty
				//print the messages
				print_r($this->messages);
				break;
			case'killNeb':
				$this->nodeProcId('kill');
				break;
			case'startNeb':
				$this->startNeb();
				break;
			case'about':
				print_r($this->about);
				break;
			case'help':
				print_r($this->about);
				break;
			default:
				print_r($this->about);
				break;
		}
	}

	private function statusCheck()
	{//This is the primary status checker and restart function
		/*
		 * Steps:
		 * 1) Check to see if there is a response from the node
		 *      a) Response good.
		 * 			i) get external api block height and compare it with local height. If height does not match or is greater than 1 (due to block time gen), report a error.
		 *          ii) Check resource usage. If resources exceed specified, restart the node and/or notify operator. Submit data to website.
		 *      b) Response good but not in sync
		 *          i) Check to see if the node is syncing up based on the previously recorded height.
		 *          ii) If height is not increasing, trigger restart.
		 *          iii)
		 * 2)No response
		 *      a) Kill all processes and restart the node.
		 *      b) Check to see if the node begins responding via RPC.
		 *      c) If no response for X amount of attempts, stop trying and notify operator (trigger statrup of backup node?)
		 * 3) If restarted, verify block height increase
		 *      a) Store the latest X number of attempts in the local log.
		 *      b) If block height is not increasing, restart the node again
		 *      c) If block height is not restored to sync state after X attempts, restart the node.
		 *4) Send report to external server for logging and contact node admin
		 *      a) Once the server receives the log, it will decide to contact the op or to even startup a secondary node.
		 *
		 * Note: This function is what will decide if the node requires a restart.
		 *
		 * Additional feature: check block height vs other nodes.
		 *  Check hdd space on partition where blocks are located.
		 */
		//Get the server hardware status
		$this->serverStatus();
		//First check if node is synced.
		$this->nodeStatusRPC();
		$this->getExternalAPIData();
		//Get the historical log status.
		$this->readWriteLog('read');

		if ($this->nodeStatus == 'online') { //Node is running //TODO verify this entire section
			$externalNebStateBlockHeight = $this->externalNebState['result']['height'];
			$externalNebStateSynchronized = $this->externalNebState['result']['synchronized'];
			if ($this->synchronized == true) { //Node is online. Check server utilization
				//Compare block height to the api should be within 1 block of one another (due to short block gen timing).
				if ($externalNebStateSynchronized == true) {
					//In case of block being generated during check, allow for a 1 block tolerance
					$acceptableHeight = [$this->externalNebState['result']['height'] - 1,
					                     $this->externalNebState['result']['height'],
					                     $this->externalNebState['result']['height'] + 1];
					if (in_array($this->nodeBlockHeight, $acceptableHeight)) { //Block heights do not match local vs external - we will not restart on this error
						//TODO make it a option)
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => "The node is reporting to be synced.",
							'result'      => 'success',
							'time'        => time()
						];
						$this->synchronizedBehindCount = 0; //Set the count to 0 for the current log status
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => "The node is reporting to be synced.",
							'result'      => 'success',
							'time'        => time()
						];
					} else {//Node is behind
						$diff = $this->externalNebState['result']['height'] - $this->nodeBlockHeight;
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => "The local node states that it is in sync but is behind when compared to external API results.\n 
                            Local Height: $this->nodeBlockHeight,| External Height: {$this->externalNebState['result']['height']} | Diff: {$diff}",
							'result'      => 'success',
							'time'        => time()];
					}
				} else {
					$this->messages[] = [
						'function'    => 'statusCheck',
						'messageRead' => "The node is reporting to be synced but the external api node was not available for verification.",
						'result'      => 'notify',
						'time'        => time()
					];
				}
			} else {
				//The node is reporting not being synced.
				$this->synchronizedBehindCount = $this->localLogLastCall['synchronizedBehindCount'] + 1; //Increase the count
				//Check the local log to see if block height is increasing and compare it to the user defined variables to confirm synchronization is happening within a minimum speed and if the node was synced last check.
				if ($this->localLogLastCall['synchronized'] == false) {//The last check resulted in a failed synchronized result. Let's see if the block height is increasing at a proper rate.
					//First see if the height increased at all
					if ($this->localLogLastCall['blockHeight'] == $this->nodeBlockHeight) {//The block height did not increase.
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => "The block height did not increase from the last check. Setting restart required to true. Log Last Height: {$this->localLogLastCall['blockHeight']} | Current Height: {$this->nodeBlockHeight}.",
							'result'      => 'error',
							'time'        => time()];
						$this->restart = true;
					} else { //The block height did increase - let's see if its within acceptable rate
						//What is the minimum amount of blocks generated we should accept.
						$minBlocksGen = (NSMSettings::delayBetweenReports / 15) + ((NSMSettings::delayBetweenReports / 15) * (NSMSettings::nodeSyncMinBlockCountIncreasePercentage / 100));//TODO look at this line closer
						$blockHeightIncreaseCount = $this->localLogLastCall['blockHeight'] + $minBlocksGen;
						if ($this->nodeBlockHeight < $blockHeightIncreaseCount) {
							//Block height increasing too slowly
							$this->messages[] = [
								'function'    => 'statusCheck',
								'messageRead' => "Block height increasing too slowly.",
								'result'      => 'warn',
								'time'        => time()
							];
						} else {
							//Block height is increasing fast enough
							$this->messages[] = [
								'function'    => 'statusCheck',
								'messageRead' => "Block height increasing at proper rate.",
								'result'      => 'info',
								'time'        => time()
							];
						}
						if ($this->synchronizedBehindCount > NSMSettings::nodeBehindRestartCount) { //The node is behind for the max amount of checks
							$this->messages[] = [
								'function'    => 'statusCheck',
								'messageRead' => "The node is behind for the max amount of checks. Setting restart required to true. Local Height: $this->nodeBlockHeight,| External Height: {$this->externalNebState['result']['height']}",
								'result'      => 'error',
								'time'        => time()
							];
							$this->synchronizedBehindCount = 0;
							$this->restart = true;
						}
					}
				}
			}
		} else { //Node is offline - find a procId, kill it and mark as restart required.
			$this->messages[] = [
				'function'    => 'statusCheck',
				'messageRead' => "The node was found to be offline. Restarting node.",
				'result'      => 'error',
				'time'        => time()
			];
			if (NSMSettings::restartServiceIfNotFound == true) {//Should we restart the node?
				$this->restart = true;//Set the node to restart
				$this->nodeProcId('kill');//See if the node is online and if so, terminate it.
			} else {
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => "The NebulasServiceMonitor found the node to be offline however, it is set to not start in the config (restartServiceIfNotFound).",
					'result'      => 'notify',
					'time'        => time()
				];
			}
		}
		if ($this->restart == true) { //nodeProcId found no running neb functions - restart the service
			if (NSMSettings::enableRestartService == true)
				$this->startNeb();//Start neb
			else {
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => "The NebulasServiceMonitor found the node to require restarting however, it is set to not restart in the config (enableRestartService).",
					'result'      => 'notify',
					'time'        => time()
				];
			}
		}
		$this->readWriteLog('write'); //Write the data to the local log
		if ($this->severityMessageMax >= NSMSettings::severityLevelMessageSend) {//send message
			$this->reportData();
		}
	}

	private function showStatus()
	{//Show the status of the node when called
		$this->nodeProcId();    //Get process id
		$this->serverStatus(); //Check load and mem usage
		$this->nodeStatusRPC();//Get the node status via RPC req
	}

	private function readWriteLog($req = 'read')
	{   //Store the current settings in a local file to verify the node status/
		//Default is to read but can specify write and erase.
		if ($req == 'erase') {
			unlink(NSMSettings::localLogFile);
		} else if ($req == 'writeInitial') {
			$this->showStatus();
			$this->localLogLatest[time()] = [//New data to add to the log
			                                 'restartRequested'        => $this->restart,
			                                 'restartAttempts'         => $this->restartAttempts,
			                                 'nodeStatus'              => $this->nodeStatus,
			                                 'synchronized'            => $this->synchronized,
			                                 'synchronizedBehindCount' => $this->synchronizedBehindCount,
			                                 'blockHeight'             => $this->nodeBlockHeight,
			                                 'serverHWUtilization'     => $this->serverHWUtilization,
			                                 'reportTime'              => time(),
			                                 'messageSeverityLevel'    => $this->severityMessageArray[$this->severityMessageMax],
			                                 'ExternalAPIStatus'       => $this->externalNebState,
			                                 'messages'                => $this->messages];
			file_put_contents(NSMSettings::localLogFile, json_encode($this->localLogLatest)); //Store the log
			chmod(NSMSettings::localLogFile, 0755);
			$req = 'write';
		}

		if (!file_exists(NSMSettings::localLogFile)) { //Set the initial file if it does not exist
			$this->readWriteLog('writeInitial');
		}
		//Get the log file
		$statusLogArr = json_decode(file_get_contents(NSMSettings::localLogFile), true); //Need to get data regardless
		if ($req == 'read') { //Get the status from the file. Stored in JSON array.
			$this->localLogHistory = $statusLogArr;
			$key = array_key_first($statusLogArr);
			$this->localLogLastCall = $statusLogArr[$key]; //Store only the last results of the log in a var to access in other locations.
		} else if ($req == 'readLastLog') {
			$this->localLogHistory = $statusLogArr;
			$key = array_key_first($statusLogArr);
			$this->localLogLastCall = $statusLogArr[$key];
		} else { //write to file
			$this->maxLogSeverityNotice();

			$this->localLogLatest[time()] = [//New data to add to the log
			                                 'restartRequested'        => $this->restart,
			                                 'restartAttempts'         => $this->restartAttempts,
			                                 'nodeStatus'              => $this->nodeStatus,
			                                 'synchronized'            => $this->synchronized,
			                                 'synchronizedBehindCount' => $this->synchronizedBehindCount,
			                                 'blockHeight'             => $this->nodeBlockHeight,
			                                 'reportTime'              => time(),
			                                 'messageSeverityLevel'    => $this->severityMessageArray[$this->severityMessageMax],
			                                 'ExternalAPIStatus'       => $this->externalNebState,
			                                 'serverHWUtilization'     => $this->serverHWUtilization,
			                                 'messages'                => $this->messages];
			if ($this->localLogHistory)
				$NewLog = $this->localLogLatest + $this->localLogHistory;
			else
				$NewLog = $this->localLogLatest;
			$cnt = count($NewLog);
			if ($cnt >= NSMSettings::eventsToStoreLocally) { //See if the array has more inputs then specified in the config
				unset($NewLog[array_key_last($NewLog)]);
			}
			file_put_contents(NSMSettings::localLogFile, json_encode($NewLog)); //Store the log
		}
	}

	function maxLogSeverityNotice()
	{//Keep track of the severity of messages
		foreach ($this->messages as $key => $val) {
			@$thisVal = array_search($val['result'], $this->severityMessageArray);
			if ($thisVal > $this->severityMessageMax)
				$this->severityMessageMax = $thisVal;
		}
	}

	private function serverStatus()
	{//Check the server hardware utilization. //TODO add more checks such as storage space
		//Check the cpu utilization
		$loadRaw = sys_getloadavg();
		$loadMessage = "1 minute: {$loadRaw[0]} | 5 minutes: {$loadRaw[1]} | 15 minutes: {$loadRaw[2]}";
		$this->serverHWUtilization['cpu'] = ['avgLoad1min'  => $loadRaw[0],
		                                     'avgLoad5min'  => $loadRaw[1],
		                                     'avgLoad15min' => $loadRaw[2]];
		if ($loadRaw[1] > NSMSettings::restartMaxLoad5MinuteAvg) {//Exceeded 5 min load average
			if (NSMSettings::restartIfMaxLoadExceeded) {
				$this->restart = true;
				$messageExtended = " Node will reboot based on config settings";
			}
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => "Average 5 minute server load average exceeded the specified max load. Current load: {$loadRaw[1]}." . $messageExtended,
				'result'      => 'error',
				'time'        => time()
			];
			unset($messageExtended);
		}
		//End check the server load

		//Get server memory utilization
		$memoryUsageRaw = shell_exec("vmstat -s -S M");//Grab the current hardware stats - view as as MB ||grep -E memory\|swap
		$memoryUsageRaw = preg_replace('/[ ]{2,}/', ' ', $memoryUsageRaw);//clean double spaces
		$memoryUsageArrayUnclean = explode("\n", $memoryUsageRaw);//convert data into array
		$memoryUsageArrayUnclean = array_filter($memoryUsageArrayUnclean);
		$memoryUsageArray = $memoryUsageDataArray = [];//Set array

		foreach ($memoryUsageArrayUnclean as $val) {//Clean spaces
			$val = ltrim($val);
			$memoryUsageArray[] = $val;
		}
		foreach ($memoryUsageArray as $key => $val) {//Step through the array (set default key to track)
			$valExp = explode(' ', $val);//Separate by spaces
			$thisSize = $valExp[0];
			if (strlen($valExp[1]) == 1) {
				$thisSizeType = $valExp[1];
				@$thisName = $valExp[2] . ucfirst($valExp[3]) . ucfirst($valExp[4]);
			} else {
				$thisSizeType = '';
				@$thisName = $valExp[1] . ucfirst($valExp[2]) . ucfirst($valExp[3]);
			}
			$memoryUsageDataArray[$thisName] = $thisSize;
			unset($thisSizeType, $thisSize, $thisName);
		}
		$freeMemoryPercent = ($memoryUsageDataArray['freeMemory'] / $memoryUsageDataArray['totalMemory']) * 100;
		$freeSwapPercent = ($memoryUsageDataArray['freeSwap'] / $memoryUsageDataArray['totalSwap']) * 100;
		$this->serverHWUtilization['memory'] = ['systemMemory'      => $memoryUsageDataArray['totalMemory'],
		                                        'freeMemory'        => $memoryUsageDataArray['freeMemory'],
		                                        'freeMemoryPercent' => $freeMemoryPercent,
		                                        'freeSwap'          => $memoryUsageDataArray['freeSwap'],
		                                        'totalSwap'         => $memoryUsageDataArray['totalSwap'],
		                                        'freeSwapPercent'   => $freeSwapPercent];
		if ($freeMemoryPercent < NSMSettings::minFreeMemoryPercent) {//Exceeded free ram
			if (NSMSettings::restartMinFreeMemoryPercent == true) {
				$this->restart = true;
				$messageExtended = " Node will reboot based on config settings";

			}
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => "Memory utilization exceeded specified minimum free memory amount percent. Free memory: $freeMemoryPercent." . $messageExtended,
				'result'      => 'error',
				'time'        => time()
			];
		}
		if ($freeSwapPercent < NSMSettings::minFreeSwapPercent) {//Exceeded free ram
			if (NSMSettings::restartMinFreeSwapPercent == true) {
				$this->restart = true;
				$messageExtended = " Node will reboot based on config settings";
			}
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => "SWAP space utilization exceeded specified minimum free memory amount percent. Free swap amount: $freeSwapPercent." . $messageExtended,
				'result'      => 'error',
				'time'        => time()
			];
		}
		//End server memory utilization

		//Result message
		$this->messages[] = [
			'function'    => 'serverStatus',
			'messageRead' => "Server Load: {$loadMessage}\nFree Memory Percentage: {$freeMemoryPercent}\nFree Swap Space Percent: $freeSwapPercent",
			'result'      => 'info',
			'time'        => time()
		];
		return null;//Results stored in pre-defined variables
	}

	private function getExternalAPIData($type = '/v1/user/nebstate')
	{ //Get the block height from a external source
		// /v1/user/nebstate - standard call
		$externalURL = NSMSettings::externalApiURL . $type;
		$curlRequest = $this->curlRequest($externalURL, $timeout = 10);
		if ($curlRequest['status'] == 'success') {
			$externalNebStateArray = json_decode($curlRequest['data'], true);
		}
		if (json_last_error() == JSON_ERROR_NONE) { //API data successfully retrieved.
			$this->externalNebState = $externalNebStateArray;
			$this->messages[] = [
				'function'    => 'getExternalAPIData',
				'messageRead' => "External API block height: {$externalNebStateArray['result']['height']}",
				'result'      => 'info',
				'time'        => time()
			];
		} else {
			$this->messages[] = [
				'function'    => 'getExternalAPIData',
				'messageRead' => "Error obtaining API data from $externalURL",
				'result'      => 'error',
				'time'        => time()
			];
			$this->externalNebState = null;
		}
	}

	private function curlRequest($url, $timeout = 5)
	{//Standard curl call (GET default)
		$ch = curl_init();
		$curlOptions = [CURLOPT_URL            => $url,
		                CURLOPT_HEADER         => false,
		                CURLOPT_TIMEOUT        => $timeout,
		                CURLOPT_CONNECTTIMEOUT => $timeout,
		                CURLOPT_RETURNTRANSFER => true];
		curl_setopt_array($ch, $curlOptions);

		$data = curl_exec($ch);

		if (curl_errno($ch)) {
			$this->messages[] = [
				'function'    => 'curlRequest',
				'messageRead' => 'Curl request failed. URL: ' . $url,
				'result'      => 'error',
				'time'        => time()
			];
			$status = 'error';
			$this->nodeStatus = 'offline';
		} else {//Successful response
			$status = 'success';
			$this->nodeStatus = 'online';
		}
		curl_close($ch);//close curl
		return ['status' => $status,
		        'data'   => $data];
	}

	private function nodeStatusRPC() //Check the node status via CURL request.
	{
		$port = NSMSettings::nebListenPort;
		$curlRequest = $this->curlRequest("http://localhost:{$port}/v1/user/nebstate");
		if ($curlRequest['status'] == 'success') {
			$nodeStatusArray = json_decode($curlRequest['data'], true);
			$this->nodeStatus = 'online';
		}
		if (json_last_error() == JSON_ERROR_NONE && $this->nodeStatus == 'online') { //Node is online - lets check the status
			$this->synchronized = $nodeStatusArray['result']['synchronized'];
			$this->nodeBlockHeight = $nodeStatusArray['result']['height'];
			$this->restart = false;
			$this->nodeStatus = 'online';
			$this->messages[] = [
				'function'    => 'nodeStatusRPC',
				'messageRead' => "Node Online. Block height: {$nodeStatusArray['result']['height']}",
				'result'      => 'success',
				'time'        => time()
			];
			if ($this->synchronized != true) { //Check the status file for the last recorded status
				$this->messages[] = [
					'function'    => 'nodeStatusRPC',
					'messageRead' => 'Node not synchronized',
					'result'      => 'warn',
					'time'        => time()
				];
			}
		} else { //No response from node - node is considered offline
			//$this->restart = true;
			$this->nodeStatus = 'offline';
			$this->messages[] = ['function'    => 'nodeStatusRPC',
			                     'messageRead' => 'Node offline',
			                     'result'      => 'error',
			                     'time'        => time()];
		}
		return null;//Results stored in pre-defined variables
	}

	private function nodeProcId($req = null)
	{ //Find the process id on the server and verify that there is only one process running (not counting children).
		$findNebProcGrep = '[' . NSMSettings::nebStartServiceCommand[0] . ']' . substr(NSMSettings::nebStartServiceCommand, 1); //Set the search string
		//$findNebProcCommand = NSMSettings::nebStartServiceCommand;
		$findNebProc = shell_exec("ps -ux | grep \"$findNebProcGrep\""); //Find the .neb process based on the $settings['restartServiceCommand'] setting
		echo "ps -ux | grep \"$findNebProcGrep\" \n$findNebProc";
		if ($findNebProc) { //Process found
			$findNebProcExp = explode('\n', $findNebProc); //Break down the results by line (ps -ux | grep "[n]eb -c mainnet/conf/config.conf")
			if (count($findNebProcExp) > 1) { //Multiple processes found - should only be one
				$this->messages[] = [
					'function'    => 'nodeProcId',
					'messageRead' => 'Multiple Neb processes found',
					'result'      => 'warn',
					'time'        => time()
				];
				if (NSMSettings::restartServiceIfMultipleProcFound == true) {
					$this->restart = true;
					$this->killAllNeb($findNebProcExp);
				}
			} else {
				if ($req == 'kill') {
					$this->messages[] = ['function'    => 'nodeProcId',
					                     'messageRead' => 'manual kill requested',
					                     'result'      => 'notify',
					                     'time'        => time()];
					$this->killAllNeb($findNebProcExp);
				} else {
					if (count($findNebProcExp) == 0) {
						$this->nodeStatus = 'offline';
					} else {
						if ($req == 'procId') {
							//return $findNebProcExp;
							$this->messages[] = [
								'function'    => 'nodeProcId',
								'messageRead' => "One Neb Process ID: {$findNebProcExp}",
								//'result'      => 'oneProcessFound',
								'result'      => 'null',
								'time'        => time()];
						} else {
							$this->nodeStatus = 'online';
						}
					}
				}
			}
		} else { //No process found
			if ($req === 'kill') {
				$this->messages[] = [
					'function'    => 'nodeProcId',
					'messageRead' => 'Manual kill requested but node not running',
					'message'     => 'killReqButNodeOffline',
					//'result'      => 'error',
					'time'        => time(),
					'custom'      => "REQ: $req"
				];
			} else {
				$this->nodeStatus = 'offline';
				$this->messages[] = [
					'function'    => 'nodeProcId',
					'messageRead' => 'No neb process found',
					//'message'      => 'markNodeAsOffline',
					'result'      => 'success',
					'time'        => time()
				];
			}
		}
		return null;//Results stored in pre-defined variables
	}

	private function killAllNeb($procList = null)
	{//Kill all running neb processes via it's procId
		if (!$procList || $procList == 'kill') { //If we do not receive any info, grab the procId and continue
			$procList = $this->nodeProcId('procId');
		}
		if (is_array($procList)) { //Expecting array (even if it's just one process to kill)
			foreach ($procList as $thisProc) {
				$thisProc = preg_replace('/[ ]{2,}/', ' ', $thisProc);//clean double spaces
				$thisProcExp = explode(' ', $thisProc);
				shell_exec("kill {$thisProcExp[1]}");
			}
			//$this->restart = true;
			$this->nodeStatus = 'offline';
			$this->messages[] = [
				'function'    => 'killAllNeb',
				'messageRead' => 'neb killed - number of processes: ' . count($procList),
				'result'      => 'success',
				'time'        => time()
			];
			$this->nodeProcStatus = 'killed';
		} else {
			$this->messages[] = [
				'function'    => 'killAllNeb',
				'messageRead' => 'Unknown data passed',
				//'result'      => 'unknownData',
				'result'      => 'error',
				'time'        => time()
			];
		}
	}

	private function startNeb() //Start the neb service
	{
		echo "Starting Neb\n";
		$restartServiceDelayCheck = NSMSettings::restartServiceDelayCheck + ($this->restartAttempts * 5);//Just in case it takes longer to start neb.
		$this->nodeProcId('kill');//Kill any existing processes - Make sure all processes are terminated
		putenv('export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH');//Set evn variables for .neb - not needed for all systems but safe than sorry.
		shell_exec('('.NSMSettings::nebStartServiceCommand . ' > /dev/null &);'); //Execute startup command and direct the output to null
		//echo 'export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH' . "\n" . NSMSettings::nebStartServiceCommand . ' > /dev/null &';
		echo '('.NSMSettings::nebStartServiceCommand . ' > /dev/null &);';
		sleep($restartServiceDelayCheck); //wait for the node to come online before checking the status
		$this->nodeStatusRPC();
		if ($this->nodeStatus == 'offline') {
			do {
				$giveup = false;
				$this->restartAttempts++;
				$this->killAllNeb();//Kill any in progress restart attempts just in case.
				$this->startNeb();
				if ($this->restartAttempts >= NSMSettings::maxRestartAttempts) {
					$giveup = true;
					$this->restart = false;
					$this->messages[] = [
						'function'    => 'startNeb',
						'messageRead' => 'Restart failed - too many attempts: ' . $this->restartAttempts,
						'result'      => 'error',
						'time'        => time()
					];
				}
			} while ($this->restart == true || $giveup == true);
		} else {
			$this->restart = false;
			$this->nodeStatus = 'online';
			$this->messages[] = [
				'function'    => 'startNeb',
				'messageRead' => 'Neb is online. Restart attempts: ' . $this->restartAttempts,
				'result'      => 'startNebSuccess',
				'time'        => time()
			];
		}
	}

	private function reportData($req = null)
	{
		/*        if (NSMSettings::reportTo == 'externalWebsite') {

				}*/
		if (NSMSettings::reportToEmail) {
			//Default email service
			$to = NSMSettings::reportToEmail;
			$subject = 'Nebulas Node monitor notification for ' . NSMSettings::nodeName;
			$logMessage = print_r($this->localLogLatest, true);
			if ($req == 'testEmail') {
				//Send a test email
				$message = 'Hello this is a requested test message from Nebulas node ' . NSMSettings::nodeName . ' with the latest log included: 
			
			';
			} else {
				$message = 'Hello this is a message about Nebulas node ' . NSMSettings::nodeName . '. It experienced a error and may require your attention. Below is the results from the NebulasServiceMonitor program running on the server.
            
            ';
			}
			$headers = array(
				'From'     => NSMSettings::reportEmailFrom,
				'Reply-To' => NSMSettings::reportEmailFrom,
				'X-Mailer' => 'PHP/' . phpversion()
			);
			mail($to, $subject, $message . $logMessage, $headers);
		}
	}
}