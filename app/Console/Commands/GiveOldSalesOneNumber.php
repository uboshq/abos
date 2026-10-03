<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SaleNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ পুরনো বিক্রিগুলোকে একটা করে নম্বর দেয় — এককালীন, হাতে ডাকার জন্য।
 *
 * ── কেন ──────────────────────────────────────────────────────────────
 * মালিক, ২৯ সেপ্টেম্বর ২০২৬: একটা বিক্রির একটাই নম্বর ([[SaleNumber]])। নতুন কাগজ
 * সেভাবেই জন্মায়। কিন্তু লাইভে আগের কাগজগুলো DC/DS/INV/GP নম্বরে, আর মালিক বললেন
 * *"egulo to test demo, somossa nai"* — ওগুলোও বদলাবে।
 *
 * ── কী বদলায় ─────────────────────────────────────────────────────────
 * - চালান: আদেশের আগের চালানের বিক্রি থাকলে সেই নম্বর (দ্বিতীয়টা /2), নাহলে নতুন S।
 * - বিল: তার চালানের নম্বর; চালান ছাড়া বিল নতুন S।
 * - গেট পাস: চালানের নম্বর (/2, /3 …)।
 * - ফেরত: ⛔ নিজের নম্বর **থাকে** — কেবল `sale_no`-তে বিক্রির নম্বর সূত্র হিসেবে বসে
 *   (মালিক: *"return id alada hobe"*)।
 * - যে টেবিলগুলো কাগজের নম্বর ধরে রাখে (খাতা, মজুদের চলাচল, ছাপা, পাঠানো …) — পুরনো
 *   নম্বর নতুনটায়। ⓘ খাতার সিল নম্বর সই করে না ([[LedgerChain::SIGNED]]), তাই শিকল অক্ষত।
 *
 * ── ⛔ কী বদলায় না ────────────────────────────────────────────────────
 * নিরীক্ষার খাতা (`audit_trails`) আর দেওয়া নম্বরের খাতা (`issued_numbers`) — ওগুলো ইতিহাস।
 *
 * ⓘ `--force` ছাড়া কেবল দেখায়: পুরো কাজটা ট্রানজেকশনে চলে আর শেষে ফিরিয়ে নেওয়া হয়।
 * ⚠️ চালানোর আগে ব্যাকআপ।
 */
class GiveOldSalesOneNumber extends Command
{
    protected $signature = 'abos:one-sale-number
                            {--company= : কেবল এই কোম্পানির (id)}
                            {--force : সত্যিই বদলায়; নাহলে কেবল দেখায়}';

    protected $description = 'পুরনো বিক্রির চালান, বিল, গেট পাসকে একটাই S-নম্বর দেয় (এককালীন)';

    /**
     * কাগজের নম্বর ধরে রাখা টেবিল — বিক্রির কাগজ নিজে বাদে।
     *
     * @var list<string>
     */
    private const REFERENCES = [
        'ledger_entries', 'inv_stock_movements', 'stock_movements',
        'inv_cost_layers', 'inv_cost_layer_uses',
        'sal_print_jobs', 'doc_deliveries', 'doc_shares', 'notices',
    ];

    public function handle(SaleNumber $numbers): int
    {
        $apply = (bool) $this->option('force');
        $only = $this->option('company');

        $companies = Company::query()->orderBy('id')
            ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($numbers, $apply, $company) {
                DB::beginTransaction();

                try {
                    $renamed = $this->renumber($numbers, (int) $company->id);
                } catch (\Throwable $e) {
                    DB::rollBack();

                    throw $e;
                }

                $apply ? DB::commit() : DB::rollBack();

                $this->line("── {$company->code}: ".count($renamed).' টা কাগজ'.($apply ? ' বদলানো হলো' : ' বদলাত'));

                foreach ($renamed as [$kind, $old, $new]) {
                    $this->line("   {$kind}  {$old}  →  {$new}");
                }
            });
        }

        if (! $apply) {
            $this->warn('কিছুই বদলায়নি — সত্যিই বদলাতে --force দিন (আগে ব্যাকআপ)।');
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function renumber(SaleNumber $numbers, int $companyId): array
    {
        $renamed = [];
        $orderSale = [];

        // ⛔ খসড়া নয় — ২ অক্টোবর ২০২৬ থেকে খসড়ার `sale_no` ইচ্ছে করেই খালি (DRF), আসল নম্বর নিশ্চিতে ([[SaleNumber::draft()]])
        foreach (DB::table('sal_challans')->where('company_id', $companyId)->whereNull('sale_no')->where('status', '<>', DocumentStatus::DRAFT)->orderBy('id')->get(['id', 'document_no', 'sales_order_id']) as $row) {
            $orderId = $row->sales_order_id === null ? null : (int) $row->sales_order_id;
            $saleNo = $orderId !== null && isset($orderSale[$orderId])
                ? $orderSale[$orderId]
                : $numbers->begin(DeliveryChallan::class);

            if ($orderId !== null) {
                $orderSale[$orderId] = $saleNo;
            }

            $renamed[] = $this->rename('sal_challans', $companyId, (int) $row->id, (string) $row->document_no,
                $numbers->asOnTheTwentyNinth(DeliveryChallan::class, $saleNo), $saleNo, 'চালান');
        }

        foreach (DB::table('sal_invoices')->where('company_id', $companyId)->whereNull('sale_no')->where('status', '<>', DocumentStatus::DRAFT)->orderBy('id')->get(['id', 'document_no']) as $row) {
            $saleNo = DB::table('sal_invoice_lines as il')
                ->join('sal_challan_lines as cl', 'cl.id', '=', 'il.delivery_challan_line_id')
                ->join('sal_challans as c', 'c.id', '=', 'cl.delivery_challan_id')
                ->where('c.company_id', $companyId)
                ->where('il.sales_invoice_id', $row->id)
                ->whereNotNull('c.sale_no')
                ->orderBy('il.id')
                ->value('c.sale_no') ?? $numbers->begin(SalesInvoice::class);

            $renamed[] = $this->rename('sal_invoices', $companyId, (int) $row->id, (string) $row->document_no,
                $numbers->asOnTheTwentyNinth(SalesInvoice::class, (string) $saleNo), (string) $saleNo, 'বিল');
        }

        foreach (DB::table('sal_gate_passes')->where('company_id', $companyId)->whereNull('sale_no')->orderBy('id')->get(['id', 'document_no', 'delivery_challan_id']) as $row) {
            $saleNo = DB::table('sal_challans')->where('company_id', $companyId)->where('id', $row->delivery_challan_id)->value('sale_no');

            if ($saleNo === null) {
                continue;
            }

            $renamed[] = $this->rename('sal_gate_passes', $companyId, (int) $row->id, (string) $row->document_no,
                $numbers->asOnTheTwentyNinth(GatePass::class, (string) $saleNo), (string) $saleNo, 'গেট পাস');
        }

        // ⛔ ফেরতের নিজের নম্বর থাকে — কেবল সূত্র
        foreach (DB::table('sal_returns')->where('company_id', $companyId)->whereNull('sale_no')->whereNotNull('sales_invoice_id')->get(['id', 'sales_invoice_id']) as $row) {
            DB::table('sal_returns')->where('company_id', $companyId)->where('id', $row->id)->update([
                'sale_no' => DB::table('sal_invoices')->where('company_id', $companyId)->where('id', $row->sales_invoice_id)->value('sale_no'),
            ]);
        }

        return $renamed;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function rename(string $table, int $companyId, int $id, string $old, string $new, string $saleNo, string $kind): array
    {
        DB::table($table)->where('company_id', $companyId)->where('id', $id)
            ->update(['document_no' => $new, 'sale_no' => $saleNo]);

        if ($old !== $new) {
            foreach (self::REFERENCES as $ref) {
                if (! Schema::hasTable($ref) || ! Schema::hasColumn($ref, 'document_no') || ! Schema::hasColumn($ref, 'company_id')) {
                    continue;
                }

                $query = DB::table($ref)->where('company_id', $companyId)->where('document_no', $old);

                // ⓘ উৎস-জোড়া থাকলে সেটাও মেলে — হাতে লেখা দুইটা কাগজের একই নম্বর থাকলেও ভুলটা বদলায় না
                if (Schema::hasColumn($ref, 'source_id')) {
                    $query->where('source_id', $id);
                }

                $query->update(['document_no' => $new]);
            }
        }

        return [$kind, $old, $new];
    }
}
