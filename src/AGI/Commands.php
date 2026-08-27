<?php

namespace PhpAgi\AGI;

use PhpAgi\Enums\ChannelState;
use ValueError;

/**
 * @phpstan-type AgiArray array{code:-1|200|510|511|520, result:string, data?:string, endpos?:int}
 */
trait Commands
{
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
}