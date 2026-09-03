<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\SystemAdmin\Services\ScheduledReportRunner;
use Illuminate\Console\Command;

/**
 * যে নির্ধারিত রিপোর্টগুলোর সময় হয়েছে, সেগুলো চালায় — শিডিউলার থেকে।
 *
 * প্রতিটা সূচি চলে তার নির্মাতার অনুমতিতে (runner-এর কাজ), তাই ক্রন থেকে
 * চললেও কেউ পর্দায় না-দেখা সংখ্যা পান না। ব্যাকআপ ও বই-যাচাইয়ের মতোই
 * প্রতি কয়েক মিনিটে জেগে দেখে "কার সময় হয়েছে"।
 */
class ReportsDue extends Command
{
    protected $signature = 'abos:reports-due';

    protected $description = 'সময় হয়ে যাওয়া নির্ধারিত রিপোর্টগুলো তৈরি করে ও প্রাপকদের জানায়';

    public function handle(ScheduledReportRunner $runner): int
    {
        $count = $runner->runDue();

        // পুরনো ফাইল সরানো — নাহলে private ডিস্কে জমে ক্রয়মূল্যভরা হাজারটা রিপোর্ট
        $pruned = $runner->prune((int) config('abos.reports.retention_days', 90));

        $this->info("{$count}টি রিপোর্ট চালানো, {$pruned}টি পুরনো ফাইল সরানো।");

        return self::SUCCESS;
    }
}
