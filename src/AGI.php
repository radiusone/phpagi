<?php /** @noinspection PhpUnused */

namespace PhpAgi;

use GlobIterator;
use PhpAgi\AGI\Commands;
use PhpAgi\AGI\Request;
use PhpAgi\AGI\Response;
use PhpAgi\Enums\AgiResult;

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
 * @phpstan-type AgiArray array{code:-1|200|500|510|511|520, result:string, data?:string, endpos?:int, ...<string,string>}
 * @phpstan-type ConfigAgi array{error_handler:bool, debug:bool, admin:string|null, tempdir:string}
 * @phpstan-type ConfigAmi array{server:string, port:int, username:string, secret:string, write_log:bool}
 * @phpstan-type ConfigFestival array{text2wave?:string}
 * @phpstan-type ConfigCepstral array{swift?:string, voice?:string}
 * @phpstan-type ConfigFull array{phpagi:ConfigAgi, asmanager:ConfigAmi, cepstral:ConfigCepstral, festival:ConfigFestival}
 */
class AGI
{
    use Commands;

    /** @var bool whether methods will return AGI\Response objects or traditional arrays */
    public static bool $returnObjects = false;

    public const DEFAULT_CONFIG = [
        'error_handler' => true,
        'debug' => false,
        'admin' => null,
        'tempdir' => self::AST_TMP_DIR,
    ];

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

    /** @var ConfigFull Config variables */
    public array $config = [
        'phpagi' => self::DEFAULT_CONFIG,
        'asmanager' => AMI::DEFAULT_CONFIG,
        'cepstral' => [],
        'festival' => [],
    ];

    /** @var false|resource Input Stream */
    protected $in;

    /** @var false|resource Output Stream */
    protected $out;

    /** @var false|resource Audio Stream */
    public $audio = false;

    /** @var string|null An email address to send errors to */
    protected ?string $phpagi_error_handler_email = null;
    
    /** @var AMI an AMI instance */
    protected AMI $asm;

    /**
     * Constructor
     *
     * @param string|null $config the name of the config file to parse
     * @param array{error_handler?:bool, debug?:bool, admin?:string, tempdir?:string, ...} $optconfig an array of configuration vars and vals, stuffed into $this->config['phpagi']
     */
    public function __construct(?string $config = null, array $optconfig = [])
    {
        // load config
        $config ??= self::DEFAULT_PHPAGI_CONFIG;
        if (file_exists($config)) {
            $result = parse_ini_file($config, true, INI_SCANNER_TYPED) ?: [];
            $this->config = array_merge_recursive($this->config, $result);
        }

        // If optconfig is specified, stuff vals and vars into 'phpagi' config array
        $this->config['phpagi'] = array_merge($this->config['phpagi'], $optconfig);

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
            set_error_handler($this->phpagi_error_handler(...));
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
        $this->request = Request::fromInput($this->in);

        // open audio if eagi detected
        if ($this->request->agi_enhanced === '1.0') {
            if (file_exists($fn = '/proc/' . getmypid() . '/fd/' . self::AUDIO_FILENO)) {
                $this->audio = fopen($fn, 'r');
            } elseif (file_exists($fn = '/dev/fd/' . self::AUDIO_FILENO)) {
                // may need to mount fdescfs
                $this->audio = fopen($fn, 'r');
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
     * @param callable $method
     * @param string $buffer
     * @param string $escape_digits
     * @param mixed ...$args arguments to be passed to the method
     * @return Response|AgiArray `result` is -1 on hangup or error, 0 if playback completes with no
     * digit received, otherwise the ASCII code of the DTMF key
     */
    protected function fastpass_proxy(callable $method, string &$buffer, string $escape_digits, ...$args): Response|array
    {
        // $buffer is always first argument but we have it already
        unset($args[0]);
        $proceed = false;
        $last = substr($buffer, -1);
        if ($escape_digits !== '' && $buffer !== '' && !str_contains($escape_digits, $last)) {
            // last char of buffer was not an escape digit
            $proceed = true;
        }
        if ($buffer === '' || $proceed) {
            $res = $method(...$args);
            if (
                ($res['code'] === AgiResult::Ok || $res['code'] === AgiResult::Ok->value)
                && $res['result'] > 0
            ) {
                $cp = max(1, min(255, (int)$res['result']));
                $buffer .= chr($cp);
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
        return $this->fastpass_proxy(
            $this->say_digits(...),
            $buffer,
            $escape_digits,
            func_get_args(),
        );
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
        return $this->fastpass_proxy(
            $this->say_number(...),
            $buffer,
            $escape_digits,
            func_get_args(),
        );
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
        return $this->fastpass_proxy(
            $this->say_phonetic(...),
            $buffer,
            $escape_digits,
            func_get_args(),
        );
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
        return $this->fastpass_proxy(
            $this->say_time(...),
            $buffer,
            $escape_digits,
            func_get_args(),
        );
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
        return $this->fastpass_proxy(
            $this->stream_file(...),
            $buffer,
            $escape_digits,
            func_get_args()
        );
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
        return $this->fastpass_proxy(
            $this->text2wav(...),
            $buffer,
            $escape_digits,
            func_get_args()
        );
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
        return $this->fastpass_proxy(
            $this->swift(...),
            $buffer,
            $escape_digits,
            func_get_args(),
        );
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
     * @param positive-int $max_digits
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
        $max_digits = max(1, $max_digits);
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
            $cp = max(1, min(255, (int)$res['result']));
            if ($cp === ord('#')) {
                break;
            }
            $buffer .= chr($cp);
        }
        $ret = new Response(AgiResult::Ok, $buffer);

        return self::$returnObjects ? $ret : $ret->toArray();
    }

    /**
     * setContext - Set context, extension and priority.
     *
     * @param string $context
     * @param string $extension
     * @param int|string $priority
     */
    public function setContext(string $context, string $extension = 's', int|string $priority = 1): void
    {
        $this->set_context($context);
        $this->set_extension($extension);
        $this->set_priority($priority);
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
        if (!is_executable($this->config['festival']['text2wave'])) {
            return new Response(AgiResult::Error, "-1");
        }
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
        if (!is_executable($this->config['cepstral']['swift'])) {
            return new Response(AgiResult::Error, "-1");
        }
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
     * Create a new AGI_AsteriskManager.
     */
    public function AMI(): AMI
    {
        if (!isset($this->asm)) {
            $this->asm = new AMI(null, $this->config['asmanager']);
            $this->asm->setPagi($this);
            $this->config['asmanager'] = $this->asm->config;
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
                // this will be $ret['result'] or $ret['endpos']
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
     * @param int<1,4> $vbl verbose level
     * @return void
     */
    public function conlog(string $str, int $vbl = 1): void
    {
        static $busy = false;
        $vbl = max(1, min(4, $vbl));

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
        $error_type = match ($level) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_ERROR, E_USER_ERROR => 'Error',
            default => 'Other error',
        };

        error_log(sprintf(
            '%s "%s" in %s:%d',
            $error_type,
            $message,
            $file,
            $line
        ));

        // send the mail if fewer than 5 errors
        if (
            self::$mailcount > 5
            || !filter_var($this->phpagi_error_handler_email, FILTER_VALIDATE_EMAIL)
        ) {
            return true;
        }

        // build message
        $basefile = basename($file);
        $subject = "$basefile/$line/$error_type: $message";
        $message = "$error_type: $message in $file on line $line\n\n";

        // figure out who we are
        if (extension_loaded('sockets')) {
            $addr = null;
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_connect($socket, '224.0.0.0', 1);
            socket_getsockname($socket, $addr);
            socket_close($socket);
            if ($addr) {
                $message .= "\n\nIP Address: $addr\n";
            }
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

        mail($this->phpagi_error_handler_email, $subject, $message);
        self::$mailcount++;

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
