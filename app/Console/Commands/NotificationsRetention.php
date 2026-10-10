<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Notifications\RetentionService;
use Illuminate\Console\Command;

/**
 * ⭐ বিজ্ঞপ্তির আর্কাইভ আর রাখার মেয়াদ — প্রতি ঘণ্টায়; বারবার চালালেও একই ফল (ধাপ ৪; [[RetentionService]])।
 */
final class NotificationsRetention extends Command
{
    protected $signature = 'abos:notifications-retention';

    protected $description = 'Archive old read notifications and remove delivery records past the retention period';

    public function handle(RetentionService $retention): int
    {
        $done = $retention->run();

        $this->info("archived={$done['archived']} events={$done['events']} purged={$done['purged']}");

        return self::SUCCESS;
    }
}
