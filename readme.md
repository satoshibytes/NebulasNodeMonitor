# Nebulas Blockchain Node Monitor #
A service tool to monitor the status of a Nebulas.io node and intervene (e.g. restart, notify admin) if necessary. It is currently in a beta phase of development with all current features functioning and currently operating on multiple independent servers (both bare metal and VM/LXC). For testing, see __How to test__ below.

## Service monitor features (currently available) ##
* Verifies the node is running;
* Verifies only one instance of the node is live;
* Verifies the node is synchronized via local RPC request and via external source;
    * If not in sync, the service monitor will verify the block height is increasing at a minimum amount per check (set in the config).
* Check memory utilization and swap allocation;
* Check CPU utilization;
* Start/Restart a node based on different conditions such as node offline, excessive memory usage, etc... Specific restart requirements are set in the config file (NebSvcMonitorSettings.inc);
* Stores the results of each check in a local log file;
* The service can be called to read the log, check the current status and more. To view available features, enter __php NebSvcMonitor.php help__
* Send emails when intervention was necessary;

__Settings must be configured via the commented NebSvcMonitorSettings.inc file.__

## Requirements ##
* The server must have php7.3-cli and php-curl installed.
* The status is checked via a shell script every 5 minutes (Can be changed).
* The files must be the same user as the neb file so it can start and stop the program.

## Installation ##
Thus far, testing has been via a Debian based server running PHP 7.3. It should work with other base operating systems as well such as Ubuntu (based on Debian) and CentOS.
* Installation of PHP 7.3-cli and PHP-CURL. This can vary based on system and package manager. 
    * For example, Debian based systems can istall PHP via sudo user with the command __sudo apt install php7.3-cli php7.3-curl__
* Clone or copy this repo into your go-nebulas directory: From your go-nebulas directory: __git clone https://github.com/satoshibytes/NebulasNodeMonitor.git__
* You should have a new directory named "NebulasNodeMonitor". Enter the directory via __cd NebulasNodeMonitor__
* Review and edit the settings stored in the file __NebSvcMonitorSettings.inc__ and adjust accordingly to your requirements. The document is comment and should be easy to edit.
* Review and edit the settings stored in the file NebSvcMonitor.sh then setup the required symbolic links by entering __./NebSvcMonitor.sh install__
    * Check and fix any errors then return to the go-nebulas directory. From there, enter __./NebSvcMonitor.sh__ and it will remain active checking your node.
    * The timing of node verification can also be set within this file and must match the setting (in seconds) within the __NebSvcMonitorSettings.inc__ file for proper operation.
* Set the permission for the files __NebSvcMonitor.sh__ and __NebSvcMonitor.php__ via chmod +x NebSvcMonitor.sh && chmod +x NebSvcMonitor.php

#### Auto start & verification
* If you would like to have this service start at boot, you can set a cron job by entering __crontab -e__ and add __@reboot /path/to/go-nebulas/NebSvcMonitor.sh >> /path/to/go-nebulas/NebSvcMonitor.log__ at the bottom of the file.
* You can also setup a cron job to verify the service monitor is functioning properly. To do this, enter: __crontab -e__ and add __*/5 * * * * /path/to/go-nebulas/NebSvcMonitor.sh checkStatus >> /path/to/go-nebulas/NebSvcMonitor.log__ to the bottom of your cron job list

_Note: /path/to/go-nebulas/ must be the actual path to your go-nebulas install. It probably looks something like /home/neb/go-nebulas/NebSvcMonitor.sh_
    
## Future Features ##
* I plan to have the program call a 3rd party server to verify the server is still live and if not, send a message to the operator.
* The 3rd party site can record information such as sync status, server load, interventions, etc...
* Integrate a Telegram bot for notifications about servers being down.
* Simpler installer.

## This is still a work in progress ##
This program is a work in progress and is being in testing on live nodes. Test in a live environment at your own risk - see below __How to test__.

_The last item that needs work for the moment is making the messages being sent out to email easier to read._

### How to test ### 
If testing, you can set the option __$NSMSettings['enableRestartService']__ to false in the _
_ file. With it set to false, the service will run and send out emails but not intervene with operations.

If used in a live environment, you can set the option to disable server restarts so it will only observe the system and send notification - it will not intervene with operation.

It's important that the permissions/owners of the neb binary and this programs files match or have proper pemissions.

### Bug report ###
When submitting a bug report, be sure to include the full error, last log entries (be sure there is no private information included such as node name), operating system/version and PHP version (check with php -v). 

### License ###

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Created 2020 by @SatoshiBytes

Original version published at https://github.com/satoshibytes/NebulasNodeMonitor
