<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Services\DepreciationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ⭐ মাসের অবচয় নিজে নিজে — কেবল যে কোম্পানিতে মালিক সুইচটা চালু করেছেন (স্থায়ী সম্পদ ধাপ ২; [[DepreciationEngine]])।
 *
 * ⓘ ডিফল্টে বন্ধ: আজ দৌড়টা হাতে, আগে দেখে তারপর বসানো। চালু করলে মাসের প্রথম দিনে গত মাসের অবচয় বসে।
 * ⓘ কোম্পানি ধরে ধরে, [[AdjustingReverse]]-এর হুবহু কারণে — একটায় আটকালে বাকিগুলো থামে না; বন্ধ মাসে থামে, কারণ লগে।
 * ⓘ বারবার চালালেও একবারই বসে (দৌড়ের অনন্য সারি), তাই শিডিউলার দুইবার ডাকলেও ক্ষতি নেই।
 */
class AssetDepreciate extends Command
{
    protected $signature = 'abos:depreciate
        {--company= : কেবল একটা কোম্পানির কোড, খালি হলে সবগুলো}
        {--month= : কোন মাস (YYYY-MM), খালি হলে গত মাস}
        {--force : সুইচ বন্ধ থাকলেও চালানো (হাতে)}';

    protected $description = 'মাসের অবচয় বসায় — শাখায় একটা কাগজ, একবারই';

    public function handle(DepreciationEngine $engine, SettingsService $settings): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))->startOfMonth()
            : Carbon::today()->subMonthNoOverflow()->startOfMonth();

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();

        $broke = false;

        foreach ($companies as $company) {
            CompanyContext::set($company->id, $company->defaultBranch()?->id);

            if (! $this->option('force') && ! (bool) $settings->get(DepreciationEngine::AUTO_RUN, false)) {
                continue;
            }

            try {
                $done = $engine->run($month);
                $this->info("{$company->code}: {$done['posted']}টি সম্পদে অবচয় বসল, {$done['skipped']}টি বাদ।");
            } catch (\Throwable $e) {
                $broke = true;
                $this->error("{$company->code}: {$e->getMessage()}");
                logger()->error('মাসের অবচয় বসানো যায়নি।', ['company' => $company->code, 'error' => $e->getMessage()]);
            }
        }

        return $broke ? self::FAILURE : self::SUCCESS;
    }
}
