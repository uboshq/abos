<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\NoticeScheduler;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * নোটিশের সময়ের কাজ — প্রকাশ, মেয়াদ, তাগাদা।
 *
 * ── ⚠️ কেন কোম্পানি ধরে ধরে ─────────────────────────────────────────
 * ⓘ [[Notice]] `BelongsToCompany` ব্যবহার করে, তাই প্রতিটা কোয়েরি
 * **চলতি কোম্পানিতে** বাঁধা। ⛔ প্রসঙ্গ না বসিয়ে চালালে কমান্ডটা
 * কোনো কোম্পানিতেই দাঁড়ায় না, আর একটাও নোটিশ খুঁজে পায় না —
 * নীরবে, শূন্য ফেরত দিয়ে।
 *
 * ⚠️ আর ঐ শূন্যটা দেখতে ঠিক *"কিছু করার ছিল না"*-র মতোই।
 *
 * ── ⓘ কেন একাধিকবার চলা নিরাপদ ──────────────────────────────────────
 * তিনটা কাজের প্রতিটাই যা হয়ে গেছে তা আবার করে না
 * ([[NoticeScheduler]])। ⭐ তাই ঘণ্টায় একবার না চললেও ক্ষতি নেই,
 * পরেরবার ধরে নেবে — ঠিক [[BackupDue]]-র যুক্তিতে।
 */
final class NoticesDue extends Command
{
    protected $signature = 'abos:notices-due';

    protected $description = 'Publish scheduled notices, expire finished ones, and chase unsigned ones';

    public function handle(NoticeScheduler $scheduler): int
    {
        $total = ['published' => 0, 'expired' => 0, 'reminded' => 0];

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $done = CompanyContext::forCompany(
                $company->id,
                fn () => $scheduler->runEverything(),
            );

            foreach ($done as $what => $many) {
                $total[$what] += $many;
            }
        }

        $this->info(sprintf(
            'notices: %d published, %d expired, %d reminded',
            $total['published'], $total['expired'], $total['reminded'],
        ));

        return self::SUCCESS;
    }
}
