<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Notifications\Channels\EmailChannel;
use App\Core\Notifications\Channels\MobilePushChannel;
use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Notifications\Channels\WebPushChannel;
use App\Models\NotificationChannel;

/**
 * ⭐ কোন কোন মাধ্যম আছে, আর একটা কোম্পানিতে প্রতিটার সাজানো কী (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⓘ ঘণ্টা (in-app) এখানে নেই — সেটা মাধ্যম নয়, খবরের নিজের সারি; সবসময় চলে, কোনো প্রোভাইডার বন্ধ থাকলেও (স্পেক §১০)।
 */
final class ChannelRegistry
{
    /** @var array<string, NotificationChannel|null> এই অনুরোধে একবার — "কোম্পানি|মাধ্যম" ধরে */
    private array $configs = [];

    /** @return array<string, DeliveryChannel> */
    public function all(): array
    {
        return [
            NotificationChannel::EMAIL => app(EmailChannel::class),
            NotificationChannel::WEB_PUSH => app(WebPushChannel::class),
            NotificationChannel::MOBILE_PUSH => app(MobilePushChannel::class),
            NotificationChannel::SMS => app(SmsChannel::class),
        ];
    }

    public function get(string $key): ?DeliveryChannel
    {
        return $this->all()[$key] ?? null;
    }

    /** কোম্পানির সাজানো সারি — না থাকলে `null` (তখন মাধ্যমের ডিফল্ট) */
    public function config(int $companyId, string $key): ?NotificationChannel
    {
        $cacheKey = $companyId.'|'.$key;

        if (! array_key_exists($cacheKey, $this->configs)) {
            $this->configs[$cacheKey] = NotificationChannel::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)->where('channel', $key)->first();
        }

        return $this->configs[$cacheKey];
    }

    public function connected(int $companyId, string $key): bool
    {
        return (bool) $this->get($key)?->connected($this->config($companyId, $key));
    }

    public function forget(): void
    {
        $this->configs = [];
    }
}
