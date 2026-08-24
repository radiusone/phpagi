<?php

namespace PhpAgi\Enums;

enum AgiResult: int
{
    /** AGI success result code */
    case Ok = 200;
    /** AGI general error result code */
    case Error = 500;
    /** AGI unknown command result code */
    case BadCommand = 510;
    /** AGI hangup dead channel result code */
    case BadHangup = 511;
    /** AGI invalid syntax result code */
    case Invalid = 520;
}
