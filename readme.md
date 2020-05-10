# Nebulas Blockchain Node Monitor #
A tool to check the status of a Nebulas.io node and intervene if necessary. Alpha phase of development.

Currently, the program will check:
* To see if the node is running;
* Verify only one instance of the node is live;
* Check if the node is synchronized via local RPC request and via external source;
* Check memory and swap allocation;
* Check CPU utilization;
* Local storage of node verification status;
* Start/Restart a node based on conditions (such as node offline, excessive memory usage, etc...);
* Send emails when intervention was necessary;

__Settings can be configured via the commented NSMSettings.inc file.__

## Requirements ##
* The server must have php7.2 and php-curl installed.
* The status is checked via a cron job.
* The file NebulasServiceMonitor.php must be the same user as the neb file so it can start and stop the program.

## Installation ##
Thus far, testing has been via a Debian based server running PHP 7.2. It should work with other base operating systems as well such as Ubuntu (based on Debian) and CentOS.
* Installation of PHP 7.2 and PHP-CURL. This can vary based on system and package manager. 
    * For example, Debian based systems can istall PHP via sudo user with the command sudo __apt install php7.2 php7.2-curl__
* Clone or copy this repo into your go-nebulas directory: From your go-nebulas directory: git clone https://github.com/satoshibytes/NebulasNodeMonitor.git
* Review the settings stored in the file __NSMSettings.inc__ and adjust accordingly to your requirements. The document is comment and should be easy to edit.
* Setup a cron job for 5 minutes (can be set higher or lower for more or less frequent checks): 
    * Type crontab -e (_if prompted for which editor, select nano_) and at the bottom of your cron file, enter __*/5 * * * * /path/to/go-nebulas/NebulasServiceMonitor.php__
    

_Note: /path/to/go-nebulas/ must be the actual path to your go-nebulas install. It probably looks something like /home/neb/go-nebulas/NebulasServiceMonitor.php_
    

## Future Features ##
* I plan to have the program call a 3rd party server to verify the server is still live and if not, send a message to the operator.
* The 3rd party site can record information such as sync status, server load, interventions, etc...
* Integrate a Telegram bot for notifications about servers being down.
* Simpler installer.

### This is still a work in progress ###
This repo is a work in progress and I am still working on the code and testing. It's not ready for real world usage at the moment. In the future, I would like to support notifications via Telegram & email as well as a web-based GUI with stored node stats. 

If used in a live environment, you can set the option to disable server restarts so it will only observe the system.

### License ###
Copyright 2020 @SatoshiBytes

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Original version published at https://github.com/satoshibytes/NebulasNodeMonitor

