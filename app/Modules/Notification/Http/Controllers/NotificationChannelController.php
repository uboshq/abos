<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Minishlink\WebPush\VAPID;

/**
 * ⭐ মাধ্যমের সাজানো — প্রোভাইডার, প্রেরকের নাম, গোপন চাবি, সংযোগ পরীক্ষা (মালিকের স্পেক §৪ "Channel Configuration";
 * বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⛔ গোপন চাবি কখনো পর্দায় ফেরত দেখানো হয় না — কেবল "বসানো আছে"; ফাঁকা রাখলে আগেরটাই থাকে। চাবি এনক্রিপ্ট করা থাকে, আর
 * নিরীক্ষায় কেবল "বদলেছে" যায়। অনুমোদিত প্রোভাইডার ছাড়া কোনো মাধ্যম "সংযুক্ত" দেখায় না (স্পেক §৭)।
 */
class NotificationChannelController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ChannelRegistry $channels,
    ) {}

    public function index(Request $request): View
    {
        $company = (int) CompanyContext::id();
        $rows = [];

        foreach ($this->channels->all() as $key => $channel) {
            $config = $this->channels->config($company, $key);
            $rows[] = [
                'key' => $key,
                'config' => $config,
                'connected' => $channel->connected($config),
                'provider' => $channel->provider($config),
            ];
        }

        return view('notification::channels.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
        ]);
    }

    public function edit(Request $request, string $channel): View
    {
        abort_unless(in_array($channel, NotificationChannel::ALL, true), 404);

        $config = $this->channels->config((int) CompanyContext::id(), $channel);

        return view('notification::channels.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'channel' => $channel,
            'config' => $config,
            'connected' => (bool) $this->channels->get($channel)?->connected($config),
            'gateways' => array_keys(SmsChannel::GATEWAYS),
            'hasSecret' => fn (string $key) => $config?->secret($key) !== null,
        ]);
    }

    public function update(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, NotificationChannel::ALL, true), 404);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'string', 'max:32'],
            'sender_id' => ['nullable', 'string', 'max:64', $channel === NotificationChannel::WEB_PUSH ? 'regex:/^(mailto:|https:\/\/)/' : 'regex:/^[\pL\pN\s\.\-_@+]*$/u'],
            'secrets' => ['nullable', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:500'],
        ]);

        $config = $this->row($channel);
        $credentials = (array) ($config->credentials ?? []);

        // ⓘ ফাঁকা ঘর মানে "আগেরটাই থাকুক" — চাবি কখনো পর্দায় ফেরত যায় না, তাই আবার লেখাতে বাধ্য করা হয় না
        foreach (array_filter((array) ($data['secrets'] ?? []), fn ($v) => is_string($v) && $v !== '') as $key => $value) {
            if (in_array($key, $this->secretKeys($channel), true)) {
                $credentials[$key] = $value;
            }
        }

        $config->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'provider' => $channel === NotificationChannel::SMS ? (($data['provider'] ?? '') ?: null) : $config->provider,
            'sender_id' => ($data['sender_id'] ?? '') ?: null,
            'credentials' => $credentials ?: null,
        ])->save();

        $this->channels->forget();
        app(NotificationAudit::class)->record('channel_update', $config, 'done', ['channel' => $channel, 'enabled' => (bool) $config->enabled]);

        return redirect()->route('notification.channels.edit', $channel)->with('saved', __('notification::channel.saved'));
    }

    /** ⭐ Web Push-এর VAPID চাবি জোড়া — গোপন অংশ এনক্রিপ্ট করা; আবার বানালে আগের সব সাবস্ক্রিপশন অকেজো হয়, তাই সতর্কবার্তা */
    public function vapid(Request $request): RedirectResponse
    {
        $config = $this->row(NotificationChannel::WEB_PUSH);
        $keys = VAPID::createVapidKeys();

        $config->fill(['credentials' => ['vapid_public' => $keys['publicKey'], 'vapid_private' => $keys['privateKey']]])->save();
        $this->channels->forget();

        app(NotificationAudit::class)->record('channel_vapid', $config, 'done', ['channel' => NotificationChannel::WEB_PUSH]);

        return redirect()->route('notification.channels.edit', NotificationChannel::WEB_PUSH)->with('saved', __('notification::channel.vapid_made'));
    }

    /** ⭐ সংযোগ পরীক্ষা — পরীক্ষাকারীর নিজের কাছে একটা পরীক্ষার খবর; ফল মাধ্যমের সারিতে আর নিরীক্ষায় */
    public function test(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, NotificationChannel::ALL, true), 404);

        // ⓘ নিয়ন্ত্রকটা একই প্রক্রিয়ায় আবার ব্যবহার হতে পারে (কিউ-কর্মী, পরীক্ষা) — পুরনো সাজানো নয়, এখনকারটা
        $this->channels->forget();
        $adapter = $this->channels->get($channel);
        $config = $this->channels->config((int) CompanyContext::id(), $channel);
        $result = $adapter->test($request->user(), $config);

        if ($config !== null) {
            $config->forceFill(['last_checked_at' => now(), 'last_check_ok' => $result->ok(), 'last_error' => $result->error])->saveQuietly();
        }

        app(NotificationAudit::class)->record('channel_test', $config, $result->ok() ? 'done' : 'failed', ['channel' => $channel, 'outcome' => $result->outcome]);

        return back()->with($result->ok() ? 'saved' : 'failed', $result->ok()
            ? __('notification::channel.test_ok')
            : __('notification::channel.test_failed', ['error' => $result->error]));
    }

    private function row(string $channel): NotificationChannel
    {
        return NotificationChannel::query()->firstOrNew(
            ['company_id' => CompanyContext::id(), 'channel' => $channel],
            ['enabled' => false],
        );
    }

    /** @return list<string> কোন মাধ্যমের কোন গোপন ঘর — ইমেইল আর মোবাইল পুশের চাবি `.env`-এ, এখানে নয় */
    private function secretKeys(string $channel): array
    {
        return match ($channel) {
            NotificationChannel::SMS => ['api_key', 'api_secret', 'webhook_secret'],
            // ⓘ ইমেইলের SMTP চাবি `.env`-এ; এখানে কেবল প্রোভাইডারের ফেরত-খবরের স্বাক্ষরের চাবি
            NotificationChannel::EMAIL => ['webhook_secret'],
            default => [],
        };
    }
}
