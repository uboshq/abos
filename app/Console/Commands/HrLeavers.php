<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Hr\Services\LeaversLoseTheirLogin;
use Illuminate\Console\Command;

/**
 * ⭐ চাকরি ছাড়ার দিন পেরোলে লগইন বন্ধ — রোজ ভোরে (ISO 27001 A.5.18; [[LeaversLoseTheirLogin]], a6, ২০ অক্টোবর ২০২৬)।
 * ⓘ আগে থেকে নোটিশ দেওয়া কর্মী: ছাড়ার দিন বসানোর সময় ভবিষ্যতে, তাই সেদিন কিছু বন্ধ হয়নি; দিন পেরোনোর পরের ভোরে এখানে।
 */
class HrLeavers extends Command
{
    protected $signature = 'abos:hr-leavers';

    protected $description = 'চাকরি ছাড়ার দিন পেরোনো কর্মীদের লগইন বন্ধ';

    public function handle(LeaversLoseTheirLogin $leavers): int
    {
        $closed = $leavers->sweep();
        $this->info("বন্ধ হলো: {$closed}");

        return self::SUCCESS;
    }
}
