<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Tests\Validator;

use PHPUnit\Framework\TestCase;
use WSystem\EInvoice\Validator\RuleUpdater;

final class RuleUpdaterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/wsr-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function update(array $manifest): array
    {
        file_put_contents("$this->tmp/manifest.json", json_encode($manifest));
        $out = fopen('php://memory', 'w+');
        $code = RuleUpdater::update("$this->tmp/manifest.json", "$this->tmp/rules", $out);
        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }

    public function testBundledPackIsCurrent(): void
    {
        [$code, $msg] = $this->update(json_decode((string) file_get_contents(__DIR__ . '/../../rules/manifest.json'), true));
        $this->assertSame(0, $code);
        $this->assertStringContainsString('is bundled with this package', $msg);
        $this->assertDirectoryDoesNotExist("$this->tmp/rules");
    }

    public function testInstallsVerifiedPackAndRefusesChecksumMismatch(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not available');
        }
        $zip = "$this->tmp/pack.zip";
        $za = new \ZipArchive();
        $za->open($zip, \ZipArchive::CREATE);
        $za->addFromString('resources/ubl/2.1/xsl/EN16931-UBL-validation.xsl', '<x/>');
        $za->close();
        $sha = hash_file('sha256', $zip);
        $pack = ['url' => 'file://' . $zip, 'sha256' => str_repeat('0', 64), 'bytes' => filesize($zip)];
        [$code, $msg] = $this->update(['schema' => 1, 'current' => 'test-pack', 'packs' => ['test-pack' => $pack]]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('checksum mismatch', $msg);
        $this->assertDirectoryDoesNotExist("$this->tmp/rules/test-pack");

        $pack['sha256'] = $sha;
        [$code, $msg] = $this->update(['schema' => 1, 'current' => 'test-pack', 'packs' => ['test-pack' => $pack]]);
        $this->assertSame(0, $code, $msg);
        $this->assertFileExists("$this->tmp/rules/test-pack/resources/ubl/2.1/xsl/EN16931-UBL-validation.xsl");
        [$code, $msg] = $this->update(['schema' => 1, 'current' => 'test-pack', 'packs' => ['test-pack' => $pack]]);
        $this->assertStringContainsString('already installed', $msg);
    }

    public function testRejectsMalformedManifest(): void
    {
        [$code, $msg] = $this->update(['schema' => 2]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unexpected manifest format', $msg);
    }
}
