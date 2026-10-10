<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Documents\Services\DocumentExpiry;
use Illuminate\Console\Command;

/**
 * ডকুমেন্টের মেয়াদের খবর — ৯০ → ৬০ → ৩০ → ১৫ → ৭ → ১ দিন আগে, আর পেরোলে একবার
 * (মালিকের ডকুমেন্ট পরিকল্পনা §১২; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ কাজটা মডিউলের সেবায় ([[DocumentExpiry]]); এই কমান্ড কেবল প্রতিটা কোম্পানিতে একবার ডাকে।
 * ⛔ যতবার চলুক একই খবর একবারই — সেবা প্রতিটা পাঠানো খবর লিখে রাখে (`dms_expiry_notices`)।
 *
 * ⚠️ একটা কোম্পানি ভাঙলে বাকিরা থামে না; শেষে ব্যর্থতা ফেরত যায়, যাতে `onFailure` লগে লেখে।
 */
final class DocumentsExpiryDue extends Command
{
    protected $signature = 'abos:documents-expiry
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'ডকুমেন্টের মেয়াদের আগাম খবর পাঠায় আর মেয়াদ পেরোনো কাগজ "মেয়াদোত্তীর্ণ" করে';

    public function handle(DocumentExpiry $expiry): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('কোনো কোম্পানি পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $broke = false;
        $notices = 0;
        $expired = 0;

        foreach ($companies as $company) {
            try {
                $done = CompanyContext::forCompany($company->id, fn () => $expiry->run());

                $notices += $done['notices'];
                $expired += $done['expired'];
            } catch (\Throwable $e) {
                $broke = true;

                $this->error("{$company->code}: {$e->getMessage()}");

                logger()->error('ডকুমেন্টের মেয়াদের খবর পাঠানো যায়নি।', [
                    'company' => $company->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("মেয়াদের খবর {$notices}টি, মেয়াদোত্তীর্ণ কাগজ {$expired}টি।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
