<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\TenancyService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ⭐ মাসের ভাড়া মাসের শুরুতেই প্রদেয় — মালিকের সিদ্ধান্ত প্র২, ৬ অক্টোবর ২০২৬ ([[RentalAccrualService]])।
 *
 * ⓘ কোম্পানি ধরে ধরে, [[MoneyDue]]-এর হুবহু কারণে: কোয়েরিগুলো `CompanyContext` ধরে চলে, আর একটা কোম্পানিতে আটকালে বাকিগুলোর
 * ভাড়া যেন না আটকায় — ভাঙাটা লগে ওঠে, ফেরত মান ব্যর্থতা, শিডিউলারের `onFailure()` তখনই ডাকে।
 *
 * ⓘ মাস না দিলে চলতি মাস। একই মাস বারবার চালালেও একবারই বসে, তাই ঘণ্টায় চালানো নিরাপদ।
 */
class RentAccrue extends Command
{
    protected $signature = 'abos:rent-accrue
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}
        {--month= : কোন মাস (YYYY-MM), খালি হলে চলতি মাস}';

    protected $description = 'মাসের ভাড়া মাসের শুরুতে বসায় — দেওয়ার দিকে প্রদেয়, ভাড়াটের দিকে পাওনা; এক মাস একবার';

    public function handle(RentalAccrualService $accruals, TenancyService $tenancies): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m-d', $this->option('month').'-01')
            : Carbon::today()->startOfMonth();

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('কোনো কোম্পানি পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $broke = false;
        $accrued = 0;
        $charged = 0;
        $held = 0;

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            try {
                $done = $accruals->run($month);
                $accrued += $done['accrued'];
                $held += $done['held'];

                // ⭐ উল্টো দিক — ভাড়াটের মাসের দাবি (মালিকের সিদ্ধান্ত প্র৩), একই নিয়মে
                $billed = $tenancies->charge($month);
                $charged += $billed['charged'];
                $held += $billed['held'];
            } catch (\Throwable $e) {
                $broke = true;

                $this->error("{$company->code}: {$e->getMessage()}");

                logger()->error('মাসের প্রদেয় ভাড়া বসানো যায়নি।', [
                    'company' => $company->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("প্রদেয় ভাড়া {$accrued}টি চুক্তিতে, ভাড়াটের দাবি {$charged}টি, সইয়ের অপেক্ষায় {$held}টি।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
