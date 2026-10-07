<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Modules\Finance\Services\InstitutionLinkProposer;
use Illuminate\Console\Command;

/**
 * পুরনো আমানতের টাইপ করা নাম ↔ আর্থিক প্রতিষ্ঠান — নাম দেখে প্রস্তাব।
 *
 * ── ⭐ কেন দরকার ─────────────────────────────────────────────────────
 * আমানতে আগে প্রতিষ্ঠানের নামটা হাতে লেখা হত। এখন তালিকা থেকে বাছা হয়
 * (`institution_id`), কিন্তু **পুরনো সারিগুলোর ঘরটা খালি** — তাই "কোন
 * প্রতিষ্ঠানে কত আমানত" পর্দায় ওগুলো "প্রতিষ্ঠান বসানো হয়নি"-তে জমে।
 *
 * ── ⚠️ কেন নিজে থেকে কিছু বসে না ─────────────────────────────────────
 * নাম মেলানো ঠিক এখানেই ভুল করে: "Dhaka Bank" আর "DBBL" দুইটা আলাদা
 * ব্যাংক। ⛔ তাই নিয়ম একটাই — **দুইটা প্রতিষ্ঠান মিললে প্রস্তাব নয়**, আর
 * অর্ধেক সারি না-মেলা থাকা দুইটা ব্যাংক এক হয়ে যাওয়ার চেয়ে ভালো।
 *
 * ⓘ লাইভে `--apply` চালান সমন্বয়কারী, মালিককে তালিকাটা দেখানোর পরে।
 */
class ProposeDepositInstitutions extends Command
{
    protected $signature = 'finance:propose-deposit-institutions
        {--company= : কেবল এই কোম্পানি কোড}
        {--apply : নিশ্চিত নামগুলোর আমানতে প্রতিষ্ঠান বসায়; না দিলে কেবল দেখায়}';

    protected $description = 'পুরনো আমানতগুলো কোন প্রতিষ্ঠানের — নাম দেখে প্রস্তাব (নিজে থেকে কিছু বসায় না)';

    public function handle(InstitutionLinkProposer $proposer): int
    {
        $apply = (bool) $this->option('apply');

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $proposer, $apply) {
                $rows = $proposer->proposeDeposits();

                $this->line('');
                $this->info("{$company->code} — {$rows->count()}টা নাম, {$rows->sum('count')}টা আমানতে প্রতিষ্ঠান বসানো নেই");

                if ($rows->isEmpty()) {
                    return;
                }

                $this->table(
                    ['টাইপ করা নাম', 'কয়টা', 'মূল টাকা', 'প্রস্তাব', 'কেন'],
                    $rows->map(fn ($r) => [
                        $r['name'] === '' ? '(ফাঁকা)' : $r['name'],
                        $r['count'],
                        Money::format($r['principal']),
                        match ($r['why']) {
                            'none' => '— মেলেনি',
                            'blank' => '— নাম নেই',
                            'ambiguous' => '? '.collect($r['candidates'])->map->name_en->implode(' | '),
                            default => $r['institution']->name_en,
                        },
                        $r['why'],
                    ])->all(),
                );

                /*
                 * ⓘ তিনটা সংখ্যা আলাদা করে বলা হয়, কারণ সিদ্ধান্তটা ঐ
                 * তিনটার অনুপাত দেখেই নেওয়া হয়: কতটা নিশ্চিত, কতটা
                 * সন্দেহজনক, আর কতটা হাতে করতে হবে।
                 */
                $sure = $rows->where('institution', '!=', null);
                $doubt = $rows->where('why', 'ambiguous');

                $this->line(sprintf(
                    '  নিশ্চিত: %dটা নাম / %dটা আমানত · দ্ব্যর্থক: %dটা নাম · বাকি: %dটা নাম',
                    $sure->count(),
                    $sure->sum('count'),
                    $doubt->count(),
                    $rows->count() - $sure->count() - $doubt->count(),
                ));

                if ($apply) {
                    $done = $proposer->applyDeposits($rows);
                    $this->info("  {$done}টা আমানতে প্রতিষ্ঠান বসল।");
                }
            });
        }

        if (! $apply) {
            $this->comment('কিছু বসানো হয়নি। নিশ্চিত নামগুলো বসাতে --apply দিন।');
        }

        return self::SUCCESS;
    }
}
