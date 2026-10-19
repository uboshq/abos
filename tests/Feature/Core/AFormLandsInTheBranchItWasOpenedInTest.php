<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Services\FormBranchPin;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesQuotation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এক ট্যাবে খোলা ফর্ম আরেক ট্যাবের শাখায় বসত — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয় (ec, ১৯ অক্টোবর ২০২৬)।
 *
 * ── কী ভেঙেছিল ───────────────────────────────────────────────────────
 * উপরের শাখা-বাছাই একজন মানুষের সব ট্যাবে একটাই। ময়মনসিংহে ভাউচারের ফর্ম খুলে আরেক ট্যাবে নেত্রকোনা বাছলে, প্রথম
 * ট্যাবের Save কাগজটা নেত্রকোনায় বসাত — নম্বরও নেত্রকোনার সিরিজ থেকে। কেউ কিছু টের পেত না।
 *
 * ── ⭐ এখন ────────────────────────────────────────────────────────────
 * `@csrf` ফর্মে খোলার শাখার ছাপ (`_branch`) বসায়; জমার সময় না মিললে কিছু না লিখে ফর্মে ফেরত, ইনপুটসহ, দুই শাখার নামে
 * বার্তা আর "ময়মনসিংহ-এই জমা দিন" বোতাম। বোতামটা সই-করা, একবারের, এই দরজার — নাগাল না থাকলে চলে না।
 *
 * ⓘ সব দাবি আসল দরজায়: পাতা খোলা (GET), উপরের শাখা বদল (`branch.switch`), জমা (POST), আর বোতামটা পাতার HTML থেকেই
 * তুলে চাপা — দুই ট্যাব, এক সেশন।
 */
final class AFormLandsInTheBranchItWasOpenedInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $opened;

    private Branch $other;

    private User $clerk;

    private Account $debit;

    private Account $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->opened = $this->branch('MMS');
        $this->other = $this->branch('NTK');
        CompanyContext::set($this->company->id, $this->opened->id);
        app(StandardChart::class)->install();

        // ⓘ হিসাবরক্ষকের নাগাল দুই শাখায় — নাগাল হারানোর দাবিটা যাতে একটা সারি মুছেই মাপা যায়
        $this->clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        foreach ([$this->opened, $this->other] as $branch) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $this->clerk->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $branch->id]);
        }

        // ⓘ ময়মনসিংহের নিজের জাবেদা-সিরিজ — নম্বরটা কোন শাখার, সেটা উপসর্গেই পড়া যায়
        NumberSeries::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->opened->id,
            'financial_year_id' => FinancialYear::forDate(now())?->id, 'module' => 'accounts', 'doc_type' => 'JV',
            'prefix' => 'JVMMS', 'format' => '{PREFIX}-{SEQ}', 'padding' => 4, 'next_number' => 1, 'start_number' => 1, 'is_active' => true]);

        [$this->debit, $this->credit] = Account::query()->postable()->active()->whereNull('money_kind')
            ->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        // ⛔ কড়া — ছাপহীন জমার ছাড় বন্ধ (TestCase-এর খোলা ছাড় এখানে খাটে না)
        config(['abos.branch_pin.grace_until' => now()->subMinute()->toIso8601String()]);
    }

    public function test_a_voucher_opened_in_one_branch_is_stopped_in_another_and_lands_in_its_own_on_the_button(): void
    {
        $this->as($this->clerk, $this->opened->id);
        $stamp = $this->stampOn($this->voucherForm());
        $this->assertSame((string) $this->opened->public_id, $stamp, '⛔ ফর্মে খোলার শাখার ছাপ নেই।');

        // ⓘ দ্বিতীয় ট্যাব — উপরের শাখা নেত্রকোনা
        $this->as($this->clerk, $this->other->id);

        $this->from($this->voucherForm())
            ->post(route('accounts.voucher.store', 'journal'), [...$this->journal('দুই ট্যাব'), FormBranchPin::FIELD => $stamp])
            ->assertRedirect($this->voucherForm())
            ->assertSessionHasInput('narration', 'দুই ট্যাব');

        $this->assertSame(0, $this->vouchers('দুই ট্যাব')->count(), '⛔ শাখা না মিলেও ভাউচার লেখা হলো।');
        $this->assertSame(
            __('core.branch_pin.moved', ['opened' => $this->opened->name(), 'current' => $this->other->name()]),
            session(FormBranchPin::OFFER)['message'] ?? null,
            '⛔ বার্তায় দুই শাখার নাম নেই।',
        );

        [$action, $data] = $this->button($this->voucherForm());
        $this->from($this->voucherForm())->post($action, $data)->assertRedirect();

        $voucher = $this->vouchers('দুই ট্যাব')->sole();
        $this->assertSame((int) $this->opened->id, (int) $voucher->branch_id, '⛔ বোতাম চেপেও কাগজ খোলার শাখায় বসেনি।');
        $this->assertStringStartsWith('JVMMS-', (string) $voucher->document_no, '⛔ নম্বর খোলার শাখার সিরিজ থেকে আসেনি।');
        $this->assertSame(__('core.branch_pin.saved_elsewhere', ['opened' => $this->opened->name()]), session(FormBranchPin::NOTICE));
    }

    public function test_a_sales_quotation_opened_in_one_branch_lands_there_too(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $customer->forceFill(['branch_id' => $this->opened->id])->saveQuietly();
        // ⓘ সব শাখার পণ্য — কোনো শাখায় বাঁধা নয়, তাই দুই শাখাতেই দেখা যায়
        $product = Product::query()->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from('inv_product_branches')->whereColumn('inv_product_branches.product_id', 'inv_products.id'))
            ->orderBy('id')->firstOrFail();
        // ⓘ পণ্যের নিজের দামেই — দামের নীতি দাবিটা ভুল কারণে থামাবে না ([[TheDealerWasQuotedAPriceAndItBecameTheOrderTest]]-এর একই কৌশল)
        $rate = bccomp((string) $product->sale_price, '0', 4) > 0 ? bcadd((string) $product->sale_price, '0', 4) : '100.0000';
        $form = route('sales.quotation.create');
        $payload = ['customer_id' => $customer->id, 'trx_date' => now()->toDateString(), 'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [['product_id' => $product->id, 'qty' => '2', 'rate' => $rate]]];

        $this->as($owner, $this->opened->id);
        $stamp = $this->stampOn($form);
        $before = SalesQuotation::query()->withoutGlobalScopes()->count();

        $this->as($owner, $this->other->id);
        $this->from($form)->post(route('sales.quotation.store'), [...$payload, FormBranchPin::FIELD => $stamp])->assertRedirect($form);
        $this->assertSame($before, SalesQuotation::query()->withoutGlobalScopes()->count(), '⛔ শাখা না মিলেও উদ্ধৃতি লেখা হলো।');

        [$action, $data] = $this->button($form);
        $this->from($form)->post($action, $data)->assertRedirect();

        $quotation = SalesQuotation::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($before + 1, SalesQuotation::query()->withoutGlobalScopes()->count());
        $this->assertSame((int) $this->opened->id, (int) $quotation->branch_id, '⛔ উদ্ধৃতি খোলার শাখায় বসেনি।');
    }

    public function test_the_button_is_refused_when_the_opened_branch_is_no_longer_in_reach(): void
    {
        $stamp = $this->openThenSwitch();
        $this->refusedPost($stamp, 'নাগাল নেই');

        UserDataScope::query()->where('user_id', $this->clerk->id)->where('scope_id', $this->opened->id)->delete();
        $this->forgetScope();

        [$action, $data] = $this->button($this->voucherForm());
        $this->from($this->voucherForm())->post($action, $data)->assertRedirect($this->voucherForm());

        $this->assertSame(0, $this->vouchers('নাগাল নেই')->count(), '⛔ নাগালের বাইরের শাখায় বোতাম চেপে লেখা গেল।');
        $this->assertSame(__('core.branch_pin.no_reach', ['opened' => $this->opened->name()]), session(FormBranchPin::OFFER)['message'] ?? null);
    }

    public function test_a_forged_or_reused_button_is_refused(): void
    {
        $stamp = $this->openThenSwitch();
        $this->refusedPost($stamp, 'জাল বোতাম');
        [$action, $data] = $this->button($this->voucherForm());

        // ⛔ জাল চিরকুট — অন্য শাখার নাম বসানোর চেষ্টা খুলতেই পারে না
        $forged = [...$data, FormBranchPin::CONFIRM => 'eyJpdiI6ImZvcmdlZCJ9', '_once' => null];
        $this->from($this->voucherForm())->post($action, $forged)->assertRedirect($this->voucherForm());
        $this->assertSame(0, $this->vouchers('জাল বোতাম')->count(), '⛔ জাল চিরকুটে লেখা হলো।');
        $this->assertSame(__('core.branch_pin.confirm_refused'), session(FormBranchPin::OFFER)['message'] ?? null);

        // ⛔ অন্য দরজায় একই চিরকুট
        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'payment'), [...$data, '_once' => null])->assertRedirect();
        $this->assertSame(0, Voucher::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('type', Voucher::PAYMENT)
            ->where('branch_id', $this->opened->id)->count(), '⛔ অন্য দরজায় চিরকুট খাটল।');

        // ⭐ আসলটা একবার চলে
        $this->from($this->voucherForm())->post($action, $data)->assertRedirect();
        $this->assertSame(1, $this->vouchers('জাল বোতাম')->count());

        // ⛔ আবার — নতুন `_once` দিয়েও (দুইবার-চাপার পাহারা পেরিয়ে) চিরকুট দ্বিতীয়বার চলে না
        $this->from($this->voucherForm())->post($action, [...$data, '_once' => null])->assertRedirect($this->voucherForm());
        $this->assertSame(1, $this->vouchers('জাল বোতাম')->count(), '⛔ একই চিরকুটে দুইটা কাগজ।');
        $this->assertSame(__('core.branch_pin.confirm_refused'), session(FormBranchPin::OFFER)['message'] ?? null);
    }

    public function test_a_form_without_a_stamp_passes_with_a_warning_in_the_grace_window_and_is_stopped_after(): void
    {
        $this->as($this->clerk, $this->other->id);

        config(['abos.branch_pin.grace_until' => now()->addHour()->toIso8601String()]);
        \Illuminate\Support\Facades\Log::spy();
        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'journal'), $this->journal('ছাড়ের ভিতরে'))->assertRedirect();
        $this->assertSame((int) $this->other->id, (int) $this->vouchers('ছাড়ের ভিতরে')->sole()->branch_id);
        // ⓘ পরীক্ষায় লগ নয় (ম্যাকের ডিস্ক), সতর্কতা পাতায় — লাইভে লগও
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning', ['A form without its branch stamp was let through in the grace window', \Mockery::any()]);
        $this->assertSame(__('core.branch_pin.unstamped', ['current' => $this->other->name()]), session(FormBranchPin::NOTICE), '⛔ ছাপহীন জমায় সতর্কতা নেই।');

        config(['abos.branch_pin.grace_until' => now()->subHour()->toIso8601String()]);
        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'journal'), $this->journal('ছাড়ের পরে'))
            ->assertRedirect($this->voucherForm())
            ->assertSessionHasInput('narration', 'ছাড়ের পরে');
        $this->assertSame(0, $this->vouchers('ছাড়ের পরে')->count(), '⛔ ছাড়ের জানালার পরে ছাপহীন জমা চলে গেল।');
        $this->assertSame(__('core.branch_pin.unstamped_refused'), session(FormBranchPin::OFFER)['message'] ?? null);
    }

    /**
     * ⛔ কাউন্টারের বিক্রির দরজা `*.store` নয় (`sales.pos.checkout`) — তবু একই পাহারা ([[FormBranchPin::CREATE_DOORS]])।
     * ⓘ টাকার দরজা: বিল, মাল বেরোনো আর টিলে নগদ — ভুল শাখায় গেলে তিনটাই ভুল শাখায়।
     */
    public function test_a_counter_sale_opened_in_one_branch_is_stopped_in_another_and_lands_in_its_own_on_the_button(): void
    {
        app(\App\Core\Services\SettingsService::class)->set('sales.screen_pos', true);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        // ⓘ ময়মনসিংহের গুদাম; টিলটা কোম্পানির (শাখাহীন) — দুই শাখাতেই দেখা যায়, তাই দাবিটা কেবল ছাপ মাপে
        $warehouse = \App\Modules\Inventory\Models\Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', 'WH-MMS')->firstOrFail();
        $till = app(\App\Modules\Accounts\Services\CashTillService::class)->ensurePrimaryTill();
        \App\Modules\Accounts\Models\CashTill::query()->withoutGlobalScopes()->whereKey($till->id)->update(['branch_id' => null]);
        $product = Product::query()->orderBy('id')->firstOrFail();
        $sale = ['warehouse_id' => $warehouse->id, 'paid' => '300', 'lines' => [['product_id' => $product->id, 'qty' => '2', 'rate' => '150']]];
        $counter = route('sales.pos.index');
        $invoices = fn () => \App\Modules\Sales\Models\SalesInvoice::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count();

        $this->as($owner, $this->opened->id);
        $stamp = $this->stampOn($counter);
        $before = $invoices();

        $this->as($owner, $this->other->id);
        $this->from($counter)->post(route('sales.pos.checkout'), [...$sale, FormBranchPin::FIELD => $stamp])
            ->assertRedirect($counter)
            ->assertSessionHasInput('paid', '300');
        $this->assertSame($before, $invoices(), '⛔ শাখা না মিলেও কাউন্টারের বিল কাটা হলো।');
        $this->assertSame(
            __('core.branch_pin.moved', ['opened' => $this->opened->name(), 'current' => $this->other->name()]),
            session(FormBranchPin::OFFER)['message'] ?? null,
        );

        [$action, $data] = $this->button($counter);
        $this->from($counter)->post($action, $data)->assertRedirect();

        $invoice = \App\Modules\Sales\Models\SalesInvoice::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->latest('id')->firstOrFail();
        $this->assertSame($before + 1, $invoices(), '⛔ বোতাম চেপেও কাউন্টারের বিল হলো না।');
        $this->assertSame((int) $this->opened->id, (int) $invoice->branch_id, '⛔ কাউন্টারের বিল খোলার শাখায় বসেনি।');
    }

    public function test_editing_an_existing_document_is_never_checked(): void
    {
        $this->as($this->clerk, $this->opened->id);
        $stamp = $this->stampOn($this->voucherForm());
        $this->from($this->voucherForm())->post(route('accounts.voucher.store', 'journal'), [...$this->journal('সম্পাদনা'), FormBranchPin::FIELD => $stamp])
            ->assertSessionHasNoErrors();
        $voucher = $this->vouchers('সম্পাদনা')->sole();

        // ⓘ কাজের শাখা নেত্রকোনা, দেখা "সব শাখা" — ময়মনসিংহের খসড়াটা দেখা যায়, ছাপ মেলে না
        $this->as($this->clerk, $this->other->id);
        $this->as($this->clerk, 'all');

        $this->put(route('accounts.voucher.update', $voucher), [...$this->journal('সম্পাদিত'), FormBranchPin::FIELD => $stamp])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing(FormBranchPin::OFFER)
            ->assertRedirect(route('accounts.voucher.show', $voucher));

        $fresh = Voucher::query()->withoutGlobalScopes()->findOrFail($voucher->id);
        $this->assertSame('সম্পাদিত', $fresh->narration, '⛔ সম্পাদনায় শাখার ছাপ দেখা হলো।');
        $this->assertSame((int) $this->opened->id, (int) $fresh->branch_id, '⛔ সম্পাদনায় কাগজ নিজের শাখা হারাল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function openThenSwitch(): string
    {
        $this->as($this->clerk, $this->opened->id);
        $stamp = $this->stampOn($this->voucherForm());
        $this->as($this->clerk, $this->other->id);

        return $stamp;
    }

    private function refusedPost(string $stamp, string $narration): void
    {
        $this->from($this->voucherForm())
            ->post(route('accounts.voucher.store', 'journal'), [...$this->journal($narration), FormBranchPin::FIELD => $stamp])
            ->assertRedirect($this->voucherForm());

        $this->assertSame(0, $this->vouchers($narration)->count());
    }

    private function voucherForm(): string
    {
        return route('accounts.voucher.create', 'journal');
    }

    /** পাতা খুলে ফর্মের ছাপটা — যেমন ব্রাউজার পায়। */
    private function stampOn(string $url): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/name="'.FormBranchPin::FIELD.'" value="([^"]+)"/', $html, $m), '⛔ ফর্মে `_branch` ঘর নেই।');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /**
     * ফর্মে ফেরা পাতা থেকে "X-এই জমা দিন" বোতামের ফর্মটা — ঠিকানা আর লুকানো ঘরগুলো, যেমন ব্রাউজার পাঠাত।
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function button(string $url): array
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<form[^>]*data-branch-pin-confirm[^>]*>(.*?)<\/form>/s', $html, $form), '⛔ বোতামটা পাতায় নেই।');
        $this->assertSame(1, preg_match('/action="([^"]+)"/', $form[0], $action));
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $form[1], $inputs, PREG_SET_ORDER);

        parse_str(implode('&', array_map(
            fn (array $i) => urlencode(html_entity_decode($i[1], ENT_QUOTES)).'='.urlencode(html_entity_decode($i[2], ENT_QUOTES)),
            $inputs,
        )), $data);

        $this->assertArrayHasKey(FormBranchPin::CONFIRM, $data, '⛔ বোতামে চিরকুট নেই।');

        return [html_entity_decode($action[1], ENT_QUOTES), $data];
    }

    /** @return array<string, mixed> */
    private function journal(string $narration): array
    {
        return ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $narration, 'save_as_draft' => '1',
            'lines' => [
                ['account_id' => $this->debit->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => $this->credit->id, 'debit' => '0', 'credit' => '100'],
            ]];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Voucher> */
    private function vouchers(string $narration): \Illuminate\Database\Eloquent\Builder
    {
        return Voucher::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('narration', $narration);
    }

    /** উপরের শাখা বদল — আসল দরজায়, অন্য ট্যাব থেকে যেমন। */
    private function as(User $user, int|string $branch): void
    {
        $this->actingAs($user->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();
        $this->forgetScope();
        $this->actingAs($user->fresh());
    }

    private function forgetScope(): void
    {
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
