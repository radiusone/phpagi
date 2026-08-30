<?php

namespace PhpAgi\AMI;

/**
 * @phpstan-type AmiArray array{Response?:string, Message?:string, Output?:string, ActionID?:string, EventList?:string, events?:list<array<string,mixed>>, ...<string,string>}
 */
trait Actions
{
    /**
     * Set absolute timeout
     * Hangup a channel after a certain time. Acknowledges set time with Timeout Set message
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AbsoluteTimeout/
     *
     * @param string $Channel Channel name to hangup
     * @param int|numeric-string $Timeout Maximum duration of the call (sec)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
     */
    public function AgentLogoff(string $Agent, ?bool $Soft = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists agents and their status
     * Will list info about all defined agents
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Agents/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Agents(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Add an AGI command to execute by Async AGI
     * Add an AGI command to the execute queue of the channel in Async AGI
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AGI/
     *
     * @param string $Channel Channel that is currently in Async AGI
     * @param string $Command Application to execute
     * @param string|null $CommandID This will be sent back in CommandID header of AsyncAGI exec event notification
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function AGI(string $Channel, string $Command, ?string $CommandID = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Generate an Advice of Charge message on a channel
     * Generates an AOC-S, AOC-D or AOC-E message on a channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/AOCMessage/
     *
     * @param 'S'|'D'|'E' $MsgType Defines what type of AOC message to create, AOC-S, AOC-D or AOC-E
     * @param string|null $Channel Channel name to generate the AOC message on. This value is required unless ChannelPrefix is given
     * @param string|null $ChannelPrefix Partial channel prefix. By using this option one can match the beginning part of a channel
     * name without having to put the entire name in. For example if a channel name is SIP/snom-00000001 and this value is set
     * to SIP/snom, then that channel matches and the message will be sent. Note however that only the first matched channel
     * has the message sent on it
     * @param 'NA'|'FREE'|'Currency'|'Unit'|null $ChargeType Defines what kind of charge this message represents for AOC-D and AOC-E
     * @param string|null $UnitAmount This represents the amount of units charged. The ETSI AOC standard specifies that this value
     * along with the optional UnitType value are entries in a list. To accommodate this these values take an index value
     * starting at 0 which can be used to generate this list of unit entries. For example, If two unit entries were required
     * this could be achieved by setting the parameter UnitAmount(0)=1234 and UnitAmount(1)=5678. Note that UnitAmount at index
     * 0 is required when ChargeType=Unit, all other entries in the list are optional
     * @param string|null $UnitType Defines the type of unit. ETSI AOC standard specifies this as an integer value between 1 and 16,
     * but this value is left open to accept any positive integer. Like the UnitAmount parameter, this value represents a list
     * entry and has an index parameter that starts at 0
     * @param string|null $CurrencyName Specifies the currency's name. Note that this value is truncated after 10 characters
     * @param int|numeric-string|null $CurrencyAmount Specifies the charge unit amount as a positive integer. This value is required
     * when ChargeType==Currency (AOC-D or AOC-E) or RateType==Duration/Flat/Volume (AOC-S)
     * @param 'OneThousandth'|'OneHundredth'|'OneTenth'|'One'|'Ten'|'Hundred'|'Thousand'|null $CurrencyMultiplier Specifies the
     * currency multiplier. This value is required when CurrencyAmount is given
     * @param 'Total'|'SubTotal'|null $TotalType Defines what kind of AOC-D total is represented
     * @param 'Normal'|'ReverseCharge'|'CreditCard'|'CallFwdUnconditional'|'CallFwdBusy'|'CallFwdNoReply'|'CallDeflection'|'CallTransfer'|null $AOCBillingId
     * Represents a billing ID associated with an AOC-D or AOC-E message. Note that only the first 3 items of the enum are
     * valid AOC-D billing IDs
     * @param string|null $ChargingAssociationId Charging association identifier. This is optional for AOC-E and can be set to any
     * value between -32768 and 32767
     * @param string|null $ChargingAssociationNumber Represents the charging association party number. This value is optional for
     * AOC-E
     * @param string|null $ChargingAssociationPlan Integer representing the charging plan associated with the
     * ChargingAssociationNumber. The value is bits 7 through 1 of the Q.931 octet containing the type-of-number and
     * numbering-plan-identification fields
     * @param 'NA'|'SpecialArrangement'|'BasicCommunication'|'CallAttempt'|'CallSetup'|'UserUserInfo'|'SupplementaryService'|null $ChargedItem
     * Defines what part of the call is charged in AOC-S. Usually this is set to BasicCommunication, which refers to the time
     * after the call is answered, but establishment (CallAttempt) or successful establishment (CallSetup) of a call can also
     * be used. Other options are available, but these generally do not carry enough information to actually calculate the
     * price of a call. It is possible to have multiple ChargedItem entries for a single call -- for example to charge for both
     * the establishment of the call and the actual call. In this case, each ChargedItem is described by a ChargedItem: header
     * and all other headers that follow it up to the next ChargedItem: header
     * @param 'NA'|'Free'|'FreeFromBeginning'|'Duration'|'Flat'|'Volume'|'SpecialCode'|null $RateType Defines how an AOC-S ChargedItem
     * is charged. The Duration option is only available when ChargedItem==BasicCommunication
     * @param string|null $Time Specifies a positive integer which is the amount of time is paid for by one CurrencyAmount. This value
     * is required when RateType==Duration
     * @param 'OneHundredthSecond'|'OneTenthSecond'|'Second'|'TenSeconds'|'Minute'|'Hour'|'Day'|null $TimeScale Specifies the time
     * multiplier. This value is required when Time is given
     * @param string|null $Granularity Specifies a positive integer which is the size of the charged time increments. This value is
     * optional when RateType==Duration and ChargingType==StepFunction
     * @param 'OneHundredthSecond'|'OneTenthSecond'|'Second'|'TenSeconds'|'Minute'|'Hour'|'Day'|null $GranularityTimeScale Specifies
     * the granularity time multiplier. This value is required when Granularity is given
     * @param 'ContinuousCharging'|'StepFunction'|null $ChargingType Specifies whether the charge increases continuously with time or
     * in increments of Time or, if provided, Granularity. This value is required when RateType==Duration
     * @param 'Octet'|'Segment'|'Message'|null $VolumeUnit Specifies the quantity of which one unit is paid for by one CurrencyAmount.
     * This value is required when RateType==Volume
     * @param int|numeric-string|null $Code Specifies the charging code, which can be set to a value between 1 and 10. This value is
     * required when ChargedItem==SpecialArrangement or RateType==SpecialCode
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function AOCMessage(
        string $MsgType,
        ?string $Channel = null,
        ?string $ChannelPrefix = null,
        ?string $ChargeType = null,
        ?string $UnitAmount = null,
        ?string $UnitType = null,
        ?string $CurrencyName = null,
        int|string|null $CurrencyAmount = null,
        ?string $CurrencyMultiplier = null,
        ?string $TotalType = null,
        ?string $AOCBillingId = null,
        ?string $ChargingAssociationId = null,
        ?string $ChargingAssociationNumber = null,
        ?string $ChargingAssociationPlan = null,
        ?string $ChargedItem = null,
        ?string $RateType = null,
        ?string $Time = null,
        ?string $TimeScale = null,
        ?string $Granularity = null,
        ?string $GranularityTimeScale = null,
        ?string $ChargingType = null,
        ?string $VolumeUnit = null,
        int|string|null $Code = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Attended transfer
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Atxfer/
     *
     * @param string $Channel Transferer's channel
     * @param string $Exten Extension to transfer to
     * @param string|null $Context Context to transfer to
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Atxfer(string $Channel, string $Exten, ?string $Context = null, ?string $ActionID = null): array
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
     * @param string|null $Context
     * @param string|null $Exten
     * @return AmiArray
     */
    public function BlindTransfer(string $Channel, ?string $Context = null, ?string $Exten = null): array
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
     * @param 'no'|'Channel1'|'Channel2'|'Both'|null $Tone Play courtesy tone to Channel 2
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Bridge(string $Channel1, string $Channel2, ?string $Tone = null, ?string $ActionID = null): array
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
     * @return AmiArray
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
     * @return AmiArray
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
     * @param string|null $BridgeUniqueid The unique ID of the bridge containing the channel to destroy. This parameter can be
     * omitted, or supplied to insure that the channel is not removed from the wrong bridge
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
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
     * @return AmiArray
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
     * @param string $BridgeTechnology The name of the bridging technology to suspend
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function BridgeTechnologySuspend(string $BridgeTechnology, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unsuspend a bridging technology
     * Clears a previously suspended bridging technology, which allows subsequently created bridges to use it
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/BridgeTechnologyUnsuspend/
     *
     * @param string $BridgeTechnology The name of the bridging technology to unsuspend
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function BridgeTechnologyUnsuspend(string $BridgeTechnology, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Cancel an attended transfer
     * Cancel an attended transfer. Note, this uses the configured cancel attended transfer feature option (atxferabort) to
     * cancel the transfer. If not available this action will fail
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CancelAtxfer/
     *
     * @param string $Channel The transferer channel
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @param 'MD5' $AuthType Digest algorithm to use in the challenge. Valid values are:
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Challenge(string $AuthType, ?string $ActionID = null): array
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
     * @return AmiArray
     */
    public function Command(string $Command, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Kick a Confbridge user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeKick/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is "all", all channels will be kicked from the conference.If this parameter is
     * "participants", all non-admin channels will be kicked from the conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeKick(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List participants in a conference
     * Lists all users in a particular ConfBridge conference. ConfbridgeList will follow as separate events, followed by a
     * final event called ConfbridgeListComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeList/
     *
     * @param string $Conference Conference number
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeList(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List active conferences
     * Lists data about all active conferences. ConfbridgeListRooms will follow as separate events, followed by a final event
     * called ConfbridgeListRoomsComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeListRooms/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeListRooms(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lock a Confbridge conference
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeLock/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeLock(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mute a Confbridge user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeMute/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.If
     * this parameter is "all", all channels will be muted.If this parameter is "participants", all non-admin channels will be
     * muted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeMute(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set a conference user as the single video source distributed to all other participants
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeSetSingleVideoSrc/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeSetSingleVideoSrc(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Start recording a Confbridge conference
     * Start recording a conference. If recording is already present an error will be returned. If RecordFile is not
     * provided, the default record file specified in the conference's bridge profile will be used, if that is not present either a
     * file will automatically be generated in the monitor directory
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeStartRecord/
     *
     * @param string $Conference
     * @param string|null $RecordFile
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeStartRecord(string $Conference, ?string $RecordFile = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Stop recording a Confbridge conference
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeStopRecord/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeStopRecord(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unlock a Confbridge conference
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnlock/
     *
     * @param string $Conference
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeUnlock(string $Conference, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unmute a Confbridge user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ConfbridgeUnmute/
     *
     * @param string $Conference
     * @param string $Channel If this parameter is not a complete channel name, the first channel with this prefix will be used.If
     * this parameter is "all", all channels will be unmuted.If this parameter is "participants", all non-admin channels will
     * be unmuted
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ConfbridgeUnmute(string $Conference, string $Channel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Control the playback of a file being played to a channel
     * Control the operation of a media file being played back to a channel. Note that this AMI action does not initiate
     * playback of media to channel, but rather controls the operation of a media operation that was already initiated on the
     * channel. The pause and restart Control options will stop a playback operation if that operation was not initiated from the
     * ControlPlayback application or the control stream file AGI command
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ControlPlayback/
     *
     * @param string $Channel The name of the channel that currently has a file being played back to it
     * @param 'stop'|'forward'|'reverse'|'pause'|'restart' $Control
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
     */
    public function CoreSettings(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List all channels connected to the specified channel
     * List all channels currently connected to the specified channel. This can be any channel, including Local channels, and
     * Local channels will be followed through to their other half
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CoreShowChannelMap/
     *
     * @param string|null $Channel The channel to get the mapping for. Requires a channel name
     * @return AmiArray
     */
    public function CoreShowChannelMap(?string $Channel = null): array
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
     * @return AmiArray
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
     * @return AmiArray
     */
    public function CoreStatus(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Creates an empty file in the configuration directory
     * This action will create an empty file in the configuration directory. This action is intended to be used before an
     * UpdateConfig action
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/CreateConfig/
     *
     * @param string $Filename The configuration filename to create (e.g. foo.conf)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function CreateConfig(string $Filename, ?string $ActionID = null): array
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
     * @return AmiArray
     */
    public function DAHDIDialOffhook(string $DAHDIChannel, string $Number, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status OFF
     * Equivalent to the CLI command "dahdi set dnd channel off". Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIDNDoff/
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND off
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DAHDIDNDoff(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Toggle DAHDI channel Do Not Disturb status ON
     * Equivalent to the CLI command "dahdi set dnd channel on". Feature only supported by analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIDNDon/
     *
     * @param string $DAHDIChannel DAHDI channel number to set DND on
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DAHDIDNDon(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Hangup DAHDI Channel
     * Simulate an on-hook event by the user connected to the channel. Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDIHangup/
     *
     * @param string $DAHDIChannel DAHDI channel number to hangup
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
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
     * @return AmiArray
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
     * @return AmiArray
     */
    public function DAHDIShowStatus(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Transfer DAHDI Channel
     * Simulate a flash hook event by the user connected to the channel. Valid only for analog channels
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DAHDITransfer/
     *
     * @param string $DAHDIChannel DAHDI channel number to transfer
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DAHDITransfer(string $DAHDIChannel, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Delete DB entry
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBDel/
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DBDel(string $Family, string $Key, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Delete DB Tree
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBDelTree/
     *
     * @param string $Family
     * @param string|null $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DBDelTree(string $Family, ?string $Key = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get DB Entry
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBGet/
     *
     * @param string $Family
     * @param string $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DBGet(string $Family, string $Key, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get DB entries, optionally at a particular family/key
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBGetTree/
     *
     * @param string|null $Family
     * @param string|null $Key
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DBGetTree(?string $Family = null, ?string $Key = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Put DB entry
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DBPut/
     *
     * @param string $Family
     * @param string $Key
     * @param float|int|string|null $Val
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DBPut(string $Family, string $Key, float|int|string|null $Val = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List the current known device states
     * This will list out all known device states in a sequence of DeviceStateChange events. When finished, a
     * DeviceStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DeviceStateList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DeviceStateList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Add an extension to the dialplan
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DialplanExtensionAdd/
     *
     * @param string $Context Context where the extension will be created. The context will be created if it does not already exist
     * @param string $Extension Name of the extension that will be created (may include callerid match by separating with '/')
     * @param int|string $Priority Priority being added to this extension. Must be either hint or a numerical value
     * @param string $Application The application to use for this extension at the requested priority
     * @param string|null $ApplicationData Arguments to the application
     * @param bool|null $Replace If set to 'yes', '1', 'true' or any of the other values we evaluate as true, then if an extension
     * already exists at the requested context, extension, and priority it will be overwritten. Otherwise, the existing
     * extension will remain and the action will fail
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DialplanExtensionAdd(
        string $Context,
        string $Extension,
        int|string $Priority,
        string $Application,
        ?string $ApplicationData = null,
        ?bool $Replace = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Remove an extension from the dialplan
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/DialplanExtensionRemove/
     *
     * @param string $Context Context of the extension being removed
     * @param string $Extension Name of the extension being removed (may include callerid match by separating with '/')
     * @param int|string|null $Priority If provided, only remove this priority from the extension instead of all priorities in the
     * extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function DialplanExtensionRemove(string $Context, string $Extension, int|string|null $Priority = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Control Event Flow
     * Enable/Disable sending of events to this manager client
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Events/
     *
     * @param 'on'|'off'|'system,call,log,...' $EventMask
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Events(string $EventMask, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check Extension Status
     * Report the extension state for given extension. If the extension has a hint, will use devicestate to check the status
     * of the device connected to the extension. Will return an Extension Status message. The response will include the hint for the
     * extension and the status
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ExtensionState/
     *
     * @param string $Exten Extension to check state on
     * @param string $Context Context for extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ExtensionState(string $Exten, string $Context, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List the current known extension states
     * This will list out all known extension states in a sequence of ExtensionStatus events. When finished, a
     * ExtensionStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ExtensionStateList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ExtensionStateList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Responds with a detailed description of a single FAX session
     * Provides details about a specific FAX session. The response will include a common subset of the output from the CLI
     * command 'fax show session <session_number>' for each technology. If the FAX technolgy used by this session does not include a
     * handler for FAXSession, then this action will fail
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/FAXSession/
     *
     * @param string $SessionNumber The session ID of the fax the user is interested in
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function FAXSession(string $SessionNumber, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists active FAX sessions
     * Will generate a series of FAXSession events with information about each FAXSession. Closes with a FAXSessionsComplete
     * event which includes a count of the included FAX sessions. This action works in the same manner as the CLI command 'fax show
     * sessions'
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/FAXSessions/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function FAXSessions(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Responds with fax statistics
     * Provides FAX statistics including the number of active sessions, reserved sessions, completed sessions, failed
     * sessions, and the number of receive/transmit attempts. This command provides all of the non-technology specific information
     * provided by the CLI command 'fax show stats'
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/FAXStats/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function FAXStats(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Dynamically add filters for the current manager session
     * See the manager.conf.sample file in the configs/samples directory of the Asterisk source tree for a full description
     * and examples. The filters added are only used for the current session. Once the connection is closed the filters are removed.
     * This command requires the system permission because this command can be used to create filters that may bypass filters
     * defined in manager.conf
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Filter/
     *
     * @param 'Add'|null $Operation
     * @param string|null $MatchCriteria Advanced match criteria. If not specified, the Filter parameter is assumed to be a regular
     * expression and will be matched against the entire event payload.Syntax:
     * [name(<event_name>)][,header(<header_name>)][,<match_method>]One of each of the following may be specified separated by
     * commas
     * @param string|null $Filter The match expression to be applied to the event.See the manager.conf.sample file in the
     * configs/samples directory of the Asterisk source tree for more information
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Filter(
        ?string $Operation = null,
        ?string $MatchCriteria = null,
        ?string $Filter = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Retrieve configuration
     * This action will dump the contents of a configuration file by category and contents or optionally by specified
     * category only. In the case where a category name is non-unique, a filter may be specified to match only categories with
     * matching variable values
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/GetConfig/
     *
     * @param string $Filename Configuration filename (e.g. foo.conf)
     * @param string|null $Category Category in configuration file
     * @param string|null $Filter A comma separated list of name_regex=value_regex expressions which will cause only categories whose
     * variables match all expressions to be considered. The special variable name TEMPLATES can be used to control whether
     * templates are included. Passing include as the value will include templates along with normal categories. Passing
     * restrict as the value will restrict the operation to ONLY templates. Not specifying a TEMPLATES expression results in
     * the default behavior which is to not include templates
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function GetConfig(string $Filename, ?string $Category = null, ?string $Filter = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Retrieve configuration (JSON format)
     * This action will dump the contents of a configuration file by category and contents in JSON format or optionally by
     * specified category only. This only makes sense to be used using rawman over the HTTP interface. In the case where a category
     * name is non-unique, a filter may be specified to match only categories with matching variable values
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/GetConfigJSON/
     *
     * @param string $Filename Configuration filename (e.g. foo.conf)
     * @param string|null $Category Category in configuration file
     * @param string|null $Filter A comma separated list of name_regex=value_regex expressions which will cause only categories whose
     * variables match all expressions to be considered. The special variable name TEMPLATES can be used to control whether
     * templates are included. Passing include as the value will include templates along with normal categories. Passing
     * restrict as the value will restrict the operation to ONLY templates. Not specifying a TEMPLATES expression results in
     * the default behavior which is to not include templates
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function GetConfigJSON(string $Filename, ?string $Category = null, ?string $Filter = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Gets a channel variable or function value
     * Get the value of a channel variable or function return. If a channel name is not provided then the variable is
     * considered global
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Getvar/
     *
     * @param string $Variable Variable name, function or expression
     * @param string|null $Channel Channel to read variable from
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @param string $Channel The exact channel name to be hungup, or to use a regular expression, set this parameter to:
     * /regex/Example exact channel: SIP/provider-0000012aExample regular expression: /^SIP/provider-.*$/
     * @param int|numeric-string|null $Cause Numeric hangup cause
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
     */
    public function IAXnetstats(): array
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
     * @return AmiArray
     */
    public function IAXpeerlist(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List IAX peers
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXpeers/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function IAXpeers(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show IAX registrations
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/IAXregistry/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function IAXregistry(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Sends a message to a Jabber Client
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/JabberSend/
     *
     * @param string $Jabber Client or transport Asterisk uses to connect to JABBER
     * @param string $JID XMPP/Jabber JID (Name) of recipient
     * @param string $Message Message to be sent to the buddy
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function JabberSend(string $Jabber, string $JID, string $Message, ?string $ActionID = null): array
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
     * @return AmiArray
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
     * @return AmiArray
     */
    public function ListCommands(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Optimize away a local channel when possible
     * A local channel created with "/n" will not automatically optimize away. Calling this command on the local channel will
     * clear that flag and allow it to optimize away if it's bridged or when it becomes bridged
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/LocalOptimizeAway/
     *
     * @param string $Channel The channel name to optimize away
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
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
     * @return AmiArray
     */
    public function LoggerRotate(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Login Manager
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Login/
     *
     * @param string $Username Username to login with as specified in manager.conf
     * @param 'plain'|'MD5'|null $AuthType Authorization type. Valid values are:
     * @param string|null $Secret Plain text secret to login with as specified in manager.conf
     * @param string|null $Key Key to use with MD5 authentication. To create the key, you must initialize a new MD5 hash, call the
     * Challenge AMI action, update the hash with the response, then update the hash with the secret as specified in
     * manager.conf. The key value must be the final result of the hash as a 32 character lower-case hex string without any
     * "0x" prepended. See the description for an example of creating a key in Python
     * @param 'on'|'off'|'system,call,log,...'|null $Events
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Login(
        string $Username,
        ?string $AuthType = null,
        ?string $Secret = null,
        ?string $Key = null,
        ?string $Events = null,
        ?string $ActionID = null
    ): array
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
     * @return AmiArray
     */
    public function Logoff(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check Mailbox Message Count
     * Checks a voicemail account for new messages. Returns number of urgent, new and old messages. Message: Mailbox Message
     * Count Mailbox: mailboxid UrgentMessages: count NewMessages: count OldMessages: count
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MailboxCount/
     *
     * @param string $Mailbox Full mailbox ID mailbox@vm-context
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MailboxCount(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check mailbox
     * Checks a voicemail account for status. Returns whether there are messages waiting. Message: Mailbox Status. Mailbox:
     * mailboxid. Waiting: 0 if messages waiting, 1 if no messages waiting
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MailboxStatus/
     *
     * @param string $Mailbox Full mailbox ID mailbox@vm-context
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MailboxStatus(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List participants in a conference
     * Lists all users in a particular MeetMe conference. MeetmeList will follow as separate events, followed by a final
     * event called MeetmeListComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MeetmeList/
     *
     * @param string|null $Conference Conference number
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MeetmeList(?string $Conference = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List active conferences
     * Lists data about all active conferences. MeetmeListRooms will follow as separate events, followed by a final event
     * called MeetmeListRoomsComplete
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MeetmeListRooms/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MeetmeListRooms(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mute a Meetme user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MeetmeMute/
     *
     * @param string $Meetme
     * @param int|numeric-string $Usernum
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MeetmeMute(string $Meetme, int|string $Usernum, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unmute a Meetme user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MeetmeUnmute/
     *
     * @param string $Meetme
     * @param int|numeric-string $Usernum
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MeetmeUnmute(string $Meetme, int|string $Usernum, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Send an out of call message to an endpoint
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MessageSend/
     *
     * @param string|null $Destination A To URI for the message. If Destination is provided, the To parameter can also be supplied and
     * may alter the message based on the specified message technology.For backwards compatibility, if Destination is not
     * provided, the To parameter must be provided and will be used as the message destination
     * @param string|null $To A To URI for the message if needed for the message technology being used to send this message. This can
     * be a SIP(S) URI, such as Alice <sip:alice@atlanta.com>, or a string in the format alice@atlanta.com.This parameter is
     * required if the Destination parameter is not provided
     * @param string|null $From A From URI for the message if needed for the message technology being used to send this message
     * @param string|null $Body The message body text. This must not contain any newlines as that conflicts with the AMI protocol
     * @param string|null $Base64Body Text bodies requiring the use of newlines have to be base64 encoded in this field. Base64Body
     * will be decoded before being sent out. Base64Body takes precedence over Body
     * @param string|null $Variable Message variable to set, multiple Variable: headers are allowed. The header value is a comma
     * separated list of name=value pairs
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MessageSend(
        ?string $Destination = null,
        ?string $To = null,
        ?string $From = null,
        ?string $Body = null,
        ?string $Base64Body = null,
        ?string $Variable = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Record a call and mix the audio during the recording. Use of StopMixMonitor is required to guarantee the audio file is available for processing during dialplan execution
     * This action records the audio on the current channel to the specified file. Will contain the filename used to record
     * the mixed stream
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MixMonitor/
     *
     * @param string $Channel Used to specify the channel to record
     * @param string|null $File Is the name of the file created in the monitor spool directory. Defaults to the same name as the
     * channel (with slashes replaced with dashes). This argument is optional if you specify to record unidirectional audio
     * with either the r(filename) or t(filename) options in the options field. If neither MIXMONITOR_FILENAME or this
     * parameter is set, the mixed stream won't be recorded
     * @param string|null $Options Options that apply to the MixMonitor in the same way as they would apply if invoked from the
     * MixMonitor application. For a list of available options, see the documentation for the mixmonitor application
     * @param string|null $Command Will be executed when the recording is over. Any strings matching ^{X} will be unescaped to X. All
     * variables will be evaluated at the time MixMonitor is called
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MixMonitor(
        string $Channel,
        ?string $File = null,
        ?string $Options = null,
        ?string $Command = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mute / unMute a Mixmonitor recording
     * This action may be used to mute a MixMonitor recording
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MixMonitorMute/
     *
     * @param string $Channel Used to specify the channel to mute
     * @param string|null $Direction Which part of the recording to mute: read, write or both (from channel, to channel or both
     * channels)
     * @param bool|null $State Turn mute on or off : 1 to turn on, 0 to turn off
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MixMonitorMute(string $Channel, ?string $Direction = null, ?bool $State = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check if module is loaded
     * Checks if Asterisk module is loaded. Will return Success/Failure. An empty Version header is also returned (which
     * doesn't contain the module revision number)
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ModuleCheck/
     *
     * @param string $Module Asterisk module name (not including extension)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ModuleCheck(string $Module, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Module management
     * Loads, unloads or reloads an Asterisk module in a running system
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ModuleLoad/
     *
     * @param 'load'|'unload'|'reload'|'refresh' $LoadType The operation to be done on module. Subsystem identifiers may only be
     * reloaded.If no module is specified for a reload loadtype, all modules are reloaded
     * @param 'cdr'|'dnsmgr'|'extconfig'|'enum'|'acl'|'manager'|'http'|'logger'|'features'|'dsp'|'udptl'|'indications'|'cel'|'plc'|null $Module
     * Asterisk module name (including .so extension) or subsystem identifier:
     * @param string|null $Recursive For refresh operations, attempt to recursively unload any other modules that are dependent on
     * this module, if that would allow it to successfully unload, and load them again afterwards
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ModuleLoad(string $LoadType, ?string $Module = null, ?string $Recursive = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mute an audio stream
     * Mute an incoming or outgoing audio stream on a channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MuteAudio/
     *
     * @param string $Channel The channel you want to mute
     * @param 'in'|'out'|'all' $Direction
     * @param bool $State
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MuteAudio(string $Channel, string $Direction, bool $State, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Delete selected mailboxes
     * Delete the specified mailboxes
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MWIDelete/
     *
     * @param string $Mailbox Mailbox ID in the form of /regex/ for all mailboxes matching the regular expression. Otherwise it is for
     * a specific mailbox
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MWIDelete(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get selected mailboxes with message counts
     * Get a list of mailboxes with their message counts
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MWIGet/
     *
     * @param string $Mailbox Mailbox ID in the form of /regex/ for all mailboxes matching the regular expression. Otherwise it is for
     * a specific mailbox
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MWIGet(string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Update the mailbox message counts
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/MWIUpdate/
     *
     * @param string $Mailbox Specific mailbox ID
     * @param int|numeric-string|null $OldMessages The number of old messages in the mailbox. Defaults to zero if missing
     * @param int|numeric-string|null $NewMessages The number of new messages in the mailbox. Defaults to zero if missing
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function MWIUpdate(
        string $Mailbox,
        int|string|null $OldMessages = null,
        int|string|null $NewMessages = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Originate a call
     * Generates an outgoing call to a Extension/Context/Priority or Application/Data
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Originate/
     *
     * @param string $Channel Channel name to call
     * @param string|null $Exten Extension to use (requires Context and Priority)
     * @param string|null $Context Context to use (requires Exten and Priority)
     * @param int|string|null $Priority Priority to use (requires Exten and Context)
     * @param string|null $Application Application to execute
     * @param string|null $Data Data to use (requires Application)
     * @param int|numeric-string|null $Timeout How long to wait for call to be answered (in ms.)
     * @param string|null $CallerID Caller ID to be set on the outgoing channel
     * @param string|null $Variable Channel variable to set, multiple Variable: headers are allowed
     * @param string|null $Account Account code
     * @param bool|null $EarlyMedia Set to true to force call bridge on early media
     * @param bool|null $Async Set to true for fast origination
     * @param string|null $Codecs Comma-separated list of codecs to use for this call
     * @param string|null $ChannelId Channel UniqueId to be set on the channel
     * @param string|null $OtherChannelId Channel UniqueId to be set on the second local channel
     * @param string|null $PreDialGoSub PreDialGoSub Context,Extension,Priority to set options/headers needed before start the
     * outgoing extension
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Originate(
        string $Channel,
        ?string $Exten = null,
        ?string $Context = null,
        int|string|null $Priority = null,
        ?string $Application = null,
        ?string $Data = null,
        int|string|null $Timeout = null,
        ?string $CallerID = null,
        ?string $Variable = null,
        ?string $Account = null,
        ?bool $EarlyMedia = null,
        ?bool $Async = null,
        ?string $Codecs = null,
        ?string $ChannelId = null,
        ?string $OtherChannelId = null,
        ?string $PreDialGoSub = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Park a channel
     * Park an arbitrary channel with optional arguments for specifying the parking lot used, how long the channel should
     * remain parked, and what dial string to use as the parker if the call times out
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Park/
     *
     * @param string $Channel Channel name to park
     * @param string|null $TimeoutChannel Channel name to use when constructing the dial string that will be dialed if the parked
     * channel times out. If TimeoutChannel is in a two party bridge with Channel, then TimeoutChannel will receive an
     * announcement and be treated as having parked Channel in the same manner as the Park Call DTMF feature
     * @param string|null $AnnounceChannel If specified, then this channel will receive an announcement when Channel is parked if
     * AnnounceChannel is in a state where it can receive announcements (AnnounceChannel must be bridged). AnnounceChannel has
     * no bearing on the actual state of the parked call
     * @param int|numeric-string|null $Timeout Overrides the timeout of the parking lot for this park action. Specified in
     * milliseconds, but will be converted to seconds. Use a value of 0 to disable the timeout
     * @param string|null $Parkinglot The parking lot to use when parking the channel
     * @param string|null $ParkingSpace The parking space extension in the parking lot. If the space is already in use then execution
     * will continue at the next priority
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Park(
        string $Channel,
        ?string $TimeoutChannel = null,
        ?string $AnnounceChannel = null,
        int|string|null $Timeout = null,
        ?string $Parkinglot = null,
        ?string $ParkingSpace = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List parked calls
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ParkedCalls/
     *
     * @param string|null $ParkingLot If specified, only show parked calls from the parking lot with this name
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ParkedCalls(?string $ParkingLot = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Get a list of parking lots
     * List all parking lots as a series of AMI events
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Parkinglots/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Parkinglots(?string $ActionID = null): array
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
     * @return AmiArray
     */
    public function Ping(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Hangup an incoming PJSIP channel with a SIP response code
     * Hangs up an incoming PJSIP channel and returns the specified SIP response code in the final response to the caller. 
     * This function must be called BEFORE anything that might cause any other final (non 1XX) response to be sent. For example
     * calling Answer() or Playback without the noanswer option will cause the call to be answered and a final 200 response to be
     * sent.  The cause code set on the channel will be translated to a standard ISDN cause code using the table defined in
     * ast_sip_hangup_sip2cause() in res_pjsip.c
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPHangup/
     *
     * @param string $Channel The exact channel name to be hungup, or to use a regular expression, set this parameter to:
     * /regex/Example exact channel: SIP/provider-0000012aExample regular expression: /^SIP/provider-.*$/
     * @param int|numeric-string $Cause May be one of
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPHangup(string $Channel, int|string $Cause, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Send a NOTIFY to either an endpoint, an arbitrary URI, or inside a SIP dialog
     * Sends a NOTIFY to an endpoint, an arbitrary URI, or inside a SIP dialog. All parameters for this event must be
     * specified in the body of this request via multiple Variable: name=value sequences. One (and only one) of Endpoint, URI, or
     * Channel must be specified. If URI is used, the default outbound endpoint will be used to send the message. If the default
     * outbound endpoint isn't configured, this command can not send to an arbitrary URI
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPNotify/
     *
     * @param string|null $Endpoint The endpoint to which to send the NOTIFY
     * @param string|null $URI Abritrary URI to which to send the NOTIFY
     * @param string|null $channel Channel name to send the NOTIFY. Must be a PJSIP channel
     * @param string|null $Option The config section name from pjsip_notify.conf to use.One of Option or Variable must be specified
     * @param string|null $Variable Appends variables as headers/content to the NOTIFY. If the variable is named Content, then the
     * value will compose the body of the message if another variable sets Content-Type. name=valueOne of Option or Variable
     * must be specified
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPNotify(
        ?string $Endpoint = null,
        ?string $URI = null,
        ?string $channel = null,
        ?string $Option = null,
        ?string $Variable = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Qualify a chan_pjsip endpoint
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPQualify/
     *
     * @param string $Endpoint The endpoint you want to qualify
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPQualify(string $Endpoint, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Register an outbound registration
     * Unregisters the specified (or all) outbound registration(s) then starts registration and schedules re-registrations
     * according to configuration
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPRegister/
     *
     * @param string $Registration The outbound registration to register or '*all' to register them all
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPRegister(string $Registration, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Enable or disable maintenance mode for a PJSIP endpoint
     * Enables or disables maintenance mode for the specified PJSIP endpoint. While in maintenance mode, new inbound
     * out-of-dialog requests are rejected with 503 Service Unavailable (except SUBSCRIBE/REGISTER with Expires: 0), and outbound
     * originations via Dial() or ARI are refused before any SIP session or channel is created. In-progress dialogs are unaffected.
     * A PJSIPMaintenanceStatus event is emitted when the state changes
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPSetMaintenance/
     *
     * @param string $Endpoint The PJSIP endpoint name, or all to toggle maintenance mode for every configured endpoint
     * @param bool $State Desired maintenance state
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPSetMaintenance(string $Endpoint, bool $State, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP AORs
     * Provides a listing of all AORs. For each AOR an AorList event is raised that contains relevant attributes and status
     * information. Once all aors have been listed an AorListComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowAors/
     *
     * @return AmiArray
     */
    public function PJSIPShowAors(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP Auths
     * Provides a listing of all Auths. For each Auth an AuthList event is raised that contains relevant attributes and
     * status information. Once all auths have been listed an AuthListComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowAuths/
     *
     * @return AmiArray
     */
    public function PJSIPShowAuths(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP Contacts
     * Provides a listing of all Contacts. For each Contact a ContactList event is raised that contains relevant attributes
     * and status information. Once all contacts have been listed a ContactListComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowContacts/
     *
     * @return AmiArray
     */
    public function PJSIPShowContacts(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Detail listing of an endpoint and its objects
     * Provides a detailed listing of options for a given endpoint. Events are issued showing the configuration and status of
     * the endpoint and associated objects. These events include EndpointDetail, AorDetail, AuthDetail, TransportDetail, and
     * IdentifyDetail. Some events may be listed multiple times if multiple objects are associated (for instance AoRs). Once all
     * detail events have been raised a final EndpointDetailComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowEndpoint/
     *
     * @param string $Endpoint The endpoint to list
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPShowEndpoint(string $Endpoint, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP endpoints
     * Provides a listing of all endpoints. For each endpoint an EndpointList event is raised that contains relevant
     * attributes and status information. Once all endpoints have been listed an EndpointListComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowEndpoints/
     *
     * @return AmiArray
     */
    public function PJSIPShowEndpoints(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show maintenance mode status for PJSIP endpoints
     * Emits one PJSIPMaintenanceStatus event per result, followed by a PJSIPMaintenanceStatusComplete event
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowMaintenance/
     *
     * @param string|null $Endpoint If specified, show the status for this endpoint only. If omitted, list all endpoints currently in
     * maintenance mode
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPShowMaintenance(?string $Endpoint = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists ContactStatuses for PJSIP inbound registrations
     * In response, ContactStatusDetail events showing status information are raised for each inbound registration (dynamic
     * contact) object. Once all events are completed a ContactStatusDetailComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowRegistrationInboundContactStatuses/
     *
     * @return AmiArray
     */
    public function PJSIPShowRegistrationInboundContactStatuses(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP inbound registrations
     * In response, InboundRegistrationDetail events showing configuration and status information are raised for all
     * contacts, static or dynamic. Once all events are completed an InboundRegistrationDetailComplete is issued. This command just
     * dumps all coonfigured AORs with contacts, even if the contact is a permanent one. To really get just inbound registrations,
     * use PJSIPShowRegistrationInboundContactStatuses
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowRegistrationsInbound/
     *
     * @return AmiArray
     */
    public function PJSIPShowRegistrationsInbound(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists PJSIP outbound registrations
     * In response OutboundRegistrationDetail events showing configuration and status information are raised for each
     * outbound registration object. AuthDetail events are raised for each associated auth object as well. Once all events are
     * completed an OutboundRegistrationDetailComplete is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowRegistrationsOutbound/
     *
     * @return AmiArray
     */
    public function PJSIPShowRegistrationsOutbound(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Displays settings for configured resource lists
     * Provides a listing of all resource lists. An event ResourceListDetail is issued for each resource list object. Once
     * all detail events are completed a ResourceListDetailComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowResourceLists/
     *
     * @return AmiArray
     */
    public function PJSIPShowResourceLists(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists inbound subscriptions
     * Provides a listing of all inbound subscriptions. An event InboundSubscriptionDetail is issued for each subscription
     * object. Once all detail events are completed an InboundSubscriptionDetailComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowSubscriptionsInbound/
     *
     * @return AmiArray
     */
    public function PJSIPShowSubscriptionsInbound(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Lists outbound subscriptions
     * Provides a listing of all outbound subscriptions. An event OutboundSubscriptionDetail is issued for each subscription
     * object. Once all detail events are completed an OutboundSubscriptionDetailComplete event is issued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPShowSubscriptionsOutbound/
     *
     * @return AmiArray
     */
    public function PJSIPShowSubscriptionsOutbound(): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Unregister an outbound registration
     * Unregisters the specified (or all) outbound registration(s) and stops future registration attempts. Call PJSIPRegister
     * to start registration and schedule re-registrations according to configuration
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PJSIPUnregister/
     *
     * @param string $Registration The outbound registration to unregister or '*all' to unregister them all
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PJSIPUnregister(string $Registration, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Play DTMF signal on a specific channel
     * Plays a dtmf digit on the specified channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PlayDTMF/
     *
     * @param string $Channel Channel name to send digit to
     * @param string $Digit The DTMF digit to play
     * @param int|numeric-string|null $Duration The duration, in milliseconds, of the digit to be played
     * @param bool|null $Receive Emulate receiving DTMF on this channel instead of sending it out
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PlayDTMF(
        string $Channel,
        string $Digit,
        int|string|null $Duration = null,
        ?bool $Receive = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Play MF digit on a specific channel
     * Plays an MF digit on the specified channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PlayMF/
     *
     * @param string $Channel Channel name to send digit to
     * @param string $Digit The MF digit to play
     * @param int|numeric-string|null $Duration The duration, in milliseconds, of the digit to be played
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PlayMF(string $Channel, string $Digit, int|string|null $Duration = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Check Presence State
     * Report the presence state for the given presence provider. Will return a Presence State message. The response will
     * include the presence state and, if set, a presence subtype and custom message
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PresenceState/
     *
     * @param string $Provider Presence Provider to check the state of
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PresenceState(string $Provider, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List the current known presence states
     * This will list out all known presence states in a sequence of PresenceStateChange events. When finished, a
     * PresenceStateListComplete event will be emitted
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PresenceStateList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PresenceStateList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set the file used for PRI debug message output
     * Equivalent to the CLI command "pri set debug file <output-file>"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PRIDebugFileSet/
     *
     * @param string $File Path of file to write debug output
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PRIDebugFileSet(string $File, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Disables file output for PRI debug messages
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PRIDebugFileUnset/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PRIDebugFileUnset(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set PRI debug levels for a span
     * Equivalent to the CLI command "pri set debug <level> span <span>"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PRIDebugSet/
     *
     * @param string $Span Which span to affect
     * @param 'off'|'on'|'hex'|'intense' $Level What debug level to set. May be a numerical value or a text value from the list below
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PRIDebugSet(string $Span, string $Level, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show status of PRI spans
     * Similar to the CLI command "pri show spans"
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/PRIShowSpans/
     *
     * @param string|null $Span Specify the specific span to show. Show all spans if zero or not present
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function PRIShowSpans(?string $Span = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Add interface to queue
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueAdd/
     *
     * @param string $Queue Queue's name
     * @param string $Interface The name of the interface (tech/name) to add to the queue
     * @param int|numeric-string|null $Penalty A penalty (number) to apply to this member. Asterisk will distribute calls to members
     * with higher penalties only after attempting to distribute calls to those with lower penalty
     * @param bool|null $Paused To pause or not the member initially (true/false or 1/0)
     * @param string|null $Reason Text description why the member is paused
     * @param string|null $MemberName Text alias for the interface
     * @param string|null $StateInterface
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueAdd(
        string $Queue,
        string $Interface,
        int|string|null $Penalty = null,
        ?bool $Paused = null,
        ?string $Reason = null,
        ?string $MemberName = null,
        ?string $StateInterface = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Change priority of a caller on queue
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueChangePriorityCaller/
     *
     * @param string $Queue The name of the queue to take action on
     * @param string $Caller The caller (channel) to change priority on queue
     * @param int|string $Priority Priority value for change for caller on queue
     * @param bool|null $Immediate When set to yes will cause the priority change to be reflected immediately, causing the channel to
     * change position within the queue
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueChangePriorityCaller(
        string $Queue,
        string $Caller,
        int|string $Priority,
        ?bool $Immediate = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Adds custom entry in queue_log
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueLog/
     *
     * @param string $Queue
     * @param string $Event
     * @param string|null $Uniqueid
     * @param string|null $Interface
     * @param string|null $Message
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueLog(
        string $Queue,
        string $Event,
        ?string $Uniqueid = null,
        ?string $Interface = null,
        ?string $Message = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set the ringinuse value for a queue member
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueMemberRingInUse/
     *
     * @param string $Interface
     * @param bool $RingInUse
     * @param string|null $Queue
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueMemberRingInUse(string $Interface, bool $RingInUse, ?string $Queue = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Makes a queue member temporarily unavailable
     * Pause or unpause a member in a queue
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueuePause/
     *
     * @param string $Interface The name of the interface (tech/name) to pause or unpause
     * @param bool $Paused Pause or unpause the interface. Set to 'true' to pause the member or 'false' to unpause
     * @param string|null $Queue The name of the queue in which to pause or unpause this member. If not specified, the member will be
     * paused or unpaused in all the queues it is a member of
     * @param string|null $Reason Text description, returned in the event QueueMemberPause
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueuePause(
        string $Interface,
        bool $Paused,
        ?string $Queue = null,
        ?string $Reason = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Set the penalty for a queue member
     * Change the penalty of a queue member
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueuePenalty/
     *
     * @param string $Interface The interface (tech/name) of the member whose penalty to change
     * @param int|numeric-string $Penalty The new penalty (number) for the member. Must be nonnegative
     * @param string|null $Queue If specified, only set the penalty for the member of this queue. Otherwise, set the penalty for the
     * member in all queues to which the member belongs
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueuePenalty(string $Interface, int|string $Penalty, ?string $Queue = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Reload a queue, queues, or any sub-section of a queue or queues
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueReload/
     *
     * @param string|null $Queue The name of the queue to take action on. If no queue name is specified, then all queues are affected
     * @param bool|null $Members Whether to reload the queue's members
     * @param bool|null $Rules Whether to reload queuerules.conf
     * @param bool|null $Parameters Whether to reload the other queue options
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueReload(
        ?string $Queue = null,
        ?bool $Members = null,
        ?bool $Rules = null,
        ?bool $Parameters = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Remove interface from queue
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueRemove/
     *
     * @param string $Queue The name of the queue to take action on
     * @param string $Interface The interface (tech/name) to remove from queue
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueRemove(string $Queue, string $Interface, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Reset queue statistics
     * Reset the statistics for a queue
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueReset/
     *
     * @param string|null $Queue The name of the queue on which to reset statistics
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueReset(?string $Queue = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Queue Rules
     * List queue rules defined in queuerules.conf
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueRule/
     *
     * @param string|null $Rule The name of the rule in queuerules.conf whose contents to list
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueRule(?string $Rule = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show queue status
     * Check the status of one or more queues
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueStatus/
     *
     * @param string|null $Queue Limit the response to the status of the specified queue
     * @param string|null $Member Limit the response to the status of the specified member
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueStatus(?string $Queue = null, ?string $Member = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show queue summary
     * Request the manager to send a QueueSummary event
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueSummary/
     *
     * @param string|null $Queue Queue for which the summary is requested
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueSummary(?string $Queue = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Request to withdraw a caller from the queue back to the dialplan
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/QueueWithdrawCaller/
     *
     * @param string $Queue The name of the queue to take action on
     * @param string $Caller The caller (channel) to withdraw from the queue
     * @param string|null $WithdrawInfo Optional info to store. If the call is successfully withdrawn from the queue, this information
     * will be available in the QUEUE_WITHDRAW_INFO variable
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function QueueWithdrawCaller(string $Queue, string $Caller, ?string $WithdrawInfo = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Redirect (transfer) a call
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Redirect/
     *
     * @param string $Channel Channel to redirect
     * @param string $Exten Extension to transfer to
     * @param string $Context Context to transfer to
     * @param int|string $Priority Priority to transfer to
     * @param string|null $ExtraChannel Second call leg to transfer (optional)
     * @param string|null $ExtraExten Extension to transfer extrachannel to (optional)
     * @param string|null $ExtraContext Context to transfer extrachannel to (optional)
     * @param int|string|null $ExtraPriority Priority to transfer extrachannel to (optional)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Redirect(
        string $Channel,
        string $Exten,
        string $Context,
        int|string $Priority,
        ?string $ExtraChannel = null,
        ?string $ExtraExten = null,
        ?string $ExtraContext = null,
        int|string|null $ExtraPriority = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Send a reload event
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Reload/
     *
     * @param string|null $Module Name of the module to reload
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Reload(?string $Module = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Send a hook flash on a specific channel
     * Sends a hook flash on the specified channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SendFlash/
     *
     * @param string $Channel Channel name to send hook flash to
     * @param bool|null $Receive Emulate receiving a hook flash on this channel instead of sending it out
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SendFlash(string $Channel, ?bool $Receive = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Sends a text message to channel. A content type can be optionally specified. If not set it is set to an empty string allowing a custom handler to default it as it sees fit
     * Sends A Text Message to a channel while in a call
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SendText/
     *
     * @param string $Channel Channel to send message to
     * @param string $Message Message to send
     * @param string|null $ContentType The type of content in the message
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SendText(string $Channel, string $Message, ?string $ContentType = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Sets a channel variable or function value
     * This command can be used to set the value of channel variables or dialplan functions. If a channel name is not
     * provided then the variable is considered global
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Setvar/
     *
     * @param string $Variable Variable name, function or expression
     * @param string $Value Variable or function value
     * @param string|null $Channel Channel to set variable for
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Setvar(string $Variable, string $Value, ?string $Channel = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show dialplan contexts and extensions
     * Show dialplan contexts and extensions. Be aware that showing the full dialplan may take a lot of capacity
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/ShowDialPlan/
     *
     * @param string|null $Extension Show a specific extension
     * @param string|null $Context Show a specific context
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function ShowDialPlan(?string $Extension = null, ?string $Context = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Expire (remove) ALL objects from a sorcery memory cache
     * Expires (removes) ALL objects from a sorcery memory cache
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SorceryMemoryCacheExpire/
     *
     * @param string $Cache The name of the cache to expire all objects from
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SorceryMemoryCacheExpire(string $Cache, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Expire (remove) an object from a sorcery memory cache
     * Expires (removes) an object from a sorcery memory cache. If full backend caching is enabled this action is not
     * available and will fail. In this case the SorceryMemoryCachePopulate or SorceryMemoryCacheExpire AMI actions must be used
     * instead
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SorceryMemoryCacheExpireObject/
     *
     * @param string $Cache The name of the cache to expire the object from
     * @param string $Object The name of the object to expire
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SorceryMemoryCacheExpireObject(string $Cache, string $Object, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Expire all objects from a memory cache and populate it with all objects from the backend
     * Expires all objects from a memory cache and populate it with all objects from the backend
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SorceryMemoryCachePopulate/
     *
     * @param string $Cache The name of the cache to populate
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SorceryMemoryCachePopulate(string $Cache, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Marks ALL objects in a sorcery memory cache as stale
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SorceryMemoryCacheStale/
     *
     * @param string $Cache The name of the cache to mark all object as stale in
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SorceryMemoryCacheStale(string $Cache, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Mark an object in a sorcery memory cache as stale
     * Marks an object as stale within a sorcery memory cache
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/SorceryMemoryCacheStaleObject/
     *
     * @param string $Cache The name of the cache to mark the object as stale in
     * @param string $Object The name of the object to mark as stale
     * @param bool|null $Reload If true, then immediately reload the object from the backend cache instead of waiting for the next
     * retrieval
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function SorceryMemoryCacheStaleObject(string $Cache, string $Object, ?bool $Reload = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List channel status
     * Will return the status information of each channel along with the value for the specified channel variables
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/Status/
     *
     * @param string|null $Channel The name of the channel to query for status
     * @param string|null $Variables Comma , separated list of variable to include
     * @param bool|null $AllVariables If set to "true", the Status event will include all channel variables for the requested
     * channel(s)
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function Status(
        ?string $Channel = null,
        ?string $Variables = null,
        ?bool $AllVariables = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Stop recording a call through MixMonitor, and free the recording's file handle
     * This action stops the audio recording that was started with the MixMonitor action on the current channel
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/StopMixMonitor/
     *
     * @param string $Channel The name of the channel monitored
     * @param string|null $MixMonitorID If a valid ID is provided, then this command will stop only that specific MixMonitor
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function StopMixMonitor(string $Channel, ?string $MixMonitorID = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Update basic configuration
     * This action will modify, create, or delete configuration elements in Asterisk configuration files
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/UpdateConfig/
     *
     * @param string $SrcFilename Configuration filename to read (e.g. foo.conf)
     * @param string $DstFilename Configuration filename to write (e.g. foo.conf)
     * @param string|null $Reload Whether or not a reload should take place (or name of specific module)
     * @param bool|null $PreserveEffectiveContext Whether the effective category contents should be preserved on template change.
     * Default is true (pre 13.2 behavior)
     * @param 'NewCat'|'RenameCat'|'DelCat'|'EmptyCat'|'Update'|'Delete'|'Append'|'Insert'|null $Action Action to take.0's represent 6
     * digit number beginning with 000000
     * @param string|null $Cat Category to operate on.0's represent 6 digit number beginning with 000000
     * @param string|null $Var Variable to work on.0's represent 6 digit number beginning with 000000
     * @param string|null $Value Value to work on.0's represent 6 digit number beginning with 000000
     * @param string|null $Match Extra match required to match line.0's represent 6 digit number beginning with 000000
     * @param string|null $Line Line in category to operate on (used with delete and insert actions).0's represent 6 digit number
     * beginning with 000000
     * @param string|null $Options A comma separated list of action-specific options.The following actions share the same
     * options...0's represent 6 digit number beginning with 000000
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function UpdateConfig(
        string $SrcFilename,
        string $DstFilename,
        ?string $Reload = null,
        ?bool $PreserveEffectiveContext = null,
        ?string $Action = null,
        ?string $Cat = null,
        ?string $Var = null,
        ?string $Value = null,
        ?string $Match = null,
        ?string $Line = null,
        ?string $Options = null,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Send an arbitrary event
     * Send an event to manager sessions
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/UserEvent/
     *
     * @param string $UserEvent Event string to send
     * @param string|null $Header1 Content1
     * @param string|null $HeaderN ContentN
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function UserEvent(string $UserEvent, ?string $Header1 = null, ?string $HeaderN = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show the mailbox contents of given voicemail user
     * Retrieves the contents of the given voicemail user's mailbox
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailBoxSummary/
     *
     * @param string $Context The context you want to check
     * @param string $Mailbox The mailbox you want to check
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailBoxSummary(string $Context, string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Forward Voicemail from one mailbox folder to another between given users
     * Forward a given Voicemail from a user's Mailbox Folder to another user's Mailbox Folder. Can be used to copy between
     * Folders within a mailbox by specifying the to context and user as the same as the from
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailForward/
     *
     * @param string $Context The context of the Voicemail you want to move
     * @param string $Mailbox The mailbox of the Voicemail you want to move
     * @param string $Folder The Folder containing the Voicemail you want to move
     * @param string $ID The ID of the Voicemail you want to move
     * @param string $ToContext The context you want to move the Voicemail to
     * @param string $ToMailbox The mailbox you want to move the Voicemail to
     * @param string $ToFolder The Folder you want to move the Voicemail to
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailForward(
        string $Context,
        string $Mailbox,
        string $Folder,
        string $ID,
        string $ToContext,
        string $ToMailbox,
        string $ToFolder,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Move Voicemail between mailbox folders of given user
     * Move a given Voicemail between Folders within a user's Mailbox
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailMove/
     *
     * @param string $Context The context of the Voicemail you want to move
     * @param string $Mailbox The mailbox of the Voicemail you want to move
     * @param string $Folder The Folder containing the Voicemail you want to move
     * @param string $ID The ID of the Voicemail you want to move
     * @param string $ToFolder The Folder you want to move the Voicemail to
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailMove(
        string $Context,
        string $Mailbox,
        string $Folder,
        string $ID,
        string $ToFolder,
        ?string $ActionID = null
    ): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Tell Asterisk to poll mailboxes for a change
     * Normally, MWI indicators are only sent when Asterisk itself changes a mailbox. With external programs that modify the
     * content of a mailbox from outside the application, an option exists called pollmailboxes that will cause voicemail to
     * continually scan all mailboxes on a system for changes. This can cause a large amount of load on a system. This command
     * allows external applications to signal when a particular mailbox has changed, thus permitting external applications to modify
     * mailboxes and MWI to work without introducing considerable CPU load. If Context is not specified, all mailboxes on the system
     * will be polled for changes. If Context is specified, but Mailbox is omitted, then all mailboxes within Context will be
     * polled. Otherwise, only a single mailbox will be polled for changes
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailRefresh/
     *
     * @param string|null $Context
     * @param string|null $Mailbox
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailRefresh(?string $Context = null, ?string $Mailbox = null, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Remove Voicemail from mailbox folder
     * Remove a given Voicemail from a user's Mailbox Folder
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailRemove/
     *
     * @param string $Context The context of the Voicemail you want to remove
     * @param string $Mailbox The mailbox of the Voicemail you want to remove
     * @param string $Folder The Folder containing the Voicemail you want to remove
     * @param string $ID The ID of the Voicemail you want to remove
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailRemove(string $Context, string $Mailbox, string $Folder, string $ID, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * List All Voicemail User Information
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailUsersList/
     *
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailUsersList(?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Show the status of given voicemail user's info
     * Retrieves the status of the given voicemail user
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/VoicemailUserStatus/
     *
     * @param string $Context The context you want to check
     * @param string $Mailbox The mailbox you want to check
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function VoicemailUserStatus(string $Context, string $Mailbox, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }

    /**
     * Wait for an event to occur
     * This action will elicit a Success response. Whenever a manager event is queued. Once WaitEvent has been called on an
     * HTTP manager session, events will be generated and queued
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/WaitEvent/
     *
     * @param int|numeric-string $Timeout Maximum time (in seconds) to wait for events, -1 means forever
     * @param string|null $ActionID ActionID for this transaction. Will be returned
     * @return AmiArray
     */
    public function WaitEvent(int|string $Timeout, ?string $ActionID = null): array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }
}
