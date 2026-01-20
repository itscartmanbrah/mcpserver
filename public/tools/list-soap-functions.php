<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../src/Bootstrap.php';

echo "SOAP FUNCTIONS\n";
echo "-------------\n";
foreach ($soapClient->__getFunctions() as $fn) {
    echo $fn . "\n";
}

echo "\nSOAP TYPES\n";
echo "---------\n";
foreach ($soapClient->__getTypes() as $t) {
    echo $t . "\n";
}
