<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Executive\Services\Snapshots;
use Illuminate\Console\Command;

/**
 * মালিকের কেন্দ্রের রাতের হিসাব — প্রতিটা কোম্পানি ও শাখার আটটা সংখ্যা, আর তিন বছরের পুরনোগুলো সরানো।
 *
 * ⓘ রাত ২৩:৫৫-এ চলে (routes/console.php) — দিনের শেষ কাগজ বসার পরে, তারিখ বদলানোর আগে।
 * ⓘ দুইবার চালালেও ক্ষতি নেই: একই দিনের সারি নতুন করে লেখা হয়, দ্বিতীয়টা বসে না।
 */
class OwnerSnapshot extends Command
{
    protected $signature = 'abos:owner-snapshot';

    protected $description = 'মালিকের কেন্দ্রের রাতের হিসাব লেখে (প্রতিটা কোম্পানি ও শাখার আটটা সংখ্যা) আর তিন বছরের পুরনোগুলো সরায়';

    public function handle(Snapshots $snapshots): int
    {
        $taken = $snapshots->take();
        $pruned = $snapshots->prune();

        $this->info("{$taken['rows']}টি সারি লেখা হলো, {$pruned}টি পুরনো সারি সরানো হলো।");

        if ($taken['skipped'] !== []) {
            // ⚠️ চুপ থাকা নয় — যে কোম্পানির হিসাব লেখা গেল না, তার নাম
            $this->warn('super_admin নেই, তাই বাদ: '.implode(', ', $taken['skipped']));
        }

        return self::SUCCESS;
    }
}
