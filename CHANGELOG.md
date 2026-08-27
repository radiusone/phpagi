This file documents *potentially breaking* changes to method signatures
in major versions of the package.

## Version 4
### AGI
<dl>
    <dt><code>AGI::$request</code></dt>
    <dd>
        This is now an object of type `PhpAgi\AGI\Request`, instead
        of an array. All properties are public, and maintain the same
        names as the previous array keys.
    </dd>
</dl>

### AMI
<dl>
    <dt><code>AMI::ActionID</code></dt>
    <dd>
        Generated AMI action IDs still start with an uppercase 'A' but
        this is now followed by 16 hexadecimal characters, instead of
        6 digits.
    </dd>
    <dt><code>AMI::BlindTransfer(string $Channel, ?string $Context = null, ?string $Exten = null)</code></dt>
    <dd>
        The <code>$Context</code> and <code>$Extension</code> parameters
        have changed places. The <code>$ActionID</code> parameter has been
        removed.
    </dd>
    <dt><code>AMI::BridgeTechnologySuspend(string $BridgeTechnology, ?string $ActionID = null)</code></dt>
    <dt><code>AMI::BridgeTechnologyUnsuspend(string $BridgeTechnology, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$BridgeTechnology</code> parameter has been added.
    </dd>
    <dt><code>AMI::Challenge(string $AuthType, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$AuthType</code> parameter no longer defaults to <code>'MD5'</code>.
    </dd>
    <dt><code>AMI::CoreShowChannelMap(?string $Channel = null)</code></dt>
    <dd>
        The <code>$Channel</code> parameter has replaced the
        <code>$ActionID</code> parameter.
    </dd>
    <dt><code>AMI::DAHDIShowStatus()</code></dt>
    <dt><code>AMI::IAXnetstats()</code></dt>
    <dd>
        The <code>$ActionID</code> parameter has been removed.
    </dd>
    <dt><code>AMI::Login(string $Username, ?string $AuthType = null, ?string $Secret = null, ?string $Key = null, ?string $Events = null, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$AuthType</code>, <code>$Key</code>, and
        <code>$Events</code> parameters have been added.
    </dd>
    <dt><code>AMI::Originate(string $Channel, ?string $Exten = null, ?string $Context = null, ?string $Priority = null, ?string $Application = null, ?string $Data = null, int|string|null $Timeout = null, ?string $CallerID = null, ?string $Variable = null, ?string $Account = null, ?bool $EarlyMedia = null, ?bool $Async = null, ?string $Codecs = null, ?string $ChannelId = null, ?string $OtherChannelId = null, ?string $PreDialGoSub = null, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$Channel</code> parameter is no longer optional, the
        <code>$Async</code> and <code>$EarlyMedial</code> parameters have
        swapped places, and the <code>$PreDialGoSub</code> parameter has
        been added.
    </dd>
    <dt><code>AMI::PJSIPHangup(string $Channel, int|string $Cause, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$Cause</code> parameter is no longer optional.
    </dd>
    <dt><code>QueueAdd(string $Queue, string $Interface, int|string|null $Penalty = null, ?bool $Paused = null, ?string $Reason = null, ?string $MemberName = null, ?string $StateInterface = null, ?string $ActionID = null)</code></dt>
    <dd>
        The <code>$Reason</code> parameter has been added, and many of
        the remaining parameters have had their order changed.
    </dd>
    <dt><code>AMI::ChangeMonitor()</code></dt>
    <dt><code>AMI::Monitor()</code></dt>
    <dt><code>AMI::StopMonitor()</code></dt>
    <dd>
        These methods have been removed. Instead use <code>AMI::MixMonitor()</code> and <code>AMI::StopMixMonitor()</code>
    </dd>
</dl>

## Version 3
### AGI
<dl>
    <dt>Namespace</dt>
    <dd>
        Formerly <code>\AGI</code>, this class is now <code>\PhpAgi\AGI</code>.
    </dd>
    <dt><code>AGI::evaluate(string $command, ...$args)</code></dt>
    <dd>
        The value of the <code>code</code> element of the return array
        will now always be an integer; the values of all other elements
        will always be strings. This method is used for the return of all
        AGI command methods.
    </dd>
    <dt><code>AGI::exec(string $application, array $args)</code></dt>
    <dd>
        Previously, <code>$args</code> could be a string or an array. Now,
        only an array can be passed.
    </dd>
</dl>

### AMI
<dl>
    <dt>Namespace</dt>
    <dd>
        Formerly <code>\AGI_AsteriskManager</code>, this class is now
        <code>\PhpAgi\AMI</code>.
    </dd>
    <dt><code>AMI::Atxfer(string $Channel, string $Exten, string $Context, string $ActionID = null)</code></dt>
    <dd>
        The <code>$Priority</code> parameter is not supported by Asterisk
        and has been removed.
    </dd>
    <dt><code>AMI::Getvar(string $Variable, string $Channel = null, string $ActionID = null)</code></dt>
    <dt><code>AMI::Setvar(string $Variable, string $Value, string $Channel = null, string $ActionID = null)</code></dt>
    <dd>
        The <code>$Variable</code> and <code>$Channel</code> parameters have
        swapped places, as the channel is now optional. If omitted, a global
        variable will instead be returned or set.
    </dd>
    <dt><code>AMI::Originate(string $Channel, string $Exten = null, string $Context = null, string $Priority = null, string $Application = null, string $Data = null, $Timeout = 0, string $Variable = null, string $Account = null, bool $Async = false, string $ActionID = null, bool $EarlyMedia = false, string $Codecs = null, string $ChannelId = null, string $OtherChannelId = null, string $CallerID = null)</code></dt>
    <dt><code>AMI::ParkedCalls(string $ParkingLot = null, string $ActionID = null)</code></dt>
    <dt><code>AMI::QueueStatus(string $Queue = null, string $Member = null, string $ActionID = null)</code></dt>
    <dt><code>AMI::Status(string $Channel = null, string $Variables = null, bool $AllVariables = false, string $ActionID = null)</code></dt>
    <dd>
        One or more additional parameters were added to match updates to the
        AMI commands. For consistency with other methods, the new parameters
        were inserted, keeping <code>$ActionID</code> as the last parameter.
    </dd>
    <dt><code>AMI::Redirect(string $Channel, string $Exten, string $Context, string $Priority, string $ExtraChannel = null, string $ExtraExten = null, string $ExtraContext = null, string $ExtraPriority = null, string $ActionID = null)</code></dt>
    <dd>
        The parameters of this method have been extensively changed to
        reflect that the <code>$ExtraChannel</code> parameter is optional,
        and to allow the setting of other optional <code>$Extra*</code>
        values.
    </dd>
</dl>
