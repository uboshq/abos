<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Notifications\ChannelRegistry;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * ⭐ মাধ্যমের স্বাস্থ্য — প্রতি ঘণ্টায় প্রতিটা কোম্পানির প্রতিটা মাধ্যম "সংযুক্ত কি" দেখে লেখে (স্পেক §৪ "Channel Health"; ধাপ ২)।
 *
 * ⓘ কিছু পাঠায় না — কেবল সাজানো দেখে (প্রোভাইডার, চাবি, `.env`)। সত্যিকারের পাঠানোর ফল চেষ্টার লগে, আর হাতের সংযোগ পরীক্ষা
 * মাধ্যমের পর্দায়। ⛔ ভুলের লেখায় গোপন কিছু নয়।
 */
final class NotificationsHealth extends Command
{
    protected $signature = 'abos:notifications-health';

    protected $description = 'Record whether each notification channel of each company is connected';

    public function handle(ChannelRegistry $channels): int
    {
        $checked = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            foreach ($channels->all() as $key => $channel) {
                $config = $channels->config((int) $companyId, $key);

                if ($config === null) {
                    continue;
                }

                $ok = $channel->connected($config);
                $config->forceFill([
                    'last_checked_at' => now(),
                    'last_check_ok' => $ok,
                    'last_error' => $ok ? null : 'not connected',
                ])->saveQuietly();
                $checked++;
            }
        }

        $channels->forget();
        $this->info("checked={$checked}");

        return self::SUCCESS;
    }
}
