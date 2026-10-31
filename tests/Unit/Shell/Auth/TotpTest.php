<?php

namespace Tests\Unit\Shell\Auth;

use App\Shell\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function test_rfc_6238_sha1_vectors_and_replay_protection(): void
    {
        $totp = new Totp;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $totp->at($secret, 1));
        $this->assertSame('081804', $totp->at($secret, 37037036));
        $this->assertSame(1, $totp->matchingCounter($secret, '287082', 59, null));
        $this->assertNull($totp->matchingCounter($secret, '287082', 59, 1));
        $this->assertNull($totp->matchingCounter($secret, 'not-code', 59, null));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $totp->generateSecret());
    }
}
