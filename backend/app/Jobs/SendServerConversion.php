<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Server-side "purchase" conversion (optional, free): GA4 Measurement Protocol and Meta
 * Conversions API. Sent from the server so ad blockers don't hide revenue. The browser
 * pixel never sends Purchase, so nothing is double counted; event_id = payment uuid.
 * Personal data goes to Meta only SHA-256 hashed, as Meta requires.
 */
class SendServerConversion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $paymentId)
    {
        $this->onQueue('default');
    }

    public static function enabled(): bool
    {
        return (filled(config('tracking.ga4.measurement_id')) && filled(config('tracking.ga4.api_secret')))
            || (filled(config('tracking.meta.pixel_id')) && filled(config('tracking.meta.access_token')));
    }

    public function handle(): void
    {
        $payment = Payment::with('user', 'planPrice.plan')->find($this->paymentId);
        if (! $payment) {
            return;
        }
        $value = round($payment->amount / 100, 2);
        $item = $payment->course_id ? 'course:'.$payment->course_id : 'plan:'.($payment->planPrice?->plan->slug ?? 'membership');

        if (filled(config('tracking.ga4.measurement_id')) && filled(config('tracking.ga4.api_secret'))) {
            Http::timeout(10)->post('https://www.google-analytics.com/mp/collect?'.http_build_query([
                'measurement_id' => config('tracking.ga4.measurement_id'),
                'api_secret' => config('tracking.ga4.api_secret'),
            ]), [
                // No browser client id on the server: a stable pseudonymous id per user.
                'client_id' => substr(hash('sha256', 'ga:'.$payment->user->uuid), 0, 20),
                'user_id' => $payment->user->uuid,
                'events' => [[
                    'name' => 'purchase',
                    'params' => [
                        'transaction_id' => $payment->uuid,
                        'currency' => $payment->currency,
                        'value' => $value,
                        'tax' => round($payment->tax_amount / 100, 2),
                        'items' => [['item_id' => $item, 'item_name' => (string) $payment->description, 'price' => $value, 'quantity' => 1]],
                    ],
                ]],
            ])->throw();
        }

        if (filled(config('tracking.meta.pixel_id')) && filled(config('tracking.meta.access_token'))) {
            $hash = fn (?string $v) => filled($v) ? hash('sha256', strtolower(trim((string) $v))) : null;
            Http::timeout(10)->post(sprintf('https://graph.facebook.com/%s/%s/events', config('tracking.meta.graph_version'), config('tracking.meta.pixel_id')), array_filter([
                'access_token' => config('tracking.meta.access_token'),
                'test_event_code' => config('tracking.meta.test_event_code') ?: null,
                'data' => [[
                    'event_name' => 'Purchase',
                    'event_time' => ($payment->paid_at ?? now())->getTimestamp(),
                    'event_id' => $payment->uuid,
                    'action_source' => 'website',
                    'event_source_url' => config('lms.frontend_url'),
                    'user_data' => array_filter([
                        'em' => [$hash($payment->user->email)],
                        'ph' => $payment->user->phone ? [$hash(preg_replace('/\D/', '', $payment->user->phone))] : null,
                        'country' => $payment->billing_country ? [$hash($payment->billing_country)] : null,
                        'external_id' => [$hash($payment->user->uuid)],
                    ]),
                    'custom_data' => ['currency' => $payment->currency, 'value' => $value, 'content_ids' => [$item], 'content_type' => 'product'],
                ]],
            ]))->throw();
        }
    }
}
