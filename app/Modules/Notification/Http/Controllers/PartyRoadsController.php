<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Services\PartyMessageDispatcher;
use App\Core\Services\SettingsService;
use App\Core\Services\WhatsAppCloud;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NotificationChannel;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsGatewayPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ⭐ বার্তার পথ — the three roads a party message can take, and their order (owner "ok" 23 Nov 2026, sample
 * HUXqpfhxng8823eEFrvH54, tab ঘ): WhatsApp by button (wa.me), WhatsApp by itself (Meta Cloud API), SMS on the
 * office phone's SIM; then "কোন পথ আগে" (fixed) and the default language.
 *
 * Tabs: পথ (this page) · SMS-এর ফোন ([[SmsPhoneController]], its own key) · খরচ (this month's counts per road).
 *
 * ⛔ The token and the app secret are never sent back to the page — blank keeps the stored one. A changed id or token
 *   clears the last check, so the road waits for a new "সংযোগ পরীক্ষা" before it is used.
 */
class PartyRoadsController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
        private readonly WhatsAppCloud $cloud,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'cost' ? 'cost' : 'roads';
        $config = $this->cloud->config();
        $companyPublicId = (string) Company::query()->whereKey(CompanyContext::id())->value('public_id');

        return view('notification::party.roads', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'tabs' => $this->tabs($request, $tab),
            'wameOn' => (bool) $this->settings->get(PartyMessageDispatcher::WAME_ON, true),
            'smsOn' => (bool) $this->settings->get(PartyMessageDispatcher::SMS_ON, true),
            'defaultLocale' => app(PartyMessageDispatcher::class)->defaultLocale(),
            'rate' => trim((string) $this->settings->get(self::RATE, '')),
            'cloud' => $config,
            'cloudOn' => (bool) $config?->enabled,
            'cloudState' => $this->cloudState($config),
            'hasToken' => $config?->secret('token') !== null,
            'hasSecret' => $config?->secret('app_secret') !== null,
            'verifyToken' => $config?->secret('verify_token'),
            'webhookUrl' => $companyPublicId === '' ? null : route('api.notification.whatsapp_webhook', $companyPublicId),
            'phones' => $tab === 'roads' ? SmsGatewayPhone::query()->orderByDesc('is_active')->orderBy('name')->orderBy('id')
                ->paginate(\App\Core\Support\PerPage::of())->withQueryString() : collect(),
            'cost' => $tab === 'cost' ? $this->cost() : null,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wame' => ['nullable', 'boolean'],
            'sms' => ['nullable', 'boolean'],
            'cloud' => ['nullable', 'boolean'],
            'default_locale' => ['required', 'in:bn,en'],
            'sender_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]*$/'],
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^\d*$/'],
            'waba_id' => ['nullable', 'string', 'max:40', 'regex:/^\d*$/'],
            'token' => ['nullable', 'string', 'max:1000'],
            'app_secret' => ['nullable', 'string', 'max:200'],
        ]);

        $this->settings->set(PartyMessageDispatcher::WAME_ON, (bool) ($data['wame'] ?? false));
        $this->settings->set(PartyMessageDispatcher::SMS_ON, (bool) ($data['sms'] ?? false));
        $this->settings->set(PartyMessageDispatcher::DEFAULT_LOCALE, $data['default_locale']);

        $config = $this->cloud->config() ?? new NotificationChannel([
            'company_id' => CompanyContext::id(), 'channel' => NotificationChannel::PARTY_WHATSAPP, 'enabled' => false,
        ]);
        $credentials = (array) ($config->credentials ?? []);
        $before = [$credentials['phone_number_id'] ?? null, $credentials['waba_id'] ?? null, $credentials['token'] ?? null];

        foreach (['phone_number_id', 'waba_id'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value === '') {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = $value;
            }
        }

        // ⓘ blank = keep the stored one — the page never shows them back
        foreach (['token', 'app_secret'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }

        $credentials['verify_token'] ??= Str::random(40);

        $config->fill([
            'enabled' => (bool) ($data['cloud'] ?? false),
            'provider' => 'meta',
            'sender_id' => trim((string) ($data['sender_id'] ?? '')) ?: null,
            'credentials' => $credentials,
        ]);

        // ⛔ a new number or token is not proven until it is checked again
        if ($before !== [$credentials['phone_number_id'] ?? null, $credentials['waba_id'] ?? null, $credentials['token'] ?? null]) {
            $config->forceFill(['last_checked_at' => null, 'last_check_ok' => null, 'last_error' => null]);
        }

        $config->save();

        app(NotificationAudit::class)->record('party_roads', $config, 'done', [
            'wame' => (bool) ($data['wame'] ?? false), 'sms' => (bool) ($data['sms'] ?? false), 'cloud' => (bool) $config->enabled,
        ]);

        return redirect()->route('notification.party_roads.index')->with('saved', __('notification::party_roads.saved'));
    }

    /** ⭐ "সংযোগ পরীক্ষা" */
    public function check(): RedirectResponse
    {
        $result = $this->cloud->check();

        return redirect()->route('notification.party_roads.index')->with($result['ok'] ? 'saved' : 'failed', $result['ok']
            ? __('notification::party_roads.check_ok')
            : __('notification::party_roads.check_failed', ['error' => $result['error']]));
    }

    /** ⭐ the template editor's "Meta-র অনুমোদনে পাঠান" (`notification.party_templates` key) */
    public function submitTemplate(PartyMessageTemplate $template): RedirectResponse
    {
        $ok = $this->cloud->submitTemplate($template);

        return back()->with($ok ? 'saved' : 'failed', $ok
            ? __('notification::party_roads.submitted')
            : __('notification::party_roads.submit_failed', ['error' => $this->cloud->lastError()]));
    }

    /** the setting the owner fills once Meta's Bangladesh rate is known — blank shows the placeholder */
    public const RATE = 'notification.party.cloud_rate';

    /** @return list<array{key: string, label: string, url: string, active: bool}> */
    private function tabs(Request $request, string $tab): array
    {
        $tabs = [['key' => 'roads', 'label' => (string) __('notification::party_roads.tabs.roads'), 'url' => route('notification.party_roads.index'), 'active' => $tab === 'roads']];

        if ($request->user()?->can('notification.sms_gateway')) {
            $tabs[] = ['key' => 'phones', 'label' => (string) __('notification::party_roads.tabs.phones'), 'url' => route('notification.sms_phones.index'), 'active' => false];
        }

        $tabs[] = ['key' => 'cost', 'label' => (string) __('notification::party_roads.tabs.cost'), 'url' => route('notification.party_roads.index', ['tab' => 'cost']), 'active' => $tab === 'cost'];

        return $tabs;
    }

    /** on · waiting for a check · connection error */
    private function cloudState(?NotificationChannel $config): string
    {
        if ($config !== null && $config->last_check_ok === false) {
            return 'error';
        }

        return $this->cloud->ready() ? 'on' : 'waiting';
    }

    /**
     * This month's party messages per road; the Cloud cost only when the owner has set Meta's rate.
     *
     * @return array{from: \Illuminate\Support\Carbon, rows: list<array{road: string, count: int, failed: int}>, rate: ?string, cloud_sent: int, cloud_cost: ?string}
     */
    private function cost(): array
    {
        $from = now()->startOfMonth();
        $counts = PartyMessage::query()->where('created_at', '>=', $from)
            ->selectRaw('route, COUNT(*) AS n, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed', [PartyMessage::FAILED])
            ->groupBy('route')->get()->keyBy('route');

        $rows = [];

        foreach ([PartyMessage::WAME, PartyMessage::CLOUD, PartyMessage::PHONE] as $road) {
            $rows[] = ['road' => $road, 'count' => (int) ($counts[$road]->n ?? 0), 'failed' => (int) ($counts[$road]->failed ?? 0)];
        }

        $rateText = str_replace(',', '', trim((string) $this->settings->get(self::RATE, '')));
        // ⓘ Meta's rate is taka — bcmath strings, never a float (MoneyIsNeverAFloatTest)
        //   ⚠️ plain digits only — bcmath refuses "1e3" or " 2" with an error, is_numeric() would let them in
        $rate = preg_match('/^\d+(\.\d+)?$/', $rateText) === 1 && bccomp($rateText, '0', 4) > 0 ? $rateText : null;
        $cloudSent = PartyMessage::query()->where('created_at', '>=', $from)->where('route', PartyMessage::CLOUD)
            ->whereIn('status', [PartyMessage::SENT, PartyMessage::DELIVERED, PartyMessage::READ])->count();

        return [
            'from' => $from,
            'rows' => $rows,
            'rate' => $rate,
            'cloud_sent' => $cloudSent,
            'cloud_cost' => $rate === null ? null : \App\Core\Support\Money::round(bcmul((string) $cloudSent, $rate, 4)),
        ];
    }
}
