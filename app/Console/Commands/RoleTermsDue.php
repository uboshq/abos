<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\RoleTerms;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * ⭐ সময়-বাঁধা রোলের রোজকার কাজ — প্রতিটা কোম্পানিতে: শুরুর দিন এলে রোল বসে, শেষ দিন পেরোলে ওঠে, শেষের আগের দিন মালিক আর
 * ঐ মানুষ খবর পান ([[RoleTerms::due()]]; রোলের পাতার নমুনা, মালিক "ok", ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ বারবার চালানো নিরাপদ — একই শেষ দিনের খবর একবারই। ⓘ এক কোম্পানি ভাঙলে বাকিগুলো চলে, শেষে ব্যর্থতা জানায়।
 */
class RoleTermsDue extends Command
{
    protected $signature = 'abos:role-terms
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}';

    protected $description = 'সময়-বাঁধা রোল — শুরু, শেষ আর শেষের আগের দিনের খবর';

    public function handle(RoleTerms $terms): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        $broke = false;
        $sum = ['started' => 0, 'ended' => 0, 'warned' => 0];

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            try {
                foreach ($terms->due() as $key => $n) {
                    $sum[$key] += $n;
                }
            } catch (\Throwable $e) {
                $broke = true;
                $this->error("{$company->code}: {$e->getMessage()}");
                logger()->error('সময়-বাঁধা রোলের রোজকার কাজ চলেনি।', ['company' => $company->code, 'error' => $e->getMessage()]);
            }
        }

        CompanyContext::clear();
        $this->info("শুরু {$sum['started']}, শেষ {$sum['ended']}, আগাম খবর {$sum['warned']}।");

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
