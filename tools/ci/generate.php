<?php
// Generate every fixture with the PHP generator into <outdir> (XML, plus PDF for the ZUGFeRD/Factur-X formats)
// and write <outdir>/manifest.json: name => {format, xrechnung, xml, pdf, expected_valid}. Dates fixed for reproducible PDFs.
require __DIR__ . '/../../vendor/autoload.php';

use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\InputError;
use WSystem\EInvoice\Pdf\AttachmentReader;
use WSystem\EInvoice\Pdf\HybridPdf;

$out = rtrim($argv[1] ?? 'build/official', '/');
@mkdir($out, 0777, true);
$in = json_decode(file_get_contents(__DIR__ . '/../../tests/fixtures/inputs.json'), true, 512, JSON_THROW_ON_ERROR);
$exp = json_decode(file_get_contents(__DIR__ . '/../../tests/fixtures/expected.json'), true, 512, JSON_THROW_ON_ERROR);
$man = [];
foreach ($in as $f) {
    try {
        $b = Generator::xml($f['input']);
    } catch (InputError) {
        continue;
    }
    $n = $f['name'];
    file_put_contents("$out/$n.xml", $b['xml']);
    $e = ['format' => $b['format'], 'xrechnung' => $b['xrechnung'], 'xml' => "$n.xml", 'pdf' => null, 'expected_valid' => $exp[$n]['valid'] ?? null];
    if ($b['pdf'] !== null) {
        $pdf = HybridPdf::build($f['input'], $b['model'], $b['xml'], $b['format'], $b['pdf']['file_name'], $b['pdf']['level'], 1764000000);
        $back = AttachmentReader::read($pdf);
        if (($back[$b['pdf']['file_name']] ?? null) !== $b['xml']) {
            fwrite(STDERR, "ROUND TRIP FAILED $n\n");
            exit(1);
        }
        file_put_contents("$out/$n.pdf", $pdf);
        $e['pdf'] = "$n.pdf";
    }
    $man[$n] = $e;
}
file_put_contents("$out/manifest.json", json_encode($man, JSON_PRETTY_PRINT));
echo count($man) . ' fixtures, ' . count(array_filter($man, fn ($e) => $e['pdf'])) . " PDFs written to $out\n";
