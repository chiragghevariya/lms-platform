<?php

namespace App\Jobs;

use App\Billing\Webhooks\RazorpayEventHandler;
use App\Billing\Webhooks\StripeEventHandler;
use App\Enums\Gateway;
use App\Enums\WebhookStatus;
use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Processes a stored webhook exactly once; failures are recorded and retried with backoff. */
class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(StripeEventHandler $stripe, RazorpayEventHandler $razorpay): void
    {
        DB::transaction(function () use ($stripe, $razorpay) {
            $event = WebhookEvent::whereKey($this->webhookEventId)->lockForUpdate()->first();
            if (! $event || in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Ignored], true)) {
                return;
            }

            $event->increment('attempts');

            try {
                $handled = match ($event->gateway) {
                    Gateway::Stripe => $stripe->handle($event->data()),
                    Gateway::Razorpay => $razorpay->handle($event->data()),
                    Gateway::Manual => false,
                };
            } catch (Throwable $e) {
                $event->forceFill(['status' => WebhookStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                throw $e;
            }

            $event->forceFill([
                'status' => $handled ? WebhookStatus::Processed : WebhookStatus::Ignored,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        });
    }
}
