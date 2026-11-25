<?php

declare(strict_types=1);

namespace WSystem\EInvoice\Validator;

use WSystem\EInvoice\InputError;

/**
 * Validation by the W System API (official KoSIT/CEN rules, engine "remote"). Opt-in only: the invoice file is sent
 * to the URL you configure (default https://api.wsystem.dev) and leaves this machine. Nothing in this package
 * uses it unless you construct it yourself; it is never a silent fallback.
 *
 *   $v = new HttpValidator(apiKey: getenv('WSYSTEM_API_KEY') ?: null);   // keyless works within the free tier
 *   $result = $v->validate($xmlOrPdf);
 */
final class HttpValidator implements ValidatorInterface
{
    /** @var \Closure(string, array<string, string>, string, int): array{0: int, 1: string} */
    private \Closure $transport;

    /**
     * @param (callable(string $url, array<string, string> $headers, string $body, int $timeout): array{0: int, 1: string})|null $transport
     */
    public function __construct(
        private string $baseUrl = 'https://api.wsystem.dev',
        private ?string $apiKey = null,
        ?callable $transport = null,
        private int $timeout = 60,
    ) {
        if (!preg_match('~^https?://~', $baseUrl)) {
            throw new \InvalidArgumentException('baseUrl must be an http(s) URL');
        }
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : \Closure::fromCallable([self::class, 'post']);
    }

    public function validate(string $data, string $rules = 'auto'): array
    {
        $rules = strtolower($rules === '' ? 'auto' : $rules);
        if (!in_array($rules, ['auto', 'en16931', 'xrechnung'], true)) {
            throw new InputError('rules must be auto, en16931 or xrechnung (peppol is not available yet)');
        }
        if ($data === '') {
            throw new InputError('empty body: send the invoice XML or PDF as the request body');
        }
        $headers = [
            'Content-Type' => str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF-') ? 'application/pdf' : 'application/xml',
            'Accept' => 'application/json',
            'User-Agent' => 'wsystem-einvoice-php',
        ];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }
        $url = rtrim($this->baseUrl, '/') . '/einvoice/validate?rules=' . rawurlencode($rules);
        [$status, $body] = ($this->transport)($url, $headers, $data, $this->timeout);
        $json = json_decode($body, true);
        if ($status === 400 && is_array($json)) {
            throw new InputError((string) ($json['message'] ?? $json['detail'] ?? $json['error'] ?? 'the API rejected the input'));
        }
        if ($status !== 200 || !is_array($json) || !array_key_exists('valid', $json)) {
            $msg = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
            throw new \RuntimeException("W System API answered HTTP {$status}" . ($msg !== '' ? ": {$msg}" : '') . " ({$url})");
        }
        $official = ($json['checked']['EN16931'] ?? null) === true;
        return ['engine' => 'remote', 'backend' => 'remote', 'conformance' => $official ? 'official' : 'pre-check',
            'remote_url' => rtrim($this->baseUrl, '/')] + $json;
    }

    /** @return array{0: int, 1: string} */
    private static function post(string $url, array $headers, string $body, int $timeout): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body,
            'timeout' => $timeout, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($resp === false && $status === 0) {
            throw new \RuntimeException("could not reach {$url}");
        }
        return [$status, (string) $resp];
    }
}
