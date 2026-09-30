<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ক্রয়-বিলের তালিকার কলাম — মালিক, ৩০ সেপ্টেম্বর–১ অক্টোবর ২০২৬:
 * *"Purchase Bills e Purchase reference no er ekta colam koro"*, তারপর বাকি কলামগুলো একসাথে।
 *
 *   Date | INV Number | Supp INV No. | Supplier | Branch | Warehouse | Items | Due on | Total | Paid | Due | State | Created by
 *
 * ⓘ সরবরাহকারীর নম্বর বিলে আগে থেকেই থাকত (`supplier_bill_no`), খোঁজেও ধরা পড়ত — কেবল তালিকায় দেখা যেত না। ⚠️ পুরনো
 * ERP থেকে আনা বিলে এই ঘরেই পুরনো "Invoice No" বসে (৬১৯, ১০৫১৩…), তাই মেলানোর কাজ এই কলাম ধরেই চলবে।
 *
 * ⓘ "শাখা" কেবল হেডারে "সব শাখা" থাকলে — এক শাখা বাছলে সব সারি একই শাখার, কলামটা কিছু বলে না।
 * ⚠️ কলামের মাথা মেলানো হয় **পুরো** লেখায়, অংশে নয়: বাংলায় "সরবরাহকারী" শব্দটা "সরবরাহকারীর বিল নং"-এর ভেতরেও আছে।
 */
final class TheBillListShowsTheSuppliersOwnNumberTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** ⭐ মালিকের ক্রম, "সব শাখা"-য় — শাখার কলামসহ */
    public function test_the_columns_stand_in_the_owners_order(): void
    {
        $this->bill('619');
        $html = (string) $this->get(route('purchase.bill.index'))->assertOk()->getContent();

        $this->assertSame($this->labels([
            'date', 'inv_number', 'supp_inv_no', 'supplier', 'branch', 'warehouse', 'items',
            'due_on', 'total', 'bill_paid', 'bill_due', 'state', 'created_by',
        ]), $this->headers($html), 'কলামগুলো মালিকের ক্রমে নেই।');
    }

    /** ⭐ হেডারে একটা শাখা বাছা — শাখার কলাম নেই, বাকিগুলো একই ক্রমে */
    public function test_the_branch_column_leaves_when_one_branch_is_picked(): void
    {
        $this->bill('619');
        $this->owner->forceFill(['view_all_branches' => false, 'current_branch_id' => CompanyContext::branchId()])->save();

        $html = (string) $this->actingAs($this->owner->fresh())->get(route('purchase.bill.index'))->assertOk()->getContent();

        $this->assertSame($this->labels([
            'date', 'inv_number', 'supp_inv_no', 'supplier', 'warehouse', 'items',
            'due_on', 'total', 'bill_paid', 'bill_due', 'state', 'created_by',
        ]), $this->headers($html), 'এক শাখা বাছার পরও শাখার কলাম আছে, বা বাকিগুলোর ক্রম ভেঙেছে।');
    }

    public function test_the_row_shows_what_each_column_promises(): void
    {
        $numbered = $this->bill('619', lines: 2);
        $blank = $this->bill(null);

        $html = (string) $this->get(route('purchase.bill.index'))->assertOk()->getContent();

        $row = $this->row($html, $numbered->document_no);
        $this->assertSame('619', $row[__('purchase::field.supp_inv_no')], 'সরবরাহকারীর নম্বর ৬১৯ সারিতে নেই।');
        $this->assertSame('2', $row[__('purchase::field.items')], 'দুই লাইনের বিলে পণ্যের সংখ্যা ২ নয়।');
        $this->assertSame($numbered->warehouse->name(), $row[__('purchase::field.warehouse')]);
        $this->assertSame($numbered->branch->name(), $row[__('purchase::field.branch')]);
        $this->assertSame($this->owner->name, $row[__('purchase::field.created_by')]);

        $this->assertSame('—', $this->row($html, $blank->document_no)[__('purchase::field.supp_inv_no')],
            'নম্বর না থাকা বিলের সারিতে "—" নেই।');
    }

    /** ⭐ ২,০০০ টাকার বিলে ৭০০ দেওয়া — পরিশোধিত ৭০০, বাকি ১,৩০০ */
    public function test_a_part_paid_bill_shows_what_was_paid_and_what_is_left(): void
    {
        $bill = app(PurchaseBillService::class)->confirm($this->bill('55', lines: 2));
        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — বিল নিশ্চিত হয়নি।');
        $this->assertSame(0, bccomp((string) $bill->fresh()->total, '2000', 4), 'দৃশ্যটাই বানানো যায়নি — বিলের মোট ২,০০০ নয়।');

        $payment = app(PaymentService::class)->create([
            'supplier_id' => $bill->supplier_id,
            'trx_date' => now()->toDateString(),
            'amount' => '700',
        ], [['purchase_bill_id' => $bill->id, 'amount' => '700']]);
        $this->putMoneyIn($payment->account, '700');
        $this->post(route('purchase.payment.confirm', $payment))->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp($bill->fresh()->paidAmount(), '700', 4), 'দৃশ্যটাই বানানো যায়নি — ৭০০ খাতায় বসেনি।');

        $row = $this->row((string) $this->get(route('purchase.bill.index'))->assertOk()->getContent(), $bill->document_no);

        $this->assertSame($this->text(Money::format('700')), $row[__('purchase::field.bill_paid')], 'পরিশোধিত ৭০০ দেখায় না।');
        $this->assertSame($this->text(Money::format('1300')), $row[__('purchase::field.bill_due')], 'বাকি ১,৩০০ দেখায় না।');
    }

    public function test_the_number_finds_its_bill_and_every_column_goes_into_the_export(): void
    {
        $numbered = $this->bill('10513');
        $other = $this->bill('777');

        $found = (string) $this->get(route('purchase.bill.index', ['q' => '10513']))->assertOk()->getContent();
        $this->assertStringContainsString($numbered->document_no, $found);
        $this->assertStringNotContainsString($other->document_no, $found, 'খোঁজে অন্য বিলও এসেছে।');

        $csv = (string) $this->get(route('purchase.bill.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('10513', $csv, 'রপ্তানির ফাইলে সরবরাহকারীর নম্বর নেই।');
        foreach (['supp_inv_no', 'warehouse', 'items', 'bill_paid', 'bill_due', 'created_by'] as $key) {
            $this->assertStringContainsString(__('purchase::field.'.$key), $csv, "রপ্তানির ফাইলে \"{$key}\" কলাম নেই।");
        }
    }

    /**
     * ⛔ নতুন কলামগুলো সারিপ্রতি কোয়েরি চালায় না — দুই বিলে আর ছয় বিলে একই সংখ্যক কোয়েরি।
     *
     * ⓘ `preventLazyLoading` কেবল local-এ ব্যতিক্রম ছোড়ে, পরীক্ষায় নয় — তাই গুনে দেখা।
     */
    public function test_more_bills_cost_no_more_queries(): void
    {
        $this->bill('1', lines: 2);
        $this->bill('2');

        // ⓘ প্রথম অনুরোধ ঠান্ডা — অনুমতি, সেটিং, মেনু প্রথমবার পড়ে; তুলনা কেবল গরম অনুরোধে
        $this->get(route('purchase.bill.index'))->assertOk();
        $few =$this->queriesFor(route('purchase.bill.index'));

        foreach (['3', '4', '5', '6'] as $no) {
            $this->bill($no, lines: 2);
        }
        $many = $this->queriesFor(route('purchase.bill.index'));

        $this->assertSame($few, $many, "দুই বিলে {$few}টা কোয়েরি, ছয় বিলে {$many}টা — কোনো কলাম সারিপ্রতি পড়ছে।");
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function bill(?string $supplierNo, int $lines = 1): PurchaseBill
    {
        $products = Product::query()
            ->whereNull('tax_id')
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->where('qc_required', false)
            ->orderBy('id')
            ->limit($lines)
            ->pluck('id');

        return app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => $supplierNo],
            $products->map(fn ($id) => ['product_id' => $id, 'qty' => '10', 'rate' => '100'])->all(),
        )->fresh(['warehouse', 'branch']);
    }

    /** @return list<string> */
    private function labels(array $keys): array
    {
        return array_map(fn (string $k) => (string) __('purchase::field.'.$k), $keys);
    }

    /** @return list<string> */
    private function headers(string $html): array
    {
        preg_match('/<thead\b.*?<\/thead>/su', $html, $head);
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/su', $head[0] ?? '', $cells);

        return array_map(fn (string $c) => $this->text($c), $cells[1]);
    }

    /** @return array<string, string> কলামের নাম → ঘরের লেখা */
    private function row(string $html, string $documentNo): array
    {
        preg_match('/<tbody\b.*?<\/tbody>/su', $html, $body);
        preg_match_all('/<tr\b.*?<\/tr>/su', $body[0] ?? '', $rows);

        foreach ($rows[0] as $tr) {
            if (! str_contains($tr, $documentNo)) {
                continue;
            }
            preg_match_all('/<td data-label="([^"]*)"[^>]*>(.*?)<\/td>/su', $tr, $cells, PREG_SET_ORDER);

            $out = [];
            foreach ($cells as [, $label, $cell]) {
                $out[$this->text($label)] = $this->text($cell);
            }

            return $out;
        }

        $this->fail("বিল {$documentNo} তালিকায় নেই।");
    }

    private function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
