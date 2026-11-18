<?php

namespace App\Notify;

use Illuminate\Support\Facades\Http;

/** Meta WhatsApp Cloud API (Graph API). Business-initiated messages must use pre-approved templates. */
class WhatsAppClient
{
    /** @return array{ok: bool, id: string|null, error: string|null, transient: bool} */
    public function sendTemplate(string $to, WhatsAppMessage $msg): array
    {
        $cfg = (array) config('notify.whatsapp');
        $components = $msg->params === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn (string $p) => ['type' => 'text', 'text' => mb_substr($p, 0, 1000)], $msg->params),
        ]];
        if ($msg->buttonParam !== null) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $msg->buttonParam]]];
        }
        $res = Http::withToken((string) $cfg['token'])->acceptJson()->timeout(10)
            ->post("https://graph.facebook.com/{$cfg['graph_version']}/{$cfg['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => self::digits($to),
                'type' => 'template',
                'template' => array_filter([
                    'name' => $msg->template,
                    'language' => ['code' => $msg->language],
                    'components' => $components ?: null,
                ]),
            ]);
        if ($res->successful() && $res->json('messages.0.id')) {
            return ['ok' => true, 'id' => (string) $res->json('messages.0.id'), 'error' => null, 'transient' => false];
        }
        $code = (int) $res->json('error.code');

        return [
            'ok' => false, 'id' => null,
            'error' => trim(($res->json('error.message') ?? 'HTTP '.$res->status()).' ('.$code.')'),
            // 4 / 80007 / 130429 / 131048 / 131056 = rate limits; 5xx = Meta outage → retry later.
            'transient' => $res->serverError() || $res->status() === 429 || in_array($code, [4, 80007, 130429, 131048, 131056], true),
        ];
    }

    public static function digits(string $phone): string
    {
        return (string) preg_replace('/\D/', '', $phone);
    }
}
