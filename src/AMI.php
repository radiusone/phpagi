<?php /** @noinspection PhpUnused */

namespace PhpAgi;

use ReflectionException;
use ReflectionMethod;

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
            if (is_null($val)) {
                continue;
            }
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
        $res = $this->Login($username, $secret);
        if ($res['Response'] === 'Success') {
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

    /**
     * Send a request based on the calling method
     *
     * This calls send_request and passes the name of the calling function and its arguments
     *
     * @return array
     * @throws ReflectionException
     */
    private function executeByReflection(): array
    {
        $stack = debug_backtrace(0, 2);
        $func = $stack[1]["function"];
        $ref = new ReflectionMethod($this, $func);
        $args = [];
         foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            $pos = $param->getPosition();
            $val = $stack[1]['args'][$pos] ?? $param->getDefaultValue();
            if ($param->getType()->getName() === 'bool') {
                $val = $val ? 'True' : 'False';
            }
            $args[$name] = $val;
        }

        return $this->send_request($func, ...$args);
    }

    // *********************************************************************************************************
    // **                       COMMANDS                                                                      **
    // *********************************************************************************************************

    /**
     * Add an AGI command to the execute queue of the channel in Async AGI
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/AGI/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel Channel that is currently in Async AGI
     * @param string $Command Application to execute
     * @param string|null $CommandID This will be sent back in CommandID header of AsyncAGI exec event notification
     * @param string|null $ActionID ActionID for this transaction. Will be returned (autogenerated if not supplied)
     * @return array
     * @throws ReflectionException
     */
    public function AGI(string $Channel, string $Command, string $CommandID = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Generate an Advice of Charge message on a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/AOCMessage/
     */
    public function AOCMessage()
    {
        die("Not implemented");
    }

    /**
     * Set Absolute Timeout
     *
     * Hangup a channel after a certain time.
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/AbsoluteTimeout/
     *
     * @param string $Channel Channel name to hangup
     * @param int $Timeout Maximum duration of the call (sec)
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function AbsoluteTimeout(string $Channel, int $Timeout, string $ActionID = null): array
    {
        return $this->send_request(
            'AbsoluteTimeout',
            ['Channel' => $Channel, 'Timeout' => $Timeout, 'ActionID' => $ActionID]
        );
    }


    /**
     * Sets an agent as no longer logged in
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/AgentLogoff/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Agent Agent ID of the agent to log off
     * @param bool $Soft Set to true to not hangup existing calls
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function AgentLogoff(string $Agent, bool $Soft = false, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Lists agents and their status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Agents/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function Agents(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Atxfer/
     */
    public function Atxfer(string $Channel, string $Exten, string $Context, string $Priority, string $ActionID = null): array
    {
        return $this->send_request(
            'Atxfer',
            [
                'Channel' => $Channel,
                'Exten' => $Exten,
                'Context' => $Context,
                'Priority' => $Priority,
                'ActionID' => $ActionID,
            ]);
    }

    /**
     * Change monitoring filename of a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ChangeMonitor/
     *
     * @param string $Channel the channel to record
     * @param string $File the new name of the file created in the monitor spool directory
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function ChangeMonitor(string $Channel, string $File, string $ActionID = null): array
    {
        return $this->send_request(
            'ChangeMontior',
            ['Channel' => $Channel, 'File' => $File, 'ActionID' => $ActionID]
        );
    }

    /**
     * Execute Command
     *
     * @example examples/sip_show_peer.php Get information about a sip peer
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Command/
     *
     * @param string $Command the Asterisk CLI command to run
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Command(string $Command, string $ActionID = null): array
    {
        return $this->send_request(
            'Command',
            ['Command' => $Command, 'ActionID' => $ActionID]
        );
    }

    /**
     *  DBGet
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBGet/
     *
     * @param string $Family key family
     * @param string $Key key name
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return string
     **/
    public function DBGet(string $Family, string $Key, string $ActionID = null): string
    {
        if (is_null($ActionID)) {
            // need this ahead of time
            $ActionID = $this->ActionID();
        }
        $response = $this->send_request(
            "DBGet",
            ['Family' => $Family, 'Key' => $Key, 'ActionID' => $ActionID]
        );
        if ($response['Response'] === "Success") {
            $response = $this->wait_response($ActionID);

            return $response['Val'];
        }

        return "";
    }

    /**
     * Enable/Disable sending of events to this manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Events/
     *
     * @param string $EventMask is either 'on', 'off', or 'system,call,log'
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Events(string $EventMask, string $ActionID = null): array
    {
        return $this->send_request(
            'Events',
            ['EventMask' => $EventMask, 'ActionID' => $ActionID]
        );
    }

    /**
     * Check Extension Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ExtensionState/
     *
     * @param string $Exten Extension to check state on
     * @param string $Context Context for extension
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function ExtensionState(string $Exten, string $Context, string $ActionID = null): array
    {
        return $this->send_request(
            'ExtensionState',
            ['Exten' => $Exten, 'Context' => $Context, 'ActionID' => $ActionID]
        );
    }

    /**
     * Gets a Channel Variable
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Getvar/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     *
     * @param string $Channel Channel to read variable from
     * @param string $Variable
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function GetVar(string $Channel, string $Variable, string $ActionID = null): array
    {
        return $this->send_request(
            'GetVar',
            ['Channel' => $Channel, 'Variable' => $Variable, 'ActionID' => $ActionID]
        );
    }

    /**
     * Hangup Channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Hangup/
     *
     * @param string $Channel The channel name to be hungup
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Hangup(string $Channel, string $ActionID = null): array
    {
        return $this->send_request(
            'Hangup',
            ['Channel' => $Channel, 'ActionID' => $ActionID]
        );
    }

    /**
     * List IAX Peers
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/IAXpeers/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function IAXPeers(string $ActionID = null): array
    {
        return $this->send_request(
            'IAXPeers',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * List available manager commands
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ListCommands/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function ListCommands(string $ActionID = null): array
    {
        return $this->send_request(
            'ListCommands',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * Login Manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Login/
     *
     * @param string $Username the user name
     * @param string $Secret the secret
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Login(string $Username, string $Secret, string $ActionID = null): array
    {
        return $this->send_request(
            'Login',
            ['Username' => $Username, 'Secret' => $Secret, 'ActionID' => $ActionID]
        );
    }

    /**
     * Logoff Manager
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Logoff/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Logoff(string $ActionID = null): array
    {
        return $this->send_request(
            'Logoff',
            ['ActionID' => $ActionID]
        );
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
     * @param string $Mailbox Full mailbox ID <mailbox>@<vm-context>
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function MailboxCount(string $Mailbox, string $ActionID = null): array
    {
        return $this->send_request(
            'MailboxCount',
            ['Mailbox' => $Mailbox, 'ActionID' => $ActionID]
        );
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
     * @param string $Mailbox Full mailbox ID <mailbox>@<vm-context>
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function MailboxStatus(string $Mailbox, string $ActionID = null): array
    {
        return $this->send_request(
            'MailboxStatus',
            ['Mailbox' => $Mailbox, 'ActionID' => $ActionID]
        );
    }

    /**
     * Monitor a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Monitor/
     *
     * @param string $Channel
     * @param string|null $File
     * @param string|null $Format
     * @param bool|null $Mix
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function Monitor(
        string $Channel,
        string $File = null,
        string $Format = null,
        bool   $Mix = false,
        string $ActionID = null
    ): array
    {
        return $this->send_request(
            'Monitor',
            [
                'Channel' => $Channel,
                'File' => $File,
                'Format' => $Format,
                'Mix' => $Mix ? 'true' : 'false',
                'ActionID' => $ActionID,
            ]
        );
    }

    /**
     * Originate Call
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Originate/
     *
     * @param string $Channel Channel name to call
     * @param string|null $Exten Extension to use (requires 'Context' and 'Priority')
     * @param string|null $Context Context to use (requires 'Exten' and 'Priority')
     * @param string|null $Priority Priority to use (requires 'Exten' and 'Context')
     * @param string|null $Application Application to use
     * @param string|null $Data Data to use (requires 'Application')
     * @param int $Timeout How long to wait for call to be answered (in ms)
     * @param string|null $CallerID Caller ID to be set on the outgoing channel
     * @param string|null $Variable Channel variable to set (VAR1=value1|VAR2=value2)
     * @param string|null $Account Account code
     * @param bool $Async true fast origination
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function Originate(
        string $Channel,
        string $Exten = null,
        string $Context = null,
        string $Priority = null,
        string $Application = null,
        string $Data = null,
        int    $Timeout = 0,
        string $CallerID = null,
        string $Variable = null,
        string $Account = null,
        bool   $Async = null,
        string $ActionID = null
    ): array
    {
        return $this->send_request(
            'Originate', 
            [
                'Channel' => $Channel,
                'Exten' => $Exten,
                'Context' => $Context,
                'Priority' => $Priority,
                'Application' => $Application,
                'Data' => $Data,
                'Timeout' => $Timeout,
                'CallerID' => $CallerID,
                'Variable' => $Variable,
                'Account' => $Account,
                'Async' => $Async ? 'true' : 'false',
                'ActionID' => $ActionID,
            ]
        );
    }

    /**
     * List parked calls
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ParkedCalls/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function ParkedCalls(string $ActionID = null): array
    {
        return $this->send_request(
            'ParkedCalls',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * Ping
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Ping/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Ping(string $ActionID = null): array
    {
        return $this->send_request(
            'Ping',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * Queue Add
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueAdd/
     *
     * @param string $Queue
     * @param string $Interface
     * @param int $Penalty
     * @param string|null $MemberName
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function QueueAdd(
        string $Queue,
        string $Interface,
        int    $Penalty = 0,
        string $MemberName = null,
        string $ActionID = null
    ): array
    {
        return $this->send_request(
            'QueueAdd',
            [
                'Queue' => $Queue,
                'Interface' => $Interface,
                'Penalty' => $Penalty,
                'MemberName' => $MemberName,
                'ActionID' => $ActionID,
            ]
        );
    }

    /**
     * @lnk https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueReload/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function QueueReload(string $ActionID = null): array
    {
        return $this->send_request(
            'QueueReload',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * Queue Remove
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueRemove/
     *
     * @param string $Queue
     * @param string $Interface
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function QueueRemove(string $Queue, string $Interface, string $ActionID = null): array
    {
        return $this->send_request(
            'QueueRemove',
            ['Queue' => $Queue, 'Interface' => $Interface, 'ActionID' => $ActionID]
        );
    }

    /**
     * Queue Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/QueueStatus/
     *
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function QueueStatus(string $ActionID = null): array
    {
        return $this->send_request(
            'QueueStatus',
            ['ActionID' => $ActionID]
        );
    }

    /**
     * Redirect
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Redirect/
     *
     * @param string $Channel
     * @param string $ExtraChannel
     * @param string $Exten
     * @param string $Context
     * @param string $Priority
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function Redirect(
        string $Channel,
        string $ExtraChannel,
        string $Exten,
        string $Context,
        string $Priority,
        string $ActionID = null
    ): array
    {
        return $this->send_request(
            'Redirect',
            [
                'Channel' => $Channel,
                'ExtraChannel' => $ExtraChannel,
                'Exten' => $Exten,
                'Context' => $Context,
                'Priority' => $Priority,
                'ActionID' => $ActionID,
            ]
        );
    }

    /**
     * Set Channel Variable
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Setvar/
     *
     * @param string $Channel Channel to set variable for
     * @param string $Variable name
     * @param string $Value
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function SetVar(string $Channel, string $Variable, string $Value, string $ActionID = null): array
    {
        return $this->send_request(
            'SetVar',
            ['Channel' => $Channel, 'Variable' => $Variable, 'Value' => $Value, 'ActionID' => $ActionID]
        );
    }

    /**
     * Channel Status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Status/
     * @param string $Channel
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function Status(string $Channel, string $ActionID = null): array
    {
        return $this->send_request(
            'Status',
            ['Channel' => $Channel, 'ActionID' => $ActionID]
        );
    }

    /**
     * Stop monitoring a channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/StopMonitor/
     *
     * @param string $Channel
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function StopMonitor(string $Channel, string $ActionID = null): array
    {
        return $this->send_request(
            'StopMonitor',
            ['Channel' => $Channel, 'ActionID' => $ActionID]
        );
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

    /**
     *  Generate random ActionID
     **/
    public function ActionID(): string
    {
        return sprintf("A%6d", rand());
    }

    /**
     * Set the parent AGI instance
     *
     * @param AGI $agi
     * @return void
     */
    public function setPagi(AGI $agi)
    {
        $this->pagi = $agi;
    }
}
