<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

/**
 * `wsystem-einvoice rules:update`: checks the published rule-pack manifest (rules/manifest.json in the repository) and,
 * when it names a pack newer than the bundled one, downloads that official, unmodified upstream zip, verifies its
 * SHA-256 and size against the manifest (any mismatch is refused), and unpacks it under the rules directory.
 * Nothing is downloaded at validation time; the validator only uses a downloaded pack when you point it there with
 * WSYSTEM_EINVOICE_RULES=<dir> (or new RulePack($dir)). The bundled pack is always the default.
 */
final class RuleUpdater
{
    public const MANIFEST_URL = 'https://raw.githubusercontent.com/wsystemdev/einvoice-php/main/rules/manifest.json';
    public const BUNDLED_PACK = 'xrechnung-3.0.2-' . RulePack::VERSION;

    /**
     * @param resource|null $out
     * @return int 0 = up to date or installed, 1 = failure
     */
    public static function update(?string $manifestUrl = null, ?string $targetDir = null, $out = null): int
    {
        $out ??= STDOUT;
        try {
            $msg = self::run($manifestUrl ?? (getenv('WSYSTEM_EINVOICE_MANIFEST') ?: self::MANIFEST_URL), $targetDir ?? self::defaultDir());
            fwrite($out, $msg . "\n");
            return 0;
        } catch (\RuntimeException $e) {
            fwrite($out, 'rules:update failed: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    public static function defaultDir(): string
    {
        $base = getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache');
        return rtrim($base, '/') . '/wsystem-einvoice/rules';
    }

    private static function run(string $manifestUrl, string $targetDir): string
    {
        $m = json_decode(self::fetch($manifestUrl, 1_000_000), true);
        if (!is_array($m) || ($m['schema'] ?? null) !== 1 || !isset($m['current'], $m['packs'][$m['current']])) {
            throw new \RuntimeException("unexpected manifest format at {$manifestUrl}");
        }
        $id = (string) $m['current'];
        $pack = $m['packs'][$id];
        if ($id === self::BUNDLED_PACK) {
            return "rules are current: {$id} is bundled with this package (sha256 " . substr(RulePack::SHA256, 0, 12) . '…). Nothing to download.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id) || !preg_match('/^[0-9a-f]{64}$/', (string) ($pack['sha256'] ?? '')) || !preg_match(preg_match('~^https?://~', $manifestUrl) ? '~^https://~' : '~^(https|file)://~', (string) ($pack['url'] ?? ''))) {
            throw new \RuntimeException("manifest entry {$id} is incomplete (needs a https url and a sha256)");
        }
        $dest = rtrim($targetDir, '/') . '/' . $id;
        if (is_file($dest . '/.sha256') && trim((string) file_get_contents($dest . '/.sha256')) === $pack['sha256']) {
            return "rules are current: {$id} is already installed in {$dest}. Use it with WSYSTEM_EINVOICE_RULES={$dest}";
        }
        $zip = self::fetch((string) $pack['url'], 50_000_000);
        $sha = hash('sha256', $zip);
        if (!hash_equals((string) $pack['sha256'], $sha)) {
            throw new \RuntimeException("checksum mismatch for {$id}: expected {$pack['sha256']}, got {$sha}; refusing the download");
        }
        if (isset($pack['bytes']) && (int) $pack['bytes'] !== strlen($zip)) {
            throw new \RuntimeException("size mismatch for {$id}; refusing the download");
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('the zip extension (ext-zip) is needed to unpack rule packs');
        }
        $tmpZip = tempnam(sys_get_temp_dir(), 'wsr');
        file_put_contents($tmpZip, $zip);
        $za = new \ZipArchive();
        if ($za->open($tmpZip) !== true) {
            @unlink($tmpZip);
            throw new \RuntimeException("{$id}: not a readable zip");
        }
        for ($i = 0; $i < $za->numFiles; $i++) {
            $n = (string) $za->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\')) {
                $za->close();
                @unlink($tmpZip);
                throw new \RuntimeException("{$id}: unsafe path in zip ({$n}); refusing");
            }
        }
        $stage = $dest . '.tmp' . getmypid();
        if (!is_dir($stage) && !mkdir($stage, 0o755, true) && !is_dir($stage)) {
            throw new \RuntimeException("cannot create {$stage}");
        }
        $za->extractTo($stage);
        $za->close();
        @unlink($tmpZip);
        file_put_contents($stage . '/.sha256', $sha . "\n");
        if (is_dir($dest)) {
            self::rmTree($dest);
        }
        rename($stage, $dest);
        return "installed {$id} (sha256 verified) in {$dest}. The bundled pack stays the default; use the new one with "
            . "WSYSTEM_EINVOICE_RULES={$dest}. Note: the php-core engine's coverage list (docs/validator-rules.md) is verified for the bundled pack.";
    }

    private static function fetch(string $url, int $max): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'wsystem-einvoice-rules-update']]);
        $data = @file_get_contents($url, false, $ctx, 0, $max + 1);
        if ($data === false) {
            throw new \RuntimeException("could not download {$url}");
        }
        if (strlen($data) > $max) {
            throw new \RuntimeException("{$url} is larger than expected");
        }
        return $data;
    }

    private static function rmTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
