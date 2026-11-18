<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Security\Captcha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Admin-editable site settings stored in `settings` and applied over `config()` on every
 * boot (web requests and, via a queue hook, long-running workers). Anything not set in
 * the admin falls back to the .env/config value, so a fresh install works unchanged.
 *
 * Secrets (gateway keys, API secrets) are encrypted at rest, never returned to the
 * browser (only "set" + last 4 chars), and only editable with `settings.secrets`.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site-settings:v1';

    /**
     * group => field => [type, config path(s), secret?, validation rules].
     *
     * @var array<string, array<string, array{type: string, config?: list<string>, secret?: bool, rules: list<mixed>}>>
     */
    public const SCHEMA = [
        'branding' => [
            'name' => ['type' => 'string', 'config' => ['lms.brand.name', 'app.name'], 'rules' => ['required', 'string', 'max:60']],
            'tagline' => ['type' => 'string', 'config' => ['lms.brand.tagline'], 'rules' => ['nullable', 'string', 'max:120']],
            'logo_url' => ['type' => 'string', 'rules' => ['nullable', 'url:https,http', 'max:500']],
            'primary_color' => ['type' => 'string', 'rules' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']],
            'support_email' => ['type' => 'string', 'config' => ['lms.contact_email'], 'rules' => ['nullable', 'email', 'max:120']],
        ],
        'seo' => [
            'default_title' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:70']],
            'default_description' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:170']],
            'og_image_url' => ['type' => 'string', 'rules' => ['nullable', 'url:https,http', 'max:500']],
            'twitter_handle' => ['type' => 'string', 'rules' => ['nullable', 'regex:/^@?[A-Za-z0-9_]{1,15}$/']],
            'allow_indexing' => ['type' => 'bool', 'rules' => ['boolean']],
        ],
        'tax' => [
            // [{country: "IN", name: "GST", rate: 18}]
            'rates' => ['type' => 'array', 'config' => ['billing.tax.rates'], 'rules' => ['array', 'max:60']],
            'seller_name' => ['type' => 'string', 'config' => ['billing.seller.name'], 'rules' => ['nullable', 'string', 'max:120']],
            'seller_address' => ['type' => 'string', 'config' => ['billing.seller.address'], 'rules' => ['nullable', 'string', 'max:500']],
            'seller_tax_id' => ['type' => 'string', 'config' => ['billing.seller.tax_id'], 'rules' => ['nullable', 'string', 'max:32']],
        ],
        'payments' => [
            'stripe_key' => ['type' => 'string', 'config' => ['cashier.key'], 'rules' => ['nullable', 'string', 'starts_with:pk_', 'max:200']],
            'stripe_secret' => ['type' => 'string', 'config' => ['cashier.secret'], 'secret' => true, 'rules' => ['nullable', 'string', 'regex:/^(sk|rk)_/', 'max:200']],
            'stripe_webhook_secret' => ['type' => 'string', 'config' => ['billing.stripe.webhook_secret', 'cashier.webhook.secret'], 'secret' => true, 'rules' => ['nullable', 'string', 'starts_with:whsec_', 'max:200']],
            'razorpay_key_id' => ['type' => 'string', 'config' => ['billing.razorpay.key_id'], 'rules' => ['nullable', 'string', 'starts_with:rzp_', 'max:100']],
            'razorpay_key_secret' => ['type' => 'string', 'config' => ['billing.razorpay.key_secret'], 'secret' => true, 'rules' => ['nullable', 'string', 'max:200']],
            'razorpay_webhook_secret' => ['type' => 'string', 'config' => ['billing.razorpay.webhook_secret'], 'secret' => true, 'rules' => ['nullable', 'string', 'max:200']],
        ],
        'tracking' => [
            'ga4_measurement_id' => ['type' => 'string', 'config' => ['tracking.ga4.measurement_id'], 'rules' => ['nullable', 'regex:/^G-[A-Z0-9]{4,12}$/']],
            'ga4_api_secret' => ['type' => 'string', 'config' => ['tracking.ga4.api_secret'], 'secret' => true, 'rules' => ['nullable', 'string', 'max:100']],
            'meta_pixel_id' => ['type' => 'string', 'config' => ['tracking.meta.pixel_id'], 'rules' => ['nullable', 'regex:/^\d{6,20}$/']],
            'meta_capi_token' => ['type' => 'string', 'config' => ['tracking.meta.access_token'], 'secret' => true, 'rules' => ['nullable', 'string', 'max:400']],
            'meta_test_event_code' => ['type' => 'string', 'config' => ['tracking.meta.test_event_code'], 'rules' => ['nullable', 'string', 'max:40']],
        ],
    ];

    /** Groups whose every field needs `settings.secrets` (gateway credentials). */
    public const SECRET_GROUPS = ['payments'];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @var array<string, mixed> config path => value before it was first overridden */
    private array $baseline = [];

    /** @return array<string, mixed> "group.field" => decoded value, only for rows that exist */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $load = fn () => Setting::all()
            ->mapWithKeys(fn (Setting $s) => [$s->key => ['value' => $s->value, 'is_secret' => $s->is_secret]])->all();
        try {
            try {
                /** @var array<string, array{value: string|null, is_secret: bool}> $rows */
                $rows = Cache::rememberForever(self::CACHE_KEY, $load);
            } catch (QueryException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e); // cache (Redis) down: read the database directly rather than failing every request
                $rows = $load();
            }
        } catch (QueryException) {
            return $this->values = []; // before migrations ran
        }

        $values = [];
        foreach ($rows as $key => $row) {
            if ($row['value'] === null) {
                continue;
            }
            try {
                $values[$key] = json_decode($row['is_secret'] ? Crypt::decryptString($row['value']) : $row['value'], true);
            } catch (\Throwable) {
                // APP_KEY rotated without re-saving secrets: ignore the stale value, fall back to .env.
            }
        }

        return $this->values = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** Pushes stored values over config(). Called on boot and before each queued job. */
    public function apply(): void
    {
        // Undo earlier overrides first, so a cleared setting falls back to the .env value.
        config($this->baseline);

        foreach (self::SCHEMA as $group => $fields) {
            foreach ($fields as $name => $def) {
                $value = $this->all()["{$group}.{$name}"] ?? null;
                if ($value === null || $value === '' || empty($def['config'])) {
                    continue;
                }
                if ($group === 'tax' && $name === 'rates') {
                    $value = collect((array) $value)->mapWithKeys(fn ($r) => [strtoupper($r['country']) => ['name' => $r['name'], 'rate' => (float) $r['rate']]])->all();
                }
                foreach ($def['config'] as $path) {
                    // Remember the .env-based value the first time this path is overridden.
                    if (! array_key_exists($path, $this->baseline)) {
                        $this->baseline[$path] = config($path);
                    }
                    config([$path => $value]);
                }
            }
        }
    }

    /**
     * Values for the admin form: plain fields show the effective value (stored or config),
     * secrets only say whether they're set.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forAdmin(bool $withSecrets): array
    {
        $out = [];
        foreach (self::SCHEMA as $group => $fields) {
            if (in_array($group, self::SECRET_GROUPS, true) && ! $withSecrets) {
                continue;
            }
            foreach ($fields as $name => $def) {
                $stored = $this->all()["{$group}.{$name}"] ?? null;
                $effective = $stored ?? (isset($def['config']) ? config($def['config'][0]) : null);
                if ($group === 'tax' && $name === 'rates' && $stored === null) {
                    $effective = collect((array) config('billing.tax.rates'))->map(fn ($r, $c) => ['country' => $c, 'name' => $r['name'], 'rate' => $r['rate']])->values()->all();
                }
                $out[$group][$name] = ($def['secret'] ?? false)
                    ? ['set' => filled($effective), 'last4' => filled($effective) ? substr((string) $effective, -4) : null, 'source' => $stored !== null ? 'admin' : (filled($effective) ? 'env' : null)]
                    : $effective;
            }
        }

        return $out;
    }

    /**
     * Saves one group. Secret fields: omitted or "" keeps the current value; `clear` lists
     * secrets to remove (fall back to .env).
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $clear
     * @return list<string> changed keys
     */
    public function update(string $group, array $input, User $by, array $clear = []): array
    {
        $fields = self::SCHEMA[$group];
        $rules = [];
        foreach ($fields as $name => $def) {
            $rules[$name] = ['sometimes', ...$def['rules']];
        }
        if ($group === 'tax') {
            $rules += [
                'rates.*.country' => ['required', 'string', 'size:2', 'alpha', 'distinct:ignore_case'],
                'rates.*.name' => ['required', 'string', 'max:16'],
                'rates.*.rate' => ['required', 'numeric', 'min:0', 'max:50'],
            ];
        }
        $data = Validator::make($input, $rules)->validate();

        $unknown = array_diff($clear, array_keys(array_filter($fields, fn ($d) => $d['secret'] ?? false)));
        if ($unknown) {
            throw ValidationException::withMessages(['clear' => 'Only secret fields can be cleared.']);
        }

        $changed = [];
        DB::transaction(function () use ($group, $fields, $data, $clear, $by, &$changed) {
            foreach ($fields as $name => $def) {
                $key = "{$group}.{$name}";
                $secret = $def['secret'] ?? false;
                if (in_array($name, $clear, true)) {
                    Setting::whereKey($key)->delete() && $changed[] = $key;

                    continue;
                }
                if (! array_key_exists($name, $data) || ($secret && blank($data[$name]))) {
                    continue;
                }
                $value = $data[$name];
                if ($def['type'] === 'bool') {
                    $value = (bool) $value;
                }
                if ($name === 'twitter_handle' && filled($value)) {
                    $value = '@'.ltrim((string) $value, '@');
                }
                if ($name === 'rates') {
                    $value = array_map(fn ($r) => ['country' => strtoupper($r['country']), 'name' => $r['name'], 'rate' => (float) $r['rate']], (array) $value);
                }
                if ($this->get($key) === $value) {
                    continue;
                }
                $json = json_encode($value);
                Setting::updateOrCreate(['key' => $key], [
                    'value' => $secret ? Crypt::encryptString((string) $json) : $json,
                    'is_secret' => $secret,
                    'updated_by' => $by->id,
                ]);
                $changed[] = $key;
            }
        });

        if ($changed) {
            $this->flush();
            // Secrets are never written to the audit log — only which keys changed.
            activity('admin')->causedBy($by)->event('settings.updated')
                ->withProperties(['group' => $group, 'keys' => $changed])->log("Updated {$group} settings");
        }

        return $changed;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forever(self::CACHE_KEY.':version', (string) microtime(true));
        $this->reload();
    }

    /** Re-reads the (shared) cache and re-applies — used by workers after another process saved. */
    public function reload(): void
    {
        $this->values = null;
        $this->apply();
    }

    /** Version stamp so queue workers can re-apply after an admin change. */
    public function version(): ?string
    {
        $v = Cache::get(self::CACHE_KEY.':version');

        return is_string($v) ? $v : null;
    }

    /**
     * Branding, SEO and the public tracking IDs for the SPA (`GET /site`).
     *
     * @return array<string, mixed>
     */
    public function public(): array
    {
        $s = $this->all();

        return [
            'brand' => [
                'name' => config('lms.brand.name'),
                'tagline' => config('lms.brand.tagline'),
                'logo_url' => $s['branding.logo_url'] ?? null,
                'primary_color' => $s['branding.primary_color'] ?? null,
                'support_email' => config('lms.contact_email'),
            ],
            'seo' => [
                'default_title' => $s['seo.default_title'] ?? null,
                'default_description' => $s['seo.default_description'] ?? null,
                'og_image_url' => $s['seo.og_image_url'] ?? null,
                'twitter_handle' => $s['seo.twitter_handle'] ?? null,
                'allow_indexing' => (bool) ($s['seo.allow_indexing'] ?? true),
            ],
            'captcha' => ['provider' => app(Captcha::class)->enabled() ? 'turnstile' : null, 'site_key' => app(Captcha::class)->siteKey()],
            'tracking' => [
                'ga4_measurement_id' => config('tracking.ga4.measurement_id') ?: null,
                'meta_pixel_id' => config('tracking.meta.pixel_id') ?: null,
            ],
        ];
    }
}
