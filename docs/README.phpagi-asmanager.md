### phpagi-asmanager:  an Asterisk Manager class written in PHP

Forked from:<br/>
  Matthew Asham<br/>
  https://github.com/welltime/phpagi

Original version:<br/>
  http://phpagi.sourceforge.net/

### SECURITY

#### Validation

If asterisk is running as root, the manager interface may allow the
execution of arbitrary shell commands as root. If the user can update any
configuration file that can execute arbitrary command (like the dialplan),
the system may be compromised.

Also, look out for command injection.  Consider the following example:
```php
$as->Events($_POST['events_status']);
```
We expect either 'on' or 'off', but the attacker uses:
```none
"\r\n\r\nAction: Command\r\nCommand: database put forward 54321 19005551212";
```

Validation is a *must* for all user data.


#### Username and Secret

Storing the username and secret in the config file will isolate them from your
code.

Isolation of username and secret in the config file does not mean that the
script cannot simple read the config file.  The config file must be readable
by the script.


### CREATING A NEW INSTANCE OF THE CLASS

The class can be created standalone of phpagi.php, or through phpagi.

#### STANDALONE:

require "vendor/autoload.php";

$as = new PhpAgi\AMI();
```
#### FROM PHPAGI:
```php
require "vendor/autoload.php";

$agi = new PhpAgi\AGI();
$as = $agi->new_AsteriskManager();
```

#### Notes:

* If the class is created using $agi->new_AsteriskManager(),
  AGI_AsteriskManager will use the parent phpagi for logging to the Asterisk 
  console.

### CONFIGURATION

phpagi-asmanager uses the same configuration file as phpagi.conf (usually
`/etc/asterisk/phpagi.conf`). All configuration information specific to
phpagi-asmanager is contained in the [asmanager] section of the .conf file.

supported directives:
```ini
[asmanager]
# server to connect to
server=localhost

# default manager port
port=5038

#username for login
username=me_and_only_me

#password for login
secret=i_am_not_telling
```

### Examples
#### CONNECTING
```php
$res = $as->connect("localhost", "username", "password");
if($res === false) {
    echo "Connection failed.\n";
} else {
    echo "Connection established.\n";
}
```
A port can also be specified for the hostname.  eg:
```php
$res = $as->connect("my.asterisk.server:1234", "username", "port");
```
If the no parameters are specified, the defaults from the config will be used.


#### DISCONNECTING
```php
$as->disconnect();
```

#### SENDING REQUESTS
```php
$as->send_request($eventname, $arrayofparameterstopass);
```
send_request() calls wait_request and returns an array of returned data from
the manager.  If something went wrong, it returns false.

wait_request() shouldn't need to be called from a script directly unless you 
are implementing merely an event listener.

wait_request() will also detect events and dispatch any registered event
handlers for the event.

examples:
```php
$res = $as->send_request(
    'EventName',
    ['Channel'=>'Zap/1/16045551212', 'SomeParameter'=>'data']
);
echo "Dump of returned data:\n";
foreach($res as $var=>$val) {
    echo "$var = $val\n";
}
```

`$res['Response']` will generally be `'Success'` on success and `'Error'` on
failure. But this is not always true.  If `$res['Response']` is `'Follows'`, a
multi-line response will be stored in `$res['data']`.

Several manager commands have been aliased for convenience. See below.

### EVENTS

TODO: non-blocking socket i/o. 

The class uses event callbacks to process events received from the manager.

The event callback prototype looks like:
```php
function dump_event(string $ecode, array $event_data, string $server, int $port)
{
    echo "received event '$ecode' from $server:$port\n";
    print_r($data);
}
```
To register an event call back:
```php
$as->add_event_handler('registry', 'dump_event');
```

The special eventname "*" can also be registered.  any eventname not
specifically registered will be handled by the "*" handler.  If no "*" handler
is defined, the event will be silently ignored.

### PRECANNED FUNCTIONS

The following Manager functions have been aliased for convenience:
* AbsoluteTimeout
* ChangeMonitor
* Command
* Events
* ExtensionState
* GetVar
* Hangup
* IAXPeers
* ListCommands
* Logoff
* MailboxCount
* MailboxStatus
* Monitor
* Originate
* ParkedCalls
* Ping
* Queues
* QueueStatus
* Redirect
* SetCDRUserField
* SetVar
* SIPpeers
* Status
* StopMontor
* ZapDialOffhook
* ZapDNDoff
* ZapDNDon
* ZapHangup
* ZapTransfer
