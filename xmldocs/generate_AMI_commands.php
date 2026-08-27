<?php
/*
 * This script requires PHP 8.4, in order to use new DOM classes
 * Manual checks will be needed:
 * - some parameters have invalid names (these should already be marked with @todo by this script)
 * - some parameters should accept int or bool instead of string
 */
require 'AMI_param_mappings.php';

const SIGNATURE_MAX_LEN = 100;
const DOCLINE_MAX_LEN = 120;

$dom = Dom\XmlDocument::createFromFile('core-en_US.xml', LIBXML_NOBLANKS | LIBXML_NOCDATA);
$dom->xinclude();
$actions = $dom->getElementsByTagName('manager');
$function_list = [];
foreach ($actions as $action) {
    $action->normalize();
    /** @var Dom\Element $action */
    $shortdesc = preg_replace('/\s+/', ' ', trim($action->querySelector('synopsis')?->textContent ?? ''));
    $shortdesc = rtrim($shortdesc, ". \n\r\t\v\0");
    $longdesc = '';
    // $descparas = $action->querySelectorAll(':scope > description para');
    // scope not supported
    $descparas = [];
    foreach ($action->childNodes as $ch) {
        if ($ch->nodeType === XML_ELEMENT_NODE && $ch->nodeName === "description") {
            $descparas = $ch->querySelectorAll('para');
            break;
        }
    }
    foreach ($descparas as $descpara) {
        $longdesc .= preg_replace('/\s+/', ' ', trim($descpara->textContent)) . ' ';
    }
    $longdesc = rtrim($longdesc, ". \n\r\t\v\0");
    if ($shortdesc === $longdesc) {
        $longdesc = '';
    } elseif ($longdesc !== '') {
        $longdesc = '     * ' . $longdesc . "\n";
        $longdesc = wordwrap($longdesc, 125, "\n     * ");
    }
    $function = $action->getAttribute('name');
    $docargs = '';
    $args = '(';
    $parameters = [];
    $note = '';
    // $snyxparams = $action->querySelectorAll(':scope > syntax parameter');
    // scope not supported
    $synxparams = [];
    foreach ($action->childNodes as $ch) {
        if ($ch->nodeType === XML_ELEMENT_NODE && $ch->nodeName === "syntax") {
            $synxparams = $ch->children;
            break;
        }
    }
    foreach ($synxparams as $p) {
        $req = $p->getAttribute('required') === 'true';
        $n = trim($p->getAttribute('name'));
        $name = $param_renames[$function][$n] ?? $n;
        $badname = 0;
        $name = preg_replace('/\W/', '', $name, count: $badname);
        $doc = '';
        foreach ($p->childNodes ?? [] as $para) {
            if ($para->nodeType === XML_ELEMENT_NODE && $para->tagName === 'para') {
                $doc .= trim(preg_replace('/\s+/', ' ', $para->textContent ?? ''));
            }
        }
        $enums = [];
        foreach ($p->querySelectorAll('enumlist enum') ?? [] as $enum) {
            /** @var Dom\Element $enum */
            $enums[] = $enum->getAttribute('name');
        }
        $enums = count($enums) ? implode('|', array_map(fn ($v) => "'$v'", $enums)) : null;
        $parameters[] = compact('req', 'name', 'doc', 'enums', 'badname');
    }
    // keep in documented order, but put required first and action ID last
    $req = [];
    $nreq = [];
    $act = [];
    foreach ($parameters as $param) {
        if ($param['name'] === 'ActionID') {
            $act[] = $param;
        } elseif ($param['req']) {
            $req[] = $param;
        } else {
            $nreq[] = $param;
        }
    }
    $parameters = array_merge($req, $nreq, $act);

    foreach ($parameters as $param) {
        $enums = '';
        $docline = sprintf(
            '%s%s $%s %s',
            $param_types[$function][$param['name']][1] ?? $param['enums'] ?? 'string',
            $param['req'] ? '' : '|null',
            $param['name'],
            $param['doc'],
        );
        $docline = rtrim($docline, ". \n\r\t\v\0");
        if (strlen($docline) > DOCLINE_MAX_LEN) {
            // wrap within the comment, ensure it doesn't wrap at the var though
            $docline = str_replace(' $', '_$', $docline);
            $docline = wordwrap($docline, DOCLINE_MAX_LEN, "\n     * ");
            $docline = str_replace('_$', ' $', $docline);
        }
        $note = $param['badname']
            ? '     * @todo check parameter names' . "\n"
            : '';
        $docargs .= sprintf("     * @param %s\n", $docline);
        $type = $param_types[$function][$param['name']][0] ?? 'string';
        if ($param['req'] === false && str_contains($type, '|')) {
            $type .= '|null';
        } elseif ($param['req'] === false) {
            $type = '?' . $type;
        }
        $arg = sprintf(
            '%s $%s%s, ',
            $type,
            $param['name'],
            $param['req'] ? '' : ' = null'
        );
        $args .= $arg;
    }
    $args = rtrim($args, ', ') . ')';
    if (strlen($args) > SIGNATURE_MAX_LEN) {
        $args = str_replace('(', "(\n        ", $args);
        $args = preg_replace('/, /', ",\n        ", $args);
        $args = str_replace(')', "\n    )", $args);
    } else {
        $args = str_replace(', )', ')', $args);
    }

    $function_list[$function] = [
        '%{shortdesc}' => $shortdesc,
        "%{longdesc}\n" => $longdesc,
        "%{note}\n" => $note,
        "%{docargs}\n" => $docargs,
        '%{args}' => trim($args, ', '),
        '%{function}' => $function,
    ];
}

$php = <<< 'PHP'
    /**
     * %{shortdesc}
%{longdesc}
     *
     * @link https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/AMI_Actions/%{function}/
%{note}
     *
%{docargs}
     * @return array
     */
    public function %{function}%{args}: array
    {
        return $this->executeByReflection(__FUNCTION__, func_get_args());
    }


PHP;

$methods = '';
uksort($function_list, "strnatcasecmp");
foreach ($function_list as $action => $function) {
    $methods .= str_replace(array_keys($function), $function, $php);
}
$methods = trim($methods);
$trait = <<< PHP
<?php

namespace PhpAgi\AMI;

trait Actions
{
    $methods
}

PHP;

file_put_contents("/Users/mike/phpagi/src/AMI/Actions.php", $trait);
