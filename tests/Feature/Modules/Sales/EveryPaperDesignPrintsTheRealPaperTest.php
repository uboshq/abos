<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Support\VoucherDesigns;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * প্রতিটা কাগজ, প্রতিটা মাপ, প্রতিটা নকশা — আসল কাগজ ছেপে, বাছা ছাঁচটাই আঁকে কিনা।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"printe template sob gulo kore deploy diba A4 A5 tharmal tintiroi"*।
 *
 * ── ⚠️ কেন আসল ছাপার পথে, নমুনায় নয় ────────────────────────────────────
 * ⛔ নমুনার পাতা বানানো তথ্য দেয়; আসল পথে আরেক রকম তথ্য আসে (চালানের facts, ভাউচারের ধরন)।
 * একটা নকশা নমুনায় সুন্দর আর আসল কাগজে ৫০০ — ঠিক এভাবেই ক্লাসিকের QR ধরা পড়েছিল।
 * ⓘ তালিকা আসে [[PaperDesigns::codes()]] / [[VoucherDesigns::codes()]] থেকে — নতুন নকশা এলে
 * এই পরীক্ষাও নিজে থেকে তাকে ছাপে।
 */
final class EveryPaperDesignPrintsTheRealPaperTest extends TestCase
{
    use RefreshDatabase;

    /** নকশার মাপ → ছাপার ঠিকানার `?paper=` */
    private const PAPER = ['a4' => 'a4', 'a5' => 'a5', 'thermal' => '80mm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⛔ ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই (মালিকের চূড়ান্ত কথা) — তাই এই পরীক্ষার গ্রাহকের সত্যিকারের বড় সীমা
        Customer::query()->firstOrFail()->forceFill(['credit_limit' => '10000000000'])->save();
    }

    public function test_every_invoice_design_prints_a_real_bill_in_its_size(): void
    {
        $invoice = $this->invoice();

        foreach (['a5', 'thermal'] as $size) {
            $this->printsEach('invoice', $size, PaperDesigns::codes('invoice', $size),
                fn (string $code) => PaperDesigns::template('invoice', $size, $code),
                route('sales.print.invoice', $invoice).'?paper='.self::PAPER[$size]);
        }
    }

    public function test_every_challan_design_prints_a_real_challan_in_its_size(): void
    {
        $challan = $this->challan();

        foreach (PaperDesigns::SIZES as $size) {
            $this->printsEach('challan', $size, PaperDesigns::codes('challan', $size),
                fn (string $code) => PaperDesigns::template('challan', $size, $code),
                route('sales.print.challan', $challan).'?paper='.self::PAPER[$size]);
        }
    }

    public function test_every_voucher_design_prints_a_real_voucher_in_its_size(): void
    {
        $voucher = $this->voucher();

        foreach (VoucherDesigns::SIZES as $size) {
            $key = VoucherDesigns::key($size);

            foreach (VoucherDesigns::codes($size) as $code) {
                app(SettingsService::class)->set($key, $code);
                $this->assertDrawn((string) VoucherDesigns::template($size, $code),
                    route('accounts.voucher.print', $voucher).'?paper='.self::PAPER[$size], "voucher/{$size}/{$code}");
            }
        }
    }

    public function test_every_order_design_prints_a_real_order_in_its_size(): void
    {
        $service = app(SalesOrderService::class);
        $order = $service->confirm($service->create($this->header(), [[
            'product_id' => Product::query()->value('id'), 'ordered_qty' => '2', 'rate' => '150',
        ]]));

        foreach (PaperDesigns::SIZES as $size) {
            $this->printsEach('order', $size, PaperDesigns::codes('order', $size),
                fn (string $code) => PaperDesigns::template('order', $size, $code),
                route('sales.print.order', $order).'?paper='.self::PAPER[$size]);
        }
    }

    public function test_every_receipt_design_prints_a_real_collection_receipt_in_its_size(): void
    {
        $invoice = $this->invoice();

        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id,
            'document_no' => 'RCV-DESIGN-1',
            'customer_id' => $invoice->customer_id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => '200',
            'status' => DocumentStatus::CONFIRMED,
            'narration' => 'হাতে নগদ',
        ]);
        CollectionLine::query()->create([
            'collection_id' => $collection->id, 'line_no' => 1, 'sales_invoice_id' => $invoice->id, 'amount' => '200',
        ]);

        foreach (PaperDesigns::SIZES as $size) {
            $this->printsEach('receipt', $size, PaperDesigns::codes('receipt', $size),
                fn (string $code) => PaperDesigns::template('receipt', $size, $code),
                route('sales.print.receipt', $collection).'?paper='.self::PAPER[$size]);
        }
    }

    public function test_the_lists_are_not_empty_where_the_owner_approved_designs(): void
    {
        /* ⛔ ফাঁকা তালিকা হলে উপরের পরীক্ষাগুলো কিছুই না ছেপে সবুজ হত */
        $this->assertCount(39, PaperDesigns::codes('invoice', 'a4'));
        $this->assertCount(29, PaperDesigns::codes('invoice', 'a5'));
        $this->assertCount(28, PaperDesigns::codes('invoice', 'thermal'));

        foreach (['challan', 'order', 'receipt'] as $paper) {
            foreach (PaperDesigns::SIZES as $size) {
                /* ⓘ চালানের A4-এ মালিকের ডিফল্ট "মোনো ক্লাসিক হালকা"-র দুই রূপ বাড়তি */
                $this->assertCount($paper === 'challan' ? 22 : 20, PaperDesigns::codes($paper, $size), "{$paper}/{$size}");
            }
        }

        foreach (VoucherDesigns::SIZES as $size) {
            $this->assertCount(20, VoucherDesigns::codes($size), "voucher/{$size}");
        }
    }

    /**
     * ⭐ কেউ না বাছলে মালিকের বাছা ডিফল্ট (৩০ সেপ্টেম্বর ২০২৬, "OK") — পুরনো "সাধারণ" নয়।
     */
    public function test_nobody_chose_so_the_owners_defaults_print(): void
    {
        $challan = $this->challan();
        $invoice = $this->invoice();

        $this->assertDrawn('sales::print.invoice-mono_light', route('sales.print.invoice', $invoice).'?paper=a4', 'invoice/a4 default');
        $this->assertDrawn('sales::print.invoice-a5_mono_light', route('sales.print.invoice', $invoice).'?paper=a5', 'invoice/a5 default');
        $this->assertDrawn('sales::print.invoice-thermal_mono_light', route('sales.print.invoice', $invoice).'?paper=80mm', 'invoice/thermal default');
        $this->assertDrawn('sales::print.challan-mono_light', route('sales.print.challan', $challan).'?paper=a4', 'challan/a4 default');
        $this->assertDrawn('print.voucher-tally_classic', route('accounts.voucher.print', $this->voucher()).'?paper=a4', 'voucher/a4 default');
    }

    public function test_standard_or_an_unknown_design_keeps_the_old_paper(): void
    {
        $challan = $this->challan();
        $settings = app(SettingsService::class);

        foreach (['standard', 'no_such_design'] as $code) {
            $settings->set(PaperDesigns::key('challan', 'a4'), $code);
            $this->assertDrawn('print.document', route('sales.print.challan', $challan).'?paper=a4', "challan/a4/{$code}");
        }

        $this->assertNull(PaperDesigns::template('challan', 'a4', 'no_such_design'));
        $this->assertNull(VoucherDesigns::template('a4', 'no_such_design'));
    }

    /** @param list<string> $codes */
    private function printsEach(string $paper, string $size, array $codes, \Closure $template, string $url): void
    {
        foreach ($codes as $code) {
            app(SettingsService::class)->set(PaperDesigns::key($paper, $size), $code);
            $this->assertDrawn((string) $template($code), $url, "{$paper}/{$size}/{$code}");
        }
    }

    private function assertDrawn(string $view, string $url, string $what): void
    {
        $drawn = false;
        View::composer($view, function () use (&$drawn) {
            $drawn = true;
        });

        $response = $this->get($url)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), "{$what}: PDF বেরোয়নি।");
        $this->assertTrue($drawn, "{$what}: বাছা নকশা ({$view}) আঁকা হয়নি — কাগজ অন্য ছাঁচে ছাপা হয়েছে।");
    }

    private function header(): array
    {
        return [
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ];
    }

    private function invoice()
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create($this->header(), [[
            'product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '150',
        ]]));
    }

    private function challan()
    {
        $service = app(DeliveryChallanService::class);

        // ⓘ মাল কীভাবে যাবে — ছাপার আগে লাগে ([[RequireTransportBeforePrint]])
        return $service->confirm($service->create([...$this->header(), 'own_transport' => true], [[
            'product_id' => Product::query()->value('id'), 'delivered_qty' => '2', 'rate' => '150',
        ]]));
    }

    private function voucher(): Voucher
    {
        $leaf = fn (string $code) => (int) Account::query()->where('is_group', false)->where('code', 'like', $code.'%')->value('id');

        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'নকশার পরীক্ষা',
        ], [
            ['account_id' => $leaf(StandardChart::CASH_IN_TRANSIT), 'debit' => '500', 'credit' => '0'],
            ['account_id' => $leaf(StandardChart::RECEIVABLE), 'debit' => '0', 'credit' => '500'],
        ]);
    }
}
