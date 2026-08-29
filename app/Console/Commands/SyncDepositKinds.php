<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Finance\Services\DepositKindInstaller;
use Illuminate\Console\Command;

/**
 * ডিপ্লয়ের সময় চলে — জমার ধরনগুলো প্রতিটা কোম্পানিতে বসায়।
 *
 * ── কেন সিডার নয় ────────────────────────────────────────────────────
 * সিডার চলে কেবল নতুন কোম্পানি তৈরির সময়। চলমান তিনটা কোম্পানি তখন
 * খালি ড্রপডাউন পেত — "নতুন জমা" খুলে ধরনের তালিকায় কিছুই নেই, আর
 * ব্যবহারকারী ভাবত পর্দাটা নষ্ট।
 *
 * ঠিক এই ভুলটা ছকের বেলায় (`abos:sync-chart`) আর অনুমতির বেলায়
 * (`abos:sync-permissions`) দুইবার হয়েছে। তৃতীয়বার আর নয়।
 *
 * ── কেন নতুন করে চালালে কিছু নষ্ট হয় না ──────────────────────────────
 * বসানো কোনো ধরন ছোঁয়া হয় না ([[DepositKindInstaller::install()]]) —
 * কোম্পানি একটা ধরনের নাম বদলে থাকতে পারে, বা নিষ্ক্রিয় করে থাকতে
 * পারে, আর সেটা মুছে দেওয়া মানে তাঁর কাজ নষ্ট করা।
 */
class SyncDepositKinds extends Command
{
    protected $signature = 'abos:sync-deposit-kinds';

    protected $description = 'প্রতিটা কোম্পানিতে জমার অনুপস্থিত ধরনগুলো বসায়';

    public function handle(DepositKindInstaller $installer): int
    {
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            /*
             * প্রতিটা কোম্পানির নিজের প্রসঙ্গে — নাহলে মডেলের কোম্পানি-
             * স্কোপ আগের কোম্পানির সারিগুলো দেখত, আর নতুনগুলো "আছে"
             * ভেবে বসাত না। ছক সিঙ্কে ঠিক এই ফাঁদটাই ধরা পড়েছিল।
             */
            $added = CompanyContext::forCompany($company->id, fn () => $installer->install());

            if ($added > 0) {
                $this->line("{$company->code}: {$added}টা ধরন যোগ হলো");
            }

            $total += $added;
        }

        $this->info($total === 0
            ? 'সব কোম্পানির জমার ধরন আগে থেকেই বসানো।'
            : "মোট {$total}টা ধরন যোগ হয়েছে।");

        return self::SUCCESS;
    }
}
