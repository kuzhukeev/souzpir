<?php

namespace App\Shell\Profile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use LogicException;

/** Feature-owned messages keep production validation readable without changing shared language files. */
final class ProfileValidation
{
    public static function validate(#[\SensitiveParameter] Request $request, array $rules): array
    {
        return $request->validate($rules, self::messages(), self::attributes());
    }

    public static function messages(): array
    {
        return self::translations('messages');
    }

    public static function attributes(): array
    {
        return self::translations('attributes');
    }

    private static function translations(string $key): array
    {
        $values = Lang::get('Shell/Profile/validation.'.$key);
        if (! is_array($values)) {
            throw new LogicException('Profile validation translations are unavailable.');
        }

        return $values;
    }
}
