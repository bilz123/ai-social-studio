<?php

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor: replaces the value of any context/extra key that looks like a secret
 * (password, token, authorization header, signature, cookie, ...) before the line is written.
 */
class RedactSensitiveData implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    private const FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'signature',
        'cookie',
        'api_key',
        'apikey',
        'credential',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
