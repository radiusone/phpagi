PhpAgi Readme
-------------

Welcome to PhpAgi. 

PhpAgi is a set of PHP classes for use in developing applications with
the Asterisk Gateway Interface and Asterisk Manager Interface, and is
licensed under the GNU Lesser General Public License (see COPYING for terms).

This release (version 3) of the PhpAgi classes is a significant overhaul
from the old versions.  API functions may have been renamed or had their
signatures and behaviour changed from the previous version.

Installation
-----

The preferred way to install this extension is through [composer](https://getcomposer.org/download/).

```bash
composer config repositories.radiusone-phpagi github https://github.com/radiusone/phpagi
composer require radiusone/phpagi dev-master
```

Files
-----
* src/AGI.php          - The AGI class.
* src/AMI.php          - The Asterisk Manager class.
* src/fastagi.php      - An example of a basic FastAGI server.

* docs/                - README files for the classes.
* api-docs/            - API Documentation (html)

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

