<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audits successful admin writes on routes whose controllers predate the audit log
 * (affiliates, payouts, instructor applications): who, which route, which records, what input.
 */
class AuditAdminWrite
{
    private const REDACT = ['password', 'token', 'secret', 'payout_details', 'account_number', 'ifsc', 'upi'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->isSuccessful() && $request->user()) {
            $route = $request->route();
            $subject = collect($route?->parameters() ?? [])->first(fn ($p) => $p instanceof Model);
            $input = collect($request->except(self::REDACT))->map(fn ($v) => is_string($v) ? mb_substr($v, 0, 500) : $v)->all();

            $name = str_replace('api.v1.', '', (string) ($route?->getName() ?? $request->path()));
            $log = activity('admin')->causedBy($request->user())->event($name)
                ->withProperties(array_filter([
                    'route' => collect($route?->parameters() ?? [])->map(fn ($p) => $p instanceof Model ? ($p->getAttribute('uuid') ?? $p->getKey()) : $p)->all(),
                    'input' => $input ?: null,
                ]));
            if ($subject instanceof Model) {
                $log->performedOn($subject);
            }
            $log->log(ucfirst(str_replace(['admin.', '.', '-'], ['', ' ', ' '], $name)));
        }

        return $response;
    }
}
