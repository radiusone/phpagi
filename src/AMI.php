<?php

namespace PhpAgi;

use Exception;

if (!class_exists('PhpAgi\\AGI')) {
    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'AGI.php');
}

/**
 * PHP Asterisk Manager Interface (AMI) client
 *
 * Copyright (c) 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * Copyright 2023 RadiusOne Inc.
 * All Rights Reserved.
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * a copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * @package PhpAgi
 * @version 3.0
 * @filesource https://github.com/welltime/phpagi
 * @see http://phpagi.sourceforge.net/
 * @noinspection PhpUnused
 * @package PhPAgi
 * @example examples/sip_show_peer.php Get information about a sip peer
 */
class AMI
{
    /** @var array<string,mixed> Config variables */
    public array $config;

    /** @var resource Socket */
    public $socket = null;

    /** @var string Server we are connected to */
    public string $server;

    /** @var int Port on the server we are connected to */
    public int $port;

    /** @var AGI|null Parent AGI */
    private ?AGI $pagi = null;

    /** @var array<string,callable> Event Handlers */
    private array $event_handlers;

    /** @var bool Whether we're successfully logged in */
    private bool $_logged_in = false;

    public function setPagi(AGI $agi)
    {
        $this->pagi = $agi;
    }

    /**
     * Constructor
     *
     * @param string|null $config is the name of the config file to parse or a parent agi from which to read the config
     * @param array $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
     * @return void
     */
    public function __construct(string $config = null, array $optconfig = [])
    {
        // load config
        if (file_exists($config ?? AGI::DEFAULT_PHPAGI_CONFIG)) {
            $this->config = parse_ini_file($config ?? AGI::DEFAULT_PHPAGI_CONFIG, true);
        }

        // If optconfig is specified, stuff vals and vars into 'asmanager' config array,
        // add default values to config for uninitialized values
        $defaults = [
            'server' => 'localhost',
            'port' => 5038,
            'username' => 'phpagi',
            'secret' => 'phpagi',
            'write_log' => false,
        ];
        $this->config['asmanager'] = array_merge($defaults, $optconfig);
    }

    /**
     * Send a request
     *
     * @param string $action
     * @param array $parameters
     * @return array of parameters
     */
    public function send_request(string $action, array $parameters = []): array
    {
        $req = ["Action: $action"];
        $actionid = null;
        foreach ($parameters as $var => $val) {
            if (is_array($val)) {
                foreach ($val as $line) {
                    $req[] = "$var: $line";
                }
            } else {
                $req[] = "$var: $val";
                if (strtolower($var) === "actionid") {
                    $actionid = $val;
                }
            }
        }
        if (is_null($actionid)) {
            $actionid = $this->ActionID();
            $req[] = "ActionID: $actionid";
        }

        $req = implode("\r\n", $req) . "\r\n";
        fwrite($this->socket, $req);

        return $this->wait_response($actionid);
    }

    /**
     * Read an AMI message
     *
     * Example AMI messages:
     * ```
     * Response: Success
     * Message: Command output follows
     * Output: Foo
     * Output: Bar
     * Output: Baz
     * ```
     * ```
     * Response: Error
     * Message: Command output follows
     * Output: No such command 'foo bar' (type 'core show help foo' for other possible commands)
     * ```
     * ```
     * Response: Success
     * Ping: Pong
     * Timestamp: 1691452183.824353
     * ```
     * ```
     * Event: PeerStatus
     * Privilege: system,all
     * ChannelType: PJSIP
     * Peer: PJSIP/1234
     * PeerStatus: Reachable
     * ```
     */
    public function read_one_msg(): array
    {
        $buffer = '';
        do {
            $buf = fgets($this->socket);
            if (false === $buf) {
                die("Error reading from AMI socket");
            }
            $buffer .= $buf;
        } while (!feof($this->socket) && strpos($buf, "\r\n\r\n") === false);

        $msg = trim($buffer);

        $msgarr = explode("\r\n", $msg);

        $parameters = [];
        foreach ($msgarr as $str) {
            $kv = explode(':', $str, 2);
            $key = trim($kv[0]);
            if (!isset($parameters[$key])) {
                $parameters[$key] = '';
            }
            $parameters[$key] .= trim($kv[1] ?? '') . "\n";
        }
        if (isset($parameters['Event'])) {
            $this->process_event($parameters);
        } elseif (isset($parameters['Response'])) {
            // keep 'data' element like in old code
            $parameters['data'] = $parameters['Output'];
        } else {
            $this->log('Unhandled response packet from Manager: ' . print_r($parameters, true));
        }

        return $parameters;
    }

    /**
     * Wait for a response
     *
     * If a request was just sent, this will return the response.
     * Otherwise, it will loop forever, handling events.
     *
     * XXX this code is slightly better then the original one
     * however it's still totally screwed up and needs to be rewritten,
     * for two reasons at least:
     * 1. it does not handle socket errors in any way
     * 2. it is terribly synchronous, esp. with eventlists,
     *    i.e. your code is blocked on waiting until full responce is received
     */
    public function wait_response($actionid = null): array
    {
        do {
            $res = $this->read_one_msg();
        } while (!is_null($actionid) && ($res['ActionID'] ?? '') === $actionid);

        if (($res['EventList'] ?? '') === 'start') {
            $evlist = [];
            do {
                $res = $this->wait_response($actionid);
                if (($res['EventList'] ?? '') === 'Complete') {
                    break;
                }
                $evlist[] = $res;
            } while (true);
            $res['events'] = $evlist;
        }

        return $res;
    }

    /**
     * Connect to Asterisk
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Login/
     *
     * @param string|null $server
     * @param string|null $username
     * @param string|null $secret
     * @return bool true on success
     *
     */
    public function connect(string $server = null, string $username = null, string $secret = null): bool
    {
        // use config if not specified
        $server = $server ?? $this->config['asmanager']['server'];
        $username = $username ?? $this->config['asmanager']['username'];
        $secret = $secret ?? $this->config['asmanager']['secret'];

        // get port from server if specified
        $c = explode(':', $server);
        $this->server = $c[0];
        $this->port = $c[1] ?? $this->config['asmanager']['port'];

        // connect the socket
        $result = fsockopen($this->server, $this->port, $errno, $errstr);
        if ($result === false) {
            $this->log("Unable to connect to manager $this->server:$this->port ($errno): $errstr");

            return false;
        }
        $this->socket = $result;

        // read the header
        $str = fgets($this->socket);
        if (!$str) {
            // a problem.
            $this->log("Asterisk Manager header not received.");

            return false;
        }

        // login
        if ($this->Login($username, $secret)) {
            $this->_logged_in = true;

            return true;
        }

        $this->log("Failed to login.");
        $this->disconnect();

        return false;
    }

    /**
     * Disconnect
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Logoff/
     *
     * @return void
     */
    public function disconnect()
    {
        if ($this->_logged_in) {
            $this->Logoff();
        }
        fclose($this->socket);
    }

    // *********************************************************************************************************
    // **                       COMMANDS                                                                      **
    // *********************************************************************************************************

    /**
     * Set Absolute Timeout
     *
     * Hangup a channel after a certain time.
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/AbsoluteTimeout/
     *
     * @param string $channel Channel name to hangup
     * @param int $timeout Maximum duration of the call (sec)
     */
    public function AbsoluteTimeout(string $channel, int $timeout): array
    {
        return $this->send_request('AbsoluteTimeout', ['Channel' => $channel, 'Timeout' => $timeout]);
    }

    /**
     * Change monitoring filename of a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ChangeMonitor/
     *
     * @param string $channel the channel to record.
     * @param string $file the new name of the file created in the monitor spool directory.
     */
    public function ChangeMonitor(string $channel, string $file): array
    {
        return $this->send_request('ChangeMontior', ['Channel' => $channel, 'File' => $file]);
    }

    /**
     * Execute Command
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Command/
     *
     * @param string $command
     * @param string|null $actionid message matching variable
     */
    public function Command(string $command, string $actionid = null): array
    {
        $parameters = ['Command' => $command];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Command', $parameters);
    }

    /**
     * Enable/Disable sending of events to this manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Events/
     *
     * @param string $eventmask is either 'on', 'off', or 'system,call,log'
     */
    public function Events(string $eventmask): array
    {
        return $this->send_request('Events', ['EventMask' => $eventmask]);
    }

    /**
     *  Generate random ActionID
     **/
    public function ActionID(): string
    {
        return "A" . sprintf(rand(), "%6d");
    }

    /**
     *
     *  DBGet
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBGet/
     *
     * @param string $family key family
     * @param string $key key name
     * @return string
     **/
    public function DBGet(string $family, string $key, $actionid = null)
    {
        $parameters = ['Family' => $family, 'Key' => $key];
        if ($actionid == null) {
            $actionid = $this->ActionID();
        }
        $parameters['ActionID'] = $actionid;
        $response = $this->send_request("DBGet", $parameters);
        if ($response['Response'] == "Success") {
            $response = $this->wait_response($actionid);

            return $response['Val'];
        }

        return "";
    }

    /**
     * Check Extension Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ExtensionState/
     *
     * @param string $exten Extension to check state on
     * @param string $context Context for extension
     * @param string|null $actionid message matching variable
     */
    public function ExtensionState(string $exten, string $context, string $actionid = null): array
    {
        $parameters = ['Exten' => $exten, 'Context' => $context];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('ExtensionState', $parameters);
    }

    /**
     * Gets a Channel Variable
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Getvar/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     *
     * @param string $channel Channel to read variable from
     * @param string $variable
     * @param string|null $actionid message matching variable
     */
    public function GetVar(string $channel, string $variable, string $actionid = null): array
    {
        $parameters = ['Channel' => $channel, 'Variable' => $variable];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('GetVar', $parameters);
    }

    /**
     * Hangup Channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Hangup/
     *
     * @param string $channel The channel name to be hungup
     */
    public function Hangup(string $channel): array
    {
        return $this->send_request('Hangup', ['Channel' => $channel]);
    }

    /**
     * List IAX Peers
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/IAXpeers/
     */
    public function IAXPeers(): array
    {
        return $this->send_request('IAXPeers');
    }

    /**
     * List available manager commands
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ListCommands/
     *
     * @param string|null $actionid message matching variable
     */
    public function ListCommands(string $actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('ListCommands', ['ActionID' => $actionid]);
        } else {
            return $this->send_request('ListCommands');
        }
    }

    /**
     * Login Manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Login/
     */
    public function Login(string $username = null, string $secret = null): bool
    {
        $res = $this->send_request('Login', ['Username' => $username, 'Secret' => $secret]);

        return $res['Response'] === 'Success';
    }

    /**
     * Logoff Manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Logoff/
     */
    public function Logoff(): bool
    {
        $res = $this->send_request('Logoff');

        return $res['Response'] === 'Goodbye';
    }

    /**
     * Check Mailbox Message Count
     *
     * Returns number of new and old messages.
     *   Message: Mailbox Message Count
     *   Mailbox: <mailboxid>
     *   NewMessages: <count>
     *   OldMessages: <count>
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/MailboxCount/
     * 
     * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
     * @param string|null $actionid message matching variable
     */
    public function MailboxCount(string $mailbox, string $actionid = null): array
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('MailboxCount', $parameters);
    }

    /**
     * Check Mailbox
     *
     * Returns number of messages.
     *   Message: Mailbox Status
     *   Mailbox: <mailboxid>
     *   Waiting: <count>
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/MailboxStatus/
     *
     * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
     * @param string|null $actionid message matching variable
     */
    public function MailboxStatus(string $mailbox, string $actionid = null): array
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('MailboxStatus', $parameters);
    }

    /**
     * Monitor a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Monitor/
     *
     * @param string $channel
     * @param string|null $file
     * @param string|null $format
     * @param bool|null $mix
     */
    public function Monitor(string $channel, string $file = null, string $format = null, bool $mix = null): array
    {
        $parameters = ['Channel' => $channel];
        if ($file) {
            $parameters['File'] = $file;
        }
        if ($format) {
            $parameters['Format'] = $format;
        }
        if (!is_null($file)) {
            $parameters['Mix'] = ($mix) ? 'true' : 'false';
        }

        return $this->send_request('Monitor', $parameters);
    }

    /**
     * Originate Call
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Originate/
     *
     * @param string $channel Channel name to call
     * @param string|null $exten Extension to use (requires 'Context' and 'Priority')
     * @param string|null $context Context to use (requires 'Exten' and 'Priority')
     * @param string|null $priority Priority to use (requires 'Exten' and 'Context')
     * @param string|null $application Application to use
     * @param string|null $data Data to use (requires 'Application')
     * @param int|null $timeout How long to wait for call to be answered (in ms)
     * @param string|null $callerid Caller ID to be set on the outgoing channel
     * @param string|null $variable Channel variable to set (VAR1=value1|VAR2=value2)
     * @param string|null $account Account code
     * @param bool|null $async true fast origination
     * @param string|null $actionid message matching variable
     */
    public function Originate(string $channel,
                              string $exten = null, string $context = null, string $priority = null,
                              string $application = null, string $data = null,
                              int    $timeout = null, string $callerid = null, string $variable = null, string $account = null, bool $async = null, string $actionid = null): array
    {
        $parameters = ['Channel' => $channel];

        if ($exten) {
            $parameters['Exten'] = $exten;
        }
        if ($context) {
            $parameters['Context'] = $context;
        }
        if ($priority) {
            $parameters['Priority'] = $priority;
        }

        if ($application) {
            $parameters['Application'] = $application;
        }
        if ($data) {
            $parameters['Data'] = $data;
        }

        if ($timeout) {
            $parameters['Timeout'] = $timeout;
        }
        if ($callerid) {
            $parameters['CallerID'] = $callerid;
        }
        if ($variable) {
            $parameters['Variable'] = $variable;
        }
        if ($account) {
            $parameters['Account'] = $account;
        }
        if (!is_null($async)) {
            $parameters['Async'] = ($async) ? 'true' : 'false';
        }
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Originate', $parameters);
    }

    /**
     * List parked calls
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ParkedCalls/
     *
     * @param string|null $actionid message matching variable
     */
    public function ParkedCalls(string $actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('ParkedCalls', ['ActionID' => $actionid]);
        } else {
            return $this->send_request('ParkedCalls');
        }
    }

    /**
     * Ping
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Ping/
     */
    public function Ping(): array
    {
        return $this->send_request('Ping');
    }

    /**
     * Queue Add
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueAdd/
     *
     * @param string $queue
     * @param string $interface
     * @param int $penalty
     * @param string $memberName
     */
    public function QueueAdd(string $queue, string $interface, int $penalty = 0, $memberName = false): array
    {
        $parameters = ['Queue' => $queue, 'Interface' => $interface];
        if ($penalty) {
            $parameters['Penalty'] = $penalty;
        }
        if ($memberName) {
            $parameters["MemberName"] = $memberName;
        }

        return $this->send_request('QueueAdd', $parameters);
    }

    /**
     * Queue Remove
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueRemove/
     *
     * @param string $queue
     * @param string $interface
     */
    public function QueueRemove(string $queue, string $interface): array
    {
        return $this->send_request('QueueRemove', ['Queue' => $queue, 'Interface' => $interface]);
    }

    /**
     * @lnk https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueReload/
     */
    public function QueueReload(): array
    {
        return $this->send_request('QueueReload');
    }

    /**
     * Queue Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueStatus/
     *
     * @param string|null $actionid message matching variable
     */
    public function QueueStatus(string $actionid = null): array
    {
        if ($actionid) {
            return $this->send_request('QueueStatus', ['ActionID' => $actionid]);
        } else {
            return $this->send_request('QueueStatus');
        }
    }

    /**
     * Redirect
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Redirect/
     *
     * @param string $channel
     * @param string $extrachannel
     * @param string $exten
     * @param string $context
     * @param string $priority
     */
    public function Redirect(string $channel, string $extrachannel, string $exten, string $context, string $priority): array
    {
        return $this->send_request('Redirect', [
            'Channel' => $channel, 'ExtraChannel' => $extrachannel, 'Exten' => $exten,
            'Context' => $context, 'Priority' => $priority,
        ]);
    }

    /**
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Atxfer/
     */
    public function Atxfer($channel, $exten, $context, $priority): array
    {
        return $this->send_request('Atxfer', [
            'Channel' => $channel, 'Exten' => $exten,
            'Context' => $context, 'Priority' => $priority,
        ]);
    }

    /**
     * Set the CDR UserField
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
     * @param string $userfield
     * @param string $channel
     * @param string|null $append
     */
    public function SetCDRUserField(string $userfield, string $channel, string $append = null): array
    {
        $parameters = ['UserField' => $userfield, 'Channel' => $channel];
        if ($append) {
            $parameters['Append'] = $append;
        }

        return $this->send_request('SetCDRUserField', $parameters);
    }

    /**
     * Set Channel Variable
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Setvar/
     *
     * @param string $channel Channel to set variable for
     * @param string $variable name
     * @param string $value
     */
    public function SetVar(string $channel, string $variable, string $value): array
    {
        return $this->send_request('SetVar', ['Channel' => $channel, 'Variable' => $variable, 'Value' => $value]);
    }

    /**
     * Channel Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Status/
     * @param string $channel
     * @param string|null $actionid message matching variable
     */
    public function Status(string $channel, string $actionid = null): array
    {
        $parameters = ['Channel' => $channel];
        if ($actionid) {
            $parameters['ActionID'] = $actionid;
        }

        return $this->send_request('Status', $parameters);
    }

    /**
     * Stop monitoring a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/StopMonitor/
     *
     * @param string $channel
     */
    public function StopMonitor(string $channel): array
    {
        return $this->send_request('StopMonitor', ['Channel' => $channel]);
    }

    // *********************************************************************************************************
    // **                       MISC                                                                          **
    // *********************************************************************************************************

    /*
     * Log a message
     *
     * @param string $message
     * @param integer $level from 1 to 4
     */
    public function log($message, $level = 1)
    {
        if ($this->pagi) {
            $this->pagi->conlog($message, $level);
        } elseif ($this->config['asmanager']['write_log']) {
            error_log(date('r') . ' - ' . $message);
        }
    }

    /**
     * Add event handler
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Events/AGIExecEnd/
     *
     * @param string $event type or * for default handler
     * @param callable $callback function to handle the event
     * @return bool sucess
     */
    public function add_event_handler(string $event, callable $callback): bool
    {
        $event = strtolower($event);
        if (!isset($this->event_handlers[$event])) {
            $this->event_handlers[$event] = [];
        }
        $this->event_handlers[$event][] = $callback;

        return true;
    }

    /**
     * Remove event handler
     *
     * @param string $event type or * for default handler
     * @return bool sucess
     **/
    public function remove_event_handler(string $event): bool
    {
        $event = strtolower($event);
        if (isset($this->event_handlers[$event])) {
            unset($this->event_handlers[$event]);

            return true;
        }
        $this->log("$event handlers are not defined.");

        return false;
    }

    /**
     * Process event
     *
     * @param array $parameters
     * @return void
     */
    private function process_event(array $parameters)
    {
        $e = strtolower($parameters['Event']);
        $this->log("Got event.. $e");

        $handlers = $this->event_handlers[$e] ?? $this->event_handlers['*'] ?? [];

        foreach ($handlers as $handler) {
            if (!is_callable($handler, false, $name)) {
                $this->log("No event handler for event '$e'");
                continue;
            }
            $this->log("Executing handler '$name'");
            call_user_func($handler, $e, $parameters, $this->server, $this->port);
        }
    }
}
