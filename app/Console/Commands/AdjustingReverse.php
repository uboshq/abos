<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\AdjustingReversals;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ⭐ সমন্বয় জাবেদা নিজের "উল্টো দাখিলার তারিখে" উল্টায় — ভাউচারের পরিকল্পনা ৩ঘ (fe, ৭ অক্টোবর ২০২৬; [[AdjustingReversals]])।
 *
 * ⓘ কোম্পানি ধরে ধরে, [[RentAccrue]]-এর হুবহু কারণে: কোয়েরি `CompanyContext` ধরে চলে, আর একটা কোম্পানি বা একটা ভাউচারে
 * আটকালে বাকিগুলো থামে না — ভাঙাটা লগে ওঠে, ফেরত মান ব্যর্থতা, শিডিউলারের `onFailure()` ডাকে।
 * ⓘ বারবার চালালেও একটা ভাউচার একবারই উল্টায়, তাই ঘণ্টায় চালানো নিরাপদ।
 */
class AdjustingReverse extends Command
{
    protected $signature = 'abos:adjusting-reverse
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}
        {--date= : কোন দিন পর্যন্ত (YYYY-MM-DD), খালি হলে আজ}';

    protected $description = 'মাসশেষের সমন্বয় জাবেদা তার উল্টো দাখিলার তারিখে নিজে উল্টায় — একবারই';

    public function handle(AdjustingReversals $reversals): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('কোনো কোম্পানি পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $broke = false;
        $reversed = 0;

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            try {
                $done = $reversals->run($today);
                $reversed += $done['reversed'];

                foreach ($done['failed'] as $why) {
                    $broke = true;
                    $this->error("{$company->code}: {$why}");
                    logger()->error('সমন্বয় জাবেদা উল্টানো যায়নি।', ['company' => $company->code, 'error' => $why]);
                }
            } catch (\Throwable $e) {
                $broke = true;
                $this->error("{$company->code}: {$e->getMessage()}");
                logger()->error('সমন্বয় জাবেদা উল্টানো যায়নি।', ['company' => $company->code, 'error' => $e->getMessage()]);
            }
        }

        $this->info("উল্টো জাবেদা বসল {$reversed}টি।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
