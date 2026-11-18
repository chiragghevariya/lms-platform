<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Analytics\AnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Business analytics for the admin dashboard (cached; see AnalyticsService for definitions). */
class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /** Dashboard: KPI cards, revenue chart, members by tier, signup funnel, top courses. */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to, $currency] = $this->range($request);

        return response()->json(['data' => [
            'summary' => $this->analytics->summary($from, $to, $currency),
            'revenue' => $this->analytics->revenue($from, $to, $currency),
            'members' => $this->analytics->membership($from, $to, $currency),
            'funnel' => $this->analytics->funnel($from, $to),
            'top_courses' => $this->analytics->learning($from, $to, $currency)['top_courses'],
        ], 'meta' => $this->meta($from, $to, $currency)]);
    }

    public function revenue(Request $request): JsonResponse
    {
        [$from, $to, $currency] = $this->range($request);

        return response()->json(['data' => $this->analytics->revenue($from, $to, $currency), 'meta' => $this->meta($from, $to, $currency)]);
    }

    public function members(Request $request): JsonResponse
    {
        [$from, $to, $currency] = $this->range($request);

        return response()->json(['data' => [
            ...$this->analytics->membership($from, $to, $currency),
            'funnel' => $this->analytics->funnel($from, $to),
            'mrr_trend' => $this->analytics->mrrTrend($currency, 90),
        ], 'meta' => $this->meta($from, $to, $currency)]);
    }

    public function learning(Request $request): JsonResponse
    {
        [$from, $to, $currency] = $this->range($request);

        return response()->json(['data' => $this->analytics->learning($from, $to, $currency), 'meta' => $this->meta($from, $to, $currency)]);
    }

    public function affiliates(Request $request): JsonResponse
    {
        [$from, $to, $currency] = $this->range($request);

        return response()->json(['data' => $this->analytics->affiliates($from, $to, $currency), 'meta' => $this->meta($from, $to, $currency)]);
    }

    public function cohorts(Request $request): JsonResponse
    {
        $months = (int) ($request->validate(['months' => ['nullable', 'integer', 'between:3,24']])['months'] ?? 12);

        return response()->json(['data' => $this->analytics->cohorts($months)]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function range(Request $request): array
    {
        $tz = (string) config('analytics.timezone');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $to = $data['to'] ?? now($tz)->toDateString();
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->toDateString();
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 731, 422, 'The range can be at most two years.');

        return [$from, $to, strtoupper($data['currency'] ?? (string) config('billing.default_currency'))];
    }

    /** @return array<string, mixed> */
    private function meta(string $from, string $to, string $currency): array
    {
        return ['from' => $from, 'to' => $to, 'currency' => $currency, 'currencies' => $this->analytics->currencies(), 'timezone' => config('analytics.timezone')];
    }
}
