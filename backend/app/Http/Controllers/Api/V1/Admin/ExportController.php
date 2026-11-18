<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Admin\PaymentQuery;
use App\Admin\UserQuery;
use App\Exports\CommissionsExport;
use App\Exports\PaymentsExport;
use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** CSV / Excel downloads of the filtered admin tables. Every export is audited. */
class ExportController extends Controller
{
    public function users(Request $request): BinaryFileResponse
    {
        $f = $request->validate([...UserQuery::rules(), ...$this->formatRules(), 'ids' => ['nullable', 'array', 'max:1000'], 'ids.*' => ['string']]);
        $query = UserQuery::build($f)->when($f['ids'] ?? null, fn ($q, $ids) => $q->whereIn('uuid', $ids));

        return $this->download($request, new UsersExport($query), 'users', $f);
    }

    public function payments(Request $request): BinaryFileResponse
    {
        $f = $request->validate([...PaymentQuery::rules(), ...$this->formatRules()]);

        return $this->download($request, new PaymentsExport(PaymentQuery::build($f)), 'payments', $f);
    }

    public function commissions(Request $request): BinaryFileResponse
    {
        $f = $request->validate([
            ...$this->formatRules(),
            'status' => ['nullable', 'in:pending,approved,paid,reversed'],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $query = Commission::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper((string) $v)))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse((string) $d)))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse((string) $d)->addDay()))
            ->latest('id');

        return $this->download($request, new CommissionsExport($query), 'commissions', $f);
    }

    /** @return array<string, mixed> */
    private function formatRules(): array
    {
        return ['format' => ['nullable', 'in:csv,xlsx']];
    }

    /** @param array<string, mixed> $filters */
    private function download(Request $request, Export $export, string $name, array $filters): BinaryFileResponse
    {
        $format = ($filters['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        unset($filters['format']);

        activity('admin')->causedBy($request->user())->event('export.'.$name)
            ->withProperties(['format' => $format, 'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== [])])
            ->log('Exported '.$name);

        $file = sprintf('%s-%s.%s', $name, now()->format('Ymd-His'), $format);

        return Excel::download($export, $file, $format === 'xlsx' ? ExcelWriter::XLSX : ExcelWriter::CSV);
    }
}
