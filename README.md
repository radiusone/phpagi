PhpAgi Readme
-------------

Welcome to PhpAgi. 

PhpAgi is a set of PHP classes for use in developing applications with
the Asterisk Gateway Interface and Asterisk Manager Interface, and is
licensed under the GNU Lesser General Public License (see COPYING for terms).

Compatibility
-------------
### Version 4
**Version 4** continues modernization of the codebase, although with
fewer public-facing API changes than version 3.

* A static property `AGI::$returnObjects` can be set to `true`, causing AGI
calls to return `PhpAgi\AGI\Response` objects instead of arrays. This will
become the default behaviour in version 5. The property may be removed in
version 6 or later.
* A small number of method parameters were left untyped in version 3, due
to the inability of PHP 7.4 to handle union types. These few exceptions
have been resolved.
* `AGI::$request` is now an object of type `PhpAgi\AGI\Request`, instead
of an array. All properties are public, and maintain the same names as the
previous array keys. In addition, a `PhpAgi\AGI\Request::$agi_args` array
has been added, which contains arguments passed to the AGI script at startup.
* The `AGI::evaluate()` method is public again, as it was in version 2.0.
* Formerly private class members are now protected to ease extension of
the class.
* Generated AMI action IDs still start with an uppercase 'A' but this is
now followed by 16 hexadecimal characters, instead of 6 digits.

### Version 3
**Version 3** of the PhpAgi classes was a significant overhaul
from the old versions.  Classes were namespaced and renamed. Class
properties were strictly typed, as were method parameters and returns.
Many new methods were added, and old ones renamed or
had their signatures and behaviour changed.

A possibly-not-exhaustive list of backwards-incompatible changes in version 3:

<dl>
    <dt>AGI::evaluate(string $command, ...$args)</dt>
    <dd>
        The value of the <code>code</code> element of the return array
        will now always be an integer; the values of all other elements
        will always be strings. This is a private method, but its return
        is used for all AGI command methods.
    </dd>
    <dd>
        Not a breaking change, but previously this method only accepted
        one string parameter. Now, if multiple parameters are provided, 
        the first is treated as a <code>printf()</code> format specification, 
        and remaining ones are passed into the string. When using this mode,
        any string values are wrapped in double quotes.
    </dd>
    <dt>AGI::exec(string $application, array $args)</dt>
    <dd>
        Previously, <code>$args</code> could be a string or an array. Now,
        only an array can be passed.
    </dd>
    <dt>AMI::Atxfer(string $Channel, string $Exten, string $Context, string $ActionID = null)</dt>
    <dd>
        The <code>$Priority</code> parameter is not supported by Asterisk
        and has been removed.
    </dd>
    <dt>AMI::Getvar(string $Variable, string $Channel = null, string $ActionID = null)</dt>
    <dt>AMI::Setvar(string $Variable, string $Value, string $Channel = null, string $ActionID = null)</dt>
    <dd>
        The <code>$Variable</code> and <code>$Channel</code> parameters have
        swapped places, as the channel is now optional. If omitted, a global
        variable will instead be returned or set.
    </dd>
    <dt>AMI::Originate(string $Channel, string $Exten = null, string $Context = null, string $Priority = null, string $Application = null, string $Data = null, $Timeout = 0, string $Variable = null, string $Account = null, bool $Async = false, string $ActionID = null, bool $EarlyMedia = false, string $Codecs = null, string $ChannelId = null, string $OtherChannelId = null, string $CallerID = null)</dt>
    <dt>AMI::ParkedCalls(string $ParkingLot = null, string $ActionID = null)</dt>
    <dt>AMI::QueueStatus(string $Queue = null, string $Member = null, string $ActionID = null)</dt>
    <dt>AMI::Status(string $Channel = null, string $Variables = null, bool $AllVariables = false, string $ActionID = null)</dt>
    <dd>
        One or more additional parameters were added to match updates to the AMI
        commands. For consistency with other methods, the new parameters were inserted,
        keeping <code>$ActionID</code> as the last parameter.
    </dd>
    <dt>AMI::Redirect(string $Channel, string $Exten, string $Context, string $Priority, string $ExtraChannel = null, string $ExtraExten = null, string $ExtraContext = null, string $ExtraPriority = null, string $ActionID = null)</dt>
    <dd>
        The parameters of this method have been extensively changed to reflect
        that the <code>$ExtraChannel</code> parameter is optional, and to allow
        the setting of other optional <code>$Extra*</code> values.
    </dd>
</dl>

Installation
-----

The preferred way to install this extension is through [composer](https://getcomposer.org/download/).

```bash
composer require radiusone/phpagi "^3.0"
```

Files
-----
* src/AGI.php          - The AGI class.
* src/AMI.php          - The Asterisk Manager class.

* docs/                - README files for the classes.

Docs
----
* README               - The main README
* README.ami           - The AMI README
* README.fastagi       - FastAGI README

* phpagi.example.conf  - An example configuration file
* fastagi.xinetd       - xinetd.conf sample configuration for the FastAGI server

SUPPORT
-------
Support for phpagi is available from the project website. 

 * https://github.com/radiusone/phpagi

