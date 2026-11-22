<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\NotificationChannel;
use App\Models\PartyMessageTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * ⭐ WhatsApp — নিজে থেকে (Meta Cloud API): the server sends a Meta-approved template to the party, and Meta's webhook
 * reports sent · delivered · read · failed (owner "ok" 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54, tab ঘ).
 *
 * ── Where its settings live ──────────────────────────────────────────────
 *   One [[NotificationChannel]] row per company, channel `party_whatsapp` (NOT in NotificationChannel::ALL — that list
 *   is the staff-notification channels page). `enabled` = the switch, `sender_id` = the business number (shown only),
 *   `credentials` (encrypted) = phone_number_id, waba_id, token, app_secret, verify_token.
 *
 * ── ⛔ Rules ─────────────────────────────────────────────────────────────
 *   · The token travels only in the Authorization header — never in a URL, a log line, an error text or the page.
 *   · Ready = switched on + ids + token + the last connection check passed. A changed id/token needs a new check.
 *   · A template goes only when Meta has approved it (`wa_status` approved). Its {variables} become Meta's numbered
 *     parameters in the order they first appear in that language's text — submit and send number them the same way.
 *   · The webhook trusts the company from the URL, the signature from that company's app secret, and then only entries
 *     that carry this company's own phone number id / business account id.
 */
class WhatsAppCloud
{
    public const DEFAULT_VERSION = 'v21.0';

    public const BASE = 'https://graph.facebook.com';

    public const TIMEOUT = 15;

    /** our locale → Meta's template language code */
    public const LANGUAGES = ['bn' => 'bn', 'en' => 'en'];

    /** `party_message_templates.wa_status` */
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAUSED = 'paused';

    public const DISABLED = 'disabled';

    /** the credential keys the roads page may write */
    public const SECRETS = ['phone_number_id', 'waba_id', 'token', 'app_secret', 'verify_token'];

    /** the most entries/changes/statuses read from one webhook call */
    public const MAX_ITEMS = 200;

    private ?string $lastError = null;

    /** this company's row — fresh each time (a page saves it, a queue worker serves several companies) */
    public function config(): ?NotificationChannel
    {
        if (CompanyContext::id() === null) {
            return null;
        }

        return NotificationChannel::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('channel', NotificationChannel::PARTY_WHATSAPP)
            ->first();
    }

    /** switched on + phone number id + token + the last connection check passed */
    public function ready(): bool
    {
        $config = $this->config();

        return $config !== null
            && (bool) $config->enabled
            && $config->secret('phone_number_id') !== null
            && $config->secret('token') !== null
            && $config->last_check_ok === true;
    }

    /** a WhatsApp template Meta has approved */
    public function canSend(PartyMessageTemplate $template): bool
    {
        return $template->channel === PartyMessageTemplate::WHATSAPP
            && filled($template->wa_name)
            && $template->wa_status === self::APPROVED;
    }

    /**
     * ⭐ Send one approved template.
     *
     * @param  string  $phone  international digits, e.g. 8801711234567
     * @param  array<string, mixed>  $values  the template's values by variable name
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function send(string $phone, PartyMessageTemplate $template, string $locale, array $values): array
    {
        $config = $this->config();

        if ($config === null || ! $config->enabled || $config->secret('phone_number_id') === null || $config->secret('token') === null) {
            return ['ok' => false, 'id' => null, 'error' => (string) __('notification::party_roads.errors.not_ready')];
        }
        if (! $this->canSend($template)) {
            return ['ok' => false, 'id' => null, 'error' => (string) __('notification::party_roads.errors.not_approved')];
        }

        $locale = $this->localeOf($template, $locale);
        $parameters = [];

        foreach (self::variablesIn($template->body($locale)) as $name) {
            $parameters[] = ['type' => 'text', 'text' => self::parameter($values[$name] ?? null)];
        }

        $message = [
            'name' => (string) $template->wa_name,
            'language' => ['code' => self::LANGUAGES[$locale]],
        ];

        if ($parameters !== []) {
            $message['components'] = [['type' => 'body', 'parameters' => $parameters]];
        }

        $response = $this->call($config, 'post', '/'.$config->secret('phone_number_id').'/messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $phone),
            'type' => 'template',
            'template' => $message,
        ]);

        if ($response instanceof Response && $response->successful()) {
            $id = $response->json('messages.0.id');

            return is_string($id) && $id !== ''
                ? ['ok' => true, 'id' => $id, 'error' => null]
                : ['ok' => false, 'id' => null, 'error' => (string) __('notification::party_roads.errors.no_id')];
        }

        return ['ok' => false, 'id' => null, 'error' => $this->errorOf($response)];
    }

    /**
     * ⭐ "সংযোগ পরীক্ষা" — reads the phone number object; the result is kept on the row (the state pill).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function check(): array
    {
        $config = $this->config();

        if ($config === null) {
            return ['ok' => false, 'error' => (string) __('notification::party_roads.errors.not_set')];
        }

        if ($config->secret('phone_number_id') === null || $config->secret('token') === null) {
            $result = ['ok' => false, 'error' => (string) __('notification::party_roads.errors.not_set')];
        } else {
            $response = $this->call($config, 'get', '/'.$config->secret('phone_number_id'), ['fields' => 'display_phone_number,verified_name']);
            $result = $response instanceof Response && $response->successful() && $response->json('id') !== null
                ? ['ok' => true, 'error' => null]
                : ['ok' => false, 'error' => $this->errorOf($response)];
        }

        $config->forceFill([
            'last_checked_at' => now(),
            'last_check_ok' => $result['ok'],
            'last_error' => $result['error'] === null ? null : mb_substr($result['error'], 0, 255),
        ])->saveQuietly();

        return $result;
    }

    /**
     * ⭐ "Meta-র অনুমোদনে পাঠান" — one UTILITY template per language under one name; `wa_status` pending until the
     * webhook says approved or rejected.
     */
    public function submitTemplate(PartyMessageTemplate $template): bool
    {
        $this->lastError = null;
        $config = $this->config();

        if ($template->channel !== PartyMessageTemplate::WHATSAPP) {
            return $this->fail((string) __('notification::party_roads.errors.not_whatsapp'));
        }
        if ($config === null || $config->secret('waba_id') === null || $config->secret('token') === null) {
            return $this->fail((string) __('notification::party_roads.errors.not_set'));
        }

        $name = $this->nameFor($template);
        $sent = 0;

        foreach (self::LANGUAGES as $locale => $code) {
            $text = trim($template->body($locale));

            if ($text === '') {
                continue;
            }

            $body = ['type' => 'BODY', 'text' => self::numbered($text)];
            $examples = array_map(fn (string $v) => self::example($v), self::variablesIn($text));

            if ($examples !== []) {
                $body['example'] = ['body_text' => [$examples]];
            }

            $response = $this->call($config, 'post', '/'.$config->secret('waba_id').'/message_templates', [
                'name' => $name,
                'language' => $code,
                'category' => 'UTILITY',
                'components' => [$body],
            ]);

            if (! ($response instanceof Response && $response->successful())) {
                return $this->fail($this->errorOf($response));
            }

            $sent++;
        }

        if ($sent === 0) {
            return $this->fail((string) __('notification::party_roads.errors.empty_template'));
        }

        $template->forceFill(['wa_name' => $name, 'wa_status' => self::PENDING])->save();

        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * ⭐ Meta's webhook body, already signed by this company's app secret ([[WhatsAppWebhookController]]).
     * Status entries go to the dispatcher; template status updates move `wa_status`.
     *
     * @param  array<string, mixed>  $payload
     * @return int how many entries were used
     */
    public function receive(int $companyId, array $payload, PartyMessageDispatcher $dispatcher): int
    {
        return (int) CompanyContext::forCompany($companyId, function () use ($companyId, $payload, $dispatcher): int {
            $config = $this->config();

            if ($config === null) {
                return 0;
            }

            $phoneId = $config->secret('phone_number_id');
            $wabaId = $config->secret('waba_id');
            $used = 0;

            foreach (array_slice((array) ($payload['entry'] ?? []), 0, self::MAX_ITEMS) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                foreach (array_slice((array) ($entry['changes'] ?? []), 0, self::MAX_ITEMS) as $change) {
                    if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                        continue;
                    }

                    $value = $change['value'];
                    $field = (string) ($change['field'] ?? '');

                    if ($field === 'messages') {
                        // ⛔ only this company's own number — one Meta app may serve several numbers
                        if ($phoneId === null || (string) ($value['metadata']['phone_number_id'] ?? '') !== $phoneId) {
                            continue;
                        }

                        foreach (array_slice((array) ($value['statuses'] ?? []), 0, self::MAX_ITEMS) as $status) {
                            if (! is_array($status) || ! is_string($status['id'] ?? null) || ! is_string($status['status'] ?? null)) {
                                continue;
                            }

                            $error = $status['errors'][0]['title'] ?? ($status['errors'][0]['message'] ?? null);
                            $dispatcher->onCloudStatus($companyId, mb_substr($status['id'], 0, 120), $status['status'],
                                is_string($error) ? mb_substr($error, 0, 255) : null);
                            $used++;
                        }
                    } elseif ($field === 'message_template_status_update') {
                        if ($wabaId === null || (string) ($entry['id'] ?? '') !== $wabaId) {
                            continue;
                        }

                        $used += $this->templateStatus($value) ? 1 : 0;
                    }
                }
            }

            return $used;
        });
    }

    /** the webhook's GET handshake: the stored verify token, compared in constant time */
    public function verifies(?string $token): bool
    {
        $stored = $this->config()?->secret('verify_token');

        return $stored !== null && is_string($token) && hash_equals($stored, $token);
    }

    /** X-Hub-Signature-256 = "sha256=" + hex HMAC-SHA256(raw body, app secret) */
    public function signed(string $body, ?string $header): bool
    {
        $secret = $this->config()?->secret('app_secret');

        if ($secret === null || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower(substr($header, 7)));
    }

    /**
     * {variables} of a text in the order they first appear — the numbering Meta sees.
     *
     * @return list<string>
     */
    public static function variablesIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_unique(array_values(array_filter($m[1], fn (string $v) => in_array($v, PartyMessageTemplate::VARIABLES, true)))));
    }

    /** {party} … {amount} → {{1}} … {{2}} — the same variable keeps its number */
    public static function numbered(string $text): string
    {
        $order = array_flip(self::variablesIn($text));

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => isset($order[$m[1]]) ? '{{'.($order[$m[1]] + 1).'}}' : $m[0], $text);
    }

    private function templateStatus(array $value): bool
    {
        $name = $value['message_template_name'] ?? null;
        $event = strtoupper((string) ($value['event'] ?? ''));

        if (! is_string($name) || $name === '') {
            return false;
        }

        $status = match ($event) {
            'APPROVED' => self::APPROVED,
            'REJECTED' => self::REJECTED,
            'PAUSED', 'FLAGGED' => self::PAUSED,
            'DISABLED' => self::DISABLED,
            'PENDING', 'REINSTATED' => null,
            default => null,
        };

        if ($status === null) {
            return false;
        }

        $template = PartyMessageTemplate::query()->where('company_id', CompanyContext::id())->where('wa_name', $name)->first();

        if ($template === null) {
            return false;
        }

        // ⓘ one name, two languages: a rejected language keeps the template off until it is sent again
        if ($status === self::APPROVED && $template->wa_status === self::REJECTED) {
            return false;
        }

        $template->forceFill(['wa_status' => $status])->save();

        return true;
    }

    /** the template's language if it has text in it, otherwise the other one */
    private function localeOf(PartyMessageTemplate $template, string $locale): string
    {
        $locale = isset(self::LANGUAGES[$locale]) ? $locale : 'bn';

        if (trim($template->body($locale)) === '') {
            return $locale === 'bn' ? 'en' : 'bn';
        }

        return $locale;
    }

    /** abos_<id>_<slug> — Meta allows lowercase letters, digits and _; a second submission gets a time suffix */
    private function nameFor(PartyMessageTemplate $template): string
    {
        $slug = Str::of(Str::ascii((string) $template->name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->trim('_')->toString();

        if ($slug === '') {
            $slug = Str::of((string) ($template->document_type ?: 'message'))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString() ?: 'message';
        }

        $name = 'abos_'.$template->id.'_'.$slug;

        return filled($template->wa_name) ? $name.'_'.now()->format('ymdHi') : $name;
    }

    /** a parameter: no new lines or tabs, no more than four spaces, never empty (Meta refuses all three) */
    private static function parameter(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));

        return mb_substr($text === '' ? '-' : $text, 0, 1000);
    }

    /** Meta wants an example value for every parameter of a submitted template */
    private static function example(string $variable): string
    {
        return match ($variable) {
            'party' => 'Rahman Enterprise',
            'paper_no' => 'INV-0001',
            'amount', 'due' => '1,000',
            'date' => now()->format('d-m-Y'),
            'company' => (string) (CompanyContext::id() !== null ? \App\Models\Company::query()->whereKey(CompanyContext::id())->value('name') : 'ABOS'),
            'link' => url('/s/example'),
            default => 'example',
        };
    }

    /** @param  array<string, mixed>  $data */
    private function call(NotificationChannel $config, string $method, string $path, array $data): ?Response
    {
        $version = (string) config('services.whatsapp_cloud.version', self::DEFAULT_VERSION);
        $url = self::BASE.'/'.$version.$path;
        $this->lastError = null;

        try {
            $request = Http::withToken((string) $config->secret('token'))->acceptJson()->timeout(self::TIMEOUT)->connectTimeout(5);

            return $method === 'get' ? $request->get($url, $data) : $request->asJson()->post($url, $data);
        } catch (ConnectionException|Throwable) {
            $this->lastError = (string) __('notification::party_roads.errors.unreachable');

            return null;
        }
    }

    /** Meta's own words for the failure — trimmed, and never the token */
    private function errorOf(?Response $response): string
    {
        if ($response === null) {
            return $this->lastError ?? (string) __('notification::party_roads.errors.unreachable');
        }

        $error = $response->json('error.error_user_msg')
            ?? $response->json('error.error_data.details')
            ?? $response->json('error.message');
        $text = is_string($error) && $error !== '' ? $error : 'HTTP '.$response->status();

        return mb_substr($text, 0, 255);
    }

    private function fail(string $error): bool
    {
        $this->lastError = $error;

        return false;
    }
}
