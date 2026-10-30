<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\PaperStates;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দফা ১, ২, ৩, ৪, ৫, ১০, ১১, ১৩, ১৫, ১৭, ২০ — টাকা নেওয়ার পর্দা নকশার মতো আঁকা (মালিকের নকশা ১০০, পাতা ১; ২১ অক্টোবর ২০২৬; a6)।
 *
 * ⓘ নকশার প্রতিটা অংশ, নকশার ক্রমে আর নকশার বাংলায়: মাথা (নাম, অবস্থার চিপ, নম্বর · বানালেন · শাখা · মাস খোলা, তিন অঙ্ক),
 * সাধারণ তথ্য (পক্ষের ধরন, গ্রাহক, তারিখ, টাকা, যে খাতে জমা, পদ্ধতি আর তার ঘর), খোলা বিল ও ক্রেডিট (আট কলাম, মোট প্রয়োগ),
 * সংযুক্তি ও বিবরণ, হিসাবের সারাংশ, খাতায় যা যাবে, নিয়ন্ত্রণ, আর নিচের পট্টি (বাতিল · খসড়া রাখুন · পোস্ট)।
 * ⓘ ছবি মেলাতে: `ABOS_DUMP_SCREEN=1` দিলে পাতাটা storage/app/screens/collection-form.html-এ লেখা হয় (১৩৬৬px পাশাপাশি ছবির জন্য)।
 * ⓘ থিমের নিয়ম (ui-grid, num, টোকেন) গোটা ERP-র জন্য tests/Feature/Design মাপে; এখানে কেবল এই পর্দার নিজের কথা।
 */
final class TheCollectionScreenIsDrawnLikeTheDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Customer $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        Account::query()->create([
            'company_id' => $company->id, 'code' => '1102-SCR', 'name_en' => 'Screen bank', 'name_bn' => 'পর্দার ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->create(['code' => 'SCR-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর',
            'is_active' => true, 'branch_id' => CompanyContext::branchId()]);
        SalesInvoice::query()->create(['document_no' => 'INV-SCR-1', 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(), 'trx_date' => now()->subDays(20)->toDateString(),
            'due_on' => now()->subDays(8)->toDateString(), 'subtotal' => '30000', 'discount' => '0', 'tax' => '0', 'total' => '30000',
            'status' => DocumentStatus::CONFIRMED]);

        $this->maker = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id, 'name' => 'Al-Amin']);
        $this->maker->companies()->attach($company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $company->id, fn () => $this->maker->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'),
                ['sales.collection.create', 'sales.collection.view'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->maker = $this->maker->fresh();
    }

    /** দফা ১, ২, ৩, ৪, ৫, ১০, ১১, ১৫, ১৭ — প্রতিটা অংশ, নকশার ক্রমে, নকশার বাংলায় */
    public function test_items_1_to_17_every_part_of_the_design_is_drawn_in_its_order_and_words(): void
    {
        $html = $this->screen();
        $w = fn (string $key, array $with = []) => __('sales::collection_form.'.$key, $with);

        $this->assertStringContainsString(e($w('title')), $html, '⛔ দফা ১: পর্দার নাম "টাকা নেওয়া" নয়');
        $this->assertStringContainsString(e((string) (PaperStates::chip('draft')['label'] ?? '')), $html, '⛔ দফা ১: অবস্থার চিপ "খসড়া" নেই');

        $form = $this->part($html);

        $order = [
            // মাথা — দফা ১, ১৩
            $w('number'), $w('number_on_save'), $w('made_by'), 'Al-Amin', $w('branch'), $w('customers_branch'), $w('month_open'),
            $w('fig_due'), $w('fig_this'), $w('fig_left'),
            // সাধারণ তথ্য — দফা ১, ২
            $w('general'), $w('party_kind'), $w('kind_customer'), $w('kind_supplier'), $w('kind_employee'), $w('kind_other'),
            // ⓘ গ্রাহক দুই লাইনে (x-ui.party-search stacked): নাম, তার নিচে পয়েন্ট · শাখা — সার্ভারেই আঁকা, Alpine-এর আগেও
            $w('customer'), $this->dealer->fresh()->name(), (string) $this->dealer->fresh()->load(Customer::PLACE)->placeName(), $w('date'), $w('amount'), $w('full_due'), $w('chosen_bills'),
            // ⭐ পদ্ধতি আগে, তারপর "যে খাতে জমা" (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe; রসিদ-পরিশোধের একই নিয়ম) — খাতের তালিকা পদ্ধতির
            //   খাতে সীমিত, তাই নতুন কাগজে (নগদ) ব্যাংকের 1102-SCR আঁকা হয় না; পদ্ধতি বদলালে আসে ([[TheCollectionFormPostsWhatTheDesignSaysTest]])
            __('money.method.legend'),
            __('money.method.cash'), __('money.method.mfs'), __('money.method.transfer'), __('money.method.deposit'), __('money.method.cheque'),
            $w('into'),
            __('money.field.transfer_mode'), __('money.field.reference'), __('money.field.value_date'), __('money.field.bank_charge'),
            $w('reference_free'),
            // খোলা বিল ও ক্রেডিট — দফা ৩
            $w('open_title'), $w('open_hint'), $w('col_paper'), $w('col_date'), $w('col_due_date'), $w('col_late'),
            $w('col_amount'), $w('col_open'), $w('col_apply'), $w('total_apply'),
            // সংযুক্তি ও বিবরণ — দফা ১৫
            $w('attach_title'), $w('drop'), $w('narration'),
            // হিসাবের সারাংশ — দফা ৪, ৫
            $w('summary_title'), $w('summary.received'), $w('summary.vds'), $w('summary.vds_chip'), $w('summary.ait'),
            $w('summary.discount'), $w('summary.owner_chip'), $w('summary.rounding', ['limit' => '10']),
            $w('summary.applied'), $w('summary.unapplied'), $w('summary.balanced'),
            // খাতায় যা যাবে — দফা ১০
            $w('ledger_title'), $w('ledger_hint'), $w('col_account'), $w('col_branch'), $w('col_dr'), $w('col_cr'), $w('total'),
            // নিয়ন্ত্রণ — দফা ১১, ১২, ৮
            $w('controls_title'), $w('controls_off'), $w('controls_bounce'),
            // নিচের পট্টি — দফা ১৭
            $w('after_post'), __('core.action.cancel'), $w('draft'), $w('post'),
        ];

        $at = 0;
        foreach ($order as $text) {
            $found = mb_strpos($form, e($text), $at);
            $this->assertNotFalse($found, '⛔ নকশার অংশ "'.$text.'" পর্দায় নেই, বা আগের অংশের আগে বসেছে');
            $at = $found + mb_strlen(e($text));
        }

        // ⓘ নকশার লেখা হুবহু — রাউন্ডিংয়ের সীমা ইংরেজি অঙ্কে, আর দুই অবস্থার অন্যটা নেই
        $this->assertStringContainsString(e('রাউন্ডিং (5305, ৳10 পর্যন্ত)'), $form, '⛔ দফা ৪: রাউন্ডিংয়ের লেবেল নকশার মতো নয়');
        $this->assertStringContainsString(e('আদায়ে ছাড় (4120)'), $form, '⛔ দফা ৪: ছাড়ের লেবেলে ৪১২০ নেই');
        $this->assertStringNotContainsString(e($w('controls_on')), $form, '⛔ দফা ১১: সই বন্ধ কোম্পানিতে "সই চালু" লেখা');
    }

    /** দফা ১, ৩ — বকেয়ার অঙ্ক পর্দার হাতে সার্ভার থেকে যায়: খোলা বিলসহ গ্রাহকের বকেয়া, আর বিলটা নিজের বাইরের id-তে */
    public function test_items_1_and_3_the_screen_starts_from_the_servers_open_items(): void
    {
        $html = $this->screen();
        $bill = SalesInvoice::query()->where('document_no', 'INV-SCR-1')->firstOrFail();

        $this->assertStringContainsString('INV-SCR-1', $html, '⛔ দফা ৩: খোলা বিলটা পর্দার শুরুর তথ্যে নেই');
        $this->assertStringContainsString((string) $bill->public_id, $html, '⛔ দফা ৩: খোলা বিল বাইরের id (public_id) নিয়ে আসেনি');
        $this->assertStringContainsString('data-open-items-table', $html, '⛔ দফা ৩: খোলা বিলের টেবিল নেই');
        $this->assertMatchesRegularExpression('/<table class="ui-grid" data-open-items-table>/', $html, '⛔ দফা ৩: খোলা বিলের টেবিল থিমের ui-grid নয়');
        $this->assertMatchesRegularExpression('/<table class="ui-grid" data-ledger-table>/', $html, '⛔ দফা ১০: খাতার টেবিল থিমের ui-grid নয়');
    }

    /**
     * টাকা লাখ-কমায় আর দুই দশমিকে ("45,000.00", নকশার মতো) — দেখানোর ঘর আর পাঠানোর ঘর আলাদা (cb-র type="money", fe, ২৪ অক্টোবর
     * ২০২৬; আদায়, ভাউচার আর পরিশোধ একসাথে)। ⓘ পাঠানো হয় কেবল কাঁচা অঙ্ক — কমা কোনো পথেই সার্ভারে যায় না; ফাঁকা কাটার ঘরে "0.00"।
     */
    public function test_every_money_box_shows_lakh_commas_and_sends_only_the_plain_figure(): void
    {
        $form = $this->part($this->screen());

        $this->assertMatchesRegularExpression('/<input\b[^>]*id="cf-amount"[^>]*data-money-shown="amount"[^>]*x-money-display="amount"/s', $form,
            '⛔ টাকার ঘর লাখ-কমায় দেখায় না — "45000" লেখাই থাকে, নকশায় "45,000.00"');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="amount"[^>]*:value="amount"/', $form, '⛔ টাকার কাঁচা অঙ্কের ঘর নেই');
        $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*id="cf-amount"[^>]*\bname=/s', $form, '⛔ দেখানোর ঘরও নাম নিয়ে পাঠায় — কমাসহ লেখা সার্ভারে যেত');

        foreach (['vds', 'ait', 'discount', 'rounding'] as $key) {
            $this->assertMatchesRegularExpression('/<input\b[^>]*id="cf-'.$key.'"[^>]*x-money-display="'.$key.'"[^>]*data-money-empty="0\.00"/s', $form,
                "⛔ {$key}-এর ঘর লাখ-কমায় নয়, বা ফাঁকায় \"0.00\" নয়");
            $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*id="cf-'.$key.'"[^>]*\bname=/s', $form, "⛔ {$key}-এর দেখানোর ঘর নাম নিয়ে পাঠায়");
        }
        foreach (['vds', 'ait'] as $key) {
            $this->assertStringContainsString('<input type="hidden" name="'.$key.'" :value="'.$key.'">', $form, "⛔ {$key}-এর কাঁচা অঙ্ক পাঠানো হয় না");
        }

        $this->assertMatchesRegularExpression('/x-model="row\.apply"[^>]*@blur="tidyApply\(row\)"/', $form, '⛔ "এখন প্রয়োগ" ঘর ছাড়লে লাখ-কমায় বসে না');
        // ⓘ রেফারেন্স লিখে ঘর ছাড়লে প্রিভিউ নতুন — নইলে "রেফারেন্স লাগবে" থামা লেখার পরেও থেকে যেত (ছবি ১১)
        $this->assertMatchesRegularExpression('/<form\b[^>]*data-collection-form[^>]*@change="later\(\)"/s', $this->screen(),
            '⛔ ঘর ছাড়লে "খাতায় যা যাবে" নতুন হয় না — লেখা রেফারেন্সের পরেও পুরনো থামা দেখায়');
        $this->assertStringContainsString(':name="\'lines[\' + i + \'][amount]\'" :value="sent(row)"', $form, '⛔ সারির টাকা কাঁচা অঙ্কে পাঠানো হয় না');
    }

    /** দফা ২০ — কীবোর্ড আর পর্দা-পাঠক: প্রতিটা ঘরের নাম আছে, টিকের কলামের নাম পর্দা-পাঠকের জন্য; টাকা ইংরেজি অঙ্কে */
    public function test_item_20_every_field_has_a_name_for_a_screen_reader_and_figures_are_english_digits(): void
    {
        $form = $this->part($this->screen());

        foreach (['cf-vds', 'cf-ait', 'cf-discount', 'cf-rounding'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $form, "⛔ দফা ২০: {$id} ঘরের লেবেল নেই — পর্দা-পাঠক নাম পড়বে না");
            $this->assertStringContainsString('id="'.$id.'"', $form, "⛔ দফা ২০: {$id} ঘরটাই নেই");
        }

        $this->assertStringContainsString('<span class="sr-only">'.e(__('sales::collection_form.col_tick')).'</span>', $form,
            '⛔ দফা ২০: টিকের কলামের নাম পর্দা-পাঠকের জন্য নেই');
        $this->assertStringContainsString(':aria-label="row.no"', $form, '⛔ দফা ২০: সারির টিক আর অঙ্কের ঘরে কাগজের নম্বর নাম হিসেবে নেই');
        $this->assertSame(0, preg_match('/[০-৯]/u', strip_tags($form)), '⛔ দফা ২০: পর্দায় বাংলা অঙ্ক — টাকা আর সংখ্যা ইংরেজি অঙ্কে');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function screen(): string
    {
        $html = (string) $this->actingAs($this->maker)
            ->get(route('sales.collection.create', ['customer' => $this->dealer->id]))
            ->assertOk()->getContent();

        if (getenv('ABOS_DUMP_SCREEN') === '1') {
            @mkdir(storage_path('app/screens'), 0775, true);
            file_put_contents(storage_path('app/screens/collection-form.html'), $html);
        }

        return $html;
    }

    /** পর্দার ফর্মটুকু — খোলস (মেনু, মাথার বার) বাদ */
    private function part(string $html): string
    {
        $start = strpos($html, 'data-collection-form');
        $this->assertNotFalse($start, 'টাকা নেওয়ার ফর্মটাই পাওয়া যায়নি');
        $end = strpos($html, '</form>', (int) $start);

        return substr($html, (int) $start, ($end === false ? strlen($html) : $end) - (int) $start);
    }
}
