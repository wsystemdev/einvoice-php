<?php

/**
 * Records SaxonC's answers for the XPath expressions in tests/fixtures/validator/xpath_cases.txt (one per line)
 * evaluated against tests/fixtures/validator/xpath_doc.xml, into xpath_expected.json. CoreEngineTest compares the
 * pure-PHP engine with them. Needs the SaxonC extension:  php -d extension=saxon.so tools/validator/xpath_expectations.php
 */

declare(strict_types=1);

$dir = __DIR__ . '/../../tests/fixtures/validator';
$p = new Saxon\SaxonProcessor(false);
$x = $p->newXPathProcessor();
$x->declareNamespace('a', 'urn:a');
$x->setContextItem($p->parseXmlFromFile(realpath("$dir/xpath_doc.xml")));
$out = [];
foreach (file("$dir/xpath_cases.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $e) {
    if (str_starts_with($e, '#')) {
        continue;
    }
    try {
        $v = $x->evaluate("string-join(for \$i in ($e) return string(\$i), '|')");
        $out[$e] = $v === null ? null : $v->getHead()?->getStringValue() ?? '';
    } catch (\Throwable $t) {
        $out[$e] = 'ERROR';
    }
}
file_put_contents("$dir/xpath_expected.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($out), " expressions recorded\n";
