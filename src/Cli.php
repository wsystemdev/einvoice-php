<?php

declare(strict_types=1);

namespace WSystem\EInvoice;

use WSystem\EInvoice\Generator\Generator;

/**
 * `vendor/bin/wsystem-einvoice`: create, validate, parse and rules:update.
 */
final class Cli
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_USAGE = 2;

    /**
     * @param list<string> $argv
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public static function run(array $argv, $stdin, $stdout, $stderr): int
    {
        array_shift($argv); // program name
        if ($argv === [] || in_array($argv[0], ['-h', '--help', 'help'], true)) {
            fwrite($stdout, self::usage());
            return self::EXIT_OK;
        }
        if (in_array($argv[0], ['-V', '--version', 'version'], true)) {
            fwrite($stdout, 'wsystem-einvoice ' . Version::VERSION . "\n");
            return self::EXIT_OK;
        }
        $cmd = $argv[0];
        try {
            return match ($cmd) {
                'create' => self::create(array_slice($argv, 1), $stdin, $stdout, $stderr),
                'validate' => self::validate(array_slice($argv, 1), $stdin, $stdout, $stderr),
                'parse' => self::parse(array_slice($argv, 1), $stdin, $stdout, $stderr),
                'rules:update' => self::rulesUpdate($stdout, $stderr),
                default => self::fail($stderr, 'unknown command: ' . $cmd . "\n" . self::usage(), self::EXIT_USAGE),
            };
        } catch (InputError $e) {
            fwrite($stderr, 'input error: ' . $e->getMessage() . "\n");
            return self::EXIT_ERROR;
        } catch (\JsonException $e) {
            fwrite($stderr, 'input error: could not parse JSON: ' . $e->getMessage() . "\n");
            return self::EXIT_ERROR;
        }
    }

    public static function usage(): string
    {
        return <<<TXT
wsystem-einvoice — create and check EU e-invoices locally (W System)

Usage:
  wsystem-einvoice create [--format FORMAT] [--json] <invoice.json|-> [output]
  wsystem-einvoice validate [--rules auto|en16931|xrechnung] [--json] <invoice.xml|pdf|->
  wsystem-einvoice parse [--json] <invoice.xml|pdf|->
  wsystem-einvoice rules:update
  wsystem-einvoice --version
  wsystem-einvoice --help

create reads the same JSON object as W System's POST /einvoice/create and writes
the invoice (UBL/CII XML, or a ZUGFeRD / Factur-X PDF/A-3) to output or stdout.
--format overrides invoice.format. --json prints metadata (valid, totals, notes,
errors) as JSON on stdout; for PDF formats give an output path, because the PDF
is not placed inside the JSON.

validate runs XML Schema and, by default, the php-core engine (conformance:
pre-check) — a pre-check, not a conformance check. The complete official rule
sets need the optional SaxonC-HE extension (engine saxonc, ext-saxonc) or the
W System validator or API (engine remote).

Exit codes: 0 ok, 1 input or validation error, 2 usage.

TXT;
    }

    /**
     * @param list<string> $args
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function create(array $args, $stdin, $stdout, $stderr): int
    {
        [$opts, $pos] = self::options($args, ['json' => false, 'format' => true]);
        if (!isset($pos[0])) {
            return self::fail($stderr, "usage: wsystem-einvoice create [--format FORMAT] [--json] <invoice.json|-> [output]\n", self::EXIT_USAGE);
        }
        $raw = self::readArg($pos[0], $stdin, 'invoice JSON');
        if (str_starts_with(ltrim($raw), '<') || str_starts_with($raw, '%PDF')) {
            throw new InputError('create expects the invoice as JSON (the same shape as POST /einvoice/create), not XML or PDF. To check an existing invoice, use validate.');
        }
        $inv = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($inv) || array_is_list($inv)) {
            throw new InputError('expected a single invoice object, not a list or a scalar');
        }
        if (isset($opts['format'])) {
            $inv['format'] = $opts['format'];
        }
        $r = Generator::create($inv);
        $asJson = array_key_exists('json', $opts);
        $outPath = $pos[1] ?? null;
        $body = $r['pdf'] ?? $r['xml'];
        if ($asJson) {
            if ($r['pdf'] !== null && $outPath === null) {
                return self::fail($stderr, "create --json for a PDF format needs an output path for the PDF file\n", self::EXIT_USAGE);
            }
            if ($outPath !== null) {
                self::writeFile($outPath, $body);
            }
            fwrite($stdout, self::encodeCreate($r) . "\n");
        } else {
            if ($outPath !== null) {
                self::writeFile($outPath, $body);
            } else {
                fwrite($stdout, $body);
            }
        }
        if ($r['valid'] === false) {
            fwrite($stderr, self::summariseErrors($r) . "\n");
            return self::EXIT_ERROR;
        }
        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function validate(array $args, $stdin, $stdout, $stderr): int
    {
        $validator = Validators::resolve();
        if ($validator === null) {
            return self::fail($stderr, "validate is provided by the validator module, which is not in this build yet. create still works.\n", self::EXIT_USAGE);
        }
        [$opts, $pos] = self::options($args, ['json' => false, 'rules' => true]);
        if (!isset($pos[0])) {
            return self::fail($stderr, "usage: wsystem-einvoice validate [--rules auto|en16931|xrechnung] [--json] <invoice.xml|pdf|->\n", self::EXIT_USAGE);
        }
        $rules = strtolower((string) ($opts['rules'] ?? 'auto'));
        if (!in_array($rules, ['auto', 'en16931', 'xrechnung'], true)) {
            throw new InputError('rules must be auto, en16931 or xrechnung');
        }
        $data = self::readArg($pos[0], $stdin, 'invoice');
        $r = $validator->validate($data, $rules);
        return self::writeValidation($r, array_key_exists('json', $opts), $stdout, $stderr);
    }

    /**
     * @param list<string> $args
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function parse(array $args, $stdin, $stdout, $stderr): int
    {
        if (!class_exists(Parser::class) || !method_exists(Parser::class, 'parse')) {
            return self::fail($stderr, "parse is provided by the validator module, which is not in this build yet.\n", self::EXIT_USAGE);
        }
        [$opts, $pos] = self::options($args, ['json' => false]);
        if (!isset($pos[0])) {
            return self::fail($stderr, "usage: wsystem-einvoice parse [--json] <invoice.xml|pdf|->\n", self::EXIT_USAGE);
        }
        $data = self::readArg($pos[0], $stdin, 'invoice');
        $r = Parser::parse($data);
        if (array_key_exists('json', $opts) || !is_string($r)) {
            fwrite($stdout, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        } else {
            fwrite($stdout, $r);
        }
        return self::EXIT_OK;
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function rulesUpdate($stdout, $stderr): int
    {
        $class = 'WSystem\\EInvoice\\Validator\\RuleUpdater';
        if (!class_exists($class) || !method_exists($class, 'update')) {
            return self::fail($stderr, "rules:update is provided by the validator module, which is not in this build yet.\n", self::EXIT_USAGE);
        }
        return (int) $class::update(null, null, $stdout);
    }

    /**
     * @param list<string> $args
     * @param array<string, bool> $spec  true = option takes a value, false = flag
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function options(array $args, array $spec): array
    {
        $opts = [];
        $pos = [];
        $n = count($args);
        for ($i = 0; $i < $n; $i++) {
            $a = $args[$i];
            if ($a === '--') {
                $pos = array_merge($pos, array_slice($args, $i + 1));
                break;
            }
            if (str_starts_with($a, '--')) {
                $name = substr($a, 2);
                $val = null;
                if (str_contains($name, '=')) {
                    [$name, $val] = explode('=', $name, 2);
                }
                if (!isset($spec[$name])) {
                    throw new InputError("unknown option --$name");
                }
                if ($spec[$name]) {
                    if ($val === null) {
                        if (!isset($args[$i + 1]) || str_starts_with((string) $args[$i + 1], '-')) {
                            throw new InputError("--$name needs a value");
                        }
                        $val = $args[++$i];
                    }
                    $opts[$name] = $val;
                } else {
                    $opts[$name] = true;
                }
                continue;
            }
            if (str_starts_with($a, '-') && $a !== '-') {
                throw new InputError("unknown option $a");
            }
            $pos[] = $a;
        }
        return [$opts, $pos];
    }

    /** @param resource $stdin */
    private static function readArg(string $path, $stdin, string $what): string
    {
        if ($path === '-') {
            $raw = stream_get_contents($stdin);
            if ($raw === false) {
                throw new InputError("could not read $what from stdin");
            }
            return $raw;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new InputError("could not read $what: file not found or not readable");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InputError("could not read $what");
        }
        return $raw;
    }

    private static function writeFile(string $path, string $body): void
    {
        $ok = @file_put_contents($path, $body);
        if ($ok === false) {
            throw new InputError("could not write $path");
        }
    }

    /** @param array<string, mixed> $r */
    private static function encodeCreate(array $r): string
    {
        $out = $r;
        unset($out['pdf']);
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string, mixed> $r
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function writeValidation(array $r, bool $asJson, $stdout, $stderr): int
    {
        if ($asJson) {
            fwrite($stdout, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        } else {
            $ok = !empty($r['valid']);
            $engine = $r['engine'] ?? $r['backend'] ?? 'unknown';
            $label = (string) ($r['rule_set'] ?? $r['conformance'] ?? $engine);
            fwrite($stdout, ($ok ? 'valid' : 'invalid') . " [$engine] $label\n");
            if (!empty($r['errors'])) {
                fwrite($stdout, self::summariseErrors($r) . "\n");
            }
            if (!empty($r['warnings'])) {
                foreach ($r['warnings'] as $w) {
                    if (is_array($w)) {
                        fwrite($stdout, 'warning ' . ($w['rule_id'] ?? '') . ': ' . ($w['message'] ?? '') . "\n");
                    }
                }
            }
        }
        return !empty($r['valid']) ? self::EXIT_OK : self::EXIT_ERROR;
    }

    /** @param array<string, mixed> $r */
    private static function summariseErrors(array $r): string
    {
        $lines = [];
        foreach ($r['errors'] ?? [] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $lines[] = 'error ' . ($e['rule_id'] ?? '') . ': ' . ($e['message'] ?? '');
        }
        $n = $r['error_count'] ?? count($lines);
        $head = $n ? "$n validation error(s)" : 'validation failed';
        return $lines ? $head . "\n" . implode("\n", $lines) : $head;
    }

    /** @param resource $stderr */
    private static function fail($stderr, string $msg, int $code): int
    {
        fwrite($stderr, $msg);
        return $code;
    }
}
