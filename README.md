PhpAgi Readme
-------------

Welcome to PhpAgi. 

PhpAgi is a set of PHP classes for use in developing applications with
the Asterisk Gateway Interface and Asterisk Manager Interface, and is
licensed under the GNU Lesser General Public License (see COPYING for terms).

Compatibility
-------------
For a list of breaking changes to class methods, see the [changelog](CHANGELOG.md).

### Version 4
**Version 4** continues modernization of the codebase, although with
fewer public-facing API changes than version 3.
* AGI command and AMI action methods are now being automatically generated
from Asterisk's XML documentation. For version 4, documentation from
Asterisk 22 has been used. The use of named parameters is now **very
strongly** suggested in user code. Why?
  1) Asterisk documentation may change
  2) parameters may be added and removed with each new Asterisk version
  3) PHP requires optional parameters to be last in the argument list
  4) the `$ActionID` parameter will always be last

* A static property `AGI::$returnObjects` can be set to `true`, causing AGI
calls to return `PhpAgi\AGI\Response` objects instead of arrays. This will
become the default behaviour in version 5. The property may be removed in
version 6 or later.

* An array `PhpAgi\AGI\Request::$agi_args` has been added to the config
object, which contains arguments passed to the AGI script at startup.

* Formerly private class members are now protected to ease extension of
the class.

### Version 3
**Version 3** of the PhpAgi classes was a significant overhaul from the
old versions.  Classes were namespaced and renamed. Class properties
were strictly typed, as were method parameters and returns. Many new
methods were added, and old ones renamed or had their signatures and
behaviour changed.

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

