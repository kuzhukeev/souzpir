<?php

namespace App\Shell\Auth\PasswordDelivery\Jobs;

use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Payload contains only the opaque intent identifier. */
final class DeliverPassword implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $intentId) {}

    public function handle(#[\SensitiveParameter] PasswordDelivery $delivery, #[\SensitiveParameter] PasswordTransport $transport): void
    {
        try {
            $claim = $delivery->claim($this->intentId);
            if ($claim) {
                $delivery->send($claim, $transport);
            }
        } catch (Throwable) {
            // Never retain the original exception or its sensitive stack arguments.
            throw new \RuntimeException('Password delivery processing failed.');
        }
    }
}
