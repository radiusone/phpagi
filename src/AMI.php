<?php /** @noinspection PhpUnused */

namespace PhpAgi;

use PhpAgi\AMI\Actions;
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
 * @phpstan-type AmiArray array{Response?:string, Message?:string, Output?:string, ActionID?:string, EventList?:string, events?:list<array<string,mixed>>, ...<string,string>}
 * @phpstan-type ConfigAmi array{server:string, port:int, username:string, secret:string, write_log:bool}
 */
class AMI
{
    use Actions;

    public const DEFAULT_CONFIG = [
        'server' => 'localhost',
        'port' => 5038,
        'username' => 'phpagi',
        'secret' => 'phpagi',
        'write_log' => false,
    ];

    /** @var ConfigAmi Config variables */
    public array $config = self::DEFAULT_CONFIG;

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
     * @param array{server?:string, port?:int, username?:string, secret?:string, write_log?:bool} $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
     * @return void
     */
    public function __construct(?string $config = null, array $optconfig = [])
    {
        // load config
        $config ??= AGI::DEFAULT_PHPAGI_CONFIG;
        if (file_exists($config)) {
            $result = parse_ini_file($config, true, INI_SCANNER_TYPED) ?: [];
            $result = is_array($result['asmanager'] ?? null) ? $result['asmanager'] : [];
            $this->config = array_merge($this->config, $result);
        }

        if ($optconfig) {
            $this->config = array_merge($this->config, $optconfig);
        }
    }

    /**
     * Send a request
     *
     * @param string $action
     * @param array<string,string|string[]> $parameters
     * @return AmiArray
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
     *
     * @return AmiArray
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
     *
     * @return AmiArray
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
        $server ??= $this->config['server'];
        $username ??= $this->config['username'];
        $secret ??= $this->config['secret'];

        // get port from server if specified
        $c = explode(':', $server);
        $this->server = $c[0];
        $this->port = $c[1] ?? $this->config['port'];

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
        $chal = $this->Challenge('MD5')['Challenge'] ?? '';
        $key = md5($chal . $secret);
        $res = $this->Login(Username: $username, AuthType: 'MD5', Key: $key);
        if (($res['Response'] ?? '') === 'Success') {
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
     * @return AmiArray
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
            $name = $this->updateParameterName($functionName, $param->getName());
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
        } elseif ($this->config['write_log']) {
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
     * @param array{Event: string, ...<string,string>} $parameters
     * @return void
     */
    protected function process_event(array $parameters): void
    {
        $e = strtolower($parameters['Event']);
        $this->log("Got event.. $e");

        $handlers = $this->event_handlers[$e] ?? $this->event_handlers['*'] ?? [];

        foreach ($handlers as $handler) {
            $this->log("Executing handler for '$e'");
            $handler($e, $parameters, $this->server, $this->port);
        }
    }

    private function updateParameterName(string $function, string $param): string
    {
        $mapping = [
            'AOCMessage' => [
                'UnitAmount' => 'UnitAmount(0)',
                'UnitType' => 'UnitType(0)',
            ],
            'SendText' => [
                'ContentType' => 'Content-Type',
            ],
            'UpdateConfig' => [
                'Action' => 'Action-000000',
                'Cat' => 'Cat-000000',
                'Var' => 'Var-000000',
                'Value' => 'Value-000000',
                'Match' => 'Match-000000',
                'Line' => 'Line-000000',
                'Options' => 'Options-000000',
            ],
        ];

        return $mapping[$function][$param] ?? $param;
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
