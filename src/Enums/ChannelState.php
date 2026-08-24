<?php

namespace PhpAgi\Enums;

enum ChannelState: int
{
    case NotFound = -1;
    case Down = 0;
    case Reserved = 1;
    case OffHook = 2;
    case Dialing = 3;
    case Ring = 4;
    case Ringing = 5;
    case Up = 6;
    case Busy = 7;
    case DialingOffHook = 8;
    case PreRing = 9;

    public function label(): string
    {
        return match($this) {
            self::NotFound => 'No channel name matched the argument given',
            self::Down => 'Channel is down and available',
            self::Reserved => 'Channel is down, but reserved',
            self::OffHook => 'Channel is off hook',
            self::Dialing => 'Digits (or equivalent) have been dialed',
            self::Ring => 'Remote end is ringing (e.g. listening to audible ringback tone). Also often used for initial state for a new channel.',
            self::Ringing => 'Line is ringing',
            self::Up => 'Line is up',
            self::Busy => 'Line is busy',
            self::DialingOffHook => 'Digits (or equivalent) have been dialed while offhook',
            self::PreRing => 'Channel has detected an incoming call and is waiting for ring',
        };
    }
}
