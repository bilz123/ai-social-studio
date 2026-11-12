<?php

namespace App\Support\Auth;

use Closure;
use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** bcrypt silently ignores everything after 72 bytes, so refuse longer passwords outright. */
    public const MAX_BYTES = 72;

    /**
     * Rules for choosing a new password (registration, reset, change).
     *
     * @return array<int, mixed>
     */
    public static function new(): array
    {
        return [
            'required',
            'string',
            Password::defaults(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                    $fail('The password may not be longer than '.self::MAX_BYTES.' bytes.');
                }
            },
        ];
    }
}
