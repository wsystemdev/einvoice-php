<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Pdf;

use WSystem\EInvoice\InputError;

/**
 * Reads embedded files (attachments) from a PDF without a PDF library: classic objects, object streams (PDF 1.5+)
 * and FlateDecode streams. Enough for ZUGFeRD / Factur-X / XRechnung hybrids; encrypted PDFs are refused.
 */
final class AttachmentReader
{
    /**
     * Most decompressed bytes read from one PDF (all streams together): 75,000,000, the same limit as the W System API's
     * PDF reader. Protects against decompression bombs (a 1 MB PDF can inflate to gigabytes).
     */
    public const MAX_INFLATED_BYTES = 75_000_000;
    private const LIMIT_MESSAGE = 'could not read PDF: decompressed data exceeds the limit of 75 MB (possible decompression bomb)';

    private string $pdf;
    /** decompressed bytes left for this PDF */
    private int $budget = self::MAX_INFLATED_BYTES;
    /** @var array<int, int> object number => byte offset of "N G obj" */
    private array $offsets = [];
    /** @var array<int, string> objects found inside object streams */
    private array $packed = [];

    public function __construct(string $pdf)
    {
        $this->pdf = $pdf;
        if (preg_match_all('/(?<![0-9])(\d{1,10})\s+(\d{1,5})\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$num, $off]) {
                $this->offsets[(int) $num] = $m[0][$i][1]; // later definitions (incremental updates) win
            }
        }
        foreach (array_keys($this->offsets) as $n) {
            [$dict, $stream] = $this->rawObject($n);
            if ($stream !== null && preg_match('#/Type\s*/ObjStm\b#', $dict)) {
                $this->unpackObjStm($dict, $stream);
            }
        }
    }

    /** @return array<string, string> file name => bytes, in document order */
    public static function read(string $pdf): array
    {
        if (preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $pdf)) {
            throw new InputError('could not read PDF: the PDF is encrypted');
        }
        return (new self($pdf))->attachments();
    }

    public function attachments(): array
    {
        $out = [];
        $nums = array_unique(array_merge(array_keys($this->offsets), array_keys($this->packed)));
        sort($nums);
        foreach ($nums as $n) {
            $dict = $this->dictOf($n);
            if ($dict === null || !preg_match('#/EF\s*<<(.*?)>>#s', $dict, $ef)) {
                continue;
            }
            if (!preg_match('#/(?:UF|F)\s+(\d+)\s+\d+\s+R#', $ef[1], $ref)) {
                continue;
            }
            $name = $this->stringValue($dict, 'UF') ?? $this->stringValue($dict, 'F') ?? ('attachment-' . $n);
            [$sdict, $stream] = $this->rawObject((int) $ref[1]);
            if ($stream === null) {
                continue;
            }
            if (!isset($out[$name])) {
                $out[$name] = $this->decode($sdict, $stream);
            }
        }
        return $out;
    }

    private function dictOf(int $n): ?string
    {
        if (isset($this->packed[$n])) {
            return $this->packed[$n];
        }
        if (!isset($this->offsets[$n])) {
            return null;
        }
        return $this->rawObject($n)[0];
    }

    /** @return array{0: string, 1: ?string} [dictionary text, raw stream bytes or null] */
    private function rawObject(int $n): array
    {
        if (!isset($this->offsets[$n])) {
            return ['', null];
        }
        $start = $this->offsets[$n];
        $head = strpos($this->pdf, 'obj', $start) + 3;
        $sPos = $this->findStreamKeyword($head);
        $ePos = strpos($this->pdf, 'endobj', $head);
        if ($ePos === false) {
            $ePos = strlen($this->pdf);
        }
        if ($sPos === null || $sPos > $ePos) {
            return [substr($this->pdf, $head, $ePos - $head), null];
        }
        $dict = substr($this->pdf, $head, $sPos - $head);
        $dataStart = $sPos + 6;
        if (($this->pdf[$dataStart] ?? '') === "\r") {
            $dataStart++;
        }
        if (($this->pdf[$dataStart] ?? '') === "\n") {
            $dataStart++;
        }
        $len = null;
        if (preg_match('#/Length\s+(\d+)\s+(\d+)\s+R#', $dict, $lm)) {
            [$ld] = $this->rawObject((int) $lm[1]);
            $len = (int) trim($ld);
        } elseif (preg_match('#/Length\s+(\d+)#', $dict, $lm)) {
            $len = (int) $lm[1];
        }
        if ($len === null || $len <= 0 || substr($this->pdf, $dataStart + $len, 30) === false
            || !preg_match('/^\s*endstream/', substr($this->pdf, $dataStart + $len, 30))) {
            $end = strpos($this->pdf, 'endstream', $dataStart);
            $len = ($end === false ? strlen($this->pdf) : $end) - $dataStart;
            $chunk = substr($this->pdf, $dataStart, $len);
            $len = strlen(rtrim($chunk, "\r\n"));
        }
        return [$dict, substr($this->pdf, $dataStart, $len)];
    }

    private function findStreamKeyword(int $from): ?int
    {
        $depth = 0;
        $n = strlen($this->pdf);
        for ($i = $from; $i < $n - 5; $i++) {
            $c = $this->pdf[$i];
            if ($c === '<' && $this->pdf[$i + 1] === '<') {
                $depth++;
                $i++;
            } elseif ($c === '>' && $this->pdf[$i + 1] === '>') {
                $depth--;
                $i++;
            } elseif ($c === '(') {
                $i = $this->skipLiteral($i);
            } elseif ($depth <= 0 && substr($this->pdf, $i, 6) === 'stream') {
                return $i;
            } elseif ($depth <= 0 && substr($this->pdf, $i, 6) === 'endobj') {
                return null;
            }
        }
        return null;
    }

    private function skipLiteral(int $i): int
    {
        $d = 0;
        $n = strlen($this->pdf);
        for (; $i < $n; $i++) {
            $c = $this->pdf[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $d++;
            } elseif ($c === ')') {
                if (--$d === 0) {
                    return $i;
                }
            }
        }
        return $n;
    }

    private function decode(string $dict, string $data): string
    {
        if (preg_match('#/Filter\s*(?:\[\s*)?/(\w+)#', $dict, $f)) {
            if ($f[1] !== 'FlateDecode') {
                throw new InputError("could not read PDF: unsupported stream filter {$f[1]}");
            }
            $out = self::inflate($data, ZLIB_ENCODING_DEFLATE, $this->budget);
            if ($out === null && strlen($data) > 2) {
                $out = self::inflate(substr($data, 2), ZLIB_ENCODING_RAW, $this->budget);
            }
            if ($out === null) {
                throw new InputError('could not read PDF: corrupt compressed stream');
            }
            $this->budget -= strlen($out);
            return $out;
        }
        return $data;
    }

    /**
     * Incremental inflate with a byte counter: small input chunks (deflate expands at most ~1032:1, so 1 KiB in gives at
     * most ~1 MiB out), stop as soon as the output passes $max. null when the data is corrupt.
     *
     * @throws InputError past $max bytes
     */
    private static function inflate(string $data, int $encoding, int $max): ?string
    {
        $ctx = @inflate_init($encoding);
        if ($ctx === false) {
            return null;
        }
        $out = '';
        $len = strlen($data);
        for ($pos = 0; $pos < $len; $pos += 1024) {
            $chunk = @inflate_add($ctx, substr($data, $pos, 1024), ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                return null;
            }
            if (strlen($out) + strlen($chunk) > $max) {
                throw new InputError(self::LIMIT_MESSAGE);
            }
            $out .= $chunk;
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                return $out;
            }
        }
        $chunk = @inflate_add($ctx, '', ZLIB_FINISH);
        if ($chunk === false) {
            // truncated stream: keep what was decoded, like gzuncompress would not; treat as corrupt
            return null;
        }
        if (strlen($out) + strlen($chunk) > $max) {
            throw new InputError(self::LIMIT_MESSAGE);
        }
        return $out . $chunk;
    }

    private function unpackObjStm(string $dict, string $stream): void
    {
        if (!preg_match('#/N\s+(\d+)#', $dict, $nm) || !preg_match('#/First\s+(\d+)#', $dict, $fm)) {
            return;
        }
        try {
            $data = $this->decode($dict, $stream);
        } catch (InputError $e) {
            if ($e->getMessage() === self::LIMIT_MESSAGE) {
                throw $e;
            }
            return;
        }
        $first = (int) $fm[1];
        $pairs = preg_split('/\s+/', trim(substr($data, 0, $first)));
        $list = [];
        for ($k = 0; $k + 1 < count($pairs) && $k / 2 < (int) $nm[1]; $k += 2) {
            $list[] = [(int) $pairs[$k], (int) $pairs[$k + 1]];
        }
        foreach ($list as $i => [$num, $off]) {
            $end = isset($list[$i + 1]) ? $list[$i + 1][1] : strlen($data) - $first;
            if (!isset($this->offsets[$num])) {
                $this->packed[$num] = substr($data, $first + $off, $end - $off);
            }
        }
    }

    /** PDF string value of /Key (literal or hex, PDFDocEncoding or UTF-16BE) */
    private function stringValue(string $dict, string $key): ?string
    {
        if (!preg_match('#/' . $key . '\s*([(<])#', $dict, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $pos = $m[1][1];
        if ($m[1][0] === '<') {
            $end = strpos($dict, '>', $pos);
            $raw = hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', substr($dict, $pos + 1, $end - $pos - 1)) ?: '') ?: '';
        } else {
            $raw = '';
            $depth = 0;
            for ($i = $pos; $i < strlen($dict); $i++) {
                $c = $dict[$i];
                if ($c === '\\') {
                    $nx = $dict[++$i] ?? '';
                    $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\'];
                    if (isset($map[$nx])) {
                        $raw .= $map[$nx];
                    } elseif (ctype_digit($nx)) {
                        $oct = $nx;
                        while (strlen($oct) < 3 && ctype_digit($dict[$i + 1] ?? 'x')) {
                            $oct .= $dict[++$i];
                        }
                        $raw .= chr(octdec($oct) & 0xff);
                    }
                    continue;
                }
                if ($c === '(') {
                    if ($depth++ === 0) {
                        continue;
                    }
                } elseif ($c === ')') {
                    if (--$depth === 0) {
                        break;
                    }
                }
                $raw .= $c;
            }
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
}
