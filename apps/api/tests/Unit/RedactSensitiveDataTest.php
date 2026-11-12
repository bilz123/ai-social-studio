<?php

namespace Tests\Unit;

use App\Support\Logging\RedactSensitiveData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactSensitiveDataTest extends TestCase
{
    public function test_it_redacts_sensitive_keys_at_any_depth_and_keeps_the_rest(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'hello',
            context: [
                'workspace_id' => 7,
                'Authorization' => 'Bearer abc',
                'nested' => ['api_token' => 'xyz', 'ok' => 1],
            ],
            extra: ['session_cookie' => 'c'],
        );

        $processed = (new RedactSensitiveData)($record);

        $this->assertSame(7, $processed->context['workspace_id']);
        $this->assertSame(RedactSensitiveData::REDACTED, $processed->context['Authorization']);
        $this->assertSame(RedactSensitiveData::REDACTED, $processed->context['nested']['api_token']);
        $this->assertSame(1, $processed->context['nested']['ok']);
        $this->assertSame(RedactSensitiveData::REDACTED, $processed->extra['session_cookie']);
        $this->assertSame('hello', $processed->message);
    }
}
