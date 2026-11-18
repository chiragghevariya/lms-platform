<?php

namespace App\Billing;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Gap-free sequential numbers per Indian financial year (Apr–Mar), e.g. INV-2026-27-000042.
     * Uses a row lock so concurrent webhooks never share a number.
     */
    public function nextNumber(): string
    {
        $now = now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;
        $series = config('billing.invoices.prefix').'-'.$startYear.'-'.substr((string) ($startYear + 1), -2);

        return DB::transaction(function () use ($series) {
            DB::table('invoice_counters')->insertOrIgnore(['series' => $series, 'last_number' => 0]);
            $row = DB::table('invoice_counters')->where('series', $series)->lockForUpdate()->first();
            $next = ((int) ($row->last_number ?? 0)) + 1;
            DB::table('invoice_counters')->where('series', $series)->update(['last_number' => $next]);

            return $series.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Renders and stores the PDF on the private invoice disk; returns the path. */
    public function generatePdf(Payment $payment): string
    {
        $payment->loadMissing('user', 'planPrice.plan');
        $pdf = Pdf::loadView('invoices.invoice', [
            'payment' => $payment,
            'seller' => config('billing.seller'),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        $path = "invoices/{$payment->user->uuid}/{$payment->invoice_number}.pdf";
        Storage::disk((string) config('billing.invoices.disk'))->put($path, $pdf->output());

        $payment->forceFill(['pdf_path' => $path])->save();

        return $path;
    }
}
