<?php

namespace App\Seller\Channels\Core;

use App\Seller\Conversations\Models\SellerModel;
use LogicException;

final class ChannelAccount extends SellerModel
{
    protected $hidden = ['credentials', 'managers_chat_id'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'managers_chat_id' => 'encrypted', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $account): void {
            $check = function (array $values) use (&$check): void {
                foreach ($values as $key => $value) {
                    if (preg_match('/password|secret|token|credential|api.?key|authorization/i', (string) $key)) {
                        throw new LogicException('Store credentials in the encrypted credentials field.');
                    }
                    if (is_array($value)) {
                        $check($value);
                    }
                }
            };
            $check($account->settings ?? []);
        });
    }
}
