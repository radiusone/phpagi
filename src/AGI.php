<?php /** @noinspection PhpUnused */

namespace PhpAgi;

use GlobIterator;
use PhpAgi\AGI\Request;
use PhpAgi\AGI\Response;
use PhpAgi\Enums\AgiResult;
use PhpAgi\Enums\ChannelState;
use ValueError;

/**
 * PHP Asterisk Manager Interface (AMI) client
 *
 * @package PhpAgi
 * @version 3.0
 * @see https://github.com/welltime/phpagi
 * @see http://phpagi.sourceforge.net/
 * @copyright 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * @copyright 2023 - 2026 RadiusOne Inc.
 * @license https://www.gnu.org/licenses/old-licenses/lgpl-2.1.txt GNU LGPL version 2.1
 * @phpstan-type AgiArray array{code:-1|200|510|511|520, result:numeric-string|null, data?:string|null}
 */
class AGI
{
    /** @var bool whether methods will return AGI\Response objects or traditional arrays */
    public static bool $returnObjects = false;

    /** @var string the Asterisk configuration directory */
    protected const AST_CONFIG_DIR = '/etc/asterisk/';

    /** @var string the Asterisk spool directory */
    protected const AST_SPOOL_DIR = '/var/spool/asterisk/';

    /** @var string the Asterisk temp directory */
    protected const AST_TMP_DIR = self::AST_SPOOL_DIR . '/tmp/';

    /** @var string the default configuration file */
    public const DEFAULT_PHPAGI_CONFIG = self::AST_CONFIG_DIR . '/phpagi.conf';

    /** @var int FD number for audio stream */
    public const AUDIO_FILENO = 3;

    /** @var int how many emails have been sent */
    protected static int $mailcount = 0;

    public Request $request;

    /** @var array<'phpagi'|'cepstral'|'festival'|'asmanager',string|array<string,string>> Config variables */
    public array $config;

    /** @var false|resource Input Stream */
    protected $in;

    /** @var false|resource Output Stream */
    protected $out;

    /** @var false|resource Audio Stream */
    public $audio = false;

    /** @var string Application option delimiter */
    public string $option_delim = ",";

    /** @var string|null An email address to send errors to */
    protected ?string $phpagi_error_handler_email = null;
    
    /** @var AMI an AMI instance */
    protected AMI $asm;

    /**
     * Constructor
     *
     * @param string|null $config the name of the config file to parse
     * @param array{error_handler:bool, debug:bool, admin:string, tempdir:string} $optconfig an array of configuration vars and vals, stuffed into $this->config['phpagi']
     */
    public function __construct(?string $config = null, array $optconfig = [])
    {
        // load config
        $config ??= self::DEFAULT_PHPAGI_CONFIG;
        if (file_exists($config)) {
            $this->config = parse_ini_file($config, true, INI_SCANNER_TYPED);
        }

        // If optconfig is specified, stuff vals and vars into 'phpagi' config array,
        // add default values to config for uninitialized values
        $defaults = [
            'error_handler' => true,
            'debug' => false,
            'admin' => null,
            'tempdir' => self::AST_TMP_DIR,
        ];
        $this->config['phpagi'] = array_merge($defaults, $optconfig);

        // festival TTS config
        $this->config['festival']['text2wave'] ??= $this->which('text2wave');

        // swift TTS config
        $this->config['cepstral']['swift'] ??= $this->which('swift');

        ob_implicit_flush();

        $this->in = fopen('php://stdin', 'r');
        $this->out = fopen('php://stdout', 'w');

        if ($this->in === false || $this->out === false) {
            die('Could not get STDIN/STDOUT handles');
        }

        // initialize error handler
        if ($this->config['phpagi']['error_handler']) {
            set_error_handler([$this, 'phpagi_error_handler']);
            $this->phpagi_error_handler_email = $this->config['phpagi']['admin'];
            error_reporting(E_ALL);
        }

        // make sure temp folder exists
        $tempdir = $this->config['phpagi']['tempdir'];
        if (!file_exists($tempdir) && !mkdir($tempdir, 0775, true)) {
            $this->conlog("Unable to create temp dir $tempdir; carrying on");
        }
        if (!is_dir($tempdir)) {
            $this->conlog("Temp dir $tempdir is not usable; carrying on");
            // revert to most sensible defaults
            if ($this->config['phpagi']['tempdir'] !== self::AST_TMP_DIR) {
                $this->config['phpagi']['tempdir'] = self::AST_TMP_DIR;
            } else {
                $this->config['phpagi']['tempdir'] = sys_get_temp_dir();
            }
        }

        // read the request
        $this->request = new Request();
        $str = fgets($this->in);
        while ($str !== "\n") {
            [$key, $val] = explode(':', $str, 2);
            $key = trim($key);
            if (preg_match('/^agi_arg_(\d+)$/', $key, $match)) {
                $this->request->agi_args[$match[1]] = trim($val);
            } elseif (property_exists($this->request, $key)) {
                $this->request->$key = trim($val);
            }
            $str = fgets($this->in);
        }

        // open audio if eagi detected
        if ($this->request->agi_enhanced === '1.0') {
            if (file_exists('/proc/' . getmypid() . '/fd/' . self::AUDIO_FILENO)) {
                $this->audio = fopen('/proc/' . getmypid() . '/fd/' . self::AUDIO_FILENO, 'r');
            } elseif (file_exists('/dev/fd/' . self::AUDIO_FILENO)) {
                // may need to mount fdescfs
                $this->audio = fopen('/dev/fd/' . self::AUDIO_FILENO, 'r');
            }

            if (is_resource($this->audio)) {
                stream_set_blocking($this->audio, false);
            } else {
                $this->conlog('Unable to open audio stream, continuing');
            }
        }

        $this->conlog('AGI Request:');
        $this->conlog(print_r($this->request, true));
        $this->conlog('PHPAGI internal configuration:');
        $this->conlog(print_r($this->config, true));
    }

    public function __destruct()
    {
        if (is_resource($this->audio)) {
            fclose($this->audio);
        }
    }

    // *********************************************************************************************************
    // **                             COMMANDS                                                                                            **
    // *********************************************************************************************************

    /**
     * Answer channel if not already in answer state.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/answer/
     *
     * @return Response|AgiArray `result` is 0 on success, -1 on failure.
     */
    public function answer(): Response|array
    {
        return $this->evaluate('ANSWER');
    }

    /**
     * Get the status of the specified channel. If no channel name is specified, return the status of the current channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/channel_status/
     *
     * @param string $channel
     * @return Response|AgiArray `data` contains a textual description of the state.
     */
    public function channel_status(string $channel = ''): Response|array
    {
        $ret = strlen($channel)
            ? $this->evaluate('CHANNEL STATUS %s', $channel)
            : $this->evaluate('CHANNEL STATUS');

        $res = (int)$ret['result'];
        try {
            $ret['data'] = ChannelState::from($res)->label();
        } catch (ValueError) {
            $ret['data'] = "Unknown ($res)";
        }

        return $ret;
    }

    /**
     * Deletes an entry in the Asterisk database for a given family and key.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_del/
     *
     * @param string $family
     * @param string $key
     * @return Response|AgiArray `result` is 1 on sucess, 0 otherwise.
     */
    public function database_del(string $family, string $key): Response|array
    {
        return $this->evaluate('DATABASE DEL %s %s', $family, $key);
    }

    /**
     * Deletes a family or specific keytree within a family in the Asterisk database.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_deltree/
     *
     * @param string $family
     * @param string $keytree
     * @return Response|AgiArray `result` is 1 on sucess, 0 otherwise.
     */
    public function database_deltree(string $family, string $keytree = ''): Response|array
    {
        return strlen($keytree)
            ? $this->evaluate('DATABASE DELTREE %s %s', $family, $keytree)
            : $this->evaluate('DATABASE DELTREE %s', $family);
    }

    /**
     * Retrieves an entry in the Asterisk database for a given family and key.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_get/
     *
     * @param string $family
     * @param string $key
     * @return Response|AgiArray `result` is 1 on sucess, 0 failure. `data` holds the value
     */
    public function database_get(string $family, string $key): Response|array
    {
        return $this->evaluate('DATABASE GET %s %s', $family, $key);
    }

    /**
     * Adds or updates an entry in the Asterisk database for a given family, key, and value.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_put/
     *
     * @param string $family
     * @param string $key
     * @param string $value
     * @return Response|AgiArray `result` is 1 on sucess, 0 otherwise
     */
    public function database_put(string $family, string $key, string $value): Response|array
    {
        return $this->evaluate('DATABASE PUT %s %s %s', $family, $key, $value);
    }


    /**
     * Sets a global variable, by executing the Set application and GLOBAL function
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Set/
     *
     * @param string $pVariable
     * @param float|int|string $pValue
     * @return Response|AgiArray `result` is 1 on sucess, 0 otherwise
     */
    public function set_global_var(string $pVariable, float|int|string $pValue): Response|array
    {
        $pVariable = preg_replace('/\W/', '', $pVariable);
        $pValue = str_replace(['"', "\r\n", "\r", "\n"], ['\\"', '\\n'], $pValue);

        return $this->exec('Set', [sprintf('GLOBAL(%s)="%s"', $pVariable, $pValue)]);
    }


    /**
     * Sets a channel variable
     * This is just an alias for AGI::set_variable()
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Set/
     * @deprecated since 3.0.6; use AGI::set_variable() instead
     * @param string $pVariable
     * @param float|int|string $pValue
     * @return Response|AgiArray `result` is 1 on sucess, 0 otherwise
     */
    public function set_var(string $pVariable, float|int|string $pValue): Response|array
    {
        return $this->set_variable($pVariable, "$pValue");
    }


    /**
     * NEW SIGNATURE IN 3.0
     * Executes the specified Asterisk application with given options. No escaping or quoting
     * is performed on the given options
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/exec/
     *
     * @param string $application
     * @param list<string|int|float> $options
     * @return Response|AgiArray `result` is whatever the application returns, or -2 on failure to find application
     */
    public function exec(string $application, array $options = []): Response|array
    {
        $options = implode($this->option_delim, $options);

        return $this->evaluate('EXEC %s %s', $application, $options);
    }

    /**
     * Plays the given file and receives DTMF data.
     *
     * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
     * while STREAM FILE returns immediately after the first DTMF digit is detected.
     *
     * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
     *
     * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
     * of silence then the command ends.
     *
     * The user has the opportunity to press a key at any time during the message or the
     * post-message silence. If the user presses a key while the message is playing, the
     * message stops playing. When the first key is pressed a timer starts counting for
     * $timeout milliseconds. Every time the user presses another key the timer is restarted.
     * The command ends when the counter goes to zero or the maximum number of digits is entered,
     * whichever happens first.
     *
     * If you don't specify a time out then a default timeout of 2000 is used following a pressed
     * digit. If no digits are pressed then 6 seconds of silence follow the message.
     *
     * If you don't specify $max_digits then the user can enter as many digits as they want.
     *
     * Pressing the # key has the same effect as the timer running out: the command ends and
     * any previously keyed digits are returned. A side effect of this is that there is no
     * way to read a # key using this command.
     *
     * @example examples/ping.php Ping an IP address
     * @link hhttps://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_data/
     *
     * @param string $filename file to play. Do not include file extension.
     * @param int $timeout milliseconds
     * @param positive-int $max_digits
     * @return Response|AgiArray `result` holds the digits and `data` holds the timeout if present.
     * This differs from other commands which return ASCII codes.
     */
    public function get_data(string $filename, int $timeout = 0, int $max_digits = 1024): Response|array
    {
        return $this->evaluate('GET DATA %s %d %d', $filename, $timeout, $max_digits);
    }

    /**
     * Fetch the value of a variable.
     *
     * Does not work with global variables. Does not work with some variables that are generated by modules.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_variable/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     *
     * @param string $variable name
     * @param bool $getvalue return the value only
     * @return ($getvalue is true ? string : Response|AgiArray) `result` is 0 if variable hasn't been set, 1 if it has.
     * `data` holds the value. Or just returns the value if `$getvalue` is `true`
     */
    public function get_variable(string $variable, bool $getvalue = false): Response|array|string
    {
        $res = $this->evaluate('GET VARIABLE %s', $variable);

        return $getvalue ? strval($res['data']) : $res;
    }

    /**
     * Fetch the result of a dialplan-like expression.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_full_variable/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Expressions/
     * @param string $variable name
     * @param string $channel channel
     * @param bool $getvalue return the value only
     * @return ($getvalue is true ? string : Response|AgiArray) when $getvalue is false, `result` is 0 if variable hasn't been set,
     * 1 if it has; `data` holds the value
     */
    public function get_fullvariable(string $variable, string $channel = '', bool $getvalue = false): Response|array|string
    {
        $res = strlen($channel ?: '')
            ? $this->evaluate('GET FULL VARIABLE %s %s', $variable, $channel)
            : $this->evaluate('GET FULL VARIABLE %s', $variable);

        return $getvalue ? strval($res['data']) : $res;
    }

    /**
     * Hangup the specified channel. If no channel name is given, hang up the current channel.
     *
     * With power comes responsibility. Hanging up channels other than your own isn't something
     * that is done routinely. If you are not sure why you are doing so, then don't.
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     * @example examples/ping.php Ping an IP address
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/hangup/
     *
     * @param string $channel
     * @return Response|AgiArray `result` is 1 on success, -1 on failure.
     */
    public function hangup(string $channel = ''): Response|array
    {
        return strlen($channel)
            ? $this->evaluate('HANGUP %s', $channel)
            : $this->evaluate('HANGUP');
    }

    /**
     * Does nothing.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/noop/
     *
     * @param string $string a message
     * @return Response|AgiArray
     */
    public function noop(string $string = ''): Response|array
    {
        return $this->evaluate("NOOP %s", $string);
    }

    /**
     * Receive a character of text from a connected channel. Waits up to $timeout milliseconds for
     * a character to arrive, or infinitely if $timeout is zero.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/receive_char/
     *
     * @param int $timeout milliseconds
     * @return Response|AgiArray `result` is 0 on timeout or not supported, -1 on failure, otherwise the ASCII code of the DTMF key
     */
    public function receive_char(int $timeout = -1): Response|array
    {
        return $this->evaluate("RECEIVE CHAR $timeout");
    }

    /**
     * Record sound to a file until an acceptable DTMF digit is received or a specified amount of
     * time has passed. Optionally the file BEEP is played before recording begins.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/record_file/
     *
     * @param string $file to record, without extension, often created in /var/lib/asterisk/sounds
     * @param string $format of the file. GSM and WAV are commonly used formats. MP3 is read-only and thus cannot be used.
     * @param string $escape_digits
     * @param int $timeout is the maximum record time in milliseconds, or -1 for no timeout.
     * @param int $offset to seek to without exceeding the end of the file.
     * @param bool $beep
     * @param int $silence number of seconds of silence allowed before the function returns despite the
     * lack of dtmf digits or reaching timeout.
     * @return Response|AgiArray `result` is -1 on error, 0 on hangup, otherwise the ASCII code of the DTMF key
     */
    public function record_file(
        string $file,
        string $format,
        string $escape_digits = '',
        int    $timeout = -1,
        int    $offset = 0,
        bool   $beep = false,
        int    $silence = 0,
    ): Response|array
    {
        $cmd = 'RECORD FILE %s %s %s %d';
        $args = [$file, $format, $escape_digits, $timeout];
        if ($offset) {
            $cmd .= ' %d';
            $args[] = $offset;
        }
        if ($beep) {
            $cmd .= ' BEEP';
        }
        if ($silence) {
            $cmd .= ' %s';
            $args[] = "s=$silence";
        }

        return $this->evaluate($cmd, ...$args);
    }

    /**
     * Say a given character string, returning early if any of the given DTMF digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_alpha/
     *
     * @param string $text
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_alpha(string $text, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY ALPHA %s %s', $text, $escape_digits);
    }

    /**
     * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_digits/
     *
     * @param int $digits
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_digits(int $digits, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY DIGITS %d %s', $digits, $escape_digits);
    }

    /**
     * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_number/
     *
     * @param int $number
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_number(int $number, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY NUMBER %d %s', $number, $escape_digits);
    }

    /**
     * Say the given character string, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_phonetic/
     *
     * @param string $text
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_phonetic(string $text, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY PHONETIC %s %s', $text, $escape_digits);
    }

    /**
     * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_time/
     *
     * @param int|null $time number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time (UTC).
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_time(?int $time = null, string $escape_digits = ''): Response|array
    {
        $time ??= time();

        return $this->evaluate('SAY TIME %d %s', $time, $escape_digits);
    }

    /**
     * Send the specified image on a channel.
     *
     * Most channels do not support the transmission of images.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/send_image/
     *
     * @param string $image without extension, often in /var/lib/asterisk/images
     * @return Response|AgiArray `result` is -1 on hangup, error or channel does not support image transmission; 0 on success
     */
    public function send_image(string $image): Response|array
    {
        return $this->evaluate('SEND IMAGE %s', $image);
    }

    /**
     * Send the given text to the connected channel.
     *
     * Most channels do not support transmission of text.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/send_text/
     *
     * @param string $text
     * @return Response|AgiArray `result` is -1 on hangup, error or channel does not support text transmission; 0 on success
     */
    public function send_text(string $text): Response|array
    {
        return $this->evaluate('SEND TEXT %s', $text);
    }

    /**
     * Cause the channel to automatically hangup at $time seconds in the future.
     * If $time is 0 then the autohangup feature is disabled on this channel.
     *
     * If the channel is hungup prior to $time seconds, this setting has no effect.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_autohangup/
     *
     * @param int $time until automatic hangup
     * @return Response|AgiArray `result` is always 0
     */
    public function set_autohangup(int $time = 0): Response|array
    {
        return $this->evaluate('SET AUTOHANGUP %d', $time);
    }

    /**
     * Changes the caller ID of the current channel.
     * This command will let you take liberties with the <caller ID specification> but the format shown in the example above works
     *  well: the name enclosed in double quotes followed immediately by the number inside angle brackets. If there is no name then
     *  you can omit it. If the name contains no spaces you can omit the double quotes around it. The number must follow the name
     *  immediately; don't put a space between them. The angle brackets around the number are necessary; if you omit them the
     *  number will be considered to be part of the name.     *
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_callerid/
     *
     * @param string $cid example: "John Smith"<1234567>
     * @return Response|AgiArray `result` is always 1
     */
    public function set_callerid(string $cid): Response|array
    {
        return $this->evaluate('SET CALLERID %s', $cid);
    }

    /**
     * Sets the context for continuation upon exiting the application.
     *
     * Setting the context does NOT automatically reset the extension and the priority; if you want to start at the top of the new
     * context you should set extension and priority yourself.
     *
     * If you specify a non-existent context you receive no error indication (['result'] is still 0) but you do get a
     * warning message on the Asterisk console.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_context/
     *
     * @param string $context
     * @return Response|AgiArray `result` is always 0
     */
    public function set_context(string $context): Response|array
    {
        return $this->evaluate('SET CONTEXT %s', $context);
    }

    /**
     * Set the extension to be used for continuation upon exiting the application.
     *
     * Setting the extension does NOT automatically reset the priority. If you want to start with the first priority of the
     * extension you should set the priority yourself.
     *
     * If you specify a non-existent extension you receive no error indication (['result'] is still 0) but you do
     * get a warning message on the Asterisk console.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_extension/
     *
     * @param string $extension
     * @return Response|AgiArray `result` is always 0
     */
    public function set_extension(string $extension): Response|array
    {
        return $this->evaluate('SET EXTENSION %s', $extension);
    }

    /**
     * Enable/Disable Music on hold generator.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_music/
     *
     * @param bool $enabled
     * @param string $class
     * @return Response|AgiArray `result` is always 0
     */
    public function set_music(bool $enabled = true, string $class = ''): Response|array
    {
        $enabled = $enabled ? 'ON' : 'OFF';

        return strlen($class)
            ? $this->evaluate('SET MUSIC %s %s', $enabled, $class)
            : $this->evaluate('SET MUSIC %s', $enabled);
    }

    /**
     * Set the priority to be used for continuation upon exiting the application.
     *
     * If you specify a non-existent priority you receive no error indication (['result'] is still 0)
     * and no warning is issued on the Asterisk console.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_music/
     *
     * @param int $priority
     * @return Response|AgiArray `result` is always 0
     */
    public function set_priority(int $priority): Response|array
    {
        return $this->evaluate('SET PRIORITY %d', $priority);
    }

    /**
     * Sets a variable to the specified value. The variables so created can later be used by later using ${<variablename>}
     * in the dialplan.
     *
     * These variables live in the channel Asterisk creates when you pickup a phone and as such they are both local and temporary.
     * Variables created in one channel can not be accessed by another channel. When you hang up the phone, the channel is deleted
     * and any variables in that channel are deleted as well.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_variable/
     *
     * @param string $variable is case sensitive
     * @param string $value
     * @return Response|AgiArray `result` is always 1
     */
    public function set_variable(string $variable, string $value): Response|array
    {
        return $this->evaluate('SET VARIABLE %s %s', $variable, $value);
    }

    /**
     * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the GET DATA
     * command but this command returns after the first DTMF digit has been pressed while GET DATA can accumulated any number of
     * digits before returning.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/stream_file/
     *
     * @param string $filename without extension, often in /var/lib/asterisk/sounds
     * @param string $escape_digits
     * @param int $offset
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function stream_file(string $filename, string $escape_digits = '', int $offset = 0): Response|array
    {
        return $this->evaluate('STREAM FILE %s %s %d', $filename, $escape_digits, $offset);
    }

    /**
     * Enable or disable TDD transmission/reception on the current channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/tdd_mode/
     *
     * @param string $setting can be on, off or mate
     * @return Response|AgiArray `result` is 1 on sucess, 0 if the channel is not TDD capable.
     */
    public function tdd_mode(string $setting): Response|array
    {
        return $this->evaluate('TDD MODE %s', $setting);
    }

    /**
     * Sends $message to the Asterisk console via the 'verbose' message system.
     *
     * If the Asterisk verbosity level is $level or greater, send $message to the console.
     *
     * The Asterisk verbosity system works as follows. The Asterisk user gets to set the desired verbosity at startup time or later
     * using the console 'set verbose' command. Messages are displayed on the console if their verbose level is less than or equal
     * to desired verbosity set by the user. More important messages should have a low verbose level; less important messages
     * should have a high verbose level.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/verbose/
     *
     * @param string $message
     * @param int<1,4> $level from 1 to 4
     * @return Response|AgiArray `result` is always 1
     */
    public function verbose(string $message, int $level = 1): Response|array
    {
        $ret = [];
        $lines = explode(
            "\n",
            str_replace("\r\n", "\n", $message)
        );
        $level = max(min($level, 4), 0);
        foreach ($lines as $msg) {
            syslog(LOG_WARNING, $msg);
            $ret = $this->evaluate('VERBOSE %s %d', $msg, $level);
        }

        return $ret;
    }

    /**
     * Waits up to $timeout milliseconds for channel to receive a DTMF digit.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/wait_for_digit/
     *
     * @param int $timeout in millisecons. Use -1 for the timeout value if you want the call to wait indefinitely.
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if wait completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function wait_for_digit(int $timeout = -1): Response|array
    {
        return $this->evaluate('WAIT FOR DIGIT %d', $timeout);
    }


    // *********************************************************************************************************
    // **                             APPLICATIONS                                                                                        **
    // *********************************************************************************************************

    /**
     * Executes an AGI compliant application.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/AGI/
     *
     * @param string $command
     * @param string $args
     * @return Response|AgiArray `result` is -1 on hangup or if application requested hangup, or 0 on non-hangup exit.
     */
    public function exec_agi(string $command, string $args): Response|array
    {
        return $this->exec("AGI $command", [$args]);
    }

    /**
     * Set Language.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Set/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/Channel-Variables/
     *
     * @param string $language code
     * @return Response|AgiArray
     */
    public function exec_setlanguage(string $language = 'en'): Response|array
    {
        $args = ['CHANNEL(language)=' . $language];

        return $this->exec('Set', $args);
    }

    /**
     * Dial.
     *
     * Dial takes input from ${VXML_URL} to send XML Url to Cisco 7960
     * Dial takes input from ${ALERT_INFO} to set ring cadence for Cisco phones
     * Dial returns ${CAUSECODE}: If the dial failed, this is the errormessage.
     * Dial returns ${DIALSTATUS}: Text code returning status of last dial attempt.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Dial/
     * 
     * @param string $type
     * @param string $identifier
     * @param int|null $timeout
     * @param string|null $options
     * @param string|null $url
     * @return Response|AgiArray
     */
    public function exec_dial(
        string $type,
        string $identifier,
        ?int $timeout = null,
        ?string $options = null,
        ?string $url = null
    ): Response|array
    {
        $args = array_filter(["$type/$identifier", $timeout, $options, $url]);

        return $this->exec('Dial', $args);
    }

    /**
     * Goto.
     *
     * This function takes three arguments: context,extension, and priority, but the leading arguments
     * are optional, not the trailing arguments.  Thuse goto($z) sets the priority to $z.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Goto/
     *
     * @param string|int ...$args the goto arguments
     * @return Response|AgiArray
     */
    public function exec_goto(...$args): Response|array
    {
        return $this->exec('Goto', $args);
    }


    // *********************************************************************************************************
    // **                             FAST PASSING                                                                                        **
    // *********************************************************************************************************

    /**
     * @param string $method
     * @param string $buffer
     * @param string $escape_digits
     * @param mixed ...$args arguments to be passed to the method
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    protected function fastpass_proxy(string $method, string &$buffer, string $escape_digits, ...$args): Response|array
    {
        if (!method_exists($this, $method)) {
            $ret = new Response(AgiResult::BadCommand, "-1");

            return self::$returnObjects ? $ret : $ret->toArray();
        }

        // $buffer is always first argument but we have it already
        unset($args[0]);
        $proceed = false;
        $last = substr($buffer, -1) ?: '';
        if ($escape_digits !== '' && $buffer !== '' && !str_contains($escape_digits, $last)) {
            // last char of buffer was not an escape digit
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $this->$method(...$args);
            if (
                (
                    (is_int($res['code']) && $res['code'] === AgiResult::Ok->value)
                    || ($res instanceof Response && $res->code === AgiResult::Ok)
                )
                && $res['result'] > 0
            ) {
                $buffer .= chr($res['result']);
            }

            return $res;
        }
        $last = (string)ord($last);
        $ret = new Response(AgiResult::Ok, $last);

        return self::$returnObjects ? $ret : $ret->toArray();
    }

    /**
     * Say the given digit string, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_digits/
     *
     * @param string $buffer
     * @param int $digits
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_say_digits(string &$buffer, int $digits, string $escape_digits = ''): Response|array
    {
        return $this->fastpass_proxy("say_digits", $buffer, $escape_digits, func_get_args());
    }

    /**
     * Say the given number, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_number/
     *
     * @param string $buffer
     * @param int $number
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_say_number(string &$buffer, int $number, string $escape_digits = ''): Response|array
    {
        return $this->fastpass_proxy("say_number", $buffer, $escape_digits, func_get_args());
    }

    /**
     * Say the given character string, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_phonetic/
     *
     * @param string $buffer
     * @param string $text
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_say_phonetic(string &$buffer, string $text, string $escape_digits = ''): Response|array
    {
        return $this->fastpass_proxy("say_phonetic", $buffer, $escape_digits, func_get_args());
    }

    /**
     * Say a given time, returning early if any of the given DTMF escape digits are received on the channel.
     * Return early if $buffer is adequate for request.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_time/
     *
     * @param string $buffer
     * @param int|null $time number of seconds elapsed since 00:00:00 on January 1, 1970, Coordinated Universal Time (UTC).
     * @param string $escape_digits
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_say_time(string &$buffer, ?int $time = null, string $escape_digits = ''): Response|array
    {
        return $this->fastpass_proxy("say_time", $buffer, $escape_digits, func_get_args());
    }

    /**
     * Play the given audio file, allowing playback to be interrupted by a DTMF digit. This command is similar to the GET DATA
     * command but this command returns after the first DTMF digit has been pressed while GET DATA can accumulated any number of
     * digits before returning.
     * Return early if $buffer is adequate for request.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/stream_file/
     *
     * @param string $buffer
     * @param string $filename without extension, often in /var/lib/asterisk/sounds
     * @param string $escape_digits
     * @param int $offset
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_stream_file(
        string &$buffer,
        string $filename,
        string $escape_digits = '',
        int $offset = 0,
    ): Response|array
    {
        $result = $this->fastpass_proxy("stream_file", $buffer, $escape_digits, func_get_args());
        $result['endpos'] = 0;

        return $result;
    }

    /**
     * Use festival to read text.
     * Return early if $buffer is adequate for request.
     *
     * @link https://www.cstr.ed.ac.uk/projects/festival/
     *
     * @param string $buffer
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_text2wav(
        string &$buffer,
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
    ): Response|array
    {
        $result = $this->fastpass_proxy("text2wav", $buffer, $escape_digits, func_get_args());
        $result['endpos'] = 0;

        return $result;
    }

    /**
     * Use Cepstral Swift to read text.
     * Return early if $buffer is adequate for request.
     *
     * @link https://www.cepstral.com/
     *
     * @param string $buffer
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @param string|null $voice
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_swift(
        string &$buffer,
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
        ?string $voice = null,
    ): Response|array
    {
        $result = $this->fastpass_proxy("swift", $buffer, $escape_digits, func_get_args());
        $result['endpos'] = 0;

        return $result;
    }

    /**
     * Say Puncutation in a string.
     * Return early if $buffer is adequate for request.
     *
     * @param string $buffer
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function fastpass_say_punctuation(
        string &$buffer,
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
    ): Response|array
    {
        return $this->fastpass_proxy("say_punctuation", $buffer, $escape_digits, func_get_args());
    }

    /**
     * Plays the given file and receives DTMF data.
     * Return early if $buffer is adequate for request.
     *
     * This is similar to STREAM FILE, but this command can accept and return many DTMF digits,
     * while STREAM FILE returns immediately after the first DTMF digit is detected.
     *
     * Asterisk looks for the file to play in /var/lib/asterisk/sounds by default.
     *
     * If the user doesn't press any keys when the message plays, there is $timeout milliseconds
     * of silence then the command ends.
     *
     * The user has the opportunity to press a key at any time during the message or the
     * post-message silence. If the user presses a key while the message is playing, the
     * message stops playing. When the first key is pressed a timer starts counting for
     * $timeout milliseconds. Every time the user presses another key the timer is restarted.
     * The command ends when the counter goes to zero or the maximum number of digits is entered,
     * whichever happens first.
     *
     * If you don't specify a time out then a default timeout of 2000 is used following a pressed
     * digit. If no digits are pressed then 6 seconds of silence follow the message.
     *
     * If you don't specify $max_digits then the user can enter as many digits as they want.
     *
     * Pressing the # key has the same effect as the timer running out: the command ends and
     * any previously keyed digits are returned. A side effect of this is that there is no
     * way to read a # key using this command.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_data/
     *
     * @param string $buffer
     * @param string $filename file to play. Do not include file extension.
     * @param int $timeout milliseconds
     * @param int $max_digits
     * @return Response|AgiArray `result` holds the keys pressed (i.e. it will match [0-9A-D*]*) and `data` will be "timeout"
     * if applicable. This differs from other commands which return ASCII codes of the DTMF keys pressed.
     */
    public function fastpass_get_data(
        string &$buffer,
        string $filename,
        int $timeout = 0,
        int $max_digits = 1024,
    ): Response|array
    {
        if ($buffer === '') {
            $res = $this->get_data($filename, $timeout, $max_digits);
            if (
                (is_int($res['code']) && $res['code'] === AgiResult::Ok->value)
                || ($res instanceof Response && $res->code === AgiResult::Ok)
            ) {
                $buffer .= $res['result'];
            }

            return $res;
        }
        while (strlen($buffer) < $max_digits) {
            $res = $this->wait_for_digit();
            if (
                (is_int($res['code']) && $res['code'] !== AgiResult::Ok->value)
                || ($res instanceof Response && $res->code !== AgiResult::Ok)
            ) {
                return $res;
            }
            if ((int)$res['result'] === ord('#')) {
                break;
            }
            $buffer .= chr($res['result']);
        }
        $ret = new Response(AgiResult::Ok, $buffer);

        return self::$returnObjects ? $ret : $ret->toArray();
    }

    // *********************************************************************************************************
    // **                             DERIVED                                                                                             **
    // *********************************************************************************************************

    /**
     * Menu.
     *
     * This function presents the user with a menu and reads the response
     *
     * @param array<int|string,string> $choices has the following structure:
     *   array('1'=>'*Press 1 for this', // festival reads if prompt starts with *
     *           '2'=>'some-gsm-without-extension',
     *           '*'=>'*Press star for help');
     * @return string|int key pressed on sucess, -1 on failure
     */
    public function menu(array $choices, int $timeout = 2000): string|int
    {
        $keys = join('', array_keys($choices));
        $choice = null;
        while (is_null($choice)) {
            foreach ($choices as $prompt) {
                if (str_starts_with($prompt, '*')) {
                    $ret = $this->text2wav(substr($prompt, 1), $keys);
                } else {
                    $ret = $this->stream_file($prompt, $keys);
                }

                if (
                    (is_int($ret['code']) && $ret['code'] !== AgiResult::Ok->value)
                    || ($ret instanceof Response && $ret->code !== AgiResult::Ok)
                    || $ret['result'] === '-1'
                ) {
                    $choice = -1;
                    break;
                }

                if ($ret['result'] !== '0') {
                    $choice = chr($ret['result']);
                    break;
                }
            }

            if (is_null($choice)) {
                $ret = $this->get_data('beep', $timeout, 1);
                if (
                    (is_int($ret['code']) && $ret['code'] !== AgiResult::Ok->value)
                    || ($ret instanceof Response && $ret->code !== AgiResult::Ok)
                    || $ret['result'] === '-1'
                ) {
                    $choice = -1;
                } elseif ($ret['result'] !== '' && str_contains($keys, $ret['result'])) {
                    $choice = $ret['result'];
                }
            }
        }

        return $choice;
    }

    /**
     * setContext - Set context, extension and priority.
     *
     * @param string $context
     * @param string $extension
     * @param int|numeric-string $priority
     */
    public function setContext(string $context, string $extension = 's', int|string $priority = 1): void
    {
        $this->set_context($context);
        $this->set_extension($extension);
        $this->set_priority($priority);
    }

    /**
     * Parse caller id.
     * "name" <proto:user@server:port>
     *
     * @example examples/dtmf.php Get DTMF tones from the user and say the digits
     * @example examples/input.php Get text input from the user and say it back
     *
     * @param string|null $callerid
     * @return array{name:string, protocol:string, port:string, username?:string}
     */
    public function parse_callerid(?string $callerid = null): array
    {
        $callerid ??= $this->request->agi_callerid;

        $ret = ['name' => '', 'protocol' => '', 'port' => ''];
        $callerid = trim($callerid);

        if (str_starts_with($callerid, '"') || str_starts_with($callerid, "'")) {
            $d = substr($callerid, 0, 1);
            $callerid = explode($d, substr($callerid, 1));
            $ret['name'] = array_shift($callerid);
            $callerid = join($d, $callerid);
        }

        $callerid = explode('@', trim($callerid, '<> '));
        $username = explode(':', array_shift($callerid));
        if (count($username) === 1) {
            $ret['username'] = $username[0];
        } else {
            $ret['protocol'] = array_shift($username);
            $ret['username'] = join(':', $username);
        }

        $callerid = join('@', $callerid);
        $host = explode(':', $callerid);
        if (count($host) === 1) {
            $ret['host'] = $host[0];
        } else {
            $ret['host'] = array_shift($host);
            $ret['port'] = join(':', $host);
        }

        return $ret;
    }

    /**
     * @param string $command the command to run
     * @param string $fname fully qualified filename, without extension
     * @param string $text the text to say
     * @param string $escape_digits
     * @return Response|AgiArray the result of stream_file
     */
    protected function tts(
        string $command,
        string $fname,
        string $text,
        string $escape_digits,
    ): Response|array
    {
        $text = trim($text);
        if ($text === '') {
            $ret = new Response(AgiResult::Ok, "0");

            return self::$returnObjects ? $ret : $ret->toArray();
        }

        // create wave file
        if (!file_exists("$fname.wav")) {
            // write text file
            file_put_contents("$fname.txt", $text);
            shell_exec("$command");
        } else {
            touch("$fname.txt");
            touch("$fname.wav");
        }

        // stream it
        $ret = $this->stream_file($fname, $escape_digits);

        // clean up old files
        $delete = time() - 2592000; // 1 month
        $this->clearTemp('tts_*', $delete);

        return $ret;
    }

    /**
     * Use festival to read text.
     *
     * @link https://www.cstr.ed.ac.uk/projects/festival/
     *
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function text2wav(
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
    ): Response|array
    {
        $fname = $this->config['phpagi']['tempdir'] . DIRECTORY_SEPARATOR . 'tts_' . md5($text);
        $command = escapeshellcmd($this->config['festival']['text2wave']);
        $args = '-F ' . escapeshellarg("$frequency");
        $args .= ' -o ' . escapeshellarg("$fname.wav");
        $args .= ' ' . escapeshellarg("$fname.txt");

        return $this->tts("$command $args", $fname, $text, $escape_digits);
    }

    /**
     * Use Cepstral Swift to read text.
     *
     * @link https://www.cepstral.com/
     *
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @param string|null $voice
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function swift(
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
        ?string $voice = null,
    ): Response|array
    {
        $voice ??= $this->config['cepstral']['voice'] ?? '';
        $fname = $this->config['phpagi']['tempdir'] . DIRECTORY_SEPARATOR . 'tts_' . md5($text);

        $command = escapeshellcmd($this->config['cepstral']['swift']);
        $args = '-p ' . escapeshellarg("audio/channels=1,audio/sampling-rate=$frequency $voice");
        $args .= ' -o ' . escapeshellarg("$fname.wav");
        $args .= ' -f ' . escapeshellarg("$fname.txt");
        if ($voice) {
            $args .= ' -n ' . escapeshellarg($voice);
        }

        return $this->tts("$command $args", $fname, $text, $escape_digits);
    }

    /**
     * Text Input.
     *
     * Based on ideas found at http://www.voip-info.org/wiki-Asterisk+cmd+DTMFToText
     *
     * Example:
     *               UC   H     LC   i      ,     SP   h     o      w    SP   a    r      e     SP   y      o      u     ?
     *   $string = '*8'.'44*'.'*5'.'444*'.'00*'.'0*'.'44*'.'666*'.'9*'.'0*'.'2*'.'777*'.'33*'.'0*'.'999*'.'666*'.'88*'.'0000*';
     *
     * @link http://www.voip-info.org/wiki-Asterisk+cmd+DTMFToText
     *
     * @param string $mode
     * @return string
     */
    public function text_input(string $mode = 'NUMERIC'): string
    {
        $alpha = [
            'k0' => ' ', 'k00' => ',', 'k000' => '.', 'k0000' => '?', 'k00000' => '0',
            'k1' => '!', 'k11' => ':', 'k111' => ';', 'k1111' => '#', 'k11111' => '1',
            'k2' => 'A', 'k22' => 'B', 'k222' => 'C', 'k2222' => '2',
            'k3' => 'D', 'k33' => 'E', 'k333' => 'F', 'k3333' => '3',
            'k4' => 'G', 'k44' => 'H', 'k444' => 'I', 'k4444' => '4',
            'k5' => 'J', 'k55' => 'K', 'k555' => 'L', 'k5555' => '5',
            'k6' => 'M', 'k66' => 'N', 'k666' => 'O', 'k6666' => '6',
            'k7' => 'P', 'k77' => 'Q', 'k777' => 'R', 'k7777' => 'S', 'k77777' => '7',
            'k8' => 'T', 'k88' => 'U', 'k888' => 'V', 'k8888' => '8',
            'k9' => 'W', 'k99' => 'X', 'k999' => 'Y', 'k9999' => 'Z', 'k99999' => '9',
        ];
        $symbol = [
            'k0' => '=',
            'k1' => '<', 'k11' => '(', 'k111' => '[', 'k1111' => '{', 'k11111' => '1',
            'k2' => '@', 'k22' => '$', 'k222' => '&', 'k2222' => '%', 'k22222' => '2',
            'k3' => '>', 'k33' => ')', 'k333' => ']', 'k3333' => '}', 'k33333' => '3',
            'k4' => '+', 'k44' => '-', 'k444' => '*', 'k4444' => '/', 'k44444' => '4',
            'k5' => "'", 'k55' => '`', 'k555' => '5',
            'k6' => '"', 'k66' => '6',
            'k7' => '^', 'k77' => '7',
            'k8' => '\\', 'k88' => '|', 'k888' => '8',
            'k9' => '_', 'k99' => '~', 'k999' => '9',
        ];
        $text = '';
        do {
            $command = false;
            $result = $this->get_data('beep');
            foreach (explode('*', $result['result']) as $code) {
                if ($command) {
                    switch (substr($code, 0, 1)) {
                        case '2':
                            $text = substr($text, 0, -1);
                            break; // backspace
                        case '5':
                            $mode = 'LOWERCASE';
                            break;
                        case '6':
                            $mode = 'NUMERIC';
                            break;
                        case '7':
                            $mode = 'SYMBOL';
                            break;
                        case '8':
                            $mode = 'UPPERCASE';
                            break;
                        case '9':
                            $text = substr($text, 0, strrpos($text, ' '));
                            break; // backspace a word
                    }
                    $code = substr($code, 1);
                    $command = false;
                }
                if ($code === '') {
                    $command = true;
                } elseif ($mode === 'NUMERIC') {
                    $text .= $code;
                } elseif ($mode === 'UPPERCASE') {
                    $text .= $alpha['k' . $code] ?? '';
                } elseif ($mode === 'LOWERCASE') {
                    $text .= strtolower($alpha['k' . $code] ?? '');
                } elseif ($mode == 'SYMBOL') {
                    $text .= $symbol['k' . $code] ?? '';
                }
            }
            $this->say_punctuation($text);
        } while (str_ends_with($result['result'], '**'));

        return $text;
    }

    /**
     * Say Puncutation in a string.
     *
     * @param string $text
     * @param string $escape_digits
     * @param int $frequency
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    public function say_punctuation(
        string $text,
        string $escape_digits = '',
        int $frequency = 8000,
    ): Response|array
    {
        $punc = [
            ' ' => 'SPACE',
            ',' => 'COMMA',
            '.' => 'PERIOD',
            '?' => 'QUESTION MARK',
            '!' => 'EXPLANATION POINT',
            ':' => 'COLON',
            ';' => 'SEMICOLON',
            '#' => 'POUND',
            '=' => 'EQUALS',
            '<' => 'LESS THAN',
            '(' => 'LEFT PARENTHESIS',
            '[' => 'LEFT BRACKET',
            '{' => 'LEFT BRACE',
            '@' => 'AT',
            '$' => 'DOLLAR SIGN',
            '&' => 'AMPERSAND',
            '%' => 'PERCENT',
            '>' => 'GREATER THAN',
            ')' => 'RIGHT PARENTHESIS',
            ']' => 'RIGHT BRACKET',
            '}' => 'RIGHT BRACE',
            '+' => 'PLUS',
            '-' => 'MINUS',
            '*' => 'ASTERISK',
            '/' => 'SLASH',
            "'" => 'SINGLE QUOTE',
            '`' => 'BACK TICK',
            '"' => 'QUOTE',
            '^' => 'CAROT',
            '\\' => 'BACK SLASH',
            '|' => 'BAR',
            '_' => 'UNDERSCORE',
            '~' => 'TILDE',
        ];
        $text = preg_replace('/(.)/', ' $1 ', $text);
        $text = str_replace(
            array_keys($punc), 
            array_values($punc), 
            $text
        );

        return $this->text2wav($text, $escape_digits, $frequency);
    }

    /**
     * Create a new AGI_AsteriskManager.
     */
    public function AMI(): AMI
    {
        if (!isset($this->asm)) {
            $this->asm = new AMI(null, $this->config['asmanager']);
            $this->asm->setPagi($this);
            $this->config['asmanager'] = $this->asm->config['asmanager'];
        }

        return $this->asm;
    }


    /**
     * NEW SIGNATURE IN 3.0
     * Send an AGI command and parse the response
     *
     * @param string $command the AGI command string to send
     * @param mixed $args if present, $command is a printf format specification that $args are applied to;
     *    string values will be escaped and quoted
     * @return Response|AgiArray
     */
    public function evaluate(string $command, ...$args): Response|array
    {
        $broken = new Response(AgiResult::Error, "-1");

        if (func_num_args() > 1) {
            $args = array_map(
                fn($v) => is_numeric($v) ? $v : '"' . str_replace(['"', "\n"], ['\\"', '\\n'], $v) . '"',
                $args,
            );

            $command = trim(vsprintf($command, $args));
        }
        $broken['data'] = $command;

        // write command
        if (!fwrite($this->out, $command . "\n")) {
            return self::$returnObjects ? $broken : $broken->toArray();
        }
        fflush($this->out);

        // Read result.  Occasionally, a command return a string followed by an extra new line.
        // When this happens, our script will ignore the new line, but it will still be in the
        // buffer.  So, if we get a blank line, it is probably the result of a previous
        // command.  We read until we get a valid result or asterisk hangs up.  One offending
        // command is SEND TEXT.
        $count = 0;
        do {
            $str = trim(fgets($this->in));
        } while ($str === '' && $count++ < 5);

        if ($count >= 5 && $str === '') {
            $this->conlog("evaluate error on read for $command");

            return self::$returnObjects ? $broken : $broken->toArray();
        }

        /**
         * Typical response formats:
         * 200 result=1
         *
         * 200 result=4 (supplementary data)
         *
         * 200 result=4 (supplementary data) foo=bar
         *
         * 510 Invalid or unknown command
         *
         * 520-Invalid command syntax.  Proper usage follows:
         * foo
         * bar
         * baz
         * 520 End of proper usage.
         */
        // parse result
        preg_match('/^(?P<code>\d+)(?:(?P<sep>[ -])(?P<data>.+))?/', $str, $matches);
        $code = (int)$matches['code'];
        $sep = trim($matches['sep'] ?? '');
        $str = trim($matches['data'] ?? '');

        if ($sep === '-') {
            // we have a multiline response!
            $empty_count = 0;
            $line = trim(fgets($this->in));
            while (!str_starts_with($line, "$code") && $empty_count < 5) {
                $str .= "\n" . $line;
                $line = trim(fgets($this->in));
                $empty_count = $line ? $empty_count + 1 : 0;
            }
            $str = trim($str);
            if ($empty_count >= 5) {
                $this->conlog("evaluate error on multiline read for $command");
                if ($str === '') {
                    return self::$returnObjects ? $broken : $broken->toArray();
                }
                $this->conlog("continuing with partial content $str");
            }
        }

        if ($code === AgiResult::BadCommand->value) {
            $ret = new Response(AgiResult::BadCommand, "0");
            $this->conlog("AGI returned unknown command error: $str");
        } elseif ($code === AgiResult::Invalid->value) {
            $ret = new Response(AgiResult::Invalid, "0");
            $this->conlog("AGI returned invalid command syntax error: $str");
        } elseif ($code !== AgiResult::Ok->value) {
            $ret = new Response(AgiResult::tryFrom($code) ?? AgiResult::Invalid, "0");
            $this->conlog("AGI returned unknown error $code: $str");
        } else {
            $ret = new Response(AgiResult::Ok, "0");
            while(preg_match('/^(?P<key>\w+)=(?P<value>\S+)(?:\s+\((?P<data>.*)\))?/s', $str, $matches)) {
                // this will be $ret['result']
                $ret[$matches['key']] = $matches['value'];
                if (isset($matches['data'])) {
                    $ret['data'] = $matches['data'];
                }
                $str = trim(str_replace($matches[0], '', $str));
            }
        }

        // log some errors
        if (($ret['result'] ?? "0") < 0) {
            $this->conlog("$command returned $ret[result]");
        }

        return self::$returnObjects ? $ret : $ret->toArray();
    }

    /**
     * Log to console if debug mode.
     *
     * @example examples/ping.php Ping an IP address
     *
     * @param string $str
     * @param int $vbl verbose level
     * @return void
     */
    public function conlog(string $str, int $vbl = 1): void
    {
        static $busy = false;

        if ($this->config['phpagi']['debug'] && $busy === false) {
            // no conlogs inside conlog!!!
            $busy = true;
            $this->verbose($str, $vbl);
            $busy = false;
        }
    }

    /**
     * Find an execuable in the path.
     *
     * @param string $cmd command to find
     * @return string the path to the command
     */
    protected function which(string $cmd): string
    {
        $default = '/bin:/sbin:/usr/bin:/usr/sbin:/usr/local/bin:/usr/local/sbin:/usr/X11R6/bin:/usr/local/apache/bin:/usr/local/mysql/bin';
        $chpath = getenv('PATH') ?: $default;

        foreach (explode(':', $chpath) as $path) {
            if (is_executable("$path/$cmd")) {
                return "$path/$cmd";
            }
        }

        return '';
    }

    /**
     * error handler for phpagi.
     *
     * @param int $level PHP error level
     * @param string $message error message
     * @param string $file path to file
     * @param int $line line number of error
     * @return true
     */
    public function phpagi_error_handler(int $level, string $message, string $file, int $line): bool
    {
        if (ini_get('error_reporting') === "0") {
            return true;
        } // this happens with an @

        syslog(LOG_WARNING, $file . '[' . $line . ']: ' . $message);

        if (!is_null($this->phpagi_error_handler_email)) {// generate email debugging information
            // decode error level
            switch ($level) {
                case E_WARNING:
                case E_USER_WARNING:
                    $level = "Warning";
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                    $level = "Notice";
                    break;
                case E_ERROR:
                case E_USER_ERROR:
                    $level = "Error";
                    break;
            }

            // build message
            $basefile = basename($file);
            $subject = "$basefile/$line/$level: $message";
            $message = "$level: $message in $file on line $line\n\n";

            // figure out who we are
            if (extension_loaded('sockets')) {
                $addr = null;
                $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                socket_connect($socket, '224.0.0.0', 1);
                socket_getsockname($socket, $addr);
                socket_close($socket);
                $message .= "\n\nIP Address: $addr\n";
            }

            // include variables
            $message .= "\n\nGLOBALS:\n" . print_r($GLOBALS, true);
            $message .= "\n\nBacktrace:\n" . print_r(debug_backtrace(), true);

            // include code fragment
            if (file_exists($file) && is_readable($file) && filesize($file) < 1024 * 1024) {
                $message .= "\n\n$file:\n";
                $code = file($file, FILE_IGNORE_NEW_LINES);
                $code = array_slice($code, max(0, $line - 5), 10, true);
                foreach ($code as $k => $v) {
                    $message .= sprintf("%-5d %s\n", $k + 1, $v);
                }
            }

            // make sure message is fully readable (convert unprintable chars to hex representation)
            $message = preg_replace_callback(
                '/[^ -~\\t\\r\\n]/', // matches anything that isn't printable or whitespace
                fn($c) => '\\x0' . dechex(ord($c[0])),
                $message
            );

            // send the mail if fewer than 5 errors
            if (self::$mailcount++ <= 5) {
                mail($this->phpagi_error_handler_email, $subject, $message);
            }
        }

        return true;
    }

    /**
     * Clear the application's temp directory files
     *
     * @param string $glob a file glob to filter by
     * @param int $delete if provided, files modified before this unix timestamp will be deleted
     * @return void
     */
    protected function clearTemp(string $glob = '*', int $delete = 0): void
    {
        $dir = new GlobIterator($this->config['phpagi']['tempdir'] . DIRECTORY_SEPARATOR . $glob);

        foreach ($dir as $file) {
            if ($delete === 0 || $file->getMTime() < $delete) {
                unlink($file);
            }
        }
    }
}
