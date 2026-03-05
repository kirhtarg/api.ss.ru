<?php

$output = [];
exec('wmic process where "name=\'php.exe\'" get commandline 2>&1', $output);
echo 'Output count: '.count($output)."\n";
foreach ($output as $i => $line) {
    echo "Line $i: ".bin2hex($line).' (HEX) - '.$line."\n";
    if (stripos($line, 'queue:work') !== false) {
        echo "  MATCH FOUND!\n";
    } else {
        echo "  NO MATCH\n";
    }
}
