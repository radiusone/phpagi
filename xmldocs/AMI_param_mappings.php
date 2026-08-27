<?php
/** @var array<string,array<string,string>> $param_renames a list of action parameters to rename */
$param_renames = [
    'AOCMessage' => [
        'UnitAmount(0)' => 'UnitAmount',
        'UnitType(0)' => 'UnitType',
    ],
    'SendText' => [
        'Content-Type' => 'ContentType',
    ],
    'UpdateConfig' => [
        'Action-000000' => 'Action',
        'Cat-000000' => 'Cat',
        'Var-000000' => 'Var',
        'Value-000000' => 'Value',
        'Match-000000' => 'Match',
        'Line-000000' => 'Line',
        'Options-000000' => 'Options',
    ],
];

$i = ['int|string', 'int|numeric-string'];
$b = ['bool', 'bool'];
$s = ['string', 'string'];
$m = ['float|int|string', 'float|int|string'];
/** @var array<string,array<string,string>> $param_types a list of action parameters that should be converted to int or bool */
$param_types = [
    'AbsoluteTimeout' => ['Timeout' => $i],
    'AgentLogoff' => ['Soft' => $b],
    'AOCMessage' => [
        'UnitAmount0' => $i,
        'UnitType0' => $i,
        'CurrencyAmount' => $i,
        'Code' => $i,
    ],
    'DBPut' => ['Val' => $m],
    'DialplanExtensionAdd' => ['Replace' => $b],
    'Filter' => ['MatchCriteria' => $s],
    'Hangup' => ['Cause' => $i],
    'MeetmeMute' => ['Usernum' => $i],
    'MeetmeUnmute' => ['Usernum' => $i],
    'MessageSend' => ['Destination' => $s],
    'MixMonitorMute' => ['State' => $b],
    'MuteAudio' => ['State' => $b],
    'MWIUpdate' => ['OldMessages' => $i, 'NewMessages' => $i],
    'Originate' => ['Timeout' => $i, 'EarlyMedia' => $b, 'Async' => $b],
    'Park' => ['Timeout' => $i],
    'PJSIPHangup' => ['Cause' => $i],
    'PJSIPSetMaintenance' => ['State' => $b],
    'PlayDTMF' => ['Duration' => $i, 'Receive' => $b],
    'PlayMF' => ['Duration' => $i],
    'QueueAdd' => ['Penalty' => $i, 'Paused' => $b],
    'QueueChangePriorityCaller' => ['Immediate' => $b],
    'QueueMemberRingInUse' => ['RingInUse' => $b],
    'QueuePause' => ['Paused' => $b],
    'QueuePenalty' => ['Penalty' => $i],
    'QueueReload' => ['Members' => $b, 'Rules' => $b, 'Parameters' => $b],
    'SendFlash' => ['Receive' => $b],
    'SetVar' => ['Value' => $m],
    'SorceryMemoryCacheStaleObject' => ['Reload' => $b],
    'Status' => ['AllVariables' => $b],
    'UpdateConfig' => ['PreserveEffectiveContext' => $b, 'Options' => $s],
    'WaitEvent' => ['Timeout' => $i],
];
