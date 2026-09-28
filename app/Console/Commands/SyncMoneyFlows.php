<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Illuminate\Console\Command;

/**
 * ⭐ প্রতিটা কোম্পানিতে প্রতিটা টাকার কাজের অনুমোদনের ছক — deploy-এ চলে।
 *
 * ── ⛔ কেন কমান্ড, মাইগ্রেশন নয় ──────────────────────────────────────
 * কোন কাজ "টাকার" তা মডিউল রেজিস্ট্রি বলে (`moves_money`), ডাটাবেস নয়।
 * ⚠️ মাইগ্রেশন একবারই চলে — পরে কোনো মডিউলে নতুন টাকার কাজ যোগ হলে
 * তার ছক কোনোদিন বসত না। ⓘ [[SyncChart]], [[SyncPermissions]],
 * [[SyncReasonCodes]] একই কারণে কমান্ড; এটা একই ছাঁচ।
 *
 * ── ⓘ কেন এই ফোল্ডারে, মডিউলের ভিতরে নয় ────────────────────────────
 * মডিউল নিজে কমান্ড নিবন্ধন করার কোনো পথ নেই — module.php-র কোনো চাবি
 * কমান্ড বয় না, আর Laravel কেবল `app/Console/Commands` নিজে থেকে চেনে।
 * ⚠️ এই ফোল্ডার কোর নয় (BoundariesTest দেখে কেবল `app/Core` আর
 * `app/Providers`), আর [[SyncReasonCodes]] একইভাবে মডিউলের সেবা ডাকে।
 * ⭐ কাজের সবটা [[MoneyFlowDefaults]]-এ; এখানে কেবল কোম্পানি বাছা আর বলা।
 *
 * ── ⛔ লাল কখন ────────────────────────────────────────────────────────
 * কোনো কোম্পানিতে বসানোর মতো কাজ আছে অথচ Accountant রোল নেই (বা নামে
 * দুইটা মেলে) — তখন নাম আন্দাজ করা হয় না, কোম্পানির সংকেত ধরে বলা হয়,
 * আর ফল অশূন্য। ⓘ বাকি কোম্পানিগুলোর কাজ তবু শেষ হয় — একটার সমস্যায়
 * তেরোটা আটকায় না।
 *
 * ⚠️ বন্ধ বা ধাপ-হীন চলতি ছক সতর্কবার্তা পায়, লাল নয়: ছকটা কোম্পানির
 * নিজের সিদ্ধান্ত, আর দ্বিতীয় ধাপের পর সেটা পোস্টের মুহূর্তে জোরে থামে।
 */
class SyncMoneyFlows extends Command
{
    protected $signature = 'abos:sync-money-flows
        {--company= : কেবল এই সংকেতের কোম্পানি}';

    protected $description = 'প্রতিটা কোম্পানিতে অনুপস্থিত টাকার কাজের অনুমোদনের ছক বসায় (হিসাবরক্ষকের সই)';

    public function handle(MoneyFlowDefaults $defaults): int
    {
        $code = trim((string) ($this->option('company') ?? ''));

        $companies = Company::query()
            ->when($code !== '', fn ($q) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            /*
             * ⛔ ভুল সংকেতে "সব ঠিক আছে" বলা যাবে না — কিছুই না দেখে সবুজ
             * দেখানো পাহারাটাই সবচেয়ে বিপজ্জনক ([[a-green-guard-may-never-have-looked]])।
             */
            $this->error($code === ''
                ? 'কোনো কোম্পানিই নেই।'
                : "এই সংকেতের কোনো কোম্পানি নেই: {$code}");

            return self::FAILURE;
        }

        $total = 0;
        $withoutRole = [];

        foreach ($companies as $company) {
            $report = $defaults->ensure($company);

            $total += count($report['created']);

            if ($report['created'] !== []) {
                $this->line("{$company->code}: ".count($report['created']).'টা ছক বসল — '
                    .implode(', ', $report['created']));
            }

            foreach ($report['inactive'] as $key) {
                $this->warn("{$company->code}: {$key} — ছক আছে কিন্তু বন্ধ; এই কাজের টাকার কাগজ পোস্ট হবে না।");
            }

            foreach ($report['empty'] as $key) {
                $this->warn("{$company->code}: {$key} — ছকে কোনো ধাপ নেই; কেউ সই দিতে পারবেন না।");
            }

            if ($report['missing_role']) {
                $why = $report['role_problem'] === 'ambiguous'
                    ? 'Accountant নামে একাধিক রোল — কোনটা আসল বলা যায় না'
                    : 'Accountant রোল নেই';

                $withoutRole[] = $company->code;

                $this->error("{$company->code} ({$company->name_en}): {$why}; "
                    .count($report['wanting']).'টা টাকার কাজের ছক বসেনি — '.implode(', ', $report['wanting']));
            }
        }

        if ($withoutRole !== []) {
            $this->error('Accountant রোল ছাড়া কোম্পানি: '.implode(', ', $withoutRole)
                .' — রোলটা বসিয়ে (abos:sync-permissions) কমান্ডটা আবার চালান।');

            return self::FAILURE;
        }

        $this->info($total === 0
            ? 'সব কোম্পানির টাকার কাজের ছক আগে থেকেই বসানো।'
            : "মোট {$total}টা টাকার ছক বসেছে।");

        return self::SUCCESS;
    }
}
