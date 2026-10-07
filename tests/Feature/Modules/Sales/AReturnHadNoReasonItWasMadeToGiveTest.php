<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Services\MasterListService;
use App\Modules\MasterData\Support\SalesReturnReasons;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Reports\SalesReturnReasonReports;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ফেরতের কারণ বসত কেবল কেউ কষ্ট করে বাছলে — NEXUS §২৪।
 *
 * ── ⛔ যা ছিল ─────────────────────────────────────────────────────────
 * `reason_code_id` ঐচ্ছিক, আর কাউন্টারের ফেরতে ঘরটাই ছিল না। ⚠️ ফলে
 * "কোন কারণে ফেরত আসে" প্রশ্নের উত্তরে বড় সারিটা হত "কারণ নেই"।
 *
 * ── ⭐ এই ফাইল যা পাহারা দেয় ─────────────────────────────────────────
 *   ১. কারণ ছাড়া ফেরত নেই — অফিসের পর্দা, সেবা, কাউন্টার, তিন পথেই
 *   ২. "অন্যান্য" নোট চায় — হেডারে, আর লাইনের নিজের কারণ হলে লাইনে
 *   ৩. অন্য কোম্পানির বা অন্য প্রসঙ্গের কারণ চলে না
 *   ৪. "মেয়াদোত্তীর্ণ" লট-ধরা পণ্যে লট চায়, আর লটটা ঐ পণ্যেরই
 *   ৫. রিপোর্টের যোগফল = খাতায় বসা ফেরতের যোগফল; লাইনের কারণ আগে
 *   ৬. রিপোর্টের দরজা নিজের চাবিতে — একই লোক, চাবি নেই → ৪০৩, আছে → ২০০
 *   ৭. অন্য কোম্পানির রিপোর্টে আমাদের ফেরত নেই; পর্দাগুলো সারিসহ খোলে
 *
 * ⚠️ প্রতিটা পাহারায় বিপজ্জনক ইনপুটটাই খাওয়ানো হয় — ফাঁকা-স্পেসের নোট,
 * অন্য পণ্যের লট, অন্য কোম্পানির সারি — ⛔ নাহলে পাহারাটা কোনোদিন
 * আসল কেসটা দেখত না।
 */
final class AReturnHadNoReasonItWasMadeToGiveTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    // ── ১ · কারণ ছাড়া ফেরত নেই ──────────────────────────────────────

    public function test_a_return_without_a_reason_is_refused_by_the_service(): void
    {
        $before = SalesReturn::query()->count();

        $this->assertSame('reason_code_id', $this->refusedOn(fn () => $this->draft([])),
            'কারণ ছাড়া ফেরত সেবা পার হয়ে গেছে।');

        $this->assertSame($before, SalesReturn::query()->count(),
            'থামানোর পরেও একটা ফেরতের সারি বসে গেছে।');
    }

    public function test_a_return_without_a_reason_is_refused_on_the_screen(): void
    {
        $before = SalesReturn::query()->count();

        $this->post(route('sales.return.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        ])->assertSessionHasErrors('reason_code_id');

        $this->assertSame($before, SalesReturn::query()->count());
    }

    public function test_the_counter_cannot_take_goods_back_without_a_reason(): void
    {
        /*
         * ⛔ কাউন্টারের পর্দায় ঘরটাই ছিল না — এই পথটা খোলা থাকলে নিয়মটা
         * অফিসের পর্দায় বসেও কিছুই করত না।
         */
        $invoice = $this->confirmedInvoice('5', '200');
        $before = SalesReturn::query()->count();

        $this->assertSame('reason_code_id', $this->refusedOn(fn () => app(PosService::class)->takeBack(
            ['document_no' => $invoice->document_no, 'warehouse_id' => $this->warehouse->id],
            [[
                'product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice->lines->first()->id,
                'qty' => '1',
            ]],
        )));

        $this->assertSame($before, SalesReturn::query()->count());
    }

    public function test_an_old_draft_without_a_reason_cannot_be_confirmed(): void
    {
        /*
         * ⚠️ নিয়মের আগের খসড়া — কারণ ছাড়াই পড়ে আছে। ⛔ create/update-এ
         * দেখলেই হয় না; নিশ্চিত করার দরজাতেও থামতে হবে।
         */
        $invoice = $this->confirmedInvoice('5', '200');
        $return = $this->draft(['reason_code_id' => $this->reason('DAMAGE')->id], $invoice);

        DB::table('sal_returns')
            ->where('company_id', $this->company->id)
            ->where('id', $return->id)
            ->update(['reason_code_id' => null]);

        $this->assertSame('reason_code_id',
            $this->refusedOn(fn () => app(SalesReturnService::class)->confirm($return->fresh())));

        $this->assertTrue($return->fresh()->isDraft(), 'কারণহীন খসড়াটা খাতায় বসে গেছে।');
    }

    // ── ২ · "অন্যান্য" নোট চায় ───────────────────────────────────────

    public function test_other_without_a_note_is_refused(): void
    {
        $other = $this->reason('OTHER');

        $this->assertTrue((bool) $other->needs_note,
            'ⓘ এই দাবির ভিত্তি: "অন্যান্য" নোট চায়। ⛔ না চাইলে নিচের সব কিছুই প্রমাণ করে না।');

        // ⚠️ ফাঁকা, কেবল স্পেস, আর দুই অক্ষর — তিনটাই "নোট দেওয়া হয়নি"
        foreach (['', '     ', ' x. '] as $note) {
            $this->assertSame('reason_note', $this->refusedOn(fn () => $this->draft([
                'reason_code_id' => $other->id,
                'reason_note' => $note,
            ])), "নোট '{$note}' দিয়ে \"অন্যান্য\" পার হয়ে গেছে।");
        }

        $return = $this->draft([
            'reason_code_id' => $other->id,
            'reason_note' => '  দোকান বন্ধ হয়ে গেছে  ',
        ]);

        $this->assertSame('দোকান বন্ধ হয়ে গেছে', $return->fresh()->reason_note,
            'নোটসহ ফেরতে নোটটা রাখা হয়নি।');
    }

    public function test_a_line_marked_other_needs_its_own_note(): void
    {
        /*
         * ⚠️ হেডারে "ক্ষতিগ্রস্ত" আর তার নোট আছে; লাইনে "অন্যান্য" — ⛔
         * হেডারের নোট ঐ লাইনের কিছুই বলে না, তাই পার হওয়া চলে না।
         */
        $this->assertSame('lines', $this->refusedOn(fn () => $this->draft(
            [
                'reason_code_id' => $this->reason('DAMAGE')->id,
                'reason_note' => 'বস্তা ফাটা',
            ],
            null,
            ['reason_code_id' => $this->reason('OTHER')->id, 'reason_note' => ' '],
        )));

        $return = $this->draft(
            ['reason_code_id' => $this->reason('DAMAGE')->id],
            null,
            ['reason_code_id' => $this->reason('OTHER')->id, 'reason_note' => 'গ্রাহক ভুল রঙ চেয়েছিলেন'],
        );

        $line = $return->fresh('lines')->lines->first();
        $this->assertSame($this->reason('OTHER')->id, (int) $line->reason_code_id);
        $this->assertSame('গ্রাহক ভুল রঙ চেয়েছিলেন', $line->reason_note);
    }

    // ── ৩ · অন্য কোম্পানির বা অন্য প্রসঙ্গের কারণ ─────────────────────

    public function test_a_reason_from_another_company_is_refused(): void
    {
        $foreign = $this->foreignReason();

        $this->assertNotSame($this->company->id, (int) $foreign->company_id);

        // সেবার দরজা
        $this->assertSame('reason_code_id', $this->refusedOn(fn () => $this->draft([
            'reason_code_id' => $foreign->id,
        ])));

        // লাইনের দরজা — ⚠️ হেডার ঠিক, কেবল লাইনে অন্যের সারি
        $this->assertSame('lines', $this->refusedOn(fn () => $this->draft(
            ['reason_code_id' => $this->reason('DAMAGE')->id],
            null,
            ['reason_code_id' => $foreign->id],
        )));

        // পর্দার দরজা
        $this->post(route('sales.return.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'reason_code_id' => $foreign->id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100']],
        ])->assertSessionHasErrors('reason_code_id');
    }

    public function test_a_reason_from_another_context_is_refused(): void
    {
        // "হারানো বা চুরি" — মজুদ সমন্বয়ের কারণ, ফেরতের নয়
        $lost = $this->reason('LOST', ReasonCode::STOCK_ADJUSTMENT);

        $this->assertSame('reason_code_id', $this->refusedOn(fn () => $this->draft([
            'reason_code_id' => $lost->id,
        ])));
    }

    // ── ৪ · মেয়াদোত্তীর্ণ লট চায় ─────────────────────────────────────

    public function test_expired_on_a_lot_tracked_product_needs_the_products_own_lot(): void
    {
        $expired = $this->reason('EXPIRED');

        $this->assertTrue((bool) $expired->needs_lot,
            'ⓘ এই দাবির ভিত্তি: "মেয়াদোত্তীর্ণ" লট চায়।');

        $this->product->forceFill(['track_batch' => true])->save();

        $other = Product::query()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();
        $own = $this->batch($this->product, 'LOT-OWN');
        $foreignLot = $this->batch($other, 'LOT-OTHER');

        // লট ছাড়া
        $this->assertSame('lines', $this->refusedOn(fn () => $this->draft(['reason_code_id' => $expired->id])));

        // ⚠️ অন্য পণ্যের লট — রিকলের সুতো ভুল পণ্যে বাঁধত
        $this->assertSame('lines', $this->refusedOn(fn () => $this->draft(
            ['reason_code_id' => $expired->id],
            null,
            ['batch_id' => $foreignLot->id],
        )));

        $return = $this->draft(['reason_code_id' => $expired->id], null, ['batch_id' => $own->id]);

        $this->assertSame($own->id, (int) $return->fresh('lines')->lines->first()->batch_id,
            'বাছা লটটা ফেরতের লাইনে বসেনি — সেবা ঘরটা হাতে বাছা তালিকায় ফেলে দিয়েছে।');
    }

    public function test_expired_on_a_plain_product_needs_no_lot(): void
    {
        // ⓘ ডিপোর চাল-ডাল-সাবানে লট নেই — এখানে লট চাইলে ফেরতই নেওয়া যেত না
        $this->product->forceFill(['track_batch' => false])->save();

        $return = $this->draft(['reason_code_id' => $this->reason('EXPIRED')->id]);

        $this->assertNull($return->fresh('lines')->lines->first()->batch_id);
    }

    // ── ৫ · রিপোর্ট = খাতা ────────────────────────────────────────────

    public function test_the_report_totals_equal_the_confirmed_returns(): void
    {
        $before = $this->reportByReason();

        $invoice = $this->confirmedInvoice('10', '200');
        $line = $invoice->lines->first()->id;
        $service = app(SalesReturnService::class);

        // ক — দুই বস্তা নষ্ট
        $a = $service->confirm($this->draft(['reason_code_id' => $this->reason('DAMAGE')->id], $invoice, ['qty' => '2']));

        /*
         * খ — হেডারে "ভুল পণ্য", কিন্তু দ্বিতীয় লাইনের নিজের কারণ
         * "অন্যান্য"। ⚠️ ঐ লাইনটা "ভুল পণ্য"-এ গোনা হলেই রিপোর্ট মিথ্যা।
         */
        $b = $service->confirm($service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_invoice_id' => $invoice->id,
                'reason_code_id' => $this->reason('WRONG')->id,
                'trx_date' => now()->toDateString(),
            ],
            [
                ['product_id' => $this->product->id, 'sales_invoice_line_id' => $line, 'qty' => '1'],
                ['product_id' => $this->product->id, 'sales_invoice_line_id' => $line, 'qty' => '3',
                    'reason_code_id' => $this->reason('OTHER')->id, 'reason_note' => 'দোকানের তাক ভরা'],
            ],
        ));

        // গ — খসড়া; ⛔ মাল ফেরেনি, রিপোর্টে আসা চলবে না
        $this->draft(['reason_code_id' => $this->reason('DAMAGE')->id], $invoice, ['qty' => '4']);

        $after = $this->reportByReason();

        $delta = fn (string $code, string $key) => bcsub(
            (string) ($after[$this->reason($code)->id][$key] ?? '0'),
            (string) ($before[$this->reason($code)->id][$key] ?? '0'),
            4,
        );

        $this->assertSame(0, bccomp('2', $delta('DAMAGE', 'qty'), 4), 'খসড়াটা রিপোর্টে ঢুকেছে, বা নিশ্চিতটা ঢোকেনি।');
        $this->assertSame(0, bccomp((string) $a->total, $delta('DAMAGE', 'total'), 4));
        $this->assertSame(0, bccomp('1', $delta('WRONG', 'qty'), 4), 'লাইনের কারণ উপেক্ষা হয়েছে।');
        $this->assertSame(0, bccomp('3', $delta('OTHER', 'qty'), 4), 'লাইনের কারণ উপেক্ষা হয়েছে।');
        $this->assertSame(0, bccomp('600', $delta('OTHER', 'value'), 4));
        $this->assertSame(0, bccomp((string) $b->total, bcadd($delta('WRONG', 'total'), $delta('OTHER', 'total'), 4), 4));

        // ⭐ মোট যোগফল = খাতায় বসা সব ফেরতের যোগফল, একই সময়সীমায়
        $result = $this->runReport();

        $posted = (string) SalesReturn::query()
            ->whereIn('status', DocumentStatus::POSTED)
            ->whereBetween('trx_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->sum('total');

        $this->assertSame(0, bccomp($posted, (string) $result->totals['total'], 4),
            'রিপোর্টের যোগফল আর নিশ্চিত ফেরতের যোগফল মেলে না — কোনো সারি হারিয়েছে বা দুইবার গোনা হয়েছে।');
    }

    public function test_the_report_filters_by_customer_and_product(): void
    {
        $invoice = $this->confirmedInvoice('10', '200');
        app(SalesReturnService::class)->confirm(
            $this->draft(['reason_code_id' => $this->reason('QUALITY')->id], $invoice, ['qty' => '2']),
        );

        $quality = $this->reason('QUALITY')->id;

        $mine = collect($this->runReport(['customer_id' => $this->customer->id, 'product_id' => $this->product->id])->rows)
            ->firstWhere('reason_code_id', $quality);
        $this->assertNotNull($mine, 'নিজের গ্রাহক ও পণ্যে ছেঁকে ফেরতটা পাওয়া গেল না।');

        $otherCustomer = Customer::query()->whereKeyNot($this->customer->id)->orderBy('id')->firstOrFail();
        $otherProduct = Product::query()->whereKeyNot($this->product->id)->orderBy('id')->firstOrFail();

        foreach ([['customer_id' => $otherCustomer->id], ['product_id' => $otherProduct->id]] as $filter) {
            $row = collect($this->runReport($filter)->rows)->firstWhere('reason_code_id', $quality);

            $this->assertNull($row, 'ছাঁকনি '.json_encode($filter).' কিছুই ছাঁকেনি।');
        }
    }

    public function test_the_report_runs_where_mysql_is_strict(): void
    {
        DB::statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ONLY_FULL_GROUP_BY')");

        $invoice = $this->confirmedInvoice('5', '200');
        app(SalesReturnService::class)->confirm(
            $this->draft(['reason_code_id' => $this->reason('DAMAGE')->id], $invoice, ['qty' => '1']),
        );

        $this->assertNotSame([], $this->runReport()->rows, 'লাইভের কড়া মোডে রিপোর্টটা চলেনি।');
    }

    // ── ৬ · রিপোর্টের দরজা ────────────────────────────────────────────

    public function test_the_report_door_needs_its_own_key_for_the_same_user(): void
    {
        // একটা নিশ্চিত ফেরত — ⚠️ খালি তালিকায় সারির কোড কোনোদিন চলত না
        $invoice = $this->confirmedInvoice('5', '200');
        app(SalesReturnService::class)->confirm(
            $this->draft(['reason_code_id' => $this->reason('QUALITY')->id], $invoice, ['qty' => '1']),
        );

        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        // ⚠️ বিক্রয়ের রিপোর্টের চাবি থাকলেও এই দরজা খোলে না
        $user->givePermissionTo('sales.report');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($user->fresh()->can(SalesReturnReasonReports::PERMISSION));

        $this->actingAs($user->fresh())->get($this->reportUrl())->assertForbidden();

        // ⭐ একই মানুষ, কেবল চাবিটা যোগ হলো
        $user->givePermissionTo(SalesReturnReasonReports::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())
            ->get($this->reportUrl())
            ->assertOk()
            ->assertSee(__('sales::return_reason.any_customer'))
            ->assertSee($this->reason('QUALITY')->name());

        // অচেনা স্লাগ — দরজা আছে, রিপোর্ট নেই
        $this->actingAs($user->fresh())
            ->get(route('sales.return.report.show', ['slug' => 'nothing-here']))
            ->assertNotFound();
    }

    public function test_the_report_definition_carries_the_same_key_the_door_uses(): void
    {
        /*
         * ⓘ সংজ্ঞায় চাবিটা লেখা অক্ষরে (গ্রুপ-বাইয়ের পাহারা যাতে দেখে) —
         * ⛔ ধ্রুবকটা থেকে সরে গেলে দরজা একটা রিপোর্ট চাইত আর নিবন্ধন আরেকটা।
         */
        $this->assertSame(SalesReturnReasonReports::KEY, SalesReturnReasonReports::byReason()->key);
        $this->assertSame(SalesReturnReasonReports::PERMISSION, SalesReturnReasonReports::byReason()->permission);
    }

    public function test_another_companys_report_never_counts_our_returns(): void
    {
        $invoice = $this->confirmedInvoice('10', '200');
        app(SalesReturnService::class)->confirm(
            $this->draft(['reason_code_id' => $this->reason('DAMAGE')->id], $invoice, ['qty' => '3']),
        );

        $ours = ReasonCode::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotEmpty(collect($this->runReport()->rows)->whereIn('reason_code_id', $ours)->all(),
            'ⓘ দাবির ভিত্তি: নিজের কোম্পানিতে সারিটা আছে।');

        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        [$rows, $total, $posted] = CompanyContext::forCompany($beta->id, function () {
            $result = $this->runReport();

            return [
                $result->rows,
                (string) ($result->totals['total'] ?? '0'),
                (string) SalesReturn::query()
                    ->whereIn('status', DocumentStatus::POSTED)
                    ->whereBetween('trx_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
                    ->sum('total'),
            ];
        });

        $this->assertSame([], collect($rows)->whereIn('reason_code_id', $ours)->all(),
            'অন্য কোম্পানির রিপোর্টে আমাদের কারণের সারি উঠেছে।');
        $this->assertSame(0, bccomp($posted, $total, 4),
            'অন্য কোম্পানির রিপোর্টের যোগফলে আমাদের ফেরত ঢুকেছে।');
    }

    // ── পর্দা — সারিসহ খোলে ─────────────────────────────────────────

    public function test_the_return_screens_show_the_reason_the_note_and_the_lot(): void
    {
        $this->product->forceFill(['track_batch' => true])->save();
        $lot = $this->batch($this->product, 'LOT-SHOWN');

        $return = $this->draft(
            ['reason_code_id' => $this->reason('OTHER')->id, 'reason_note' => 'দোকান উঠে গেছে'],
            null,
            ['reason_code_id' => $this->reason('EXPIRED')->id, 'batch_id' => $lot->id],
        );

        $this->get(route('sales.return.show', $return))
            ->assertOk()
            ->assertSee('দোকান উঠে গেছে')
            ->assertSee($this->reason('EXPIRED')->name())
            ->assertSee('LOT-SHOWN');

        // ফর্ম — লটের তালিকা পর্দায় যায় (CSP-Alpine সরল সারি পড়ে)
        $this->get(route('sales.return.edit', $return))
            ->assertOk()
            ->assertSee('LOT-SHOWN')
            ->assertSee('reason_note', false);

        $this->get(route('sales.return.create'))->assertOk();
    }

    // ── ৭ · চলমান কোম্পানিতে আটটা কারণ ────────────────────────────────

    public function test_the_eight_reasons_reach_a_running_company(): void
    {
        /*
         * ⭐ ভরা তালিকা থেকে একটা মুছে — লাইভের আসল অবস্থা। ⛔ খালি
         * তালিকায় `seed()` এমনিতেই কাজ করে, আসল ফাঁকটা দেখা হত না।
         */
        ReasonCode::query()->where('code', 'OTHER')->forceDelete();

        app(MasterListService::class)->installMissingReasons();

        $have = ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->pluck('code')->all();

        foreach (SalesReturnReasons::CODES as $code) {
            $this->assertContains($code, $have, "ফেরতের কারণ {$code} চলমান কোম্পানিতে পৌঁছায়নি।");
        }

        $this->assertTrue((bool) $this->reason('OTHER')->needs_note,
            'ফিরে আসা "অন্যান্য" নোট চায় না — নিয়মটা চুপচাপ হারিয়েছে।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────

    /**
     * একটা খসড়া ফেরত, এক লাইন।
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $line
     */
    private function draft(array $header, ?SalesInvoice $invoice = null, array $line = []): SalesReturn
    {
        return app(SalesReturnService::class)->create(
            array_merge([
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'sales_invoice_id' => $invoice?->id,
                'trx_date' => now()->toDateString(),
            ], $header),
            [array_merge([
                'product_id' => $this->product->id,
                'sales_invoice_line_id' => $invoice?->lines->first()->id,
                'qty' => '1',
                'rate' => '100',
            ], $line)],
        );
    }

    private function reason(string $code, string $context = ReasonCode::SALES_RETURN): ReasonCode
    {
        return ReasonCode::query()->inContext($context)->where('code', $code)->firstOrFail();
    }

    /** আরেক কোম্পানির একটা ফেরতের কারণ — ঐ কোম্পানির প্রসঙ্গেই খোঁজা বা বসানো। */
    private function foreignReason(): ReasonCode
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        return CompanyContext::forCompany($beta->id, function () use ($beta) {
            return ReasonCode::query()->inContext(ReasonCode::SALES_RETURN)->where('code', 'DAMAGE')->first()
                ?? ReasonCode::query()->forceCreate([
                    'company_id' => $beta->id,
                    'code' => 'DAMAGE',
                    'name_en' => 'Damaged goods',
                    'name_bn' => 'ক্ষতিগ্রস্ত পণ্য',
                    'context' => ReasonCode::SALES_RETURN,
                    'is_active' => true,
                ]);
        });
    }

    private function batch(Product $product, string $no): Batch
    {
        return Batch::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_no' => $no,
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
    }

    private function confirmedInvoice(string $qty, string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm(
            $service->create(
                [
                    'customer_id' => $this->customer->id,
                    'warehouse_id' => $this->warehouse->id,
                    'trx_date' => now()->toDateString(),
                ],
                [['product_id' => $this->product->id, 'qty' => $qty, 'rate' => $rate]],
            )
        )->load('lines');
    }

    private function reportUrl(): string
    {
        return route('sales.return.report.show', ['slug' => 'by-reason']);
    }

    /** @param array<string, mixed> $filters */
    private function runReport(array $filters = []): \App\Core\Engines\Report\ReportResult
    {
        return app(ReportEngine::class)->run(SalesReturnReasonReports::KEY, array_merge([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ], $filters), perPage: 1000);
    }

    /** @return array<int, array<string, mixed>> কারণের id ধরে সারি */
    private function reportByReason(): array
    {
        return collect($this->runReport()->rows)
            ->filter(fn (array $row) => $row['reason_code_id'] !== null)
            ->keyBy(fn (array $row) => (int) $row['reason_code_id'])
            ->all();
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — আর কোনো ব্যতিক্রম না হলে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('কাজটা থামার কথা ছিল, কিন্তু পার হয়ে গেছে।');
    }
}
