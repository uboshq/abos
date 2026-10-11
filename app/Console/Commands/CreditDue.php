<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\CreditControl\Services\CreditDaily;
use Illuminate\Console\Command;

/**
 * ⭐ বাকি নিয়ন্ত্রণের রোজকার কাজ — প্রতিটা কোম্পানিতে আজকের ঝুঁকির ছবি, আর শেষ হয়ে আসা অস্থায়ী সীমার খবর (বাকি নিয়ন্ত্রণ ধাপ ৩)।
 *
 * ⓘ বারবার চালানো নিরাপদ ([[CreditDaily]])। ⓘ এক কোম্পানি ভাঙলে বাকিগুলো চলে, শেষে ব্যর্থতা জানায়।
 */
class CreditDue extends Command
{
    protected $signature = 'abos:credit-due
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'বাকি নিয়ন্ত্রণের রোজকার কাজ — ঝুঁকির দিনের ছবি আর অস্থায়ী সীমা শেষের খবর';

    public function handle(CreditDaily $daily): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        $broke = false;
        $rated = 0;

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            try {
                $rated += $daily->snapshot();
            } catch (\Throwable $e) {
                $broke = true;
                $this->error("{$company->code}: {$e->getMessage()}");
                logger()->error('বাকি নিয়ন্ত্রণের রোজকার কাজ চলেনি।', ['company' => $company->code, 'error' => $e->getMessage()]);
            }
        }

        CompanyContext::clear();
        $this->info("ঝুঁকির ছবি {$rated} জনের।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
