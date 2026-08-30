<?php

namespace PhpAgi\AGI;

use PhpAgi\Enums\ChannelState;
use ValueError;

/**
 * @phpstan-type AgiArray array{code:-1|200|510|511|520, result:string, data?:string, endpos?:int, ...<string,string>}
 */
trait Commands
{
    /**
     * Answer channel
     * Answers channel if not already in answer state
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/answer/
     *
     * @return Response|AgiArray `result` is -1 on channel failure, or 0 if successful
     */
    public function answer(): Response|array
    {
        return $this->evaluate('ANSWER');
    }

    /**
     * Interrupts Async AGI
     * Interrupts expected flow of Async AGI commands and returns control to previous source (typically, the PBX
     * dialplan)
     *
     * @return Response|AgiArray
     */
    public function asyncagi_break(): Response|array
    {
        return $this->evaluate('ASYNCAGI BREAK');
    }

    /**
     * Returns status of the connected channel
     * Returns the status of the specified channelname. If no channel name is given then returns the status of the
     * current channel. Return values:
     * - 0 Channel is down and available
     * - 1 Channel is down, but reserved
     * - 2 Channel is off hook
     * - 3 Digits (or equivalent) have been dialed
     * - 4 Line is ringing
     * - 5 Remote end is ringing
     * - 6 Line is up
     * - 7 Line is busy
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/channel_status/
     *
     * @param string $channelname
     * @return Response|AgiArray `result` contains the state, `data` contains a textual description of the state
     */
    public function channel_status(string $channelname = ''): Response|array
    {
        $ret = strlen($channelname)
            ? $this->evaluate('CHANNEL STATUS %s', $channelname)
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
     * Sends audio file on channel and allows the listener to control the stream
     * Send the given file, allowing playback to be controlled by the given digits, if any. Use double quotes for the
     * digits if you wish none to be permitted. If offsetms is provided then the audio will seek to offsetms before
     * play starts. It sets the following channel variables upon completion:
     * - CPLAYBACKSTATUS Contains the status of the attempt as a text string "SUCCESS" or "USERSTOPPED" or
     * "REMOTESTOPPED" or "ERROR".
     * - CPLAYBACKOFFSET Contains the offset in ms into the file where playback was at when it stopped. -1 is end of
     * file.
     * - CPLAYBACKSTOPKEY If the playback is stopped by the user this variable contains the key that was pressed.
     *
     * @param string $filename The file extension must not be included in the filename
     * @param string $escape_digits
     * @param string|int $skipms
     * @param string $ffchr Defaults to #
     * @param string $rewchr Defaults to *
     * @param string $pausechr
     * @param string|int $offsetms Offset, in milliseconds, to start the audio playback
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, or the ASCII
     * numerical value of the digit if one was pressed,or -1 on error or if the channel was disconnected. Returns the
     * position where playback was terminated as endpos.
     */
    public function control_stream_file(
        string $filename,
        string $escape_digits,
        string|int $skipms = 0,
        string $ffchr = '#',
        string $rewchr = '*',
        string $pausechr = '',
        string|int $offsetms = 0
    ): Response|array
    {
        return $this->evaluate(
            'CONTROL STREAM FILE %s %s %s %s %s %s %s',
            $filename,
            $escape_digits,
            $skipms,
            $ffchr,
            $rewchr,
            $pausechr,
            $offsetms
        );
    }

    /**
     * Removes database key/value
     * Deletes an entry in the Asterisk database for a given family and key
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_del/
     *
     * @param string $family
     * @param string $key
     * @return Response|AgiArray `result` is 1 if successful, 0 otherwise.
     */
    public function database_del(string $family, string $key): Response|array
    {
        return $this->evaluate('DATABASE DEL %s %s', $family, $key);
    }

    /**
     * Removes database keytree/value
     * Deletes a family or specific keytree within a family in the Asterisk database
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_deltree/
     *
     * @param string $family
     * @param string $keytree
     * @return Response|AgiArray `result` is 1 if successful, 0 otherwise.
     */
    public function database_deltree(string $family, string $keytree = ''): Response|array
    {
        return strlen($keytree)
            ? $this->evaluate('DATABASE DELTREE %s %s', $family, $keytree)
            : $this->evaluate('DATABASE DELTREE %s', $family);
    }

    /**
     * Gets database value
     * Retrieves an entry in the Asterisk database for a given family and key
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_get/
     *
     * @param string $family
     * @param string $key
     * @return Response|AgiArray `result` is 0 if key is not set. `result` is 1 if key is set and returns the variable
     * in `data`
     */
    public function database_get(string $family, string $key): Response|array
    {
        return $this->evaluate('DATABASE GET %s %s', $family, $key);
    }

    /**
     * Adds/updates database value
     * Adds or updates an entry in the Asterisk database for a given family, key, and value.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/database_put/
     *
     * @param string $family
     * @param string $key
     * @param string $value
     * @return Response|AgiArray `result` is 1 if successful, 0 otherwise
     */
    public function database_put(string $family, string $key, string $value): Response|array
    {
        return $this->evaluate('DATABASE PUT %s %s %s', $family, $key, $value);
    }


    /**
     * Executes a given Application
     * Executes the specified Asterisk application with given options. exec does not evaluate dialplan functions and
     * variables unless it is explicitly enabled by setting the AGIEXECFULL variable to yes.
     *
     * No escaping or quoting is performed on the given options.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/exec/
     *
     * @param string $application
     * @param list<string|int|float> $options
     * @return Response|AgiArray `result` is whatever the application returns, or -2 on failure to find application
     */
    public function exec(string $application, array $options = []): Response|array
    {
        $options = implode(',', $options);

        return $this->evaluate('EXEC %s %s', $application, $options);
    }

    /**
     * Prompts for DTMF on a channel
     * Stream the given file, and receive DTMF data
     *
     * @link hhttps://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_data/
     *
     * @param string $file file to play. Do not include file extension
     * @param int $timeout milliseconds to wait after playing the file or receiving a digit before exiting
     * @param positive-int $max_digits if this many digits are received, exits immediately
     * @return Response|AgiArray `result` is the digits received from the channel at the other end; `data` is 'timeout' if applicable
     */
    public function get_data(string $file, int $timeout = 0, int $max_digits = 1024): Response|array
    {
        return $this->evaluate('GET DATA %s %d %d', $file, $timeout, $max_digits);
    }

    /**
     * Evaluates a channel expression
     * Evaluates the given expression against the channel specified by channelname, or the current channel if
     * channelname is not provided. Unlike GET VARIABLE, the expression is processed in a manner similar to dialplan
     * evaluation, allowing complex and built-in variables to be accessed, e.g. 'The time is ${EPOCH}'
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_full_variable/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Expressions/
     *
     * @param string $variable name
     * @param string $channelname channel
     * @param bool $getvalue return the value only
     * @return ($getvalue is true ? string : Response|AgiArray) when $getvalue is false, `result` is 0 if no channel
     * matching channelname exists, 1 otherwise; `data` holds the value
     */
    public function get_fullvariable(string $variable, string $channelname = '', bool $getvalue = false): Response|array|string
    {
        $res = strlen($channelname ?: '')
            ? $this->evaluate('GET FULL VARIABLE %s %s', $variable, $channelname)
            : $this->evaluate('GET FULL VARIABLE %s', $variable);

        return $getvalue ? strval($res['data']) : $res;
    }

    /**
     * Stream file, prompt for DTMF, with timeout
     * Behaves similar to STREAM FILE but used with a timeout option
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_option/
     *
     * @param string $filename
     * @param string $escape_digits
     * @param int $timeout
     * @return Response|array
     */
    public function get_option(string $filename, string $escape_digits = '', int $timeout = 0): Response|array
    {
        return $timeout > 0
            ? $this->evaluate('GET OPTION %s %s %d', $filename, $escape_digits, $timeout)
            : $this->evaluate('GET OPTION %s %s', $filename, $escape_digits);
    }

    /**
     * Gets a channel variable
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/get_variable/
     * @link https://docs.asterisk.org/Configuration/Dialplan/Variables/
     *
     * @param string $variablename
     * @param bool $getvalue return the value only
     * @return ($getvalue is true ? string : Response|AgiArray) `result` is 0 if variablename is not set. `result` is 1
     * if variablename is set and returns the variable in `data`
     */
    public function get_variable(string $variablename, bool $getvalue = false): Response|array|string
    {
        $res = $this->evaluate('GET VARIABLE %s', $variablename);

        return $getvalue ? strval($res['data']) : $res;
    }

    /**
     * Cause the channel to execute the specified dialplan subroutine.
     * Cause the channel to execute the specified dialplan subroutine, returning to the dialplan with execution of
     * a Return()
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/gosub/
     *
     * @param string $context
     * @param string $extension
     * @param string|int $priority
     * @param string $optional_argument
     * @return Response|array
     */
    public function gosub(
        string $context,
        string $extension,
        string|int $priority,
        string $optional_argument = ''
    ): Response|array
    {
        return strlen($optional_argument)
            ? $this->evaluate('GOSUB %s %s %s %s', $context, $extension, $priority, $optional_argument)
            : $this->evaluate('GOSUB %s %s %s', $context, $extension, $priority);
    }

    /**
     * Hangup a channel
     * Hangs up the specified channel. If no channel name is given, hangs up the current channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/hangup/
     *
     * @param string $channelname
     * @return Response|AgiArray
     */
    public function hangup(string $channelname = ''): Response|array
    {
        return strlen($channelname)
            ? $this->evaluate('HANGUP %s', $channelname)
            : $this->evaluate('HANGUP');
    }

    /**
     * Does nothing
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/noop/
     *
     * @param string $string a message; Asterisk ignores this but it can be helpful for debugging
     * @return Response|AgiArray
     */
    public function noop(string $string = ''): Response|array
    {
        return $this->evaluate("NOOP %s", $string);
    }

    /**
     * Receives one character from channels supporting it
     * Receives a character of text on a channel. Most channels do not support the reception of text.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/receive_char/
     *
     * @param int $timeout The maximum time to wait for input in milliseconds, or 0 for infinite
     * @return Response|AgiArray `result` is the decimal value of the character if one is received, or 0 if the
     * channel does not support text reception. `result` is -1 only on error or hangup
     */
    public function receive_char(int $timeout): Response|array
    {
        return $this->evaluate('RECEIVE CHAR %d', $timeout);
    }

    /**
     * Receives text from channels supporting it
     * Receives a string of text on a channel. Most channels do not support the reception of text.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/receive_text/
     *
     * @param int $timeout The timeout to be the maximum time to wait for input in milliseconds, or 0 for infinite
     * @return Response|array `result` is -1 for failure or 1 for success, and the string in `data`
     */
    public function receive_text(int $timeout): Response|array
    {
        return $this->evaluate('RECEIVE TEXT %d', $timeout);
    }

    /**
     * Records to a given file
     * Record sound to a file until an acceptable DTMF digit is received or a specified amount of
     * time has passed. Optionally the file BEEP is played before recording begins.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/record_file/
     *
     * @param string $file The destination filename of the recorded audio
     * @param string $format The audio format in which to save the resulting file
     * @param string $escape_digits The DTMF digits that will terminate the recording process
     * @param int $timeout The maximum recording time in milliseconds. Set to -1 for no limit
     * @param int $offset_samples Causes the recording to first seek to the specified offset before recording begins
     * @param bool $beep Causes Asterisk to play a beep as recording begins
     * @param int $silence The number of seconds of silence that are permitted before the recording is terminated,
     * regardless of the escape_digits or timeout arguments
     * @return Response|AgiArray `result` is -1 on error, 0 on hangup, otherwise the ASCII code of the DTMF key
     */
    public function record_file(
        string $file,
        string $format,
        string $escape_digits = '',
        int $timeout = -1,
        int $offset_samples = 0,
        bool $beep = false,
        int $silence = 0,
    ): Response|array
    {
        $cmd = 'RECORD FILE %s %s %s %d';
        $args = [$file, $format, $escape_digits, $timeout];
        if ($offset_samples) {
            $cmd .= ' %d';
            $args[] = $offset_samples;
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
     * Says a given character string
     * Say a given character string, returning early if any of the given DTMF digits are received on the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_alpha/
     *
     * @param string $number
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_alpha(string $number, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY ALPHA %s %s', $number, $escape_digits);
    }

    /**
     * Says a given date
     * Say a given date, returning early if any of the given DTMF digits are received on the channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_date/
     *
     * @param int|null $date Is number of seconds elapsed since 00:00:00 on January 1, 1970 Coordinated Universal Time
     * (UTC) or null for current time
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_date(?int $date = null, string $escape_digits = ''): Response|array
    {
        $date ??= time();

        return $this->evaluate('SAY DATE %d %s', $date, $escape_digits);
    }


    /**
     * Says a given time as specified by the format given
     * Say a given time, returning early if any of the given DTMF digits are received on the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_time/
     *
     * @param int|null $time Is number of seconds elapsed since 00:00:00 on January 1, 1970 Coordinated Universal Time
     * (UTC) or null for the current time
     * @param string $escape_digits
     * @param string $format Is the format the time should be said in. See voicemail.conf (defaults to ABdY 'digits/at' IMp)
     * @param string $timezone Acceptable values can be found in /usr/share/zoneinfo. Defaults to machine default
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_datetime(
        ?int $time = null,
        string $escape_digits = '',
        string $format = '',
        string $timezone = '',
    ): Response|array
    {
        $time ??= time();
        $cmd = 'SAY DATETIME %d %s';
        $args = [$time, $escape_digits];
        if ($format) {
            $cmd .= ' %s';
            $args[] = $format;
        }
        if ($timezone) {
            $cmd .= ' %s';
            $args[] = $timezone;
        }

        return $this->evaluate($cmd, $args);
    }

    /**
     * Says a given digit string
     * Say a given digit string, returning early if any of the given DTMF digits are received on the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_digits/
     *
     * @param int $number
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_digits(int $number, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY DIGITS %d %s', $number, $escape_digits);
    }

    /**
     * Says a given number
     * Say a given number, returning early if any of the given DTMF digits are received on the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_number/
     *
     * @param int $number
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_number(int $number, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY NUMBER %d %s', $number, $escape_digits);
    }

    /**
     * Says a given character string with phonetics
     * Say a given character string with phonetics, returning early if any of the given DTMF digits are received on
     * the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_phonetic/
     *
     * @param string $text
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_phonetic(string $text, string $escape_digits = ''): Response|array
    {
        return $this->evaluate('SAY PHONETIC %s %s', $text, $escape_digits);
    }

    /**
     * Says a given time
     * Say a given time, returning early if any of the given DTMF digits are received on the channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/say_time/
     *
     * @param int|null $time Is number of seconds elapsed since 00:00:00 on January 1, 1970 Coordinated Universal Time
     * (UTC) or null for the current time
     * @param string $escape_digits
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error/hangup
     */
    public function say_time(?int $time = null, string $escape_digits = ''): Response|array
    {
        $time ??= time();

        return $this->evaluate('SAY TIME %d %s', $time, $escape_digits);
    }

    /**
     * Sends images to channels supporting it
     * Send the given image on a channel. Most channels do not support the transmission of images
     *
     * Most channels do not support the transmission of images.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/send_image/
     *
     * @param string $image Image names should not include extensions
     * @return Response|AgiArray `result` is 0 if image is sent, or if the channel does not support image transmission.
     * `result` is -1 only on error/hangup
     */
    public function send_image(string $image): Response|array
    {
        return $this->evaluate('SEND IMAGE %s', $image);
    }

    /**
     * Sends text to channels supporting it
     * Sends the given text on a channel. Most channels do not support the transmission of text
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/send_text/
     *
     * @param string $text
     * @return Response|AgiArray `result` is 0 if text is sent, or if the channel does not support text transmission.
     * `result` is -1 only on error/hangup
     */
    public function send_text(string $text): Response|array
    {
        return $this->evaluate('SEND TEXT %s', $text);
    }

    /**
     * Autohangup channel in some time
     * Cause the channel to automatically hangup at time seconds in the future. Setting to 0 will cause the autohangup
     * feature to be disabled on this channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_autohangup/
     *
     * @param int $time
     * @return Response|AgiArray
     */
    public function set_autohangup(int $time): Response|array
    {
        return $this->evaluate('SET AUTOHANGUP %d', $time);
    }

    /**
     * Sets callerid for the current channel
     * Changes the caller ID of the current channel.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_callerid/
     *
     * @param string $number example: "John Smith"<1234567>
     * @return Response|AgiArray
     */
    public function set_callerid(string $number): Response|array
    {
        return $this->evaluate('SET CALLERID %s', $number);
    }

    /**
     * Sets channel context
     * Sets the context for continuation upon exiting the application.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_context/
     *
     * @param string $desired_context
     * @return Response|AgiArray
     */
    public function set_context(string $desired_context): Response|array
    {
        return $this->evaluate('SET CONTEXT %s', $desired_context);
    }

    /**
     * Changes channel extension
     * Changes the extension for continuation upon exiting the application
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_extension/
     *
     * @param string $new_extension
     * @return Response|AgiArray
     */
    public function set_extension(string $new_extension): Response|array
    {
        return $this->evaluate('SET EXTENSION %s', $new_extension);
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
     * Enable/Disable Music on hold generator
     * Enables/Disables the music on hold generator. If class is not specified, then the default music on hold class
     * will be used. This generator will be stopped automatically when playing a file
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_music/
     *
     * @param bool $enabled
     * @param string $class
     * @return Response|AgiArray Always `result` is 0
     */
    public function set_music(bool $enabled = true, string $class = ''): Response|array
    {
        $enabled = $enabled ? 'on' : 'off';

        return strlen($class)
            ? $this->evaluate('SET MUSIC %s %s', $enabled, $class)
            : $this->evaluate('SET MUSIC %s', $enabled);
    }

    /**
     * Set channel dialplan priority
     * Changes the priority for continuation upon exiting the application. The priority must be a valid priority
     * or label
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_music/
     *
     * @param string|int $priority
     * @return Response|AgiArray `result` is always 0
     */
    public function set_priority(string|int $priority): Response|array
    {
        return $this->evaluate('SET PRIORITY %s', $priority);
    }


    /**
     * This is just an alias for AGI::set_variable()
     *
     * @deprecated since 3.0.6; use AGI::set_variable() instead
     */
    public function set_var(string $pVariable, float|int|string $pValue): Response|array
    {
        return $this->set_variable($pVariable, "$pValue");
    }


    /**
     * Sets a channel variable
     * Sets a variable to the current channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/set_variable/
     *
     * @param string $variablename
     * @param string $value
     * @return Response|AgiArray `result` is always 1
     */
    public function set_variable(string $variablename, string $value): Response|array
    {
        return $this->evaluate('SET VARIABLE %s %s', $variablename, $value);
    }

    /**
     * Activates a grammar
     * Activates the specified grammar on the speech object
     *
     * @param string $grammar_name
     * @return Response|AgiArray
     */
    public function speech_activate_grammar(string $grammar_name): Response|array
    {
        return $this->evaluate('SPEECH ACTIVATE GRAMMAR %s', $grammar_name);
    }

    /**
     * Creates a speech object
     * Create a speech object to be used by the other Speech AGI commands
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_create/
     *
     * @param string $engine
     * @return Response|AgiArray
     */
    public function speech_create(string $engine): Response|array
    {
        return $this->evaluate('SPEECH CREATE %s', $engine);
    }

    /**
     * Deactivates a grammar
     * Deactivates the specified grammar on the speech object
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_deactivate_grammar/
     *
     * @param string $grammar_name
     * @return Response|AgiArray
     */
    public function speech_deactivate_grammar(string $grammar_name): Response|array
    {
        return $this->evaluate('SPEECH DEACTIVATE GRAMMAR %s', $grammar_name);
    }

    /**
     * Recognizes speech
     * Plays back given prompt while listening for speech and dtmf
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_recognize/
     *
     * @param string $prompt
     * @param int $timeout
     * @param int $offset
     * @return SpeechRecognizeResponse|AgiArray
     */
    public function speech_recognize(string $prompt, int $timeout = 0, int $offset = 0): SpeechRecognizeResponse|array
    {
        return $this->evaluate('SPEECH RECOGNIZE %s %d %d', $prompt, $timeout, $offset);
    }

    /**
     * Sets a speech engine setting
     * Set an engine-specific setting
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_set/
     *
     * @param string $name
     * @param string $value
     * @return Response|AgiArray
     */
    public function speech_set(string $name, string $value): Response|array
    {
        return $this->evaluate('SPEECH SET %s %s', $name, $value);
    }

    /**
     * Destroys a speech object
     * Destroy the speech object created by SPEECH CREATE
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_destroy/
     *
     * @return Response|AgiArray
     */
    public function speech_destroy(): Response|array
    {
        return $this->evaluate('SPEECH DESTROY');
    }

    /**
     * Loads a grammar
     * Loads the specified grammar as the specified name
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_load_grammar/
     *
     * @param string $grammar_name
     * @param string $path_to_grammar
     * @return Response|AgiArray
     */
    public function speech_load_grammar(string $grammar_name, string $path_to_grammar): Response|array
    {
        return $this->evaluate('SPEECH LOAD GRAMMAR %s %s', $grammar_name, $path_to_grammar);
    }

    /**
     * Unloads a grammar
     * Unloads the specified grammar
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/speech_unload_grammar/
     *
     * @param string $grammar_name
     * @return Response|array
     */
    public function speech_unload_grammar(string $grammar_name): Response|array
    {
        return $this->evaluate('SPEECH UNLOAD GRAMMAR %s', $grammar_name);
    }

    /**
     * Sends audio file on channel
     * Send the given file, allowing playback to be interrupted by the given digits, if any. If musiconhold is
     * playing before calling stream file it will be automatically stopped and will not be restarted after completion.
     * It sets the following channel variables upon completion:
     * - PLAYBACKSTATUS The status of the playback attempt as a text string "SUCCESS" or "FAILED"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/stream_file/
     *
     * @param string $filename File name to play. The file extension must not be included in the filename
     * @param string $escape_digits Use double quotes for the digits if you wish none to be permitted
     * @param int $sample_offset If sample offset is provided then the audio will seek to sample offset before play starts
     * @return Response|AgiArray `result` is 0 if playback completes without a digit being pressed, the ASCII
     * numerical value of the digit if one was pressed, or -1 on error or if the channel was disconnected
     */
    public function stream_file(string $filename, string $escape_digits = '', int $sample_offset = 0): Response|array
    {
        return $this->evaluate('STREAM FILE %s %s %d', $filename, $escape_digits, $sample_offset);
    }

    /**
     * Toggles TDD mode (for the deaf)
     * Enable/Disable TDD transmission/reception on a channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/tdd_mode/
     *
     * @param bool $setting
     * @return Response|AgiArray `result` is 1 if sucessful, or 0 if the channel is not TDD-capable
     */
    public function tdd_mode(bool $setting): Response|array
    {
        return $this->evaluate('TDD MODE %s', $setting ? "on" : "off");
    }

    /**
     * Logs a message to the asterisk verbose log
     * Sends message to the console via verbose message system. level is the verbose level (1-4)
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/verbose/
     *
     * @param string $message
     * @param int<1,4> $level
     * @return Response|AgiArray Always `result` is 1
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
            $ret = $this->evaluate('VERBOSE %s %d', $msg, $level);
        }

        return $ret;
    }

    /**
     * Waits for a digit to be pressed
     * Waits up to timeout milliseconds for channel to receive a DTMF digit.
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AGI_Commands/wait_for_digit/
     *
     * @param int $timeout Use -1 for the timeout value if you desire the call to block indefinitely
     * @return Response|AgiArray `result` is -1 on channel failure, 0 if no digit is receive in the timeout, or the
     * numerical value of the ascii digit if one is received.
     */
    public function wait_for_digit(int $timeout = -1): Response|array
    {
        return $this->evaluate('WAIT FOR DIGIT %d', $timeout);
    }
}
