<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Console\Command;

/**
 * ডিপ্লয়ের সময় চলে — নতুন মান খাতগুলো প্রতিটা কোম্পানিতে বসায়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `StandardChart::install()` আগে থেকেই কেবল **অনুপস্থিত** খাতগুলো বসায়,
 * আর ওটা একবার সারানোও হয়েছে। কিন্তু **কেউ ওটা ডাকত না**: ছক বসে
 * কেবল নতুন কোম্পানি তৈরির সময়।
 *
 * ফলে ব্যবস্থায় নতুন খাত যোগ হলে চলমান কোম্পানিগুলো সেটা কোনোদিন পেত
 * না। ধরা পড়ত ব্যবহারকারীর হাতে, প্রথম ব্যবহারের দিনে — চেক বসাতে গিয়ে
 * "হিসাবের ছকটা এখনো বসানো হয়নি"।
 *
 * ঠিক এই ভুলটা অনুমতির বেলায় একবার হয়েছিল: `sales.cost.view` আর
 * `sales.reprint.override` কয়েকদিন লাইভে **মৃত** পড়ে ছিল, কারণ
 * ডিপ্লয়ে কেউ সিঙ্ক চালাত না। সমাধানটাও তখনই ঠিক হয়েছিল — কমান্ডটা
 * `abos:optimise`-এর ভেতরে বসানো, যাতে ডিপ্লয় নিজেই নিজেকে সারায়।
 *
 * ── কেন প্রতিটা কোম্পানি ধরে ─────────────────────────────────────────
 * ছক কোম্পানি ধরে বসে, আর একই ABOS-এ কয়েকটা কোম্পানি চলে। একটার
 * প্রসঙ্গে চালালে বাকিরা পুরনোই থেকে যেত।
 */
class SyncChart extends Command
{
    protected $signature = 'abos:sync-chart';

    protected $description = 'প্রতিটা কোম্পানিতে মান হিসাব-ছকের অনুপস্থিত খাতগুলো বসায়';

    public function handle(StandardChart $chart): int
    {
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            /*
             * প্রতিটা কোম্পানির নিজের প্রসঙ্গে — নাহলে `Account` মডেলের
             * কোম্পানি-স্কোপ আগের কোম্পানির খাত দেখত, আর নতুনগুলো "আছে"
             * ভেবে বসাত না।
             */
            $added = CompanyContext::forCompany($company->id, fn () => $chart->install());

            if ($added > 0) {
                $this->line("{$company->code}: {$added}টা খাত যোগ হলো");
            }

            $total += $added;
        }

        $this->info($total === 0
            ? 'সব কোম্পানির ছক আগে থেকেই পূর্ণ।'
            : "মোট {$total}টা খাত যোগ হয়েছে।");

        return self::SUCCESS;
    }
}
