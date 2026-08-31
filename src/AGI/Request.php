<?php

namespace PhpAgi\AGI;

use ArrayAccess;
use InvalidArgumentException;
use LogicException;

/**
 * @implements ArrayAccess<string,array<positive-int,string>|string|null>
 */
class Request implements ArrayAccess
{
    /**
     * @param string|null $agi_request name of agi script
     * @param string|null $agi_channel current channel
     * @param string|null $agi_language current language
     * @param string|null $agi_type channel type (SIP, ZAP, IAX, ...)
     * @param string|null $agi_uniqueid unique id based on unix time
     * @param string|null $agi_version the Asterisk version
     * @param string|null $agi_callerid caller ID number
     * @param string|null $agi_calleridname caller ID name
     * @param string|null $agi_callingpres
     * @param string|null $agi_callingani2
     * @param string|null $agi_callington
     * @param string|null $agi_callingtns
     * @param string|null $agi_dnid dialed number id
     * @param string|null $agi_rdnis referring DNIS number
     * @param string|null $agi_context current dialplan context
     * @param string|null $agi_extension current dialplan extension
     * @param string|null $agi_priority current dialplan priority
     * @param string|null $agi_enhanced value is 1.0 if started as an EAGI script, 0.0 otherwise
     * @param string|null $agi_accountcode set by SetAccount in the dialplan
     * @param string|null $agi_threadid
     * @param string|null $agi_network value is yes if this is a fastagi
     * @param string|null $agi_network_script name of the script to execute
     * @param array<positive-int,string> $agi_args arguments passed to the AGI script
     */
    public function __construct(
        public ?string $agi_request = null,
        public ?string $agi_channel = null,
        public ?string $agi_language = null,
        public ?string $agi_type = null,
        public ?string $agi_uniqueid = null,
        public ?string $agi_version = null,
        public ?string $agi_callerid = null,
        public ?string $agi_calleridname = null,
        public ?string $agi_callingpres = null,
        public ?string $agi_callingani2 = null,
        public ?string $agi_callington = null,
        public ?string $agi_callingtns = null,
        public ?string $agi_dnid = null,
        public ?string $agi_rdnis = null,
        public ?string $agi_context = null,
        public ?string $agi_extension = null,
        public ?string $agi_priority = null,
        public ?string $agi_enhanced = null,
        public ?string $agi_accountcode = null,
        public ?string $agi_threadid = null,
        public ?string $agi_network = null,
        public ?string $agi_network_script = null,
        public array $agi_args = [],
    )
    {
    }

    /**
     * @param resource $input
     */
    public static function fromInput($input): self
    {
        $req = new self();
        $str = fgets($input);
        while ($str && $str !== "\n") {
            [$key, $val] = explode(':', $str, 2);
            $key = trim($key);
            if (preg_match('/^agi_arg_(\d+)$/', $key, $match)) {
                $i = max(1, (int)$match[1]);
                $req->agi_args[$i] = trim($val);
            } elseif (property_exists($req, $key)) {
                $req->$key = trim($val);
            }
            $str = fgets($input);
        }

        return $req;
    }

    public function offsetExists(mixed $offset): bool
    {
        return property_exists($this, $offset);
    }

    /**
     * @return ($offset is 'agi_args' ? array<positive-int,string> : string|null)
     */
    public function offsetGet(mixed $offset): mixed
    {
        return property_exists($this, $offset)
            ? $this->$offset
            : throw new InvalidArgumentException();
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException();
    }
}
