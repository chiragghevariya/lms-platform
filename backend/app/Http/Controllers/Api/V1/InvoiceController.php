<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\InvoiceService;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    /** Payment history (succeeded + failed) — succeeded ones carry an invoice. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PaymentResource::collection(
            $user->payments()->whereIn('status', [PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Refunded])
                ->latest('id')->paginate(20)
        );
    }

    public function download(Payment $payment, InvoiceService $invoices): StreamedResponse
    {
        $this->authorize('view', $payment);
        abort_if($payment->invoice_number === null, 404, 'No invoice for this payment.');

        $disk = Storage::disk((string) config('billing.invoices.disk'));
        if (! $payment->pdf_path || ! $disk->exists($payment->pdf_path)) {
            $invoices->generatePdf($payment); // generated lazily if the queued job hasn't run yet
        }

        return $disk->download((string) $payment->pdf_path, $payment->invoice_number.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
