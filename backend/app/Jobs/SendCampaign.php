<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Notifications\Platform\CampaignNotification;
use App\Notify\Segment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/** Fans a campaign out to its segment in chunks; each recipient × channel is its own rate-limited queued job. */
class SendCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $campaignId) {}

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || ! in_array($campaign->status, ['scheduled', 'sending'], true)) {
            return;
        }
        $campaign->forceFill(['status' => 'sending', 'started_at' => $campaign->started_at ?? now(), 'audience_count' => Segment::query($campaign->segment)->count()])->save();

        Segment::query($campaign->segment)->chunkById(500, function ($users) use ($campaign) {
            if (Campaign::whereKey($campaign->id)->value('status') === 'cancelled') {
                return false;
            }
            Notification::send($users, new CampaignNotification($campaign));
        });

        if ($campaign->fresh()?->status === 'sending') {
            $campaign->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
        }
    }
}
