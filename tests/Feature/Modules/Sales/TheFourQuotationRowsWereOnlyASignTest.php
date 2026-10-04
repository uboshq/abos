<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Http\Controllers\PlannedScreenController;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\SalesQuotationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * উদ্ধৃতির চার সারি কেবল একটা সাইনবোর্ড ছিল — এখন আসল পাতা।
 *
 * ── ⛔ কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * বিক্রয় মেনুর "নতুন উদ্ধৃতি · উদ্ধৃতির তালিকা · উদ্ধৃতির তুলনা · উদ্ধৃতির সংশোধন" চারটাই
 * [[PlannedScreenController]]-এর *"এখনো তৈরি হচ্ছে"* পাতায় যেত — অথচ উদ্ধৃতির আসল কোড (NEXUS §৮) পাশেই ছিল।
 * তুলনা আর সংস্করণ কোথাও ছিল না: পাঠানো দর বদলালে আগের দরটা মুছে যেত।
 *
 * ── ⭐ মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬ (*"অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"*) ─────────────
 *   ⓵ চার সারি আসল রুটে, একই ক্রমে, উদ্ধৃতির নিজের চাবিতে; পুরনো ঠিকানা ঠিক পাতায় নামে (৩০২)
 *   ⓶ তালিকা — একই মানুষ, চাবি বন্ধ → ৪০৩, চালু → সারি দেখা যায়; ট্যাব: খসড়া · পাঠানো · গৃহীত · মেয়াদোত্তীর্ণ ·
 *      আদেশ হয়েছে · হারানো, প্রতিটা কেবল নিজের সারি
 *   ⓷ সংস্করণ — পাঠানোর পরে বদল মানে একই মূল নম্বরে `-R১, -R২`; পুরনোটা কেবল পড়ার জন্য; ইতিহাস দেখা যায়;
 *      পাঠানোর আগে বদল একই কাগজে
 *   ⓸ মেয়াদ — কোম্পানির সেটিং থেকে; পেরোলে আদেশ নয়, নতুন সংস্করণ ছাড়া
 *   ⓹ "আদেশে পরিণত করুন" — দুই দিকে সূত্র, দ্বিতীয়বার নয়
 *   ⓺ মজুদ, খাতা, বাকির সীমা — কিছুই ছোঁয় না
 *   ⓻ তুলনা — সারি ধরে, বদল/নেই/নতুন আলাদা করে
 *   ⓼ অন্য কোম্পানির উদ্ধৃতি — ৪০৪, তুলনায়ও
 */
final class TheFourQuotationRowsWereOnlyASignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $outsider;

    private Customer $customer;

    /** @var list<Product> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /* ⭐ ভূমিকাহীন — চাবি দাবির ভিতরে দেওয়া হয় ([[same-user-key-off-then-on]]) */
        $this->outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->outsider->companies()->attach($this->company->id, ['is_active' => true]);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->products = Product::query()->orderBy('id')->limit(3)->get()->all();

        $this->assertCount(3, $this->products, 'ⓘ দাবির ভিত্তি: ডেমোতে অন্তত তিনটা পণ্য।');

        $this->actingAs($this->owner);
    }

    // ── ⓵ মেনু আর পুরনো ঠিকানা ───────────────────────────────────────────

    /** ⭐ চার সারি আসল পাতায়, আগের ক্রমে (নতুন → তালিকা → তুলনা → সংস্করণ), আর আদেশের ভাঁজের আগে। */
    public function test_the_four_rows_open_the_real_pages_in_their_old_order(): void
    {
        $html = $this->get(route('sales.quotation.compare'))->assertOk()->getContent();

        $at = fn (string $url) => strpos($html, 'href="'.e($url).'"');

        $rows = [
            'new' => $at(route('sales.quotation.create')),
            'list' => $at(route('sales.quotation.index')),
            'compare' => $at(route('sales.quotation.compare')),
            'revision' => $at(route('sales.quotation.revisions')),
            'orders' => $at(route('sales.order.index')),
        ];

        foreach ($rows as $row => $position) {
            $this->assertNotFalse($position, "⛔ '{$row}' সারিটা মেনুতে নেই।");
        }

        $sorted = $rows;
        asort($sorted);
        $this->assertSame(array_keys($rows), array_keys($sorted), '⛔ উদ্ধৃতির সারির ক্রম বদলেছে: '.json_encode($rows));

        foreach (array_keys(PlannedScreenController::MOVED) as $screen) {
            $this->assertStringNotContainsString(e(route('sales.planned', ['screen' => $screen])), $html,
                "⛔ '{$screen}' এখনো \"তৈরি হচ্ছে\" পাতায় যায়।");
            $this->assertNotContains($screen, PlannedScreenController::SCREENS);
        }
    }

    /** ⭐ বুকমার্ক মরে না — পুরনো চার ঠিকানা আসল পাতায় নামে। */
    public function test_the_old_planned_addresses_land_on_the_real_pages(): void
    {
        $expected = [
            'quotation_new' => route('sales.quotation.create'),
            'quotation_list' => route('sales.quotation.index'),
            'quotation_compare' => route('sales.quotation.compare'),
            'quotation_revision' => route('sales.quotation.revisions'),
        ];

        foreach ($expected as $screen => $target) {
            $this->get(route('sales.planned', ['screen' => $screen]))->assertStatus(302)->assertRedirect($target);
        }
    }

    // ── ⓶ তালিকা: চাবি আর ট্যাব ─────────────────────────────────────────

    /** ⛔ দেখার চাবি ছাড়া তালিকা, তুলনা, সংস্করণ — ৪০৩; ⭐ একই মানুষ, চাবি চালু — খোলে আর সারি দেখায়। */
    public function test_the_list_compare_and_revision_pages_open_only_with_the_view_key(): void
    {
        $quotation = $this->sent();
        $revision = app(SalesQuotationService::class)->revise($quotation);

        $this->assertFalse($this->outsider->can('sales.quotation.view'),
            'ⓘ দাবির ভিত্তি: ভূমিকাহীন মানুষের চাবি নেই।');

        foreach (['sales.quotation.index', 'sales.quotation.compare', 'sales.quotation.revisions'] as $route) {
            $this->actingAs($this->outsider)->get(route($route))->assertForbidden();
        }

        $this->outsider->givePermissionTo('sales.quotation.view');
        $same = $this->outsider->fresh();

        $this->assertTrue($this->seesRow($this->actingAs($same)->get(route('sales.quotation.index'))->assertOk()->getContent(), $revision),
            '⛔ চাবি দেওয়ার পরও তালিকায় উদ্ধৃতিটা নেই।');
        $this->actingAs($same)->get(route('sales.quotation.compare'))->assertOk();
        $this->assertTrue($this->seesRow($this->actingAs($same)->get(route('sales.quotation.revisions'))->assertOk()->getContent(), $revision),
            '⛔ সংস্করণের পাতায় চালু সংস্করণটা নেই।');
    }

    /**
     * ⭐ প্রতিটা ট্যাব কেবল নিজের সারি দেখায়।
     *
     * ⚠️ বিপজ্জনক ইনপুট: মেয়াদ পেরোনো অথচ সারিতে এখনো "পাঠানো" — সেটা "পাঠানো"-তে নয়, কেবল "মেয়াদোত্তীর্ণ"-তে;
     * আর পুরনো সংস্করণ কোনো ট্যাবেই নয়, "সব"-এও নয়।
     */
    public function test_each_tab_shows_only_its_own_quotations(): void
    {
        $service = app(SalesQuotationService::class);

        $draft = $this->draft();
        $sent = $this->sent();
        $accepted = $this->accepted();
        $expiring = $this->sent(validUntil: now()->toDateString());
        $converted = $this->accepted();
        $service->convert($converted);
        $rejected = $service->reject($this->sent(), 'দর বেশি');
        $cancelled = $service->cancel($this->draft(), 'ডিলার আর চান না');
        $old = $this->sent();
        $current = $service->revise($old);

        $this->travel(1)->days();

        $expect = [
            'draft' => [$draft, $current],
            'sent' => [$sent],
            'accepted' => [$accepted],
            'expired' => [$expiring],
            'converted' => [$converted],
            'lost' => [$rejected, $cancelled],
            'all' => [$draft, $current, $sent, $accepted, $expiring, $converted, $rejected, $cancelled],
        ];

        $everyone = [$draft, $sent, $accepted, $expiring, $converted, $rejected, $cancelled, $old, $current];

        foreach ($expect as $tab => $rows) {
            $html = $this->get(route('sales.quotation.index', $tab === 'all' ? [] : ['tab' => $tab]))->assertOk()->getContent();

            foreach ($everyone as $quotation) {
                $wanted = in_array($quotation->id, array_map(fn ($q) => $q->id, $rows), true);

                $this->assertSame($wanted, $this->seesRow($html, $quotation),
                    "⛔ '{$tab}' ট্যাবে {$quotation->document_no} ".($wanted ? 'নেই' : 'আছে, অথচ থাকার কথা নয়').'।');
            }
        }
    }

    // ── ⓷ সংস্করণ ───────────────────────────────────────────────────────

    /**
     * ⭐ পাঠানো উদ্ধৃতির বদল — একই মূল নম্বরে `-R1`, তারপর `-R2`; সারি আর মোট হুবহু; পুরনোটা কেবল পড়ার জন্য।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পুরনো সংস্করণের **বাসি বস্তু** দিয়ে আবার বদল — তালা আর অবস্থা না দেখলে তৃতীয় একটা
     * সংস্করণ জন্মাত।
     */
    public function test_a_sent_quotation_revised_keeps_its_base_number_and_the_old_one_reads_only(): void
    {
        $service = app(SalesQuotationService::class);
        $original = $this->sent(lines: [$this->line(0, '3'), $this->line(1, '2')], header: '10');
        $stale = SalesQuotation::query()->findOrFail($original->id);

        $this->post(route('sales.quotation.revise', $original))->assertSessionHasNoErrors()->assertRedirect();

        $r1 = SalesQuotation::query()->where('revised_from_id', $original->id)->firstOrFail();

        $this->assertSame($original->document_no.'-R1', $r1->document_no, '⛔ নতুন সংস্করণ মূল নম্বর ধরে রাখেনি।');
        $this->assertSame(1, $r1->revision_no);
        $this->assertSame($original->id, (int) $r1->root_quotation_id);
        $this->assertSame(SalesQuotation::DRAFT, $r1->status);
        $this->assertCount(2, $r1->lines);
        foreach (['subtotal', 'discount', 'header_discount', 'tax', 'total'] as $field) {
            $this->assertSame(0, bccomp((string) $original->{$field}, (string) $r1->{$field}, 4), "⛔ নতুন সংস্করণের {$field} আগেরটার সাথে মেলেনি।");
        }

        $fresh = $original->fresh();
        $this->assertSame(SalesQuotation::REVISED, $fresh->status, '⛔ পুরনো সংস্করণ এখনো চালু।');
        $this->assertSame($this->owner->id, (int) $fresh->superseded_by);

        /* ⛔ পুরনোটা কেবল পড়ার জন্য — সম্পাদনা ৪০৩, প্রতিটা ধাপ থামে */
        $this->put(route('sales.quotation.update', $original), $this->payload())->assertForbidden();
        foreach ([
            fn () => $service->submit($original->fresh()),
            fn () => $service->markSent($original->fresh()),
            fn () => $service->accept($original->fresh()),
            fn () => $service->convert($original->fresh()),
            fn () => $service->cancel($original->fresh(), 'x'),
            fn () => $service->revise($stale),
        ] as $step) {
            $this->assertSame('status', $this->refusedOn($step));
        }
        $this->assertSame(SalesQuotation::REVISED, $original->fresh()->status);
        $this->assertSame(2, $original->fresh()->family()->count(), '⛔ বাসি বস্তু দিয়ে আরেকটা সংস্করণ জন্মেছে।');

        /* ⭐ দ্বিতীয় বদল — মূল একই, নম্বর -R2 */
        $r2 = $service->revise($service->markSent($service->submit($r1->fresh())));
        $this->assertSame($original->document_no.'-R2', $r2->document_no);
        $this->assertSame($original->id, (int) $r2->root_quotation_id, '⛔ দ্বিতীয় সংস্করণ মূলের বদলে আগের সংস্করণকে মূল ধরেছে।');

        /* ⭐ ইতিহাস দেখা যায় — পুরনোর পাতায় চালুটার দিকে আঙুল, চালুর পাতায় তিনটা সংস্করণ */
        $oldPage = $this->get(route('sales.quotation.show', $original))->assertOk()->getContent();
        $this->assertStringContainsString('data-superseded', $oldPage);
        $this->assertTrue($this->seesRow($oldPage, $r2), '⛔ পুরনো সংস্করণের পাতা চালুটা দেখায় না।');
        $this->assertStringNotContainsString(e(route('sales.quotation.edit', $original)), $oldPage);

        $newPage = $this->get(route('sales.quotation.show', $r2))->assertOk()->getContent();
        $this->assertSame(3, substr_count($newPage, 'data-revision="'), '⛔ ইতিহাসে তিনটা সংস্করণ নেই।');

        $families = $this->get(route('sales.quotation.revisions'))->assertOk()->getContent();
        $this->assertTrue($this->seesRow($families, $original) && $this->seesRow($families, $r2));
    }

    /** ⓘ ডিলার দেখার আগে (অনুমোদিত, পাঠানো হয়নি) বদল একই কাগজে — নতুন নম্বর জন্মায় না। */
    public function test_before_the_dealer_sees_it_a_change_stays_on_the_same_paper(): void
    {
        $approved = app(SalesQuotationService::class)->submit($this->draft());
        $before = SalesQuotation::query()->withTrashed()->count();

        $this->post(route('sales.quotation.revise', $approved))->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.quotation.show', $approved));

        $this->assertSame(SalesQuotation::DRAFT, $approved->fresh()->status);
        $this->assertSame($before, SalesQuotation::query()->withTrashed()->count());
    }

    // ── ⓸ মেয়াদ ────────────────────────────────────────────────────────

    /** ⭐ মেয়াদ কোম্পানির সেটিং থেকে — ফর্মে, সেবায়, আর নতুন সংস্করণে। */
    public function test_the_validity_comes_from_the_company_setting(): void
    {
        app(SettingsService::class)->set(SalesQuotationService::VALID_DAYS_SETTING, 7);
        $week = now()->addDays(7)->toDateString();

        // ⓘ তারিখের ঘর [[x-ui.date]] — মানটা `abosDate("…")`-এ বসে, `value=`-এ নয়
        $this->get(route('sales.quotation.create'))->assertOk()->assertSee('abosDate(&quot;'.$week.'&quot;', false);

        $quotation = app(SalesQuotationService::class)->create(
            ['customer_id' => $this->customer->id, 'trx_date' => now()->toDateString()],
            [$this->line(0, '1')],
        );

        $this->assertSame($week, $quotation->valid_until->toDateString(), '⛔ মেয়াদ সেটিং মানেনি।');
    }

    /**
     * ⛔ মেয়াদ পেরোনো গৃহীত উদ্ধৃতি আদেশ হয় না; ⭐ নতুন সংস্করণ — নতুন মেয়াদ — আবার রাজি — তবেই আদেশ।
     *
     * ⓘ বোতামও তাই বলে: মেয়াদ পেরোনো পাতায় "আদেশে পরিণত করুন" নেই, নতুন সংস্করণের গৃহীত পাতায় আছে।
     */
    public function test_an_expired_quotation_becomes_an_order_only_through_a_new_revision(): void
    {
        app(SettingsService::class)->set(SalesQuotationService::VALID_DAYS_SETTING, 7);
        $service = app(SalesQuotationService::class);

        $expired = $this->accepted(validUntil: now()->toDateString());
        $this->travel(1)->days();

        $this->assertSame('status', $this->refusedOn(fn () => $service->convert($expired->fresh())));
        $this->get(route('sales.quotation.show', $expired))->assertOk()
            ->assertDontSee(e(route('sales.quotation.convert', $expired)), false);

        $r1 = $service->revise($expired->fresh());
        $this->assertSame(now()->addDays(7)->toDateString(), $r1->valid_until->toDateString(),
            '⛔ নতুন সংস্করণ নতুন মেয়াদ পায়নি।');

        $r1 = $service->accept($service->markSent($service->submit($r1)));

        $this->get(route('sales.quotation.show', $r1))->assertOk()
            ->assertSee(e(route('sales.quotation.convert', $r1)), false)
            ->assertSee(__('sales::quotation.action.convert'));

        $this->post(route('sales.quotation.convert', $r1))->assertSessionHasNoErrors()->assertRedirect();

        $order = SalesOrder::query()->where('sales_quotation_id', $r1->id)->firstOrFail();
        $this->assertSame($order->id, (int) $r1->fresh()->sales_order_id, '⛔ উদ্ধৃতি জানে না কোন আদেশ হয়েছে।');
        $this->assertSame(SalesQuotation::CONVERTED, $r1->fresh()->status);
        $this->assertNull($expired->fresh()->sales_order_id, '⛔ পুরনো সংস্করণেও আদেশ বসেছে।');

        /* ⛔ দ্বিতীয়বার নয় — দরজা দিয়েও */
        $this->post(route('sales.quotation.convert', $r1))->assertSessionHasErrors('status');
        $this->assertSame(1, SalesOrder::query()->where('sales_quotation_id', $r1->id)->count());

        /* ⭐ দুই দিকে সূত্র — আদেশের পাতা উদ্ধৃতির নম্বর দেখায় */
        $this->get(route('sales.order.show', $order))->assertOk()->assertSee($r1->document_no);
    }

    // ── ⓺ মজুদ, খাতা, সীমা ──────────────────────────────────────────────

    /**
     * ⭐ উদ্ধৃতি মজুদ, খাতা বা বাকির সীমা — কিছুই ছোঁয় না: লেখা, জমা, পাঠানো, বদল (নতুন সংস্করণ), রাজি।
     *
     * ⚠️ বিপজ্জনক ইনপুট: সীমা **এক টাকা** — আর দেয়ালটা সত্যিই এই ডিলারকে আটকায়, সেটা আগে মাপা হয়; নাহলে
     * "সীমা ছোঁয়নি" কিছুই প্রমাণ করত না।
     */
    public function test_a_quotation_never_touches_stock_the_books_or_the_credit_limit(): void
    {
        $this->customer->forceFill(['credit_limit' => '1'])->save();
        $this->assertFalse(app(CreditExposure::class)->check($this->customer->fresh(), '500')['fits'],
            'ⓘ দাবির ভিত্তি: দেয়ালটা এই ডিলারকে সত্যিই আটকায়।');

        $count = fn () => [
            'stock' => DB::table((new StockMovement)->getTable())->count(),
            'ledger' => DB::table((new LedgerEntry)->getTable())->count(),
            'vouchers' => DB::table((new Voucher)->getTable())->count(),
        ];
        $before = $count();

        $this->post(route('sales.quotation.store'), $this->payload(['lines' => [$this->line(0, '500')]]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $quotation = SalesQuotation::query()->latest('id')->firstOrFail();

        $this->post(route('sales.quotation.submit', $quotation))->assertSessionHasNoErrors();
        $this->post(route('sales.quotation.send', $quotation))->assertSessionHasNoErrors();
        $this->post(route('sales.quotation.revise', $quotation))->assertSessionHasNoErrors();

        $revision = SalesQuotation::query()->where('revised_from_id', $quotation->id)->firstOrFail();
        $this->post(route('sales.quotation.submit', $revision))->assertSessionHasNoErrors();
        $this->post(route('sales.quotation.send', $revision))->assertSessionHasNoErrors();
        $this->post(route('sales.quotation.accept', $revision))->assertSessionHasNoErrors();

        $this->assertSame(SalesQuotation::ACCEPTED, $revision->fresh()->status, '⛔ বাকির সীমা উদ্ধৃতি থামিয়েছে।');
        $this->assertSame($before, $count(), '⛔ উদ্ধৃতি মজুদ বা খাতা ছুঁয়েছে: '.json_encode([$before, $count()]));
    }

    // ── ⓻ তুলনা ─────────────────────────────────────────────────────────

    /**
     * ⭐ সারি ধরে তুলনা — বদলানো পরিমাণ হলুদ, বাদ পড়া সারি "নেই", নতুন সারি "নতুন"।
     *
     * ⚠️ বিপজ্জনক ইনপুট: মাঝের সারিটা বাদ — সারির নম্বর ধরে মেলালে নিচের সারি এক ঘর সরে "সব বদলেছে" বলত।
     */
    public function test_compare_lines_up_the_lines_and_marks_what_changed(): void
    {
        $service = app(SalesQuotationService::class);
        $original = $this->sent(lines: [$this->line(0, '3'), $this->line(1, '2'), $this->line(2, '4')]);
        $r1 = $service->revise($original);
        $service->update($r1, [
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ], [$this->line(0, '5'), $this->line(2, '4')]);

        foreach ([
            route('sales.quotation.compare', ['family' => $r1->id]),
            route('sales.quotation.compare', ['ids' => [$original->id, $r1->id]]),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-comparison', $html);
            $this->assertSame(1, substr_count($html, 'data-changed="qty"'), '⛔ বদলানো পরিমাণ একবার চিহ্নিত হয়নি।');
            $this->assertSame(1, substr_count($html, '<td data-missing'), '⛔ বাদ পড়া সারি "নেই" বলেনি।');
            $this->assertSame(0, substr_count($html, 'data-added title='), '⛔ সারি সরে গিয়ে "নতুন" বলেছে।');
            $this->assertSame(3, substr_count($html, '<tr data-line>'), '⛔ তিন পণ্যের তিন সারি নয়।');
        }

        /* ⭐ নতুন পণ্য যোগ হলে "নতুন" */
        $r2 = $service->revise($service->markSent($service->submit($r1->fresh())));
        $service->update($r2, [
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ], [$this->line(1, '2')]);

        $html = $this->get(route('sales.quotation.compare', ['ids' => [$r1->id, $r2->id]]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-added title='), '⛔ ভিত্তিতে না থাকা সারি "নতুন" বলেনি।');
    }

    // ── ⓼ অন্য কোম্পানি ────────────────────────────────────────────────

    /** ⛔ অন্য কোম্পানি থেকে — একই মানুষ — উদ্ধৃতিটা তুলনায় বা বদলে ঢোকে না: ৪০৪। */
    public function test_another_companys_quotation_is_not_found_anywhere(): void
    {
        $a = $this->sent();
        $b = $this->sent();

        $this->get(route('sales.quotation.compare', ['ids' => [$a->id, $b->id]]))->assertOk();

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner->switchCompany($mart->id);
        $there = $this->owner->fresh();

        $this->actingAs($there)->get(route('sales.quotation.compare'))->assertOk();
        $this->actingAs($there)->get(route('sales.quotation.show', $a))->assertNotFound();
        $this->actingAs($there)->get(route('sales.quotation.compare', ['ids' => [$a->id, $b->id]]))->assertNotFound();
        $this->actingAs($there)->get(route('sales.quotation.compare', ['family' => $a->id]))->assertNotFound();
        $this->actingAs($there)->post(route('sales.quotation.revise', $a))->assertNotFound();
        $this->assertFalse($this->seesRow($this->actingAs($there)->get(route('sales.quotation.index'))->assertOk()->getContent(), $a));

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->assertSame(SalesQuotation::SENT, $a->fresh()->status, '⛔ অন্য কোম্পানি থেকে উদ্ধৃতিটা বদলে গেছে।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /** সারিটা পাতায় আছে কি না — নিজের পাতার পুরো লিংক ধরে (নম্বর ধরে নয়: `QTN-1` নিজেই `QTN-1-R1`-এর শুরু)। */
    private function seesRow(string $html, SalesQuotation $quotation): bool
    {
        return str_contains($html, 'href="'.e(route('sales.quotation.show', $quotation)).'"');
    }

    /** @return array<string, string|int> */
    private function line(int $product, string $qty): array
    {
        $p = $this->products[$product];
        $rate = bccomp((string) $p->sale_price, '0', 4) > 0 ? bcadd((string) $p->sale_price, '0', 4) : '100.0000';

        return ['product_id' => $p->id, 'qty' => $qty, 'rate' => $rate];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [$this->line(0, '3')],
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>|null  $lines */
    private function draft(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        return app(SalesQuotationService::class)->create([
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'valid_until' => $validUntil ?? now()->addDays(10)->toDateString(),
            'header_discount' => $header,
        ], $lines ?? [$this->line(0, '3')]);
    }

    /** @param  list<array<string, mixed>>|null  $lines */
    private function sent(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        $service = app(SalesQuotationService::class);

        // ⓘ ছক নেই, তাই জমা দিলেই অনুমোদিত
        return $service->markSent($service->submit($this->draft($lines, $header, $validUntil)));
    }

    /** @param  list<array<string, mixed>>|null  $lines */
    private function accepted(?array $lines = null, string $header = '0', ?string $validUntil = null): SalesQuotation
    {
        return app(SalesQuotationService::class)->accept($this->sent($lines, $header, $validUntil));
    }

    /** ব্যর্থ হলে কোন ঘরের বার্তা — আর কোনো ব্যতিক্রম না হলে দাবিটা ব্যর্থ। */
    private function refusedOn(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কাজটা আটকানোর কথা ছিল, অথচ পার হয়ে গেছে।');
    }
}
