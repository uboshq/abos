<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Console\Command;

/**
 * ⭐ আইনি বেতনের খাতগুলো প্রতিটা চলমান কোম্পানিতে — ৫২২৫ (ভবিষ্য তহবিলে প্রতিষ্ঠানের চাঁদা), ২১৩২ (উৎসে কর), ৫২২৬ (উৎসব ভাতা),
 * ৫২২৭ আর ২১৩৪ (গ্র্যাচুইটি), ৫২২৩ আর ২১৩৩ (শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল, মেশানোর পর) — ছকের ঠিক দলে (পরিচালন ব্যয় ৫২০০,
 * চলতি দায় ২১০০) (a6, ২১ অক্টোবর ২০২৬; [[StandardChart::HR_LAW_CODES]], [[NoTwoChartAccountsShareACodeTest]])।
 *
 * ⓘ বারবার চালানো নিরাপদ: কেবল না থাকা খাত বসায়, আছে এমন খাত ছোঁয় না ([[StandardChart::install()]])। `--dry` কিছুই বসায় না,
 * কোন কোম্পানিতে কী বসবে কেবল তা বলে। ⛔ খাত না থাকলে বেতনের রান নিজেই থামে, ভুল খাতে বসে না ([[PayrollService]])।
 */
class HrLawAccounts extends Command
{
    protected $signature = 'abos:hr-law-accounts {--dry : কিছু না বসিয়ে কেবল দেখায়}';

    protected $description = 'প্রতিটা কোম্পানিতে আইনি বেতনের খাত (5225, 2132, 5226, 5227, 2134, 5223, 2133) বসায় — না থাকলে';

    public function handle(StandardChart $chart): int
    {
        $dry = (bool) $this->option('dry');
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $missing = CompanyContext::forCompany($company->id, fn () => $chart->missing(StandardChart::HR_LAW_CODES));

            if ($missing === []) {
                continue;
            }

            if (! $dry) {
                CompanyContext::forCompany($company->id, fn () => $chart->install(StandardChart::HR_LAW_CODES));
            }

            $this->line(($dry ? 'বসবে' : 'বসল').' — '.$company->code.': '.implode(', ', $missing));
            $total += count($missing);
        }

        $this->info($total === 0 ? 'সব কোম্পানিতে খাতগুলো আগে থেকেই আছে।' : ($dry ? "মোট {$total}টা খাত বসবে (শুকনো চালানো)।" : "মোট {$total}টা খাত বসল।"));

        return self::SUCCESS;
    }
}
