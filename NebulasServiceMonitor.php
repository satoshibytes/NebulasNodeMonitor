<?php

/*
 * This script was created to monitor the status of a Nebulas node.
 * It should work as-is on all modern Debian (Ubuntu, etc...) based systems (have not tested on RHEL (should work) or BSD (probably needs some modifications) as of yet).
 *
 * 
 * Be sure to set your configuration in the NebulasServiceMonitorSettings.inc file.
 * Script requirements
 * ->Server must have PHP 5.6 or later installed installed
 * ->Server must have curl installed
 * ->chmod +x NebulasServiceMonitor.php
 * execution: php NebulasServiceMonitor.php REQ
 * ->php NebulasServiceMonitor.php stopNeb
 * ->php NebulasServiceMonitor.php startNeb
 */
//--//

if (isset($argv[1])) { //&& $argv[2] == 'fromBash'
	print_r($argv);
	$doProcess = $argv[1];
} else {
	$doProcess = 'about';
}
//Call the class
$NebulasServiceMonitor = new NebulasServiceMonitor();
//Do a process as defined via command line
$NebulasServiceMonitor->doProcess($doProcess);

class NebulasServiceMonitor extends NSMSettings
{
	public $about = [
		'version' => '0.1',
		'name' => 'Nebulas Service Monitor',
		'creator' => '@SatoshiBytes',
		'warning' => 'Work in progress and experimental - do not use on a live node server',
		'github' => 'https://github.com/satoshibytes/NebulasNodeMonitor/',
		'available actions' => "To be added..."
	]; //About this script

	//Define initial variables
	private $restart = false; //does the node need to be restarted - set initial to false
	private $restartAttempts = 0; //count how many restart attempts have been made
	private $messages = []; //store any messages from the processes
	private $nodeStatus; //Current node status - Can be online or offline
	private $nodeProcStatus; //The status of the nodes process id - can be live, killed, zombie
	private $synchronized; //Current sync status - can be true or false
	private $nodeBlockHeight; //Current node block height
	private $serverLoad; //Current server load stats
	private $serverMemUtilization; //Current server Memory utilization
	private $statusLog; //Store the local log in a array
	private $localLogStatus; //Store the local log in a array
	private $synchronizedBehindCount; //Variable to store how long a node has been behind
	private $externalNebState; //Store the nebstate from external API

	private function readWriteLog($do = 'read')
	{   //store the current settings in a local file to verify the node status
		if (!file_exists(NSMSettings::statusFilename)) { //Set the initial file
			chmod(NSMSettings::statusFilename, 0755);
			file_put_contents(NSMSettings::statusFilename, json_encode(['']));
		}
		$statusLogArr = json_decode(file_get_contents(NSMSettings::statusFilename), true); //Need to get data regardless
		if ($do == 'read') { //Get the status from the file. Stored in JSON array.
			$this->statusLog = $statusLogArr;
			$this->localLogStatus = $statusLogArr[0]; //Store only the last results of the log in a var to access in other locations.
			/*
			 * Get last block height: $this->statusLogLastResult['blockHeight']
			 * Get last reported timestamp(epoch time): $this->statusLogLastResult['reportTime']
			 * Get last sync result: $this->statusLogLastResult['synchronized']
			 */
		} else { //write to file
			//New data
			$currentStatus = [
				'restartRequested' => $this->restart,
				'restartAttempts' => $this->restartAttempts,
				'nodeStatus' => $this->nodeStatus,
				'synchronized' => $this->synchronized,
				'synchronizedBehindCount' => $this->synchronizedBehindCount,
				'blockHeight' => $this->nodeBlockHeight,
				'serverLoad' => $this->serverLoad,
				'$serverMemUtilization' => $this->serverMemUtilization,
				'messages' => $this->messages,
				'reportTime' => time()
			];
			$NewLog = $currentStatus + $this->statusLog;
			if (count($NewLog) >= NSMSettings::eventsToStoreLocally) { //See if the array has more inputs than requested
				$NewLog = array_pop($NewLog);
			}
			file_put_contents(NSMSettings::statusFilename, json_encode($NewLog)); //Store the log
		}
	}

	private function statusCheck() //This is the primary monitor and restart function
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
		 * Additional feature: check block height vs other nodes.
		 *  Check hdd space on partition where blocks are located.
		 */

		//Get the server hardware status
		$this->serverStatus();
		//First check if node is synced.
		$this->nodeStatusRPC();
		//Get the historical log status.
		$this->readWriteLog('read');

		if ($this->nodeStatus == 'online') { //Node is running
			$externalNebStateBlockHeight =	$this->externalNebState['result']['height'];
			$externalNebStateSynchronized =	$this->externalNebState['result']['synchronized'];
			if ($this->synchronized == true) { //Node is online. Check server utilization
				//Compare block height to the api
				if ($externalNebStateSynchronized == true) {
					//In case of block being generated during check, allow for a 1 block tolerance
					$acceptableHeight = [$this->externalNebState['result']['height'] - 1, $this->externalNebState['result']['height'], $this->externalNebState['result']['height'] + 1];
					if (!in_array($this->nodeBlockHeight, $acceptableHeight)) { //Block heights do not match local vs external - we will not restart on this error 
						//TODO make it a option)
						$this->messages = [
							'function' => 'statusCheck',
							'messageRead' => "The node is reporting to be synced.",
							'result' => 'success',
							'time' => time()
						];
					}
				}
				$this->synchronizedBehindCount = 0; //Set the count to 0 for the current log status
				$this->messages = [
					'function' => 'statusCheck',
					'messageRead' => "The node is reporting to be synced.",
					'result' => 'success',
					'time' => time()
				];
			} else {
				//The node is reporting not being synced.
				$this->synchronizedBehindCount = $this->localLogStatus['synchronizedBehindCount'] + 1; //Increase the count

				//Check the local log to see if block height is increasing and compare it to the user defined variables to confirm synchronization is happening within a minimum speed and if the node was synced last check.
				if ($this->localLogStatus['synchronized'] == false) {
					//The last check resulted in a failed synchronized result. Let's see if the block height is increasing at a proper rate.
					//First see if the height increased at all
					if ($this->localLogStatus['blockHeight'] == $this->nodeBlockHeight) {
						//The block height did not increase.
						$this->messages = [
							'function' => 'statusCheck',
							'messageRead' => "The block height did not increase from the last check. Setting restart required to true.",
							'result' => 'error',
							'time' => time()
						];
						$this->restart = true;
					} else {
						//The block height did increase - let's see if its within acceptable rate
						//What is the minimum amount of blocks generated we should accept.
						$minBlocksGen = (NSMSettings::delayBetweenReports / 15) + ((NSMSettings::delayBetweenReports / 15) * (NSMSettings::nodeSyncMinBlockCountIncreasePercentage / 100));
						$blockHeightIncreaseCount = $this->localLogStatus['blockHeight'] + $minBlocksGen;
						if ($this->nodeBlockHeight < $blockHeightIncreaseCount) {
							//Block height increasing too slowly
							$this->messages = [
								'function' => 'statusCheck',
								'messageRead' => "Block height increasing too slowly.",
								'result' => 'error',
								'time' => time()
							];
						} else {
							//Block height is increasing fast enough
							$this->messages = [
								'function' => 'statusCheck',
								'messageRead' => "Block height increasing at proper rate.",
								'result' => 'success',
								'time' => time()
							];
						}
						if ($blockHeightIncreaseCount >= NSMSettings::nodeBehindRestartCount) { //The node is behind for the max amount of checks
							$this->messages = [
								'function' => 'statusCheck',
								'messageRead' => "The node is behind for the max amount of checks. Setting restart required to true.",
								'result' => 'error',
								'time' => time()
							];
							$this->restart = true;
						}
					}
				}
			}
		} else { //Node is offline - find a procId, kill it and mark as restart required.
			$this->restart = true;
			//See if the node is online and if so, terminate it.
			$this->nodeProcId('kill');
		}
		if ($this->restart == true) { //nodeProcId found no running neb functions - restart the service
			$this->startNeb();
		}
		$this->readWriteLog('write'); //Write the data to the local log
	}

	public function doProcess($doThis) //This are the available actions.
	{
		if ($doThis == 'about') {
			//print the about section
			print_r($this->about);
		} else {
			if ($doThis == 'showSettings') {
				//print all the settings
				print_r(get_defined_constants(true));
			} else {
				if ($doThis == 'statusCheck') {
					//check the status of the node and intervene if necessary - This is the default action
					$this->statusCheck();
				} else {
					if ($doThis == 'showStatus') { //check the status of the node but do not intervene
						$this->showStatus(); //TODO Make content pretty
					} else {
						if ($doThis == 'killNeb' || $doThis == 'stopNeb') { //Stops the node
							$this->nodeProcId('kill');
							print_r($this->messages);
						} else {
							if ($doThis == 'startNeb') { //Start the node
								$this->startNeb();
								print_r($this->messages);
							}
						}
					}
				}
			}
		}
	}

	private function showStatus()
	{
		$nodeProcId = $this->nodeProcId();    //Get process id
		$serverStatus = $this->serverStatus(); //Check load and mem usage
		$nodeStatus = $this->nodeStatusRPC();
		return [
			'serverStatus' => $serverStatus,
			'nodeProcId' => $nodeProcId,
			'nodeStatus' => $nodeStatus
		];
	}

	private function serverStatus()
	{
		$load = sys_getloadavg();
		$memoryUsage = shell_exec("free"); //Grab the current memory status
		$this->messages = [
			'function' => 'serverStatus',
			'messageRead' => "
        Server Load: {$load}\n
        Memory Usage: $memoryUsage",
			'time' => time()
		];
		//return array('load' => $load, 'memoryUsage' => $memoryUsage);
	}

	private function getExternalAPIData($type = '/v1/user/nebstate')
	{ //Get the block height from a external source
		// /v1/user/nebstate - standard call
		$externalURL = NSMSettings::externalApiURL . $type;
		$apiResult = shell_exec("curl -H 'Content-Type: application/json' -X GET $externalURL");
		$externalNebStateArray = json_decode($apiResult, true);
		if (json_last_error() == JSON_ERROR_NONE) { //API data successfully retrieved. 
			$this->externalNebState =	$externalNebStateArray;
		} else {
			$this->messages = [
				'function' => 'getExternalAPIData',
				'messageRead' => "Error obtaining API data from $externalURL",
				'time' => time()
			];
			$this->externalNebState = null;
		}
	}

	protected function nodeStatusRPC() //Check the node status via CURL request.
	{
		$port = NSMSettings::nebListenPort;
		$nodeStatus = shell_exec("curl -H 'Content-Type: application/json' -X GET http://localhost:{$port}/v1/user/nebstate");
		$nodeStatusJson = json_decode($nodeStatus, true);
		if (json_last_error() == JSON_ERROR_NONE) { //Node is online - let's check the status
			$nodeStatusArray = json_decode($nodeStatusJson, true);
			$this->synchronized = $nodeStatusArray['result']['synchronized'];
			$this->nodeBlockHeight = $nodeStatusArray['result']['height'];
			$this->restart = false;
			$this->nodeStatus = 'online';
			$this->messages = [
				'function' => 'nodeStatus',
				'messageRead' => 'Node Online',
				'time' => time()
			];
			if ($this->synchronized != true) { //Check the status file for the last recorded status
				$this->messages = [
					'function' => 'nodeStatus',
					'messageRead' => 'Node not synchronized',
					'time' => time()
				];
			}
		} else { //No response from node - node is considered offline
			//$this->restart = true;
			$this->nodeStatus = 'offline';
			$this->messages = [
				'function' => 'nodeStatus',
				'messageRead' => 'Node offline',
				'time' => time()
			];
		}
	}


	private function nodeProcId($req = null) //Find the process id on the server and verify that there is only one process running (not counting children).
	{
		$findNebProcGrep = '[' . NSMSettings::nebStartServiceCommand[0] . ']' .
			substr(NSMSettings::nebStartServiceCommand, 1); //Set the search string
		$findNebProc = shell_exec("ps -ux | grep \"$findNebProcGrep\""); //Find the .neb process based on the $settings['restartServiceCommand'] setting
		if ($findNebProc) { //Process found
			$findNebProcExp = explode('\n', $findNebProc); //Break down the results by line (ps -ux | grep "[n]eb -c mainnet/conf/config.conf")
			//  echo "\nTEST: ".count($findNebProcExp)."\n";
			//  print_r($findNebProcExp);
			if (count($findNebProcExp) > 1) { //Multiple processes found - should only be one
				$this->messages = [
					'function' => 'nodeProcId',
					'messageRead' => 'Multiple Neb processes found',
					'result' => 'multipleProcessesFound',
					'time' => time()
				];
				if (NSMSettings::restartServiceIfMultipleProcFound == true) {
					$this->restart = true;
					$this->killAllNeb($findNebProcExp);
				}
			} else {
				if ($req == 'kill') {
					//    $this->messages = ['function' => 'nodeProcId', 'messageRead' => 'manual kill requested', 'result' => '', 'time' => time()];
					$this->killAllNeb($findNebProcExp);
				} else {
					if (count($findNebProcExp) == 0) {
						$this->nodeStatus = 'offline';
					} else {
						if ($req == 'procId') {
							//return $findNebProcExp;
							$this->messages = [
								'function' => 'nodeProcId',
								'messageRead' => "Neb Process ID: {$findNebProcExp}",
								'messageType' => 'oneProcessFound',
								'result' => 'null',
								'time' => time()
							];
						} else {
							$this->nodeStatus = 'online';
						}
					}
				}
			}
		} else { //No process found
			if ($req === 'kill') {
				$this->messages = [
					'function' => 'nodeProcId',
					'messageRead' => 'Manual kill requested but node not running',
					'messageType' => 'killReqButNodeOffline',
					'result' => 'error',
					'time' => time(),
					'custom' => "REQ: $req"
				];
			} else {
				$this->nodeStatus = 'offline';
				$this->messages = [
					'function' => 'nodeProcId',
					'messageRead' => 'No neb process found',
					'messageType' => 'markNodeAsOffline',
					'result' => 'success',
					'time' => time()
				];
			}
		}
	}

	private function killAllNeb($procList = null) //Kill all running neb processes via it's procid
	{
		if (!$procList || $procList == 'kill') { //If we do not receive any info, grab the procId and continue
			$procList = $this->nodeProcId('procId');
		}
		if (is_array($procList)) { //Expecting array (even if it's just one process to kill)
			foreach ($procList as $thisProc) {
				$thisProcExp = preg_split('/\s+/', $thisProc);
				//  print_r($thisProcExp);
				$thisProcId = $thisProcExp[1];
				shell_exec("kill $thisProcId");
			}
			//$this->restart = true;
			$this->nodeStatus = 'offline';
			$this->messages = [
				'function' => 'killAllNeb',
				'messageRead' => 'neb killed - number of processes: ' . count($procList),
				'messageType' => 'nebTerminated',
				'result' => 'success',
				'time' => time()
			];
			$this->nodeProcStatus = 'killed';
		} else {
			$this->messages = [
				'function' => 'killAllNeb',
				'messageRead' => 'Unknown data passed',
				'messageType' => 'unknownData',
				'result' => 'error',
				'time' => time()
			];
		}
	}

	private function startNeb() //Start the neb service
	{
		//Kill any existing processes
		$this->nodeProcId('kill'); //Make sure all processes are terminated
		/* Testing operation methods
		   //shell_exec("source ~/.bashrc");
		   //export LD_LIBRARY_PATH
		   //  echo 'export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib"; ' . $this->NSMSettings->goNebulasDirectory;
		   //$test = shell_exec('export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib"; ' . $this->NSMSettings->goNebulasDirectory);
		   //shell_exec('export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib;"');
		   //export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH
		   //echo "--> export LD_LIBRARY_PATH={$this->NSMSettings->goNebulasDirectory}native-lib:\$LD_LIBRARY_PATH \n";
		   // exec("export LD_LIBRARY_PATH={$this->NSMSettings->goNebulasDirectory}native-lib:\$LD_LIBRARY_PATH");+
		   // echo 'export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH';
		  */
		putenv('export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH');
		//  echo "\n-->" . NSMSettings::goNebulasDirectory . NSMSettings::nebStartServiceCommand . ' > /dev/null &' . "\n";
		exec(NSMSettings::goNebulasDirectory . NSMSettings::nebStartServiceCommand . ' > /dev/null &'); //Execute startup command
		//echo "--> ./nebStart.sh " . $this->NSMSettings->nebStartServiceCommand . '&';
		//shell_exec("./nebStart.sh " . $this->NSMSettings->nebStartServiceCommand . '&');//Execute startup command
		sleep(NSMSettings::restartServiceDelayCheck); //wait for the node to come online before checking the status
		$maxRestartAttempts = NSMSettings::maxRestartAttempts;
		echo "\nEntered startNeb - Restart attempts: {$this->restartAttempts} | Max restart attempts: {$maxRestartAttempts}\n";
		$this->nodeStatusRPC();
		// echo 'Node status: ' . $this->status . "\n";
		if ($this->nodeStatus == 'offline') {
			do {
				$this->restartAttempts++;
				$this->startNeb();
				echo "\nStarting neb - Restart attempt: {$this->restartAttempts}\n";
				if ($this->restartAttempts >= NSMSettings::maxRestartAttempts) {
					$this->restart = false;
					// echo "\n Restart failed - too many attempts.";
					$this->messages = [
						'function' => 'startNeb',
						'messageRead' => 'Restart failed - too many attempts.',
						'result' => 'startNebFailed',
						'time' => time()
					];
				}
			} while ($this->restart === true);
		} else {
			$this->restart = false;
			$this->nodeStatus = 'online';
			//echo "\n Neb is online - Restart attempt: {$this->restartAttempts}\n";
			$this->messages = [
				'function' => 'startNeb',
				'messageRead' => 'Neb is online',
				'result' => 'startNebSuccess',
				'time' => time()
			];
		}
	}

	private function reportData($settings)
	{
		if (NSMSettings::reportTo == 'externalWebsite') {
		} else if (NSMSettings::reportTo == 'email') {
		}
	}
}

$messages = [];
$messages['help'] = '';
$messages['showConfig'] = '';
$messages[] = '';

/* function __construct()//Constructor
 {
     // $this->NSMSettings = Reflection::getModifierNames($this->getModifiers());
     // $this->getSettings();//get all the settings from the config file
 }*/
/*    private function getSettings()//get all the settings from the config file
 {
     require_once(__DIR__ . '/NebulasServiceMonitorSettings.inc');
     $this->NSMSettings = new NSMSettings();//store all the settings in the class NSMSettings
 }*/
