<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\MasterData\Services\MasterListService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * ডিপ্লয়ের সময় চলে — অনুপস্থিত কারণ কোডগুলো প্রতিটা কোম্পানিতে বসায়।
 *
 * ── ⛔ কেন সিডার নয় ─────────────────────────────────────────────────
 * [[MasterListService::installDefaults()]] চলে কেবল নতুন কোম্পানি তৈরির
 * সময়, আর ভিতরের [[MasterListService::seed()]] শুরুতেই থেমে যায় যদি
 * তালিকায় **একটাও** সারি থাকে। ⚠️ তাই পরে যোগ করা কোনো কারণ চলমান
 * কোম্পানিতে কোনোদিন পৌঁছায় না।
 *
 * ⓘ ঠিক এই ভুলটা ছকের বেলায় ([[SyncChart]]) আর অনুমতির বেলায়
 * ([[SyncPermissions]]) হয়েছিল, আর জমার ধরনে তৃতীয়বার
 * ([[SyncDepositKinds]])। ⛔ কারণ কোডে এটা চতুর্থ — ধরা পড়েছে
 * `HOLD-TRN` ("অন্য গুদামের পথে") যোগ করার দিন, ২৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⚠️ ফেলটা কেমন ছিল, সেটাই সবচেয়ে গুরুত্বপূর্ণ ─────────────────────
 * কিছুই ভাঙত না। ⓘ স্থানান্তর আগের মতোই চলত, কোড না পেলে
 * [[StockTransferService::onTheWay()]] চুপচাপ `null` ফেরাত, আর আটকানোর
 * রিপোর্টে কারণের ঘরটা ফাঁকা থাকত — ঠিক যেমন আগে ছিল। ⛔ অর্থাৎ ফিচারটা
 * লাইভে **কিছুই করত না**, আর কোথাও লাল হত না।
 *
 * ── ⓘ নতুন করে চালালে কিছু নষ্ট হয় না ───────────────────────────────
 * বসানো কোনো কারণ ছোঁয়া হয় না — কোম্পানি একটার নাম বদলে থাকতে পারেন,
 * বা নিষ্ক্রিয় করে থাকতে পারেন, আর সেটা উল্টে দেওয়া মানে তাঁর কাজ নষ্ট
 * করা। ⚠️ মুছে ফেলা কারণও ফিরিয়ে আনা হয় না: কোড মিলে গেলেই সারিটা বাদ।
 */
class SyncReasonCodes extends Command
{
    protected $signature = 'abos:sync-reason-codes';

    protected $description = 'প্রতিটা কোম্পানিতে অনুপস্থিত কারণ কোডগুলো বসায়';

    public function handle(MasterListService $lists): int
    {
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            /*
             * প্রতিটা কোম্পানির নিজের প্রসঙ্গে — ⛔ নাহলে মডেলের
             * কোম্পানি-স্কোপ আগের কোম্পানির সারিগুলো দেখত, আর নতুনগুলো
             * "আছে" ভেবে বসাত না। ⓘ ছক সিঙ্কে ঠিক এই ফাঁদটাই ধরা পড়েছিল।
             */
            try {
                $added = CompanyContext::forCompany(
                    $company->id,
                    fn () => $lists->installMissingReasons(),
                );
            } catch (ValidationException $refused) {
                /*
                 * ⚠️ একটা কোম্পানিতে আটকালে বাকিরা যেন বাদ না পড়ে।
                 *
                 * ⓘ সম্ভাব্য কারণ: কেউ একটা কারণের নাম বদলে এমন কিছু
                 * করেছেন যা নতুন সারিটার নামের সাথে মেলে, আর
                 * [[DuplicationEngine]] থামিয়ে দিয়েছে। ⛔ ওটা ঐ
                 * কোম্পানির সমস্যা, বাকি তেরোটার নয়।
                 */
                $this->warn("{$company->code}: " . $refused->getMessage());

                continue;
            }

            if ($added > 0) {
                $this->line("{$company->code}: {$added}টা কারণ যোগ হলো");
            }

            $total += $added;
        }

        $this->info($total === 0
            ? 'সব কোম্পানির কারণ কোড আগে থেকেই বসানো।'
            : "মোট {$total}টা কারণ যোগ হয়েছে।");

        return self::SUCCESS;
    }
}
