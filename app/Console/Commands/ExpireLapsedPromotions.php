<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Promotion\Services\PromotionExpiry;
use Illuminate\Console\Command;

/**
 * মেয়াদ পেরোনো অফার `EXPIRED` করা — স্পেক §১৮, প্রতিটা কোম্পানিতে।
 *
 * ── ⓘ কেন এই ফাইলটা কোরের ঘরে, মডিউলে নয় ─────────────────────────
 * ⚠️ মডিউলের নিজের কমান্ড নিবন্ধনের কোনো পথ এখানে নেই — `module.php`-এ
 * `commands` বা `schedule` চাবি পড়া হয় না, আর Laravel কেবল
 * `app/Console/Commands` খুঁজে পায়। ⭐ হিসাবটা মডিউলেই থাকে
 * ([[PromotionExpiry]]); এই ফাইল কেবল প্রতিটা কোম্পানিতে দাঁড়িয়ে ওটাকে ডাকে।
 *
 * ⓘ [[BoundariesTest]] কেবল `app/Core` আর `app/Providers` পাহারা দেয়; এই
 * ঘরের চৌদ্দটা কমান্ড আগে থেকেই মডিউলের সেবা ডাকে (`ProposeInstitutionLinks`,
 * `SyncSalesChannels`, …) — তাই এটা নতুন কোনো দরজা খোলে না।
 *
 * ── ⚠️ কেন কোম্পানি ধরে ধরে ─────────────────────────────────────────
 * ⓘ [[Promotion]] `BelongsToCompany` ব্যবহার করে, তাই কোয়েরিটা চলতি
 * কোম্পানিতে বাঁধা। ⛔ প্রসঙ্গ না বসালে একটাও অফার পাওয়া যেত না, আর
 * কমান্ডটা *"০টা বদলানো হলো"* বলে সফল হয়ে ফিরত — দেখতে ঠিক *"আজ কিছু
 * করার ছিল না"*-র মতো।
 *
 * ── ⓘ কেন একাধিকবার চলা নিরাপদ ──────────────────────────────────────
 * ⭐ কেবল `ACTIVE`/`PAUSED` অফার ধরা হয়; যা একবার `EXPIRED` হয়েছে তা আর
 * তালিকায় আসে না। ⓘ তাই একবার ফসকালে পরের বার ধরে নেয়, আর দুইবার চললেও
 * কিছু দুইবার ঘটে না।
 *
 * ⚠️ চলতে ভুলে গেলেও কোনো বিলে মেয়াদ-পেরোনো অফার বসে না — ইঞ্জিন তারিখ
 * দেখে, অবস্থা নয়। ⓘ এই কাজের দায় কেবল তালিকাকে সত্য বলানো।
 */
final class ExpireLapsedPromotions extends Command
{
    protected $signature = 'promotion:expire
        {--company= : কেবল এই কোম্পানি কোড}';

    protected $description = 'Mark offers whose end moment has passed as expired, in every company';

    public function handle(PromotionExpiry $expiry): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($companies as $company) {
            /*
             * ⭐ `forCompany`, `set` নয়।
             *
             * ⓘ `forCompany` শেষে আগের প্রসঙ্গ ফিরিয়ে দেয় — `finally`-তে।
             * ⛔ `set` দিলে একটা কোম্পানিতে ব্যতিক্রম হলে প্রসঙ্গটা ঐ
             * কোম্পানিতেই আটকে থাকত, আর একই প্রক্রিয়ায় পরের কাজটা (শিডিউলার
             * একটা প্রক্রিয়াতেই কয়েকটা কমান্ড চালাতে পারে) ভুল কোম্পানির
             * খাতায় লিখত।
             */
            $count = CompanyContext::forCompany($company->id, fn () => $expiry->expireLapsed());

            if ($count > 0) {
                $this->line(sprintf('%s: %d', $company->code, $count));
            }

            $total += $count;
        }

        $this->info(sprintf('promotion:expire — %d offer(s) expired', $total));

        return self::SUCCESS;
    }
}
