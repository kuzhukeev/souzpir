<?php

namespace App\Shell\Auth\VerificationDelivery\Jobs;

use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** The queue contains only an opaque intent ID, never a session ID, URL, address or claim token. */
final class DeliverVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $intentId) {}

    public function handle(VerificationDelivery $delivery, VerificationTransport $transport): void
    {
        $claim = $delivery->claim($this->intentId);
        if ($claim) {
            $delivery->send($claim, $transport);
        }
    }
}
