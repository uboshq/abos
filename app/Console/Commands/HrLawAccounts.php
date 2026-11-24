<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Console\Command;

/**
 * ⭐ আইনি বেতনের খাতগুলো প্রতিটা চলমান কোম্পানিতে — ৫২২৫ (ভবিষ্য তহবিলে প্রতিষ্ঠানের চাঁদা), ২১৩২ (উৎসে কর), ৫২২৬ (উৎসব ভাতা),
 * ৫২২৭ আর ২১৩৪ (গ্র্যাচুইটি), ৫২২৩ আর ২১৩৩ (শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল, মেশানোর পর) — ছকের ঠিক দলে (পরিচালন ব্যয় ৫২০০,
 * চলতি দায় ২১০০) (a6, ২১ অক্টোবর ২০২৬; [[StandardChart::HR_LAW_CODES]], [[NoTwoChartAccountsShareACodeTest]])।
 *
 * ⭐ আর সিস্টেমের চিহ্ন — আগে থেকে থাকা কিন্তু চিহ্নহীন আইনি খাত (যেমন WPPF-এর ৫২২৩/২১৩৩, যা সিস্টেমের তালিকায় আসার আগে বসেছিল)
 * এখন সিস্টেমের: নিষ্ক্রিয় করা যায় না, ধরন বা কোড বদলায় না (fe-র সিদ্ধান্ত ৪, ২১ অক্টোবর ২০২৬; [[StandardChart::SYSTEM_CODES]],
 * [[TheWppfAccountsAreSystemAccountsTest]])। ⓘ নতুন বসানো খাত [[StandardChart::install()]] নিজেই চিহ্ন দেয়।
 *
 * ⓘ বারবার চালানো নিরাপদ: কেবল না থাকা খাত বসায় আর চিহ্নহীনকে চিহ্ন দেয়; নাম, দল বা অন্য কিছু ছোঁয় না। `--dry` কিছুই বসায় না,
 * কোন কোম্পানিতে কী হবে কেবল তা বলে। ⛔ খাত না থাকলে বেতনের রান নিজেই থামে, ভুল খাতে বসে না ([[PayrollService]])।
 */
class HrLawAccounts extends Command
{
    protected $signature = 'abos:hr-law-accounts {--dry : কিছু না বসিয়ে কেবল দেখায়}';

    protected $description = 'প্রতিটা কোম্পানিতে আইনি বেতনের খাত (5225, 2132, 5226, 5227, 2134, 5223, 2133) বসায় — না থাকলে; আর সিস্টেমের চিহ্ন দেয়';

    public function handle(StandardChart $chart, AccountService $accounts): int
    {
        $dry = (bool) $this->option('dry');
        $total = 0;
        $marked = 0;
        // ⓘ আইনি খাতের যেগুলো সিস্টেমের — আজ সাতটাই
        $system = array_values(array_intersect(StandardChart::HR_LAW_CODES, StandardChart::SYSTEM_CODES));

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $missing = CompanyContext::forCompany($company->id, fn () => $chart->missing(StandardChart::HR_LAW_CODES));

            if ($missing !== []) {
                if (! $dry) {
                    CompanyContext::forCompany($company->id, fn () => $chart->install(StandardChart::HR_LAW_CODES));
                }

                $this->line(($dry ? 'বসবে' : 'বসল').' — '.$company->code.': '.implode(', ', $missing));
                $total += count($missing);
            }

            // ⭐ আছে, অথচ সিস্টেমের নয় — চিহ্ন দেওয়া (কেবল চিহ্ন; বাকি সারি যেমন ছিল)
            $unmarked = CompanyContext::forCompany($company->id, fn () => Account::query()
                ->whereIn('code', $system)->where('is_group', false)->where('is_system', false)->orderBy('code')->get());

            if ($unmarked->isEmpty()) {
                continue;
            }

            if (! $dry) {
                CompanyContext::forCompany($company->id, fn () => $unmarked->each(fn (Account $account) => $accounts->markAsSystem($account)));
            }

            $this->line(($dry ? 'সিস্টেমের হবে' : 'সিস্টেমের হলো').' — '.$company->code.': '.$unmarked->pluck('code')->implode(', '));
            $marked += $unmarked->count();
        }

        $this->info($total === 0 && $marked === 0
            ? 'সব কোম্পানিতে খাতগুলো আগে থেকেই আছে, সিস্টেমের চিহ্নসহ।'
            : ($dry ? "মোট {$total}টা খাত বসবে, {$marked}টা সিস্টেমের হবে (শুকনো চালানো)।" : "মোট {$total}টা খাত বসল, {$marked}টা সিস্টেমের হলো।"));

        return self::SUCCESS;
    }
}
