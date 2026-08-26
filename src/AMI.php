<?php /** @noinspection PhpUnused */

namespace PhpAgi;

use Random\Randomizer;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * PHP Asterisk Manager Interface (AMI) client
 *
 * @package PhpAgi
 * @version 3.0
 * @see https://github.com/welltime/phpagi
 * @see http://phpagi.sourceforge.net/
 * @copyright 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * @copyright 2023 RadiusOne Inc.
 * @license https://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt GNU LGPL version 2.1
 * @phpstan-type EventHandler callable(string $event, array<string,mixed> $parameters, string $server, int $port):void
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
    protected ?AGI $pagi = null;

    /** @var array<string,EventHandler[]> Event Handlers */
    protected array $event_handlers = ['*' => []];

    /** @var bool Whether we're successfully logged in */
    protected bool $logged_in = false;

    /**
     * Constructor
     *
     * @param string|null $config is the name of the config file to parse or a parent agi from which to read the config
     * @param array $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
     * @return void
     */
    public function __construct(?string $config = null, array $optconfig = [])
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
        } while (!feof($this->socket) && !str_contains($buffer, "\r\n\r\n"));

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
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Login/
     *
     * @param string|null $server
     * @param string|null $username
     * @param string|null $secret
     * @return bool true on success
     */
    public function connect(?string $server = null, ?string $username = null, ?string $secret = null): bool
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
            $this->logged_in = true;

            return true;
        }

        $this->log("Failed to login: " . ($res["Message"] ?? "Unknown error"));
        $this->disconnect();

        return false;
    }

    /**
     * Disconnect
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Logoff/
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->logged_in) {
            $this->Logoff();
        }
        fclose($this->socket);
    }

    /**
     * Send a request based on the calling method
     *
     * This calls send_request and passes the name of the calling function and its arguments
     *
     * @param string $functionName the name of the class method (and AMI command) being run
     * @param list<mixed> $functionArgs the arguments passed to the method (and AMI command)
     * @return array
     */
    protected function executeByReflection(string $functionName, array $functionArgs): array
    {
        try {
            $ref = new ReflectionMethod($this, $functionName);
        } catch (ReflectionException) {
            $this->log("Error executing $functionName");

            return [];
        }
        $args = [];
        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            $pos = $param->getPosition();
            try {
                $val = array_key_exists($pos, $functionArgs)
                    ? $functionArgs[$pos]
                    : $param->getDefaultValue();
                $type = $param->getType();
                $isBool = false;
                if ($type instanceof ReflectionNamedType) {
                    $isBool = $type->getName() === 'bool';
                } elseif ($type instanceof ReflectionIntersectionType || $type instanceof ReflectionUnionType) {
                    $isBool = array_any(
                        $type->getTypes(),
                        function (ReflectionType $v) {
                            return ($v instanceof ReflectionNamedType && $v->getName() === 'bool')
                                || (
                                    $v instanceof ReflectionIntersectionType
                                    && in_array('bool', $v->getTypes())
                                );
                        }
                    );
                }
                if ($isBool) {
                    $val = $val ? 'True' : 'False';
                }
                $args[$name] = $val;
            } catch (ReflectionException) {
                // arguments from user code are missing a required argument
                $this->log("Required arguments missing from call to $functionName");

                return [];
            }
        }

        return $this->send_request($functionName, $args);
    }

    // *********************************************************************************************************
    // **                       COMMANDS                                                                      **
    // *********************************************************************************************************

    /**
     * Add an AGI command to the execute queue of the channel in Async AGI
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AGI/
     *
     * @param string $Channel Channel that is currently in Async AGI
     * @param string $Command Application to execute
     * @param string|null $CommandID This will be sent back in CommandID header of AsyncAGI exec event notification
     * @param string|null $ActionID ActionID for this transaction. Will be returned (autogenerated if not supplied)
     * @return array
     */
    public function AGI(string $Channel, string $Command, ?string $CommandID = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Generate an Advice of Charge message on a channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AOCMessage/
     */
    public function AOCMessage(): never
    {
        die("Not implemented");
    }

    /**
     * Set absolute timeout
     * Hangup a channel after a certain time. Acknowledges set time with Timeout Set message
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AbsoluteTimeout/
     *
     * @param string $Channel Channel name to hangup
     * @param int|string $Timeout Maximum duration of the call (sec)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function AbsoluteTimeout(string $Channel, int|string $Timeout, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Sets an agent as no longer logged in
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AgentLogoff/
     *
     * @param string $Agent Agent ID of the agent to log off
     * @param bool|null $Soft Set to true to not hangup existing calls
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function AgentLogoff(string $Agent, ?bool $Soft = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists agents and their status
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Agents/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Agents(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Attended transfer
     * Attended transfer
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Atxfer/
     *
     * @param string $Channel Transferer's channel
     * @param string $Exten Extension to transfer to
     * @param string $Context Context to transfer to
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     */
    public function Atxfer(string $Channel, string $Exten, string $Context, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Blind transfer channel(s) to the given destination
     * Redirect all channels currently bridged to the specified channel to the specified destination
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BlindTransfer/
     *
     * @param string $Channel
     * @param string $Exten
     * @param string $Context
     * @param string|null $ActionID
     * @return array
     */
    public function BlindTransfer(string $Channel, string $Exten, string $Context, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Bridge two channels already in the PBX
     * Bridge together two channels already in the PBX
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Bridge/
     *
     * @param string $Channel1 Channel to Bridge to Channel2
     * @param string $Channel2 Channel to Bridge to Channel1
     * @param string $Tone Play courtesy tone to Channel: no, Channel1, Channel2, Both
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Bridge(string $Channel1, string $Channel2, string $Tone = 'no', ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Destroy a bridge
     * Deletes the bridge, causing channels to continue or hang up
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeDestroy/
     *
     * @param string $BridgeUniqueid The unique ID of the bridge to destroy
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeDestroy(string $BridgeUniqueid, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get information about a bridge
     * Returns detailed information about a bridge and the channels in it
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeInfo/
     *
     * @param string $BridgeUniqueid The unique ID of the bridge about which to retrieve information
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeInfo(string $BridgeUniqueid, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Kick a channel from a bridge
     * The channel is removed from the bridge
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeKick/
     *
     * @param string $Channel The channel to kick out of a bridge
     * @param string|null $BridgeUniqueid The unique ID of the bridge containing the channel to destroy.
     *     This parameter can be supplied to ensure that the channel is not removed from the wrong bridge
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeKick(string $Channel, ?string $BridgeUniqueid = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get a list of bridges in the system
     * Returns a list of bridges, optionally filtering on a bridge type
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeList/
     *
     * @param string|null $BridgeType Optional type for filtering the resulting list of bridges
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeList(?string $BridgeType = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List available bridging technologies and their statuses
     * Returns detailed information about the available bridging technologies
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeTechnologyList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeTechnologyList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Suspend a bridging technology
     * Marks a bridging technology as suspended, which prevents subsequently created bridges from using it
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeTechnologySuspend/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeTechnologySuspend(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unsuspend a bridging technology
     * Clears a previously suspended bridging technology, which allows subsequently created bridges to use it
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeTechnologyUnsuspend/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function BridgeTechnologyUnsuspend(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Cancel an attended transfer
     * Cancel an attended transfer. Note, this uses the configured cancel attended transfer feature option (atxferabort)
     * to cancel the transfer. If not available this action will fail
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CancelAtxfer/
     *
     * @param string $Channel The transferer channel
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CancelAtxfer(string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Generate Challenge for MD5 Auth
     * Generate a challenge for MD5 authentication
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Challenge/
     *
     * @param string $AuthType Digest algorithm to use in the challenge. Valid values are MD5
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Challenge(string $AuthType = 'MD5', ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Change monitoring filename of a channel
     * This action may be used to change the file started by a previous 'Monitor' action
     *
     * @link https://docs.asterisk.org/Asterisk_20_Documentation/API_Documentation/Dialplan_Applications/ChangeMonitor/
     *
     * @param string $Channel Used to specify the channel to record
     * @param string $File Is the new name of the file created in the monitor spool directory
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ChangeMonitor(string $Channel, string $File, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Execute Asterisk CLI Command
     * Run a CLI command
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Command/
     *
     * @param string $Command Asterisk CLI command to run
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Command(string $Command, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Kick a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeKick/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is "all", all channels will be kicked from the conference.
     *     If this parameter is "participants", all non-admin channels will be kicked from the conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeKick(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List participants in a conference
     * Lists all users in a particular ConfBridge conference. ConfbridgeList will follow as separate events,
     * followed by a final event called ConfbridgeListComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeList/
     *
     * @param string $Conference Conference number
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeList(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List active conferences
     * Lists data about all active conferences. ConfbridgeListRooms will follow as separate events,
     * followed by a final event called ConfbridgeListRoomsComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeListRooms/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeListRooms(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lock a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeLock/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeLock(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mute a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeMute/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.
     *     If this parameter is "all", all channels will be muted. If this parameter is "participants", all non-admin channels will be muted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeMute(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set a conference user as the single video source distributed to all other participants
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeSetSingleVideoSrc/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeSetSingleVideoSrc(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Start recording a Confbridge conference
     * Start recording a conference. If recording is already present an error will be returned.
     * If RecordFile is not provided, the default record file specified in the conference's bridge profile will be used,
     * if that is not present either a file will automatically be generated in the monitor directory
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeStartRecord/
     *
     * @param string $Conference
     * @param string|null $RecordFile
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeStartRecord(string $Conference, ?string $RecordFile = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Stop recording a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeStopRecord/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeStopRecord(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unlock a Confbridge conference
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnlock/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeUnlock(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unmute a Confbridge user
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnmute/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.
     *     If this parameter is "all", all channels will be unmuted. If this parameter is "participants", all non-admin channels will be unmuted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ConfbridgeUnmute(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Control the playback of a file being played to a channel
     * Control the operation of a media file being played back to a channel. Note that this AMI action does not initiate playback
     * of media to channel, but rather controls the operation of a media operation that was already initiated on the channel.
     * Note The pause and restart Control options will stop a playback operation if that operation was not initiated from
     * the ControlPlayback application or the control stream file AGI command.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ControlPlayback/
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
     */
    public function ControlPlayback(string $Channel, string $Control, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show PBX core settings (version etc)
     * Query for Core PBX settings
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreSettings/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CoreSettings(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List all channels connected to the specified channel
     * List all channels currently connected to the specified channel. This can be any channel, including Local channels,
     * and Local channels will be followed through to their other half
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreShowChannelMap/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CoreShowChannelMap(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List currently active channels
     * List currently defined channels and some information about them
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreShowChannels/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CoreShowChannels(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show PBX core status variables
     * Query for Core PBX status
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreStatus/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CoreStatus(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Creates an empty file in the configuration directory
     * This action will create an empty file in the configuration directory. This action is intended to be used before an UpdateConfig action
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CreateConfig/
     *
     * @param string $Filename The configuration filename to create (e.g. foo.conf)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function CreateConfig(string $Filename, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status OFF
     * Equivalent to the CLI command "dahdi set dnd channel off".
     * Note Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIDNDoff/
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND off
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIDNDoff(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status ON
     * Equivalent to the CLI command "dahdi set dnd channel on".
     * Note Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIDNDon/
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND on
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIDNDon(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Dial over DAHDI channel while offhook
     * Generate DTMF control frames to the bridged peer
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIDialOffhook/
     *
     * @param string $DAHDIChannel DAHDI channel number to dial digits
     * @param string $Number Digits to dial
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIDialOffhook(string $DAHDIChannel, string $Number, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Hangup DAHDI Channel
     * Simulate an on-hook event by the user connected to the channel.
     * Note Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIHangup/
     *
     * @param string $DAHDIChannel DAHDI channel number to hangup
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIHangup(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Fully Restart DAHDI channels (terminates calls)
     * Equivalent to the CLI command "dahdi restart"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIRestart/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIRestart(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show status of DAHDI channels
     * Similar to the CLI command "dahdi show channels"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIShowChannels/
     *
     * @param string|null $DAHDIChannel Specify the specific channel number to show. Show all channels if zero or not present
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIShowChannels(?string $DAHDIChannel = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show status of DAHDI spans
     * Similar to the CLI command "dahdi show status"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIShowStatus/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDIShowStatus(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }


    /**
     * Transfer DAHDI Channel
     * Simulate a flash hook event by the user connected to the channel.
     * Note Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDITransfer/
     *
     * @param string $DAHDIChannel DAHDI channel number to transfer
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DAHDITransfer(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     *  Delete DB entry
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBDel/
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DBDel(string $Family, string $Key, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     *  Delete DB Tree
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBDelTree/
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DBDelTree(string $Family, string $Key, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get DB Entry
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBGet/
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return string
     **/
    public function DBGet(string $Family, string $Key, ?string $ActionID = null): string
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
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBGetTree/
     *
     * @param string|null $Family
     * @param string|null $Key
     * @param string|null $ActionID the optional action ID (autogenerated if not supplied)
     * @return array
     */
    public function DBGetTree(?string $Family = null, ?string $Key = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     *  Put DB entry
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBPut/
     *
     * @param string $Family
     * @param string $Key
     * @param string $Val
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DBPut(string $Family, string $Key, string $Val, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List the current known device states
     * This will list out all known device states in a sequence of DeviceStateChange events. When finished, a DeviceStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DeviceStateList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DeviceStateList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Add an extension to the dialplan
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DialplanExtensionAdd/
     *
     * @param string $Context Context where the extension will be created. The context will be created if it does not already exist
     * @param string $Extension Name of the extension that will be created (may include callerid match by separating with '/')
     * @param string $Priority Priority being added to this extension. Must be either hint or a numerical value
     * @param string $Application The application to use for this extension at the requested priority
     * @param string|null $ApplicationData Arguments to the application
     * @param bool|null $Replace If true, then if an extension already exists at the requested context, extension, and priority it will be overwritten.
     *     Otherwise, the existing extension will remain and the action will fail
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DialplanExtensionAdd(
        string  $Context,
        string  $Extension,
        string  $Priority,
        string  $Application,
        ?string $ApplicationData = null,
        ?bool   $Replace = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Remove an extension from the dialplan
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DialplanExtensionRemove/
     *
     * @param string $Context Context of the extension being removed
     * @param string $Extension Name of the extension being removed (may include callerid match by separating with '/')
     * @param string|null $Priority If provided, only remove this priority from the extension instead of all priorities in the extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function DialplanExtensionRemove(
        string  $Context,
        string  $Extension,
        ?string $Priority = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Control Event Flow
     * Enable/Disable sending of events to this manager client
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Events/
     *
     * @param string $EventMask on - If all events should be sent.
     *     off - If no events should be sent.
     *     system,call,log,... - To select which flags events should have to be sent.
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Events(string $EventMask, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check Extension Status
     * Report the extension state for given extension. If the extension has a hint,
     * will use devicestate to check the status of the device connected to the
     * extension.
     * Will return an 'Extension Status' message. The response will include the hint
     * for the extension and the status.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ExtensionState/
     *
     * @param string $Exten Extension to check state on
     * @param string $Context Context for extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ExtensionState(string $Exten, string $Context, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List the current known extension states
     * This will list out all known extension states in a sequence of ExtensionStatus events. When finished, a ExtensionStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ExtensionStateList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ExtensionStateList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Responds with a detailed description of a single FAX session
     * Provides details about a specific FAX session. The response will include a common subset of the
     * output from the CLI command 'fax show session ' for each technology. If the FAX technolgy used
     * by this session does not include a handler for FAXSession, then this action will fail.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/FAXSession/
     *
     * @param string $SessionNumber The session ID of the fax the user is interested in.
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function FAXSession(string $SessionNumber, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Gets a channel variable or function value
     * Get the value of a channel variable or function return.
     * Note If a channel name is not provided then the variable is considered global
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Getvar/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     *
     * @param string $Variable Variable name, function or expression
     * @param string|null $Channel Channel to read variable from
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Getvar(string $Variable, ?string $Channel = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Hangup channel
     * Hangup a channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Hangup/
     *
     * @param string $Channel The exact channel name to be hungup, or to use a regular expression, set this parameter to: /regex/
     *     Example exact channel: SIP/provider-0000012a Example regular expression: /^SIP/provider-.*$/
     * @param int|string|null $Cause Numeric hangup cause
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Hangup(string $Channel, int|string|null $Cause = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show IAX Netstats
     * Show IAX channels network statistics
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXnetstats/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function IAXnetstats(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List IAX Peers
     * List all the IAX peers
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXpeerlist/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function IAXpeerlist(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List IAX peers
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXpeers/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function IAXPeers(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show IAX registrations
     * Show IAX registrations
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXregistry/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function IAXregistry(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List categories in configuration file
     * This action will dump the categories in a given file
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ListCategories/
     *
     * @param string $Filename Configuration filename (e.g. foo.conf)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ListCategories(string $Filename, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List available manager commands
     * Returns the action name and synopsis for every action that is available to the user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ListCommands/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ListCommands(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Optimize away a local channel when possible
     * A local channel created with "/n" will not automatically optimize away. Calling this command on the local channel
     * will clear that flag and allow it to optimize away if it's bridged or when it becomes bridged
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/LocalOptimizeAway/
     *
     * @param string $Channel The channel name to optimize away
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function LocalOptimizeAway(string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Reload and rotate the Asterisk logger
     * Reload and rotate the logger. Analogous to the CLI command 'logger rotate'
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/LoggerRotate/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function LoggerRotate(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Login Manager
     * Login Manager
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Login/
     *
     * @param string $Username Username to login with as specified in manager.conf
     * @param string $Secret Secret to login with as specified in manager.conf
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Login(string $Username, string $Secret, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Logoff Manager
     * Logoff the current manager session
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Logoff/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Logoff(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check Mailbox Message Count
     * Checks a voicemail account for new messages.
     * Returns number of urgent, new and old messages.
     * Message: Mailbox Message Count
     * Mailbox: mailboxid
     * UrgentMessages: count
     * NewMessages: count
     * OldMessages: count
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MailboxCount/
     *
     * @param string $Mailbox Full mailbox ID mailbox@vm-context
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     */
    public function MailboxCount(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check mailbox
     * Checks a voicemail account for status.
     * Returns whether there are messages waiting.
     * Message: Mailbox Status.
     * Mailbox: mailboxid.
     * Waiting: 0 if messages waiting, 1 if no messages waiting.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MailboxStatus/
     *
     * @param string $Mailbox Full mailbox ID mailbox@vm-context
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function MailboxStatus(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Monitor a channel
     * This action may be used to record the audio on a specified channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Monitor/
     *
     * @param string $Channel Used to specify the channel to record
     * @param string|null $File Is the name of the file created in the monitor spool directory.
     *     Defaults to the same name as the channel (with slashes replaced with dashes)
     * @param string|null $Format Is the audio recording format. Defaults to wav
     * @param bool|null $Mix Boolean parameter as to whether to mix the input and output channels together
     *     after the recording is finished
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Monitor(
        string  $Channel,
        ?string $File = null,
        ?string $Format = null,
        ?bool   $Mix = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Originate a call
     * Generates an outgoing call to a Extension/Context/Priority or Application/Data
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Originate/
     *
     * @param string|null $Channel Channel name to call
     * @param string|null $Exten Extension to use (requires 'Context' and 'Priority')
     * @param string|null $Context Context to use (requires 'Exten' and 'Priority')
     * @param string|null $Priority Priority to use (requires 'Exten' and 'Context')
     * @param string|null $Application Application to execute
     * @param string|null $Data Data to use (requires 'Application')
     * @param int|string|null $Timeout How long to wait for call to be answered (in ms)
     * @param string|null $CallerID Caller ID to be set on the outgoing channel
     * @param string|null $Variable Channel variable to set, multiple Variable: headers are allowed
     * @param string|null $Account Account code
     * @param bool|null $Async Set to true for fast origination
     * @param bool|null $EarlyMedia Set to true to force call bridge on early media
     * @param string|null $Codecs Comma-separated list of codecs to use for this call
     * @param string|null $ChannelId Channel UniqueId to be set on the channel
     * @param string|null $OtherChannelId Channel UniqueId to be set on the second local channel
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Originate(
        ?string         $Channel,
        ?string         $Exten = null,
        ?string         $Context = null,
        ?string         $Priority = null,
        ?string         $Application = null,
        ?string         $Data = null,
        int|string|null $Timeout = null,
        ?string         $CallerID = null,
        ?string         $Variable = null,
        ?string         $Account = null,
        ?bool           $Async = null,
        ?bool           $EarlyMedia = null,
        ?string         $Codecs = null,
        ?string         $ChannelId = null,
        ?string         $OtherChannelId = null,
        ?string         $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * List parked calls
     * List parked calls
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ParkedCalls/
     *
     * @param string|null $ParkingLot If specified, only show parked calls from the parking lot with this name
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function ParkedCalls(?string $ParkingLot = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Keepalive command
     * A 'Ping' action will elicit a 'Pong' response. Used to keep the manager connection open
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Ping/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Ping(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Hangup an incoming PJSIP channel with a SIP response code
     * Hangs up an incoming PJSIP channel and returns the specified SIP response code in the final response to the caller.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPHangup/
     *
     * @param string $Channel The exact channel name to be hungup, or to use a regular expression, set this parameter to: /regex/
     *     Example exact channel: SIP/provider-0000012a Example regular expression: /^SIP/provider-.*$/
     * @param int|string|null $Cause May be one of...
     *     Response code - A numeric response code in the range 400 ->699
     *     Response code name - A response code name from 'third-party/pjproject/source/pjsip/include/pjsip/sip_msg.h'
     *         such as 'USE_IDENTITY_HEADER' or 'PJSIP_SC_USE_IDENTITY_HEADER'
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function PJSIPHangup(string $Channel, int|string|null $Cause = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Add interface to queue
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueAdd/
     *
     * @param string $Queue Queue's name
     * @param string $Interface The name of the interface (tech/name) to add to the queue
     * @param int|string|null $Penalty A penalty (number) to apply to this member. Asterisk will distribute calls
     *    to members with higher penalties only after attempting to distribute calls to those with lower penalty.
     * @param string|null $MemberName Text alias for the interface.
     * @param string|null $StateInterface
     * @param bool|null $Paused To pause or not the member initially
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function QueueAdd(
        string          $Queue,
        string          $Interface,
        int|string|null $Penalty = null,
        ?string         $MemberName = null,
        ?string         $StateInterface = null,
        ?bool           $Paused = null,
        ?string         $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Reload a queue, queues, or any sub-section of a queue or queues
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueReload/
     *
     * @param string|null $Queue The name of the queue to take action on. If no queue name is specified, then all queues are affected
     * @param bool|null $Members Whether to reload the queue's members
     * @param bool|null $Rules Whether to reload queuerules.conf
     * @param bool|null $Parameters Whether to reload the other queue options
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function QueueReload(
        ?string $Queue = null,
        ?bool   $Members = null,
        ?bool   $Rules = null,
        ?bool   $Parameters = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Remove interface from queue
     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueRemove/
     *
     * @param string $Queue The name of the queue to take action on
     * @param string $Interface The interface (tech/name) to remove from queue
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function QueueRemove(string $Queue, string $Interface, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Show queue status
     * Check the status of one or more queues
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueStatus/
     *
     * @param string|null $Queue Limit the response to the status of the specified queue
     * @param string|null $Member Limit the response to the status of the specified member
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     */
    public function QueueStatus(?string $Queue = null, ?string $Member = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Redirect (transfer) a call
     * Redirect (transfer) a call
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Redirect/
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
     */
    public function Redirect(
        string  $Channel,
        string  $Exten,
        string  $Context,
        string  $Priority,
        ?string $ExtraChannel = null,
        ?string $ExtraExten = null,
        ?string $ExtraContext = null,
        ?string $ExtraPriority = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * Sets a channel variable or function value
     * This command can be used to set the value of channel variables or dialplan functions.
     * Note If a channel name is not provided then the variable is considered global
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Setvar/
     *
     * @param string $Variable Variable name, function or expression
     * @param string $Value Variable or function value
     * @param string|null $Channel Channel to set variable for
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Setvar(string $Variable, string $Value, ?string $Channel = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * NEW SIGNATURE IN 3.0
     * List channel status
     * Will return the status information of each channel along with the value for the specified channel variables
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Status/
     *
     * @param string|null $Channel The name of the channel to query for status
     * @param string|null $Variables Comma ',' separated list of variable to include
     * @param bool|null $AllVariables If set to "true", the Status event will include all channel variables for the requested channel(s)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function Status(
        ?string $Channel = null,
        ?string $Variables = null,
        ?bool   $AllVariables = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Stop monitoring a channel
     * This action may be used to end a previously started 'Monitor' action
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/StopMonitor/
     *
     * @param string $Channel The name of the channel monitored
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return array
     */
    public function StopMonitor(string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
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
    public function log($message, $level = 1): void
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
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Events/AGIExecEnd/
     *
     * @param string $event type or * for default handler
     * @param EventHandler $callback function to handle the event
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
    protected function process_event(array $parameters): void
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
            $handler($e, $parameters, $this->server, $this->port);
        }
    }

    /**
     *  Generate random ActionID
     **/
    public function ActionID(): string
    {
        return 'A' . strtoupper(bin2hex((new Randomizer())->getBytes(8)));
    }

    /**
     * Set the parent AGI instance
     *
     * @param AGI $agi
     * @return void
     */
    public function setPagi(AGI $agi): void
    {
        $this->pagi = $agi;
    }
}
