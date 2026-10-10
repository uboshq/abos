<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Notifications\DigestService;
use Illuminate\Console\Command;

/**
 * ⭐ সারসংক্ষেপ চিঠি — প্রতি ঘণ্টায় দেখে কার দিনের বা সপ্তাহের চিঠির সময় হলো (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩; [[DigestService]])।
 */
final class NotificationsDigest extends Command
{
    protected $signature = 'abos:notifications-digest';

    protected $description = 'Send each person their daily or weekly notification digest when its hour has come';

    public function handle(DigestService $digests): int
    {
        $done = $digests->run();

        $this->info("sent={$done['sent']} released={$done['released']} failed={$done['failed']} waiting={$done['waiting']}");

        return self::SUCCESS;
    }
}
