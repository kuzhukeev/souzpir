<?php

namespace App\Shell\Brands;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class BrandDomain extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['host', 'purpose', 'verification_method', 'status', 'verification_token', 'verified_at', 'last_checked_at', 'last_error', 'check_version'];

    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime', 'check_version' => 'integer'];
    }

    public function instructions(): array
    {
        return ['file_path' => '/.well-known/eyon-domain-verification.txt', 'file_content' => $this->verification_token, 'dns_name' => '_eyon.'.$this->host, 'dns_value' => $this->verification_token];
    }
}
