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
    private array $config;

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
        $config ??= AGI::DEFAULT_PHPAGI_CONFIG;
        if (file_exists($config)) {
            $this->config = parse_ini_file($config, true);
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
     * Retrieves the full config array or a section thereof
     *
     * @param string $section the config section to retrieve
     * @return array<array>|array<string,mixed>
     */
    public function getConfig(string $section = ''): array
    {
        if ($section) {
            return $this->config[$section] ?? [];
        }

        return $this->config;
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

        $req = implode("\r\n", $req) . "\r\n\r\n";
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
        } while (!feof($this->socket) && strpos($buffer, "\r\n\r\n") === false);

        $msg = trim($buffer);

        $msgarr = explode("\r\n", $msg);

        $parameters = [];
        foreach ($msgarr as $str) {
            $kv = explode(':', $str, 2);
            $key = trim($kv[0]);
            $val = trim($kv[1] ?? '');
            if (!isset($parameters[$key])) {
                $parameters[$key] = $val;
            } else {
                $parameters[$key] .= "\n$val";
            }
        }
        if (isset($parameters['Event'])) {
            $this->process_event($parameters);
        } elseif (isset($parameters['Response'])) {
            // keep 'data' element like in old code
            $parameters['data'] = $parameters['Output'] ?? '';
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
        } while (!is_null($actionid) && ($res['ActionID'] ?? '') !== $actionid);

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
        $server ??= $this->config['asmanager']['server'];
        $username ??= $this->config['asmanager']['username'];
        $secret ??= $this->config['asmanager']['secret'];

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
        $func = $stack[1]['function'];
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

        return $this->send_request($func, $args);
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
     * @param int|string $Timeout Maximum duration of the call (sec)
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function AbsoluteTimeout(string $Channel, $Timeout, string $ActionID = null): array
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
    public function Atxfer(string $Channel, string $Exten, string $Context, string $ActionID = null): array
    {
        return $this->send_request(
            'Atxfer',
            [
                'Channel' => $Channel,
                'Exten' => $Exten,
                'Context' => $Context,
                'ActionID' => $ActionID,
            ]);
    }

    /**
     * Blind transfer channel(s) to the given destination
     * Redirect all channels currently bridged to the specified channel to the specified destination
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BlindTransfer/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel
     * @param string $Exten
     * @param string $Context
     * @param string|null $ActionID
     * @return array
     * @throws ReflectionException
     */
    public function BlindTransfer(string $Channel, string $Exten, string $Context, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Bridge two channels already in the PBX
     * Bridge together two channels already in the PBX
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Bridge/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel1 Channel to Bridge to Channel2
     * @param string $Channel2 Channel to Bridge to Channel1
     * @param string $Tone Play courtesy tone to Channel: no, Channel1, Channel2, Both
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function Bridge(string $Channel1, string $Channel2, string $Tone = 'no', string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Destroy a bridge
     * Deletes the bridge, causing channels to continue or hang up
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeDestroy/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $BridgeUniqueid The unique ID of the bridge to destroy
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeDestroy(string $BridgeUniqueid, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Get information about a bridge
     * Returns detailed information about a bridge and the channels in it
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeInfo/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $BridgeUniqueid The unique ID of the bridge about which to retrieve information
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeInfo(string $BridgeUniqueid, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Kick a channel from a bridge
     * The channel is removed from the bridge
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeKick/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel The channel to kick out of a bridge
     * @param string|null $BridgeUniqueid The unique ID of the bridge containing the channel to destroy.
     *     This parameter can be supplied to ensure that the channel is not removed from the wrong bridge
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeKick(string $Channel, string $BridgeUniqueid = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Get a list of bridges in the system
     * Returns a list of bridges, optionally filtering on a bridge type
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeList/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $BridgeType Optional type for filtering the resulting list of bridges
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeList(string $BridgeType = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List available bridging technologies and their statuses
     * Returns detailed information about the available bridging technologies
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeTechnologyList/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeTechnologyList(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Suspend a bridging technology
     * Marks a bridging technology as suspended, which prevents subsequently created bridges from using it
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeTechnologySuspend/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeTechnologySuspend(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Unsuspend a bridging technology
     * Clears a previously suspended bridging technology, which allows subsequently created bridges to use it
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/BridgeTechnologyUnsuspend/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function BridgeTechnologyUnsuspend(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Cancel an attended transfer
     * Cancel an attended transfer. Note, this uses the configured cancel attended transfer feature option (atxferabort)
     * to cancel the transfer. If not available this action will fail
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CancelAtxfer/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel The transferer channel
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CancelAtxfer(string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Generate Challenge for MD5 Auth
     * Generate a challenge for MD5 authentication
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Challenge/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $AuthType Digest algorithm to use in the challenge. Valid values are MD5
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function Challenge(string $AuthType = 'MD5', string $ActionID = null): array
    {
        return $this->executeByReflection();
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
     * Kick a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeKick/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string $Channel If this parameter is "all", all channels will be kicked from the conference.
     *     If this parameter is "participants", all non-admin channels will be kicked from the conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeKick(string $Conference, string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List participants in a conference
     * Lists all users in a particular ConfBridge conference. ConfbridgeList will follow as separate events,
     * followed by a final event called ConfbridgeListComplete
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeList/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference Conference number
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeList(string $Conference, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List active conferences
     * Lists data about all active conferences. ConfbridgeListRooms will follow as separate events,
     * followed by a final event called ConfbridgeListRoomsComplete
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeListRooms/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeListRooms(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Lock a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeLock/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeLock(string $Conference, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Mute a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeMute/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.
     *     If this parameter is "all", all channels will be muted. If this parameter is "participants", all non-admin channels will be muted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeMute(string $Conference, string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Set a conference user as the single video source distributed to all other participants
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeSetSingleVideoSrc/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeSetSingleVideoSrc(string $Conference, string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Start recording a Confbridge conference
     * Start recording a conference. If recording is already present an error will be returned.
     * If RecordFile is not provided, the default record file specified in the conference's bridge profile will be used,
     * if that is not present either a file will automatically be generated in the monitor directory
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeStartRecord/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string|null $RecordFile
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeStartRecord(string $Conference, string $RecordFile = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Stop recording a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeStopRecord/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeStopRecord(string $Conference, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Unlock a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnlock/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeUnlock(string $Conference, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Unmute a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnmute/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.
     *     If this parameter is "all", all channels will be unmuted. If this parameter is "participants", all non-admin channels will be unmuted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ConfbridgeUnmute(string $Conference, string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Control the playback of a file being played to a channel
     * Control the operation of a media file being played back to a channel. Note that this AMI action does not initiate playback
     * of media to channel, but rather controls the operation of a media operation that was already initiated on the channel.
     * Note The pause and restart Control options will stop a playback operation if that operation was not initiated from
     * the ControlPlayback application or the control stream file AGI command.
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ControlPlayback/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel The name of the channel that currently has a file being played back to it
     * @param string $Control stop - Stop the playback operation
     *     forward - Move the current position in the media forward. The amount of time that the stream moves forward is determined
     *     by the skipms value passed to the application that initiated the playback. Note The default skipms value is 3000 ms
     *     reverse - Move the current position in the media backward. The amount of time that the stream moves backward is determined
     *     by the skipms value passed to the application that initiated the playback. Note The default skipms value is 3000 ms
     *     pause - Pause/unpause the playback operation, if supported. If not supported, stop the playback
     *     restart - Restart the playback operation, if supported. If not supported, stop the playback
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ControlPlayback(string $Channel, string $Control, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Show PBX core settings (version etc)
     * Query for Core PBX settings
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CoreSettings/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CoreSettings(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List all channels connected to the specified channel
     * List all channels currently connected to the specified channel. This can be any channel, including Local channels,
     * and Local channels will be followed through to their other half
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CoreShowChannelMap/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CoreShowChannelMap(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List currently active channels
     * List currently defined channels and some information about them
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CoreShowChannels/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CoreShowChannels(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Show PBX core status variables
     * Query for Core PBX status
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CoreStatus/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CoreStatus(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Creates an empty file in the configuration directory
     * This action will create an empty file in the configuration directory. This action is intended to be used before an UpdateConfig action
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/CreateConfig/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Filename The configuration filename to create (e.g. foo.conf)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function CreateConfig(string $Filename, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status OFF
     * Equivalent to the CLI command "dahdi set dnd channel off".
     * Note Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIDNDoff/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND off
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIDNDoff(string $DAHDIChannel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status ON
     * Equivalent to the CLI command "dahdi set dnd channel on".
     * Note Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIDNDon/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND on
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIDNDon(string $DAHDIChannel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Dial over DAHDI channel while offhook
     * Generate DTMF control frames to the bridged peer
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIDialOffhook/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $DAHDIChannel DAHDI channel number to dial digits
     * @param string $Number Digits to dial
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIDialOffhook(string $DAHDIChannel, string $Number, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Hangup DAHDI Channel
     * Simulate an on-hook event by the user connected to the channel.
     * Note Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIHangup/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $DAHDIChannel DAHDI channel number to hangup
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIHangup(string $DAHDIChannel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Fully Restart DAHDI channels (terminates calls)
     * Equivalent to the CLI command "dahdi restart"
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIRestart/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIRestart(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Show status of DAHDI channels
     * Similar to the CLI command "dahdi show channels"
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDIShowChannels/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $DAHDIChannel Specify the specific channel number to show. Show all channels if zero or not present
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDIShowChannels(string $DAHDIChannel = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }


    /**
     * Transfer DAHDI Channel
     * Simulate a flash hook event by the user connected to the channel.
     * Note Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DAHDITransfer/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $DAHDIChannel DAHDI channel number to transfer
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DAHDITransfer(string $DAHDIChannel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     *  Delete DB entry
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBDel/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DBDel(string $Family, string $Key, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     *  Delete DB Tree
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBDelTree/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DBDelTree(string $Family, string $Key, string $ActionID = null): array
    {
        return $this->executeByReflection();
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
        // need this ahead of time
        $ActionID ??= $this->ActionID();
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
     * Get DB entries, optionally at a particular family/key
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBGetTree/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $Family
     * @param string|null $Key
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     * @throws ReflectionException
     */
    public function DBGetTree(string $Family = null, string $Key = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     *  Put DB entry
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DBPut/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Family
     * @param string $Key
     * @param string $Val
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DBPut(string $Family, string $Key, string $Val, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List the current known device states
     * This will list out all known device states in a sequence of DeviceStateChange events. When finished, a DeviceStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DeviceStateList/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DeviceStateList(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Add an extension to the dialplan
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DialplanExtensionAdd/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Context Context where the extension will be created. The context will be created if it does not already exist
     * @param string $Extension Name of the extension that will be created (may include callerid match by separating with '/')
     * @param string $Priority Priority being added to this extension. Must be either hint or a numerical value
     * @param string $Application The application to use for this extension at the requested priority
     * @param string|null $ApplicationData Arguments to the application
     * @param bool $Replace If true, then if an extension already exists at the requested context, extension, and priority it will be overwritten.
     *     Otherwise, the existing extension will remain and the action will fail
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DialplanExtensionAdd(
        string $Context,
        string $Extension,
        string $Priority,
        string $Application,
        string $ApplicationData = null,
        bool   $Replace = false,
        string $ActionID = null
    ): array
    {
        return $this->executeByReflection();
    }

    /**
     * Remove an extension from the dialplan
     *
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/DialplanExtensionRemove/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Context Context of the extension being removed
     * @param string $Extension Name of the extension being removed (may include callerid match by separating with '/')
     * @param string|null $Priority If provided, only remove this priority from the extension instead of all priorities in the extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function DialplanExtensionRemove(
        string $Context,
        string $Extension,
        string $Priority = null,
        string $ActionID = null
    ): array
    {
        return $this->executeByReflection();
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
     * List the current known extension states
     * This will list out all known extension states in a sequence of ExtensionStatus events. When finished, a ExtensionStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ExtensionStateList/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ExtensionStateList(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Gets a channel variable or function value
     * Get the value of a channel variable or function return.
     * Note If a channel name is not provided then the variable is considered global
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Getvar/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Variable Variable name, function or expression
     * @param string|null $Channel Channel to read variable from
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function Getvar(string $Variable, string $Channel = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Hangup Channel
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Hangup/
     *
     * @param string $Channel The exact channel name to be hungup, or to use a regular expression, set this parameter to: /regex/
     *     Example exact channel: SIP/provider-0000012a Example regular expression: /^SIP/provider-.*$/
     * @param string|int|null $Cause Numeric hangup cause
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     */
    public function Hangup(string $Channel, $Cause = null, string $ActionID = null): array
    {
        return $this->send_request(
            'Hangup',
            ['Channel' => $Channel, 'Cause' => $Cause, 'ActionID' => $ActionID]
        );
    }

    /**
     * Show IAX Netstats
     * Show IAX channels network statistics
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/IAXnetstats/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function IAXnetstats(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List IAX Peers
     * List all the IAX peers
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/IAXpeerlist/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function IAXpeerlist(string $ActionID = null): array
    {
        return $this->executeByReflection();
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
     * Show IAX registrations
     * Show IAX registrations
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/IAXregistry/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function IAXregistry(string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * List categories in configuration file
     * This action will dump the categories in a given file
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/ListCategories/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Filename Configuration filename (e.g. foo.conf)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function ListCategories(string $Filename, string $ActionID = null): array
    {
        return $this->executeByReflection();
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
     * Optimize away a local channel when possible
     * A local channel created with "/n" will not automatically optimize away. Calling this command on the local channel
     * will clear that flag and allow it to optimize away if it's bridged or when it becomes bridged
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/LocalOptimizeAway/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel The channel name to optimize away
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function LocalOptimizeAway(string $Channel, string $ActionID = null): array
    {
        return $this->executeByReflection();
    }

    /**
     * Reload and rotate the Asterisk logger
     * Reload and rotate the logger. Analogous to the CLI command 'logger rotate'
     *
     * #link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/LoggerRotate/
     *
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function LoggerRotate(string $ActionID = null): array
    {
        return $this->executeByReflection();
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
     * @param string|int $Timeout How long to wait for call to be answered (in ms)
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
               $Timeout = 0,
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
     * @param string|int $Penalty
     * @param string|null $MemberName
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function QueueAdd(
        string $Queue,
        string $Interface,
               $Penalty = 0,
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
     * NEW SIGNATURE IN 3.0
     * Redirect (transfer) a call
     * Redirect (transfer) a call
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Redirect/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Channel
     * @param string $Exten
     * @param string $Context
     * @param string $Priority
     * @param string|null $ExtraChannel
     * @param string|null $ExtraExten
     * @param string|null $ExtraContext
     * @param string|null $ExtraPriority
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     * @throws ReflectionException
     */
    public function Redirect(
        string $Channel,
        string $Exten,
        string $Context,
        string $Priority,
        string $ExtraChannel = null,
        string $ExtraExten = null,
        string $ExtraContext = null,
        string $ExtraPriority = null,
        string $ActionID = null
    ): array
    {
        return $this->executeByReflection();
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Sets a channel variable or function value
     * This command can be used to set the value of channel variables or dialplan functions.
     * Note If a channel name is not provided then the variable is considered global
     *
     * @link https://docs.asterisk.org/Asterisk_18_Documentation/API_Documentation/AMI_Actions/Setvar/
     * @noinspection PhpUnusedParameterInspection
     *
     * @param string $Variable Variable name, function or expression
     * @param string $Value Variable or function value
     * @param string|null $Channel Channel to set variable for
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     * @throws ReflectionException
     */
    public function Setvar(string $Variable, string $Value, string $Channel = null, string $ActionID = null): array
    {
        return $this->executeByReflection();
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
        $this->event_handlers[$event] ??= [];
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
