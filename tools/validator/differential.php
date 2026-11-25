<?php

/**
 * Differential test of the pure-PHP engine (php-core) against SaxonC (the official engine) on the validator corpus
 * plus seeded random mutations of every sample (delete / empty / garble / change numbers / change codes).
 * Writes per-rule coverage JSON. Needs the SaxonC extension.
 *
 *   php -d extension=saxon.so tools/validator/differential.php [--mutants=N] [--seed=S] [--out=file.json] [--only=substr]
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use WSystem\EInvoice\Validator\Backend\CoreRulesBackend;
use WSystem\EInvoice\Validator\Backend\SaxonCBackend;
use WSystem\EInvoice\Validator\Core\SchematronProgram;
use WSystem\EInvoice\Validator\Document;
use WSystem\EInvoice\Validator\RulePack;
use WSystem\EInvoice\Validator\Validator;
use WSystem\EInvoice\Validator\RuleEvaluationError;
use WSystem\EInvoice\InputError;

$opt = getopt('', ['mutants::', 'seed::', 'out::', 'only::']);
$mutants = (int) ($opt['mutants'] ?? 20);
mt_srand((int) ($opt['seed'] ?? 1));
if (!SaxonCBackend::available()) {
    fwrite(STDERR, "SaxonC extension not loaded\n");
    exit(2);
}
$root = __DIR__ . '/../../tests/fixtures/validator';
$golden = json_decode(file_get_contents("$root/golden.json"), true)['cases'];
$core = new Validator(new CoreRulesBackend());
$saxon = new Validator(new SaxonCBackend());

$sig = static fn (array $r) => array_map(
    static fn ($i) => implode('|', [$i['rule_id'], $i['severity'], $i['ruleset'], $i['location'] ?? '', $i['message']]),
    array_merge($r['errors'], $r['warnings'])
);

/** @return list<string> mutated XML documents */
$mutate = static function (string $xml, int $n): array {
    $out = [];
    for ($k = 0; $k < $n; $k++) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        $els = iterator_to_array($dom->getElementsByTagName('*'));
        array_shift($els); // keep the root element
        $leaves = array_values(array_filter($els, static fn ($e) => $e->childElementCount === 0));
        $edits = mt_rand(1, 3);
        for ($e = 0; $e < $edits && $els; $e++) {
            $kind = mt_rand(0, 6);
            if ($kind === 0) {
                $el = $els[mt_rand(0, count($els) - 1)];
                if ($el->parentNode) {
                    $el->parentNode->removeChild($el);
                }
                continue;
            }
            if (!$leaves) {
                continue;
            }
            $el = $leaves[mt_rand(0, count($leaves) - 1)];
            $v = $el->textContent;
            $new = match ($kind) {
                1 => '',
                2 => ['XX', 'ZZZ', 'FOO', '1', 'S', 'DE', 'AE', 'O', 'EUR', 'USD', '0'][mt_rand(0, 10)],
                3 => is_numeric($v) ? (string) round((float) $v + [0.01, -0.01, 1, -1, 100, 0.005][mt_rand(0, 5)], 3) : $v . 'x',
                4 => is_numeric($v) ? '-' . $v : ' ' . $v . ' ',
                5 => preg_match('/^\d{4}-\d\d-\d\d$/', $v) ? ['2025-02-30', '2025-13-01', '20250101', '2099-01-01'][mt_rand(0, 3)] : strrev($v),
                default => is_numeric($v) ? '0' : '',
            };
            $el->textContent = $new;
            if ($el->hasAttributes() && mt_rand(0, 2) === 0) {
                $a = $el->attributes->item(mt_rand(0, $el->attributes->length - 1));
                $a->value = ['XX', '', '0088', 'EUR', 'C62', '9930'][mt_rand(0, 5)];
            }
        }
        $out[] = $dom->saveXML();
    }
    return $out;
};

$stats = ['cases' => 0, 'mutants' => 0, 'identical' => 0, 'differ' => 0, 'input_errors' => 0];
$rules = []; // rule => ['saxon_fired' => n, 'core_fired' => n, 'mismatch' => n, 'not_evaluated' => n]
$diffs = [];
$t0 = microtime(true);
foreach ($golden as $key => $c) {
    if ($c['input_error'] || (isset($opt['only']) && !str_contains($key, $opt['only']))) {
        continue;
    }
    $data = file_get_contents("$root/files/{$c['file']}");
    $docs = [[$key, $data]];
    $xml = Document::extractXml($data)[0];
    foreach ($mutate($xml, $mutants) as $i => $m) {
        $docs[] = ["$key#m$i", $m];
    }
    foreach ($docs as [$name, $bytes]) {
        try {
            $s = $saxon->validate($bytes, $c['rules']);
        } catch (RuleEvaluationError $e) {
            $s = 'ABORT';
        } catch (InputError $e) {
            $stats['input_errors']++;
            continue;
        }
        try {
            $r = $core->validate($bytes, $c['rules']);
        } catch (RuleEvaluationError $e) {
            $r = 'ABORT';
        }
        if ($s === 'ABORT' || $r === 'ABORT') {
            $stats['aborted_saxonc'] = ($stats['aborted_saxonc'] ?? 0) + ($s === 'ABORT' ? 1 : 0);
            if ($s === $r) {
                $stats['identical']++;
                $stats['aborted_both'] = ($stats['aborted_both'] ?? 0) + 1;
            } else {
                $stats['differ']++;
                $diffs[] = ['doc' => $name, 'missing' => [$s === 'ABORT' ? 'saxonc aborted: ' . $e->getMessage() : 'saxonc completed'],
                    'extra' => [$r === 'ABORT' ? 'php-core aborted: ' . $e->getMessage() : 'php-core completed'], 'order_only' => false];
                @mkdir('/tmp/diffdocs');
                file_put_contents('/tmp/diffdocs/' . preg_replace('/[^A-Za-z0-9._#-]/', '_', $name) . '.xml', $bytes);
            }
            continue;
        }
        str_contains($name, '#m') ? $stats['mutants']++ : $stats['cases']++;
        $a = $sig($s);
        $b = $sig($r);
        foreach ($s['errors'] as $i) {
            $rules[$i['rule_id']]['saxon_fired'] = ($rules[$i['rule_id']]['saxon_fired'] ?? 0) + 1;
        }
        foreach ($s['warnings'] as $i) {
            $rules[$i['rule_id']]['saxon_fired'] = ($rules[$i['rule_id']]['saxon_fired'] ?? 0) + 1;
        }
        foreach ($r['rules_not_evaluated'] as $x) {
            $rules[$x['rule_id']]['not_evaluated'] = ($rules[$x['rule_id']]['not_evaluated'] ?? 0) + 1;
            $rules[$x['rule_id']]['reason'] = $x['reason'];
        }
        if ($a === $b) {
            $stats['identical']++;
            continue;
        }
        $stats['differ']++;
        $miss = array_values(array_diff($a, $b));
        $extra = array_values(array_diff($b, $a));
        foreach (array_merge($miss, $extra) as $line) {
            $id = explode('|', $line)[0];
            $rules[$id]['mismatch'] = ($rules[$id]['mismatch'] ?? 0) + 1;
        }
        if (count($diffs) < 200) {
            $diffs[] = ['doc' => $name, 'missing' => array_slice($miss, 0, 5), 'extra' => array_slice($extra, 0, 5), 'order_only' => !$miss && !$extra];
            if (str_contains($name, '#m') && !is_dir('/tmp/diffdocs')) {
                mkdir('/tmp/diffdocs');
            }
            file_put_contents('/tmp/diffdocs/' . preg_replace('/[^A-Za-z0-9._#-]/', '_', $name) . '.xml', $bytes);
        }
    }
}
// every rule in the bundled files, with its php-core status
$pack = new RulePack();
$all = [];
foreach (['ubl', 'cii'] as $syn) {
    foreach (['EN16931', 'XRechnung'] as $rs) {
        $p = SchematronProgram::load($pack->xsl($syn, $rs));
        foreach ($p->ruleIds() as $id) {
            $all[$id]['files'][] = "$rs/$syn";
            if (isset($p->unsupported[$id])) {
                $all[$id]['unsupported'] = $p->unsupported[$id];
            }
        }
    }
}
foreach ($all as $id => &$info) {
    $info += $rules[$id] ?? [];
}
unset($info);
$stats['seconds'] = round(microtime(true) - $t0, 1);
$report = ['stats' => $stats, 'diffs' => $diffs, 'rules' => $all, 'other_rules' => array_diff_key($rules, $all)];
file_put_contents($opt['out'] ?? '/tmp/differential.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo json_encode($stats), "\n";
foreach (array_slice($diffs, 0, 15) as $d) {
    echo "DIFF {$d['doc']}\n  - ", implode("\n  - ", $d['missing']), "\n  + ", implode("\n  + ", $d['extra']), "\n";
}
exit($stats['differ'] > 0 ? 1 : 0);
