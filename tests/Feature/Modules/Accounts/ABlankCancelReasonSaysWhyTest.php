<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\AlpineLiteral;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * বাতিলের কারণ ফাঁকা রেখে "ঠিক আছে" চাপলাম — কিছুই হলো না, কোনো বার্তাও না।
 *
 * ── কী ভাঙা ছিল, ২৮ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * চারটা পাতার বাতিলের ফর্ম CSP-Alpine-এর `reasonPrompt`-কে কেবল প্রশ্নটা
 * দিত। ফাঁকা কারণে কম্পোনেন্ট জমা থামাত — ঠিকই — কিন্তু চুপচাপ: মানুষ
 * ভাবতেন বোতামটা নষ্ট।
 *
 * ⭐ এখন প্রতিটা ফর্ম `empty:` বার্তাও দেয়
 * (`core.form.cancel_needs_reason`), আর কম্পোনেন্ট সেটা দেখায়।
 *
 * ── প্রতিটা দাবি যা মাপে ────────────────────────────────────────────
 *   ১. পাতা খোলে (২০০)
 *   ২. **ঠিক ঐ কাগজের বাতিলের ফর্মের** ট্যাগেই `reasonPrompt(` আর
 *      `empty:` বার্তাটা — অন্য কোনো `reasonPrompt` দেখে সবুজ নয়
 *   ৩. প্রত্যাশিত লেখাটা [[AlpineLiteral::from()]] দিয়ে বানানো — কম্পাইল
 *      করা পাতা যেটা ডাকে — হাতে টাইপ করা নয়
 *
 * ⚠️ বার্তাটা ব্যবহারকারীর ভাষায় আসে ([[ResolveCompanyContext]]), তাই
 * প্রত্যাশাও সেই ভাষাতেই বানানো হয় — পরীক্ষার নিজের ভাষায় নয়।
 */
final class ABlankCancelReasonSaysWhyTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($this->company->id);

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner->fresh());

        app(StandardChart::class)->install();
    }

    /** ভাউচার — খসড়া জাবেদা; `@can('accounts.voucher.delete')`-এর পিছনে। */
    public function test_the_voucher_cancel_form_says_why_a_blank_reason_stops(): void
    {
        [$first, $second] = Account::query()
            ->postable()
            ->active()
            ->whereNull('money_kind')
            ->orderBy('code')
            ->limit(2)
            ->get()
            ->all();

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'BLANK-REASON-VOUCHER',
        ], [
            ['account_id' => $first->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => $second->id, 'debit' => '0', 'credit' => '1000'],
        ]);

        $this->assertCancelFormSaysWhy(
            route('accounts.voucher.show', $voucher),
            route('accounts.voucher.cancel', $voucher),
            'ভাউচার',
        );
    }

    /** নোট — খসড়া ক্রেডিট নোট; `@can('accounts.note.manage')`-এর পিছনে। */
    public function test_the_note_cancel_form_says_why_a_blank_reason_stops(): void
    {
        $note = app(NoteService::class)->create([
            'direction' => Note::CREDIT,
            'party_type' => 'customer',
            'party_id' => (int) Customer::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'tax_amount' => '0',
            'reason' => 'price_correction',
            'against_no' => 'INV-BLANK-1',
            'narration' => 'BLANK-REASON-NOTE',
        ]);

        $this->assertCancelFormSaysWhy(
            route('accounts.note.show', $note),
            route('accounts.note.cancel', $note),
            'নোট',
        );
    }

    /**
     * হস্তান্তর — পথে থাকা (pending); `@can('accounts.transfer.create')`-এর পিছনে।
     *
     * ⓘ নগদ শূন্যের নিচে নামে না, তাই আগে টিলে টাকা ([[PutsMoneyInTheTill]])।
     */
    public function test_the_transfer_cancel_form_says_why_a_blank_reason_stops(): void
    {
        $tills = app(CashTillService::class);
        $from = $tills->ensurePrimaryTill();
        $to = $tills->create([
            'code' => 'SAFE',
            'name_en' => 'Office Safe',
            'name_bn' => 'অফিসের সিন্দুক',
        ]);

        $this->putMoneyIn(Account::query()->findOrFail($from->account_id), '20000');

        $transfer = app(MoneyTransferService::class)->initiate([
            'from_till_id' => $from->id,
            'to_till_id' => $to->id,
            'amount' => '12000',
            'trx_date' => now()->toDateString(),
            'narration' => 'BLANK-REASON-TRANSFER',
        ]);

        $this->assertTrue($transfer->isPending(), 'হস্তান্তরটা পথে নেই — প্রস্তুতিটাই ভুল।');

        $this->assertCancelFormSaysWhy(
            route('accounts.transfer.show', $transfer),
            route('accounts.transfer.cancel', $transfer),
            'হস্তান্তর',
        );
    }

    /** ক্রয়ের বিল — খসড়া; `@can('delete', $bill)` = `purchase.bill.cancel`। */
    public function test_the_purchase_bill_cancel_form_says_why_a_blank_reason_stops(): void
    {
        $payload = [
            'supplier_id' => Supplier::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'BLANK-REASON-1',
            'lines' => [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']],
        ];

        $bill = app(PurchaseBillService::class)->create($payload, $payload['lines']);

        $this->assertCancelFormSaysWhy(
            route('purchase.bill.show', $bill),
            route('purchase.bill.cancel', $bill),
            'ক্রয়ের বিল',
        );
    }

    /**
     * পাতা খুলে ঠিক বাতিলের ফর্মের খোলার ট্যাগটা তুলে আনা, তারপর তার ভিতরে
     * `reasonPrompt(` আর `empty:` বার্তা খোঁজা।
     *
     * ⛔ পুরো পাতায় খুঁজলে অন্য কোনো ফর্মের `reasonPrompt` (যেমন পোস্ট বা
     * অন্য বাতিল) দাবিটাকে মিথ্যা সবুজ করতে পারত।
     */
    private function assertCancelFormSaysWhy(string $page, string $cancelUrl, string $what): void
    {
        $html = (string) $this->get($page)->assertOk()->getContent();

        $action = 'action="'.e($cancelUrl).'"';

        $this->assertStringContainsString($action, $html,
            "⛔ {$what}: বাতিলের ফর্মটাই পাতায় নেই — বোতাম না থাকলে দাবিটা কিছু মাপে না।");

        $matched = preg_match('/<form\b[^>]*'.preg_quote($action, '/').'[^>]*>/s', $html, $tag);
        $this->assertSame(1, $matched, "{$what}: বাতিলের ফর্মের খোলার ট্যাগ পড়া গেল না।");

        $locale = $this->owner->fresh()->locale ?? config('app.locale');
        $message = __('core.form.cancel_needs_reason', [], $locale);

        // ⚠️ চাবিটা অনুবাদ না পেলে চাবির নামটাই ফেরে — তখন দাবি নিজের সাথেই মিলত
        $this->assertNotSame('core.form.cancel_needs_reason', $message,
            "'{$locale}' ভাষায় `core.form.cancel_needs_reason` অনুবাদ নেই।");

        $this->assertStringContainsString('reasonPrompt(', $tag[0],
            "⛔ {$what}: বাতিলের ফর্মে `reasonPrompt` নেই।");

        $this->assertStringContainsString('empty: '.AlpineLiteral::from($message), $tag[0],
            "⛔ {$what}: বাতিলের ফর্ম ফাঁকা কারণের বার্তা দেয় না — \"ঠিক আছে\" চাপলে আবার নীরবতা।");
    }
}
