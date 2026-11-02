<?php

namespace App\Seller\Catalog;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

final class YmlFeedStore
{
    private function path(string $organization, string $brand): string
    {
        if (! Str::isUlid($organization) || ! Str::isUlid($brand)) {
            throw new LogicException('Invalid tenant.');
        }

        return 'seller/catalog/feeds/'.$organization.'/'.$brand.'.enc';
    }

    public function read(string $organization, string $brand): ?array
    {
        $disk = Storage::disk('local');
        $path = $this->path($organization, $brand);
        if (! $disk->exists($path)) {
            return null;
        }

        return json_decode(Crypt::decryptString($disk->get($path)), true, 64, JSON_THROW_ON_ERROR);
    }

    public function write(string $organization, string $brand, array $settings): void
    {
        Storage::disk('local')->put($this->path($organization, $brand), Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)));
    }
}
