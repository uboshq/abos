<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\HeldCounterSaleFinisher;
use Illuminate\Console\Command;

/**
 * ⭐ এককালীন — সই হয়ে যাওয়া অথচ শেষ না হওয়া কাউন্টারের বিক্রি।
 *
 * ⓘ শেষ সইয়ের পরে নিজে শেষ হওয়ার পথ ([[FinishTheHeldSaleOnTheLastSignature]])
 * কেবল **এখন থেকে** পড়া সইয়ে চলে। ⚠️ তার আগে সই পড়া বিক্রিগুলো আটকেই থাকত —
 * লাইভে INV-0005: চালান ও জমা দুইটার সই-ই অনুমোদিত, বিল আর RCV-0005 খসড়া।
 *
 * ⭐ একই সেবা ([[HeldCounterSaleFinisher]]), একই শর্ত — আলাদা কোনো "শেষ করার"
 * যুক্তি নেই। `--dry-run` কিছুই লেখে না, কেবল বলে কোনটা শেষ হবে আর কোনটা কেন নয়।
 */
class FinishSignedCounterSales extends Command
{
    protected $signature = 'abos:finish-signed-counter-sales
        {--dry-run : কী হত তা দেখায়, কিছু বসায় না}
        {--company= : কেবল এই কোম্পানির কোড}';

    protected $description = 'Finish counter sales whose signatures are all approved but which still sit as drafts';

    public function handle(HeldCounterSaleFinisher $finisher): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('company');
        $counts = ['finished' => 0, 'left' => 0];

        $companies = Company::query()
            ->when(filled($only), fn ($q) => $q->where('code', $only))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($finisher, $dry, $company, &$counts) {
                $held = DirectSaleService::openCounterDrafts()
                    ->withoutGlobalScope('user-branch')
                    ->whereNull('sal_invoices.counter_draft')
                    ->orderBy('sal_invoices.id')
                    ->get();

                foreach ($held as $invoice) {
                    $result = $dry ? $finisher->readiness($invoice) : $finisher->finish($invoice);
                    $done = $result['state'] === HeldCounterSaleFinisher::FINISHED;

                    $counts[$done ? 'finished' : 'left']++;

                    $this->line(sprintf(
                        '%s  %s  %s  %s',
                        $company->code,
                        $invoice->document_no,
                        $done ? ($dry ? 'WOULD FINISH' : 'FINISHED') : strtoupper($result['state']),
                        $result['reason'],
                    ));
                }
            });
        }

        $this->info(sprintf(
            '%s: %d %s, %d left as they are.',
            $dry ? 'Dry run' : 'Done',
            $counts['finished'],
            $dry ? 'would finish' : 'finished',
            $counts['left'],
        ));

        return self::SUCCESS;
    }
}
