<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Finance\Services\InstitutionLinkProposer;
use Illuminate\Console\Command;

/**
 * ছকের ব্যাংক/MFS খাত ↔ আর্থিক প্রতিষ্ঠান — নাম দেখে প্রস্তাব।
 *
 * ⚠️ চালালে কেবল তালিকা দেখায়; কিছু বসায় না। `--apply` দিলে তবেই কেবল
 * **নিশ্চিত** (একটাই প্রতিষ্ঠান মেলে) জোড়াগুলো বসে। দ্ব্যর্থক আর না-মেলা
 * খাত পর্দা থেকে হাতে জোড়া হয় — প্রতিষ্ঠানের পাতায় "খাত জোড়ো"।
 *
 * ⓘ লাইভে `--apply` চালান সমন্বয়কারী, আগে তালিকাটা পড়ে।
 */
class ProposeInstitutionLinks extends Command
{
    protected $signature = 'finance:propose-institution-links
        {--company= : কেবল এই কোম্পানি কোড}
        {--apply : নিশ্চিত জোড়াগুলো বসায়; না দিলে কেবল দেখায়}';

    protected $description = 'ব্যাংক/MFS খাতগুলো কোন আর্থিক প্রতিষ্ঠানের — নাম দেখে প্রস্তাব (নিজে থেকে কিছু বসায় না)';

    public function handle(InstitutionLinkProposer $proposer): int
    {
        $apply = (bool) $this->option('apply');

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $proposer, $apply) {
                $proposals = $proposer->propose();

                $this->line('');
                $this->info("{$company->code} — {$proposals->count()}টা জোড়াহীন ব্যাংক/MFS খাত");

                $this->table(
                    ['খাত', 'নাম', 'প্রস্তাব', 'কেন'],
                    $proposals->map(fn ($p) => [
                        $p['account']->code,
                        $p['account']->name_en,
                        match ($p['why']) {
                            'none' => '— মেলেনি',
                            'ambiguous' => '? '.collect($p['candidates'])->map->name_en->implode(' | '),
                            default => $p['institution']->name_en,
                        },
                        $p['why'],
                    ])->all(),
                );

                if ($apply) {
                    $made = $proposer->apply($proposals);
                    $this->info("  {$made}টা জোড়া বসল।");
                }
            });
        }

        if (! $apply) {
            $this->comment('কিছু বসানো হয়নি। নিশ্চিত জোড়াগুলো বসাতে --apply দিন।');
        }

        return self::SUCCESS;
    }
}
