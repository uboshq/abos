<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Notifications\ScheduleRunner;
use Illuminate\Console\Command;

/**
 * ⭐ সূচিমতো খবর — প্রতি মিনিটে সময় হওয়া সূচিগুলো পাঠায় (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩; [[ScheduleRunner]])।
 */
final class NotificationsSchedule extends Command
{
    protected $signature = 'abos:notifications-schedule';

    protected $description = 'Send the scheduled notifications whose time has come and set their next run';

    public function handle(ScheduleRunner $runner): int
    {
        $done = $runner->run();

        $this->info("ran={$done['ran']} sent={$done['sent']}");

        return self::SUCCESS;
    }
}
