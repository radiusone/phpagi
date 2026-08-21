### phpagi:  an AGI class written in PHP

Forked from:<br/>
  Matthew Asham
  https://github.com/welltime/phpagi

Original version:<br/>
  http://phpagi.sourceforge.net/

Contributions by:<br/>
  Florian Overkamp<br/>
  David Eder<br/>
  Roland Hu<br/>
  Others<br/>

### OVERVIEW

Install it:
```sh
composer install radiusone/phpagi
```
Include it:
```php
require './vendor/autoload.php';
```
Construct it:
```php
$agi = new PhpAgi\AGI();
```
This creates a new AGI object with all the agi vars read in, you're now ready
to rock and roll.

Answer the line.
```php
$agi->answer();
```
Play a file.
```php
$agi->stream_file('somefile.gsm');
```
Record a file.
```php
$agi->record_file($filename, $format, escape_digits: '', timeout: -1, beep: false)
```
There's more, read the class or refer to the PHPAGI website for more information.  

This README is seriously lacking.

### CONFIGURATION

phpagi supports an ini style configuration file, and run time configuration.

By default the class reads in the contents of /etc/asterisk/phpagi.conf into
$this->config.  The format of the ini file is as follows:
```ini
[examplesection]
foo=bar
bar=dew
dew=pale ale
```
This is read into $this->config as:
```php
$this->config['examplesection']['foo'] = 'bar';
$this->config['examplesection']['bar] = 'dew';
$this->config['examplesection']['dew'] = 'pale ale';
```

Run time configuration is also supported.  Pass an array of variables and values as the
second parameter to the AGI constructor, these fields are stored in `$this->config['phpagi']`.

ie:
```php
$myconfig = [
    "error_handler" => true,
];

$agi = new PhpAgi\AGI("/etc/asterisk/phpagi.conf", $myconfig);
```

The following run-time configuration options are used by the phpagi class to change behaviour.  They 
are all contained in the $this->config['phpagi'] array.

 * error_handler - set to true to enable php debugging
 * debug - set to true to enable console logging
 * admin - set to an email address to enable error messages via mail
 * tempdir - set to a specific temporary directory
 