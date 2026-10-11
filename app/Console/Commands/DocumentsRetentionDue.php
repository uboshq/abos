<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Documents\Services\DocumentRetention;
use Illuminate\Console\Command;

/**
 * ডকুমেন্টের রাখার নিয়ম — আর্কাইভ, তারপর রিসাইকেল বিন; ⛔ কখনো চিরতরে মোছা নয়
 * (মালিকের ডকুমেন্ট পরিকল্পনা §২০; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ কাজটা মডিউলের সেবায় ([[DocumentRetention]]); অবস্থা দেখে চলে, তাই যতবার চলুক একই ফল।
 */
final class DocumentsRetentionDue extends Command
{
    protected $signature = 'abos:documents-retention
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'ডকুমেন্টের রাখার নিয়ম চালায় — আর্কাইভ আর রিসাইকেল বিন, কখনো চিরতরে মোছা নয়';

    public function handle(DocumentRetention $retention): int
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
        $archived = 0;
        $binned = 0;

        foreach ($companies as $company) {
            try {
                $done = CompanyContext::forCompany($company->id, fn () => $retention->run());

                $archived += $done['archived'];
                $binned += $done['binned'];
            } catch (\Throwable $e) {
                $broke = true;

                $this->error("{$company->code}: {$e->getMessage()}");

                logger()->error('ডকুমেন্টের রাখার নিয়ম চালানো যায়নি।', [
                    'company' => $company->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("আর্কাইভে {$archived}টি, রিসাইকেল বিনে {$binned}টি।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
