<?php
// php tools/parity/compare.php inputs.json expected.json  -> prints mismatches (used for large fuzz runs outside PHPUnit)
require __DIR__ . '/../../vendor/autoload.php';
use WSystem\EInvoice\Generator\Generator;
use WSystem\EInvoice\InputError;
$in = json_decode(file_get_contents($argv[1]), true);
$ex = json_decode(file_get_contents($argv[2]), true);
$bad = 0;
foreach ($in as $f) {
    $e = $ex[$f['name']];
    try {
        $r = Generator::xml($f['input']);
        $ok = !isset($e['error']) && $e['xml'] === $r['xml'] && $e['totals'] === $r['totals'];
        $got = $r['xml'];
    } catch (InputError $x) {
        $ok = isset($e['error']) && str_replace(', or ksef-fa3', '', $e['error']) === $x->getMessage();
        $got = 'ERR ' . $x->getMessage();
    }
    if (!$ok) {
        $bad++;
        if ($bad <= 3) {
            echo "MISMATCH {$f['name']}\n exp: " . substr($e['xml'] ?? ('ERR ' . $e['error']), 0, 300) . "\n got: " . substr($got, 0, 300) . "\n";
            if (isset($e['xml']) && !str_starts_with($got, 'ERR')) {
                $i = strspn($e['xml'] ^ $got, "\0");
                echo " first diff at $i: exp …" . substr($e['xml'], max(0, $i - 80), 160) . "\n got …" . substr($got, max(0, $i - 80), 160) . "\n";
            }
        }
    }
}
echo count($in) . " fixtures, $bad mismatches\n";
exit($bad ? 1 : 0);
