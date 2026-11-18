{{-- PDF invoice (dompdf). The only Blade outside emails: rendered server-side to a file, never served as a page. --}}
@php
    $m = fn (int $amount) => $payment->money($amount)->formatted();
    $address = collect($payment->billing_address ?? [])->filter()->implode(', ');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $payment->invoice_number }}</title>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 12px; color: #1f2937; margin: 24px; }
    h1 { font-size: 22px; margin: 0; color: #4338ca; }
    .muted { color: #6b7280; }
    .row { width: 100%; margin-top: 24px; }
    .col { display: inline-block; vertical-align: top; width: 49%; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 28px; }
    table.items th { text-align: left; border-bottom: 2px solid #e5e7eb; padding: 8px 4px; font-size: 11px; text-transform: uppercase; color: #6b7280; }
    table.items td { padding: 10px 4px; border-bottom: 1px solid #f3f4f6; }
    .right { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-top: 16px; }
    .totals td { padding: 4px; }
    .grand td { font-weight: bold; font-size: 14px; border-top: 2px solid #1f2937; padding-top: 8px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #dcfce7; color: #166534; font-size: 11px; }
</style>
</head>
<body>
    <div class="row" style="margin-top:0">
        <div class="col">
            <h1>{{ $seller['name'] }}</h1>
            @if($seller['address'])<div class="muted">{{ $seller['address'] }}</div>@endif
            @if($seller['tax_id'])<div class="muted">GSTIN/VAT: {{ $seller['tax_id'] }}</div>@endif
            @if($seller['email'])<div class="muted">{{ $seller['email'] }}</div>@endif
        </div>
        <div class="col right">
            <div style="font-size:18px;font-weight:bold">TAX INVOICE</div>
            <div>No. <strong>{{ $payment->invoice_number }}</strong></div>
            <div class="muted">Date: {{ $payment->paid_at?->format('d M Y') }}</div>
            <div style="margin-top:6px"><span class="badge">PAID</span></div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <div class="muted" style="text-transform:uppercase;font-size:10px">Billed to</div>
            <div><strong>{{ $payment->billing_name }}</strong></div>
            <div>{{ $payment->billing_email }}</div>
            @if($address)<div>{{ $address }}</div>@endif
            @if($payment->billing_country)<div>{{ $payment->billing_country }}</div>@endif
            @if($payment->billing_tax_id)<div>Tax ID: {{ $payment->billing_tax_id }}</div>@endif
        </div>
        <div class="col right">
            <div class="muted" style="text-transform:uppercase;font-size:10px">Payment</div>
            <div>{{ ucfirst($payment->gateway->value) }}</div>
            <div class="muted">{{ $payment->gateway_payment_id }}</div>
        </div>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th>Period</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $payment->description }}</td>
                <td>
                    @if($payment->period_start && $payment->period_end)
                        {{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}
                    @elseif($payment->planPrice?->isLifetime()) Lifetime access @else — @endif
                </td>
                <td class="right">{{ $m($payment->amount + $payment->discount_amount) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        @if($payment->discount_amount > 0)
            <tr><td>Discount</td><td class="right">− {{ $m($payment->discount_amount) }}</td></tr>
        @endif
        <tr><td>Taxable value</td><td class="right">{{ $m($payment->subtotal) }}</td></tr>
        @if($payment->tax_amount > 0)
            <tr><td>{{ $payment->tax_name }} @ {{ rtrim(rtrim((string) $payment->tax_rate, '0'), '.') }}%</td><td class="right">{{ $m($payment->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total paid</td><td class="right">{{ $m($payment->amount) }}</td></tr>
    </table>

    <p class="muted" style="margin-top:40px">Prices are inclusive of applicable taxes. This is a computer-generated invoice.</p>
</body>
</html>
