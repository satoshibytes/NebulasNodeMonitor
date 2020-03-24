<?php

/*
 * This script was created to monitor the status of a Nebulas node.
 * It should work as-is on all modern Debian (Ubuntu, etc...) based systems (have not tested on RHEL (should work) or BSD (probably needs some modifications) as of yet).
 *
 * Be sure to set your configuration in the NebulasServiceMonitorSettings.inc file.
 * Script requirements
 * ->Server must have PHP 5.6 or later installed installed
 * ->Server must have curl installed
 * ->chmod +x NebulasServicMonitor.php
 * execution: php NebulasServiceMonitor.php REQ
 * ->php NebulasServiceMonitor.php stopNeb
 * ->php NebulasServiceMonitor.php startNeb
 */
if (isset($argv[1])) {//&& $argv[2] == 'fromBash'
    print_r($argv);
    $doProcess = $argv[1];
} else {
    $doProcess = 'about';
}
$NebulasServiceMonitor = new NebulasServiceMonitor();
$NebulasServiceMonitor->doProcess($doProcess);

class NebulasServiceMonitor extends NSMSettings
{
    public $about = array('version' => '0.1', 'name' => 'Nebulas Service Monitor', 'creator' => '@satoshiBytes', 'github' => '', 'avaiable actions' => "");//About this script

    //Define initial variables
    private $restart = false;//does the node need to be restarted - set initial to false
    private $restartAttempts = 0;//count how many restart attempts have been made
    private $messages = array();//store any messages from the processes
    private $status = 'online';//if node is down, set to offline
    private $synchronized = null;
    private $blockHeight = null;

    private function statusCheck()//This is the primary monitor and restart function
    {
        /*
         * Steps:
         * 1) Check to see if there is a response from the node
         *      a) Response good and in sync
         *          i) Check resource usage. If resources exceed specified, restart the node and/or notify operator. Submit data to website.
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
         *4) Send report to external server for logging and contacting operator
         *      a) Once the server receives the log, it will decide to contact the op or to even startup a secondary node.
         */
        $this->nodeStatusRPC();
        $this->nodeProcId();
        $this->serverStatus();
        if ($this->restart == true) {//nodeProcId found no running neb functions - restart the service
            $this->startNeb();
        }
    }

    public function doProcess($doThis)//This are the available actions.
    {
        if ($doThis == 'about') {//print the about section
            print_r($this->about);
        } else if ($doThis == 'showSettings') {//print all the settings
            print_r(get_defined_constants(true));
        } else if ($doThis == 'statusCheck') {//check the status of the node and intervene if necessary - This is the default action
            $this->statusCheck();
        } else if ($doThis == 'showStatus') {//check the status of the node but do not intervene

        } else if ($doThis == 'killNeb' || $doThis == 'stopNeb') {
            $this->nodeProcId('kill');
            print_r($this->messages);
        } else if ($doThis == 'startNeb') {
            $this->startNeb();
            print_r($this->messages);
        }

    }

    private function showStatus()
    {

        $nodeProcId = $this->nodeProcId();    //Get process id
        $serverStatus = $this->serverStatus();//Check load and mem usage
        $nodeStatus = $this->nodeStatusRPC();
        return array('serverStatus' => $serverStatus, 'nodeProcId' => $nodeProcId, 'nodeStatus' => $nodeStatus);
    }

    private function serverStatus()
    {
        $load = sys_getloadavg();
        $memoryUsage = shell_exec("free");//Grab the current memory status
        return array('load' => $load, 'memoryUsage' => $memoryUsage);
    }

    protected function nodeStatusRPC()//Check the node status via CURL request.
    {
        $port = NSMSettings::nebListenPort;
        $nodeStatus = shell_exec("curl -H 'Content-Type: application/json' -X GET http://localhost:{$port}/v1/user/nebstate");
        $nodeStatusJson = json_decode($nodeStatus, true);
        if (json_last_error() == JSON_ERROR_NONE) {//Node is online - let's check the status
            $nodeStatusArray = json_decode($nodeStatusJson, true);
            $this->synchronized = $nodeStatusArray['result']['synchronized'];
            $this->blockHeight = $nodeStatusArray['result']['height'];
            $this->restart = false;
            $this->status = 'online';
            $this->messages = ['function' => 'nodeStatus', 'messageRead' => 'Node Online', 'time' => time()];
            if ($this->synchronized != true) {//Check the status file for the last recorded status
                $this->messages = ['function' => 'nodeStatus', 'messageRead' => 'Node not synchronized', 'time' => time()];
            }
        } else {//No response from node - node is considered offline
            $this->restart = true;
            $this->status = 'offline';
            $this->messages = ['function' => 'nodeStatus', 'messageRead' => 'Node offline', 'time' => time()];
        }
    }

    private function readWriteStatus($do = 'read')//store the current settings in a local file to verify the node is increasing block height.
    {
        if ($do == 'read') {//Get the status from the file. Stored in JSON array.

        } else {//write to file

        }
    }

    private function nodeProcId($req = null)//Find the process id on the server and verify that there is only one process running (not counting children).
    {
        $findNebProcGrep = '[' . NSMSettings::nebStartServiceCommand[0] . ']' . substr(NSMSettings::nebStartServiceCommand, 1);//Set the search string
        $findNebProc = shell_exec("ps -ux | grep \"$findNebProcGrep\"");//Find the .neb process based on the $settings['restartServiceCommand'] setting
        if ($findNebProc) {//Process found
            $findNebProcExp = explode('\n', $findNebProc);//Break down the results by line (ps -ux | grep "[n]eb -c mainnet/conf/config.conf")
            //  echo "\nTEST: ".count($findNebProcExp)."\n";
            //  print_r($findNebProcExp);
            if (count($findNebProcExp) > 1) {//Multiple processes found - should only be one
                $this->messages = ['function' => 'nodeProcId', 'messageRead' => 'Multiple Neb processes found', 'result' => 'multipleProcessesFound', 'time' => time()];
                if (NSMSettings::restartServiceIfMultipleProcFound == true) {
                    $this->restart = true;
                    $this->killAllNeb($findNebProcExp);
                }
            } else if ($req == 'kill') {
                //    $this->messages = ['function' => 'nodeProcId', 'messageRead' => 'manual kill requested', 'result' => '', 'time' => time()];
                $this->killAllNeb($findNebProcExp);
            } else if (count($findNebProcExp) == 0) {
                $this->status = 'offline';
            } else if ($req == 'procId') {
                return $findNebProcExp;
            } else {
                $this->status = 'online';
            }
        } else {//No process found
            if ($req === 'kill') {
                $this->messages = ['function' => 'nodeProcId', 'messageRead' => 'Manual kill requested but node not running', 'result' => 'killReqButNodeOffline', 'time' => time(), 'custom' => "REQ: $req"];
            } else {
                $this->status = 'offline';
                $this->messages = ['function' => 'nodeProcId', 'messageRead' => 'No neb process found', 'result' => 'markNodeAsOffline', 'time' => time()];
            }
        }
        return null;
    }

    private function killAllNeb($procList = null)//Kill all running neb processes via it's procid
    {
        if (!$procList || $procList == 'kill') {//If we do not receive any info, grab the procId and continue
            $procList = $this->nodeProcId('procId');
        }
        if (is_array($procList)) {//Expecting a array (even if it's just one process to kill)
            foreach ($procList as $thisProc) {
                $thisProcExp = preg_split('/\s+/', $thisProc);
                //  print_r($thisProcExp);
                $thisProcId = $thisProcExp[1];
                shell_exec("kill $thisProcId");
            }
            $this->restart = true;
            $this->status = 'offline';
            $this->messages = ['function' => 'killAllNeb', 'messageRead' => 'neb killed - number of processes: ' . count($procList), 'result' => 'nebTerminated', 'time' => time()];
        } else {
            $this->messages = ['function' => 'killAllNeb', 'messageRead' => 'Unknown data passed', 'result' => 'unknownData', 'time' => time()];
        }
    }

    private function startNeb()//Start the neb service
    {
        //Kill any existing processes
        $this->nodeProcId('kill');//Make sure all processes are terminated
        //shell_exec("source ~/.bashrc");
        //export LD_LIBRARY_PATH
        //  echo 'export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib"; ' . $this->NSMSettings->goNebulasDirectory;
        //$test = shell_exec('export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib"; ' . $this->NSMSettings->goNebulasDirectory);
        //shell_exec('export LD_LIBRARY_PATH="' . $this->NSMSettings->goNebulasDirectory . '/native-lib;"');
        //export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH
        //echo "--> export LD_LIBRARY_PATH={$this->NSMSettings->goNebulasDirectory}native-lib:\$LD_LIBRARY_PATH \n";
        // exec("export LD_LIBRARY_PATH={$this->NSMSettings->goNebulasDirectory}native-lib:\$LD_LIBRARY_PATH");+
        // echo 'export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH';
        putenv('export LD_LIBRARY_PATH=$CUR_DIR/native-lib:$LD_LIBRARY_PATH');
        echo "\n-->" . NSMSettings::goNebulasDirectory . NSMSettings::nebStartServiceCommand . ' > /dev/null &' . "\n";
        exec(NSMSettings::goNebulasDirectory . NSMSettings::nebStartServiceCommand . ' > /dev/null &');//Execute startup command
        //echo "--> ./nebStart.sh " . $this->NSMSettings->nebStartServiceCommand . '&';
        //shell_exec("./nebStart.sh " . $this->NSMSettings->nebStartServiceCommand . '&');//Execute startup command
        sleep(NSMSettings::restartServiceDelayCheck);//wait for the node to come online before checking the status
        $maxRestartAttempts = NSMSettings::maxRestartAttempts;
        echo "\nEntered startNeb - Restart attempts: {$this->restartAttempts} | Max restart attempts: {$maxRestartAttempts}\n";
        $this->nodeStatus();
        echo 'Node status: ' . $this->status . "\n";
        if ($this->status == 'offline') {
            do {
                $this->restartAttempts++;
                $this->startNeb();
                echo "\nStarting neb - Restart attempt: {$this->restartAttempts}\n";
                if ($this->restartAttempts >= NSMSettings::maxRestartAttempts) {
                    $this->restart = false;
                    echo "\n Restart failed - too many attempts.";
                }
            } while ($this->restart === true);
        } else {
            $this->restart = false;
            $this->status = 'online';
            echo "\n Neb is online - Restart attempt: {$this->restartAttempts}\n";
        }
    }

    private function reportData($settings)
    {
        if (NSMSettings::reportTo == 'externalWebsite') {

        } elseif (NSMSettings::reportTo == 'email') {

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
