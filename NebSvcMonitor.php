<?php
/*
 * This script was created to monitor the status of a Nebulas node.
 * It should work as-is on all modern Debian (Ubuntu, etc...) based systems (have not tested on RHEL (should work) or BSD (probably needs some modifications) as of yet).
 *
 * Be sure to set your configuration in the NebulasServiceMonitorSettings.inc file and read all documentation within the file
 * Script requirements
 * ->the file NebSvcMonitor.php and neb need to be owned by the same users (for some systems, it may be required to change who php runs as or give sudo access)//TODO check permission services
 * ->chmod +x NebSvcMonitor.php && chmod +x NebSvcMonitor.sh
 * execution: php NebSvcMonitor.php
 * By running "php NebSvcMonitor.php help" you can see a list of available commands
 */

if (isset($argv[1])) { //&& $argv[2] == 'fromBash'
	$doProcess = $argv[1];
} else {
	$doProcess = 'statusCheck';
}

//Include the settings
$NSMSettings = [];
require_once "NebSvcMonitorSettings.inc";
set_time_limit($NSMSettings['delayBetweenReports'] - 10);

//Call the class
$NebulasServiceMonitor = new NebSvcMonitor($NSMSettings);
//Do a process as defined via command line4
$NebulasServiceMonitor->doProcess($doProcess);

class NebSvcMonitor
{
	//Define initial variables
	private $nodeRestartRequested = false; //does the node need to be restarted - set initial to false
	private $emergencyRestart = false;
	private $readableMessages;//Store the readable messages separately to send via email
	private $repeatedRestartRequests = 0;//Number of requests made in a row for a restart - this reduces contiguous restarts
	private $performNodeRestart;//Confirms a node restart will be performed (as long as allowed in the config)
	private $restartAttempts = 0; //count how many restart attempts have been made
	private $messages = []; //store any messages from the processes. All messages contain a result field which can be either success, warn, fail.
	private $nodeStatusRPC; //Current node status - Can be online or offline
	private $nodeProcStatus; //The status of the nodes process id - can be live, killed, zombie
	private $synchronized; //Current sync status - can be true or false
	private $nodeBlockHeight; //Current node block height
	private $serverHWUtilization; //Current server hardware utilization as array
	private $localLogHistory; //Store the local log in a array
	private $localLogLastCall; //Store the local log in a array
	private $synchronizedBehindCount; //Variable to store how long a node has been behind
	private $externalNebState; //Store the nebstate from external API
	private $localLogLatest;//
	private $severityMessageArray = [0 => 'success', 1 => 'info', 2 => 'notify', 3 => 'warn', 4 => 'error'];
	private $severityMessageMax = 0;
	private $NSMSettings;//Settings storage
	private $logEchoNumber = 1;
	private $nodeProcRunningCount;
	//private $synchronizedBehindCountIncreased; //Watch for double increments
	//   private $serverLoad; //Current server load stats as array

	public $about = [//About
	                 'version'            => '0.9 (beta)',
	                 'name'               => 'Nebulas Service Monitor',
	                 'creator'            => '@SatoshiBytes',
	                 'warning'            => 'Work in progress and experimental - do not use on a live node server',
	                 'github'             => 'https://github.com/satoshibytes/NebulasNodeMonitor/',
	                 'available commands' => ['serverStatus' => 'Displays the information about the node and resource usage',
	                                          'showSettings' => 'Shows all the settings as set in the NebSvcMonitorSettings.inc file',
	                                          'statusCheck'  => 'Check the status of the node and intervene if necessary - This is the default action',
	                                          'showStatus'   => 'Check the status of the node but do not intervene',
	                                          'killNeb'      => 'Kills the process - cal alternatively use stopNeb',
	                                          'startNeb'     => 'Checks to see if the node is running then starts the node up if not.',
	                                          'readLog'      => 'Read the local log message',
	                                          'readLastLog'  => 'Read just the last log',
	                                          'eraseLog'     => 'Delete the log file',
	                                          'testEmail'    => 'Send a test email to the address listed in the config.']
	];

	function __construct($NSMSettings)
	{
		$this->NSMSettings = $NSMSettings;
	}

	public function doProcess($doThis) //This are the available actions.
	{//readLog
		$this->verboseLog("Called NebSvcMonitor.php $doThis", 'info');

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
				print_r(print_r($this->NSMSettings));
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
				//	$this->checkNodeProcRunning('kill');
				$this->killNebProc();
				break;
			case'startNeb':
				//$this->checkNodeProcRunning('count');
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
		$this->cleanLogFile();//Check if the log file is too big and remove old entries (size is set in the config)
	}

	private function statusCheck()//This is the primary status checker and restart function
	{
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
		 * Additional feature:
		 *  Check hdd space on partition where blocks are located.
		 */
		$this->verboseLog("## Entered statusCheck ##", 'info');
		//Get the server hardware status
		$this->verboseLog("serverStatus", 'info');
		$this->serverStatus();
		//First check if node is synced.
		$this->verboseLog("nodeStatusRPC", 'info');
		$this->nodeStatusRPCCheck();
		$this->verboseLog("getExternalAPIData", 'info');
		$this->getExternalAPIData();
		$this->readWriteLog('read');        //Get the historical log status.

		$this->verboseLog("Node synced:{$this->synchronized}\nBlock Height: {$this->nodeBlockHeight}\nNode Status: {$this->nodeStatusRPC}\nExternal API Block Height: {$this->externalNebState['result']['height']}\nExternal API Synced: {$this->externalNebState['result']['synchronized']}", 'info');

		if ($this->nodeStatusRPC == 'online') { //Node is running //TODO verify this entire section
			//$externalNebStateBlockHeight = $this->externalNebState['result']['height'];
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
						$msg = "The node is reporting to be synced.";
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => $msg,
							'result'      => 'success',
							'time'        => time()
						];
						$this->synchronizedBehindCount = 0; //Set the count to 0 for the current log status
						$this->verboseLog($msg, 'info');
					} else {//Node is behind
						$diff = $this->externalNebState['result']['height'] - $this->nodeBlockHeight;
						if ($this->NSMSettings['nodeRestartIfLocalHeightNotEqualToExternal'] == true)
							$this->synchronizedBehindCount = $this->localLogLastCall['synchronizedBehindCount'] + 1;//Increase the behind count by one since we are not properly synced up.
						$msg = "The local node states that it is in sync but is behind when compared to external API results.\n 
                            Local Height: $this->nodeBlockHeight,| External Height: {$this->externalNebState['result']['height']} | Diff: {$diff} | Behind Count: {$this->synchronizedBehindCount}";

						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => $msg,
							'result'      => 'success',
							'time'        => time()];
						$this->verboseLog($msg, 'info');
					}
				} else {
					$msg = "The node is reporting to be synced but the external api node was not available for verification.";
					$this->messages[] = [
						'function'    => 'statusCheck',
						'messageRead' => $msg,
						'result'      => 'notify',
						'time'        => time()
					];
					$this->verboseLog($msg, 'notify');
				}
				//$this->verboseLog("-------------------",'');
			} else {
				//The node is reporting not being synced.
				$this->synchronizedBehindCount = $this->localLogLastCall['synchronizedBehindCount'] + 1; //Increase the count
				//Check the local log to see if block height is increasing and compare it to the user defined variables to confirm synchronization is happening within a minimum speed and if the node was synced last check.
				if ($this->localLogLastCall['synchronized'] == false) {//The last check resulted in a failed synchronized result. Let's see if the block height is increasing at a proper rate.
					//First see if the height increased at all
					if ($this->localLogLastCall['blockHeight'] == $this->nodeBlockHeight) {//The block height did not increase.
						$msg = "The block height did not increase from the last check. Log Last Height: {$this->localLogLastCall['blockHeight']} | Current Height: {$this->nodeBlockHeight}.";
						$this->messages[] = [
							'function'    => 'statusCheck',
							'messageRead' => $msg,
							'result'      => 'error',
							'time'        => time()];
						$this->verboseLog($msg, 'error');
						//$this->nodeRestart = true;
					} else { //The block height did increase - let's see if its within acceptable rate
						//What is the minimum amount of blocks generated we should accept.
						$minBlocksGen = ($this->NSMSettings['delayBetweenReports'] / 15) + (($this->NSMSettings['delayBetweenReports'] / 15) * ($this->NSMSettings['nodeSyncMinBlockCountIncreasePercentage'] / 100));
						$blockHeightIncreaseCount = $this->localLogLastCall['blockHeight'] + $minBlocksGen;
						if ($this->nodeBlockHeight < $blockHeightIncreaseCount) {
							//Block height increasing too slowly
							$msg = "Block height increasing too slowly.";
							//$syncAtProperRate = false;
							$this->messages[] = [
								'function'    => 'statusCheck',
								'messageRead' => $msg,
								'result'      => 'warn',
								'time'        => time()];
							$this->verboseLog($msg, 'warn');
						} else {
							//Block height is increasing fast enough
							$msg = "Block height increasing at proper rate.";
							//$syncAtProperRate = true;
							$this->messages[] = [
								'function'    => 'statusCheck',
								'messageRead' => $msg,
								'result'      => 'info',
								'time'        => time()
							];
							$this->verboseLog($msg, 'info');
						}
					}
				}
			}
		} else { //Node is offline - find a procId, kill it and mark as restart required.
			$msg = "The node was found to be offline. Restarting node.";
			$this->messages[] = [
				'function'    => 'statusCheck',
				'messageRead' => "The node was found to be offline. Restarting node.",
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');
			if ($this->NSMSettings['restartServiceIfNotFound'] == true) {//Should we restart the node?
				$this->nodeRestartRequested = true;//Set the node to restart
				$this->checkNodeProcRunning('kill');//See if the node is online and if so, terminate it.
			} else {
				$msg = "The NebSvcMonitor found the node to be offline however, it is set to not start in the config (restartServiceIfNotFound).";
				$this->emergencyRestart = true;
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => $msg,
					'result'      => 'notify',
					'time'        => time()
				];
				$this->verboseLog($msg, 'notify');
			}
		}
		if ($this->synchronizedBehindCount > $this->NSMSettings['nodeBehindRestartCount']) { //The node is behind for the max amount of checks
			$msg = "The node is behind for the max amount of checks. Setting restart required to true. Local Height: $this->nodeBlockHeight,| External Height: {$this->externalNebState['result']['height']}";
			$this->messages[] = [
				'function'    => 'statusCheck',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');
			$this->synchronizedBehindCount = 0;//Set to 0 to stop continuous restart
			$this->nodeRestartRequested = true;//Set the node to restart
		}
		//$this->repeatedRestartRequests
		/*
		 * Restart requests are handled on multiple levels.
		 * 1. emergencyRestart - restart the node without other checks since the node is down.
		 * 2. restart the node if as long as the node was not recently restart as specified in the config restartAttemptsMinWait
		 * 3. restarting can be disabled completely by setting the config enableRestartService to false. This is good for testing while still receiving emails.
		 */
		if ($this->nodeRestartRequested == true) {//Some part of the program requested a restart.
			if ($this->repeatedRestartRequests == 0) {//No previous restarts - let's assume we need to restart the node.
				$this->repeatedRestartRequests = $this->localLogHistory['repeatedRestartRequests'] + 1;//Increase the count by 1
				$this->performNodeRestart = true;//We are confirming that we want to restart
				$msg = "A node restart is required with no previous restart attempts for the last {$this->localLogHistory['repeatedRestartRequests']} checks.";
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => $msg,
					'result'      => 'notify',
					'time'        => time()];
				$this->verboseLog($msg, 'notify');
			} else if (($this->repeatedRestartRequests >= $this->NSMSettings['restartAttemptsMinWait'])) {//Confirm we have not recently restarted the node
				$this->performNodeRestart = true;//We are confirming that we want to restart
				$this->repeatedRestartRequests = 0;
				$msg = "A node restart is required due to reaching the max amount of delayed restarts of {$this->localLogHistory['repeatedRestartRequests']}.";
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => $msg,
					'result'      => 'notify',
					'time'        => time()];
				$this->verboseLog($msg, 'notify');
			}
		} else {//No restart required. Let's be sure to set repeatedRestartRequests to 0
			$this->repeatedRestartRequests = 0;
		}
		if ($this->performNodeRestart == true || $this->emergencyRestart == true) { //nodeProcId found no running neb functions - restart the service
			if ($this->NSMSettings['enableRestartService'] == true)
				$this->startNeb();//Start neb
			else {
				$msg = "The NebSvcMonitor found the node to require restarting however, it is set to not restart in the config (enableRestartService).";
				$this->messages[] = [
					'function'    => 'statusCheck',
					'messageRead' => $msg,
					'result'      => 'notify',
					'time'        => time()
				];
				$this->verboseLog($msg, 'notify');
			}
		}
		$this->readWriteLog('write'); //Write the data to the local log
		if ($this->severityMessageMax >= $this->NSMSettings['severityLevelMessageSend']) {//send message
			$this->reportData();
		}
	}

	private function showStatus()//Show the status of the node when called
	{
		$this->verboseLog("## ENTER checkNodeProcRunning ##", 'info');
		$this->checkNodeProcRunning();
		$this->verboseLog("## ENTER serverStatus ##", 'info');
		$this->serverStatus(); //Check load and mem usage
		$this->verboseLog("## ENTER nodeStatusRPCCheck ##", 'info');
		$this->nodeStatusRPCCheck();//Get the node status via RPC req
		$this->verboseLog("## ENTER getExternalAPIData ##", 'info');
		$this->getExternalAPIData();
	}

	private function serverStatus()//Check the server hardware utilization.
	{
		//TODO add more checks such as storage space
		//Check the cpu utilization
		$loadRaw = sys_getloadavg();
		$loadMessage = "1 minute: {$loadRaw[0]} | 5 minutes: {$loadRaw[1]} | 15 minutes: {$loadRaw[2]}";
		$this->serverHWUtilization['cpu'] = ['avgLoad1min'  => $loadRaw[0],
		                                     'avgLoad5min'  => $loadRaw[1],
		                                     'avgLoad15min' => $loadRaw[2]];
		if ($loadRaw[1] > $this->NSMSettings['restartMaxLoad5MinuteAvg']) {//Exceeded 5 min load average
			$messageExtended = '';
			if ($this->NSMSettings['restartIfMaxLoadExceeded']) {
				$this->nodeRestartRequested = true;
				$messageExtended = " Node will reboot based on config settings";
			}
			$msg = "Average 5 minute server load average exceeded the specified max load. Current load: {$loadRaw[1]}." . $messageExtended;
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');
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
		$realFreeMemory = $memoryUsageDataArray['totalMemory'] - $memoryUsageDataArray['usedMemory'];
		$freeMemoryPercent = ($realFreeMemory / $memoryUsageDataArray['totalMemory']) * 100;
		$freeSwapPercent = ($memoryUsageDataArray['freeSwap'] / $memoryUsageDataArray['totalSwap']) * 100;
		$this->serverHWUtilization['memory'] = ['systemMemory'      => $memoryUsageDataArray['totalMemory'],
		                                        'freeMemory'        => $memoryUsageDataArray['freeMemory'],
		                                        'freeMemoryPercent' => $freeMemoryPercent,
		                                        'freeSwap'          => $memoryUsageDataArray['freeSwap'],
		                                        'totalSwap'         => $memoryUsageDataArray['totalSwap'],
		                                        'freeSwapPercent'   => $freeSwapPercent];
		if ($freeMemoryPercent < $this->NSMSettings['minFreeMemoryPercent']) {//Exceeded free ram
			$messageExtended = '';
			if ($this->NSMSettings['restartMinFreeMemoryPercent'] == true) {
				$this->nodeRestartRequested = true;
				$messageExtended = " Node will reboot based on config settings";
			}
			$msg = "Memory utilization exceeded specified minimum free memory amount percent. Free memory: $freeMemoryPercent." . $messageExtended;
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');
		}
		if ($freeSwapPercent < $this->NSMSettings['minFreeSwapPercent']) {//Exceeded free ram
			$messageExtended = '';
			if ($this->NSMSettings['restartMinFreeSwapPercent'] == true) {
				$this->nodeRestartRequested = true;
				$messageExtended = " Node will reboot based on config settings";
			}
			$msg = "SWAP space utilization exceeded specified minimum free memory amount percent. Free swap amount: $freeSwapPercent." . $messageExtended;
			$this->messages[] = [
				'function'    => 'serverStatus',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');
		}
		//End server memory utilization

		//Result message
		$msg = "Server Load: {$loadMessage}\nFree Memory Percentage: {$freeMemoryPercent}\nFree Swap Space Percent: $freeSwapPercent";
		$this->messages[] = [
			'function'    => 'serverStatus',
			'messageRead' => $msg,
			'result'      => 'info',
			'time'        => time()
		];
		$this->verboseLog($msg, 'info');
		return null;//Results stored in pre-defined variables
	}

	private function nodeStatusRPCCheck() //Check the node status via CURL request.
	{
		$port = $this->NSMSettings['nebListenPort'];
		$curlRequest = $this->curlRequest("http://localhost:{$port}/v1/user/nebstate");
		if ($curlRequest['status'] == 'success') {
			$nodeStatusArray = json_decode($curlRequest['data'], true);
			$this->nodeStatusRPC = 'online';
		}
		if (json_last_error() == JSON_ERROR_NONE && $this->nodeStatusRPC == 'online') { //Node is online - lets check the status
			$this->synchronized = $nodeStatusArray['result']['synchronized'];
			$this->nodeBlockHeight = $nodeStatusArray['result']['height'];
			$this->nodeStatusRPC = 'online';
			$msg = "Node Online. Block height: {$nodeStatusArray['result']['height']}";
			$this->messages[] = [
				'function'    => 'nodeStatusRPC',
				'messageRead' => $msg,
				'result'      => 'success',
				'time'        => time()
			];
			$this->verboseLog($msg, 'info');

			if ($this->synchronized != true) { //Check the status file for the last recorded status
				$msg = 'Node not synchronized';
				$this->messages[] = [
					'function'    => 'nodeStatusRPC',
					'messageRead' => $msg,
					'result'      => 'warn',
					'time'        => time()
				];
				$this->verboseLog($msg, 'warn');
			}
		} else { //No response from node - node is considered offline
			//$this->restart = true;
			$this->nodeStatusRPC = 'offline';
			$msg = 'Node offline';
			$this->messages[] = ['function'    => 'nodeStatusRPC',
			                     'messageRead' => $msg,
			                     'result'      => 'error',
			                     'time'        => time()];
			$this->verboseLog($msg, 'error');
			$this->nodeRestartRequested = true;
		}
		return null;//Results stored in pre-defined variables
	}

	private function checkNodeProcRunning($req = null)
	{ //Find the process id on the server and verify that there is only one process running (not counting children).
		$findNebProcCount = shell_exec("ps -ef | grep \"neb -c\" | grep -v \"grep\" | grep -v \"checkStatus\" | grep -v \"tail\" | grep -v \"kill\" | grep -v \".sh\" |  wc -l"); //Find the .neb process based on the $settings['restartServiceCommand'] setting
		$this->verboseLog("Running Processes: $findNebProcCount", 'info');
		$this->nodeProcRunningCount = $findNebProcCount;//set how many node processes are running
		if ($req == 'count') {//Return live count of node
			return $findNebProcCount;
		} else if ($req == 'kill') {
			if ($findNebProcCount == 0) {
				$msg = 'Manual Kill requested but node not running';
				$this->messages[] = ['function'    => 'nodeProcId',
				                     'messageRead' => $msg,
				                     'message'     => 'killReqButNodeOffline',
				                     'result'      => 'error',
				                     'time'        => time(),
				                     'custom'      => "REQ: $req"];
				$this->verboseLog($msg, 'error');
				$this->nodeStatusRPC = 'offline';
			} else {
				$msg = "Manual kill requested. Processes running: $findNebProcCount";
				$this->messages[] = ['function'    => 'nodeProcId',
				                     'messageRead' => $msg,
				                     'result'      => 'notify',
				                     'time'        => time()];
				$this->verboseLog($msg, 'notify');
				$this->killNebProc();
			}
		} else {
			if ($findNebProcCount > 1) { //Multiple processes found - should only be one
				$msg = 'Multiple Neb processes found';
				$this->messages[] = [
					'function'    => 'nodeProcId',
					'messageRead' => $msg,
					'result'      => 'warn',
					'time'        => time()
				];
				$this->verboseLog($msg, 'warn');
				if ($this->NSMSettings['restartServiceIfMultipleProcFound'] == true) {
					$this->nodeRestartRequested = true;
					$this->killNebProc();
				}
			}
		}
		return null;//Results stored in pre-defined variables
	}

//////////////////////////////////////
	private function killNebProc()
	{//Kill all running neb processes via it's procId

		shell_exec('pkill neb');//Kill the process//TODO Check if this works
		//Now see if there are any remaining running processes
		$checkNodeProcRunningCount = $this->checkNodeProcRunning('count');
		if ($checkNodeProcRunningCount == 0) {
			$this->nodeStatusRPC = 'offline';
			$this->nodeProcStatus = 'killed';
			$this->verboseLog('Node killed - offline', 'info');
			$msg = "Neb killed. Current processes running: $checkNodeProcRunningCount";
			$this->messages[] = [
				'function'    => 'killAllNeb',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()];
		} else {
			$this->nodeStatusRPC = 'zombie';
			$msg = "Killing Neb failed - potentially process zombie. Total neb processes: $checkNodeProcRunningCount";
			$this->messages[] = [
				'function'    => 'killAllNeb',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()];
		}
		return null;
	}


	private function startNebProc()
	{
		$this->verboseLog('Entered startNebProc', 'info');
		/*$exec = 'nohup ' . $this->NSMSettings['nebStartServiceCommand'] . '&';//Some environments may need a different startup command such as appending 2>&1 at the end  > /dev/null
		$this->verboseLog("Command: " . $exec);
		$execOutput = exec($exec, $res); //Execute startup command and direct the output to null
		$this->verboseLog("Startup result: " . print_r($res) . $execOutput);*/
		$startNeb = exec('./NebSvcMonitor.sh startNeb');
		$this->verboseLog($startNeb, 'command');
		$restartServiceDelayCheck = $this->NSMSettings['restartServiceDelayCheck'] + ($this->restartAttempts * 5);//Just in case it takes longer to start neb.
		sleep($restartServiceDelayCheck); //wait for the node to come online before checking the status
		return null;
	}

	private function startNeb() //Start the neb service
	{
		$this->verboseLog('Entered Starting Neb', 'info');
		$this->checkNodeProcRunning('count');
		$this->verboseLog("Node Process Count: {$this->nodeProcRunningCount}", 'info');
		if ($this->nodeProcRunningCount >= 1) {//Kill any running service
			$shutdownAttempts = 0;
			$done = false;
			$this->killNebProc();
			while ($done == false) {
				$this->checkNodeProcRunning('count');
				if ($this->nodeProcRunningCount == 0)
					$done = true;
				if ($shutdownAttempts >= $this->NSMSettings['maxRestartAttempts'])
					$done = true;
			}
			if ($this->nodeProcRunningCount != 0) {
				$msg = 'Could not shutdown the existing neb process. - ' . $this->nodeProcRunningCount;
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'error',
					'time'        => time()
				];
				$this->verboseLog($msg, 'error');
			} else {
				$msg = 'Shutdown process completed. - ' . $this->nodeProcRunningCount;
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'error',
					'time'        => time()
				];
				$this->verboseLog($msg, 'error');
				$this->nodeProcStatus = 'offline';

			}
		}
		if ($this->nodeProcStatus = 'offline') {
			$this->verboseLog('No processes running - starting neb', 'info');
			$restartAttempts = 0;
			$done = false;
			while ($done == false) {
				$this->startNebProc();
				$this->checkNodeProcRunning('count');
				$this->verboseLog("startNebProc (a1): {$this->nodeProcRunningCount} | Restart Attempts: $restartAttempts", 'info');
				$restartAttempts++;
				if ($this->nodeProcRunningCount == 1)
					$done = true;
				if ($restartAttempts >= $this->NSMSettings['maxRestartAttempts'])
					$done = true;
			}
			$this->verboseLog('Exit restart Loop', 'info');

			if ($this->nodeProcRunningCount == 1) {
				$this->nodeStatusRPC = 'online';
				$msg = 'neb process started. Required attempts: ' . $restartAttempts;
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'success',
					'time'        => time()
				];
				$this->verboseLog($msg, 'success');
			} else {
				$msg = 'neb process could not be started. Required attempts: ' . $restartAttempts;
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'success',
					'time'        => time()
				];
				$this->verboseLog($msg, 'success');
			}
		}
		//Check RPC service
		if ($this->nodeProcStatus == 'online') {
			$this->nodeStatusRPCCheck();
			if ($this->nodeStatusRPC == 'online') {//online
				$msg = 'neb RPC process live.';
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'success',
					'time'        => time()
				];
				$this->verboseLog($msg, 'success');
			} else {//offline
				$msg = 'neb RPC process down.';
				$this->messages[] = [
					'function'    => 'startNeb',
					'messageRead' => $msg,
					'result'      => 'error',
					'time'        => time()
				];
				$this->verboseLog($msg, 'error');
			}
		}
	}

	private function getExternalAPIData($type = '/v1/user/nebstate')
	{ //Get the block height from a external source
		// /v1/user/nebstate - standard call
		$externalURL = $this->NSMSettings['externalApiURL'] . $type;
		$curlRequest = $this->curlRequest($externalURL, $timeout = 10);
		if ($curlRequest['status'] == 'success') {
			$externalNebStateArray = json_decode($curlRequest['data'], true);
		}
		if (json_last_error() == JSON_ERROR_NONE) { //API data successfully retrieved.
			$this->externalNebState = $externalNebStateArray;
			$msg = "External API block height: {$externalNebStateArray['result']['height']}";
			$this->messages[] = [
				'function'    => 'getExternalAPIData',
				'messageRead' => $msg,
				'result'      => 'info',
				'time'        => time()
			];
			$this->verboseLog($msg, 'info');
		} else {
			$msg = "Error obtaining API data from $externalURL";
			$this->messages[] = [
				'function'    => 'getExternalAPIData',
				'messageRead' => "Error obtaining API data from $externalURL",
				'result'      => 'error',
				'time'        => time()
			];
			$this->externalNebState = null;
			$this->verboseLog($msg, 'error');
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
			$msg = 'Curl request failed. URL: ' . $url;
			$this->messages[] = [
				'function'    => 'curlRequest',
				'messageRead' => $msg,
				'result'      => 'error',
				'time'        => time()
			];
			$this->verboseLog($msg, 'error');

			$status = 'error';
			$this->nodeStatusRPC = 'offline';
		} else {//Successful response
			$status = 'success';
			$this->nodeStatusRPC = 'online';
		}
		curl_close($ch);//close curl
		return ['status' => $status,
		        'data'   => $data];
	}

	private function reportData($req = null)
	{
		/*    //Future feature
		  if ($this->NSMSettings['reportTo == 'externalWebsite') {

				}*/
		if ($this->NSMSettings['reportToEmail']) {
			//Default email service
			$to = $this->NSMSettings['reportToEmail'];
			$subject = 'Nebulas Node monitor notification for ' . $this->NSMSettings['nodeName'];
			$logMessage = print_r($this->localLogLatest, true);
			if ($req == 'testEmail') {
				//Send a test email
				$message = 'Hello this is a requested test message from Nebulas node ' . $this->NSMSettings['nodeName'] . ' with the latest log included with readable messages plus the full array result: 
			
			';
			} else {
				$message = 'Hello this is a message about Nebulas node ' . $this->NSMSettings['nodeName'] . '. It experienced a error and may require your attention. Below is the results from the NebSvcMonitor program running on the server  with readable messages plus the full array result: .
            
            ';
			}
			$headers = array(
				'From'     => $this->NSMSettings['reportEmailFrom'],
				'Reply-To' => $this->NSMSettings['reportEmailFrom'],
				'X-Mailer' => 'PHP/' . phpversion()
			);
			mail($to, $subject,
				$message .
				$this->readableMessages .
				"\n--------------------------\n" .
				$logMessage,
				$headers);
		}
	}

	private function readWriteLog($req = 'read')
	{   //Store the current settings in a local file to verify the node status/
		//Default is to read but can specify write and erase.
		if ($req == 'erase') {
			unlink($this->NSMSettings['localDataFile']);
		} else if ($req == 'writeInitial') {
			$this->showStatus();
			$time = time();
			$this->localLogLatest[$time] = [//New data to add to the log
			                                'restartRequested'        => $this->nodeRestartRequested,
			                                'restartAttempts'         => $this->restartAttempts,
			                                'repeatedRestartRequests' => $this->repeatedRestartRequests,
			                                'nodeStatus'              => $this->nodeStatusRPC,
			                                'synchronized'            => $this->synchronized,
			                                'synchronizedBehindCount' => $this->synchronizedBehindCount,
			                                'blockHeight'             => $this->nodeBlockHeight,
			                                'serverHWUtilization'     => $this->serverHWUtilization,
			                                'reportTime'              => $time,
			                                'messageSeverityLevel'    => $this->severityMessageArray[$this->severityMessageMax],
			                                'ExternalAPIStatus'       => $this->externalNebState,
			                                'messages'                => $this->messages];
			file_put_contents($this->NSMSettings['localDataFile'], json_encode($this->localLogLatest)); //Store the log
			chmod($this->NSMSettings['localDataFile'], 0755);
			$req = 'write';
		}

		if (!file_exists($this->NSMSettings['localDataFile'])) { //Set the initial file if it does not exist
			$this->readWriteLog('writeInitial');
		}
		//Get the log file
		$statusLogArr = json_decode(file_get_contents($this->NSMSettings['localDataFile']), true); //Need to get data regardless
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
			                                 'restartRequested'        => $this->nodeRestartRequested,
			                                 'restartAttempts'         => $this->restartAttempts,
			                                 'repeatedRestartRequests' => $this->repeatedRestartRequests,
			                                 'nodeStatus'              => $this->nodeStatusRPC,
			                                 'synchronized'            => $this->synchronized,
			                                 'synchronizedBehindCount' => $this->synchronizedBehindCount,
			                                 'blockHeight'             => $this->nodeBlockHeight,
			                                 'reportTime'              => time(),
			                                 'messageSeverityLevel'    => $this->severityMessageArray[$this->severityMessageMax],
			                                 'ExternalAPIStatus'       => $this->externalNebState,
			                                 'serverHWUtilization'     => $this->serverHWUtilization,
			                                 'messages'                => $this->messages];
			if ($this->localLogHistory)//
				$NewLog = $this->localLogLatest + $this->localLogHistory;
			else
				$NewLog = $this->localLogLatest;
			$cnt = count($NewLog);
			if ($cnt >= $this->NSMSettings['eventsToStoreLocally']) { //See if the array has more inputs then specified in the config
				unset($NewLog[array_key_last($NewLog)]);
			}
			file_put_contents($this->NSMSettings['localDataFile'], json_encode($NewLog)); //Store the log
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

	private function verboseLog($val, $severity)
	{//Primarily used for debugging - can be disabled in the config
		$now = date("m j, Y, H:i:s");
		$logEntry = $now . ' | ' . $severity . ' | ' . $this->logEchoNumber . ' | Message:' . $val . "\n";
		if ($this->NSMSettings['verbose'] != false) {
			$this->logEchoNumber++;
			if ($this->NSMSettings['verbose'] == 'echo') {
				echo $logEntry;
			} else {//Write to log
				file_put_contents($this->NSMSettings['verbose'], $logEntry, FILE_APPEND);
			}
		}
		$this->readableMessages .= $logEntry;
	}

	private function cleanLogFile()
	{
		$currentLog = file_get_contents($this->NSMSettings['logName']);
		$lineCount = substr_count($currentLog, "\n");
		if ($lineCount > $this->NSMSettings['logMaxLines']) {
			$linesToRemove = $lineCount - $this->NSMSettings['logMaxLines'];
			echo "\nLine count: $lineCount | To remove: $linesToRemove\n";
			$revisedLog = implode("\n", array_slice(explode("\n", $lineCount), $linesToRemove));
			file_put_contents($this->NSMSettings['logName'], $revisedLog);
		}
		return null;
	}

	private function setEnvVariables()//Currently not used.
	{
		//$currentDir = $exec = exec('echo $PWD');
		//exec("export LD_LIBRARY_PATH=$currentDir/native-lib:\$LD_LIBRARY_PATH");//Set evn variables for .neb - not needed for all systems but safe than sorry.
		//exec('export LD_LIBRARY_PATH');
		//exec(' source $HOME/.bashrc');
		//$this->verboseLog("completed putenv");
	}
}
