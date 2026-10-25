<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ খোলা বিলের কার্ডে বিলে না-বাঁধা ক্রেডিট/ডেবিট নোট — দেখায়, প্রয়োগ হয় না (a5-এর চুক্তি 56f7e0e15, fe-র দুই বিকল্পই; cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ নোট এই কাগজে প্রয়োগ আলাদা কাজ — তাই নোটের সারিতে টিক নেই, "এই রসিদে কত" নেই; লেখার যাচাই নোট চেনেই না।
 *
 * দাবি:
 *   ১. কার্ডের উত্তরে নোটের নিজের সারি — ধরন credit_note, বাকি ঋণাত্মক; আর পক্ষের বকেয়া নোটে কমেছে (বিলের মোট − নোট)
 *   ২. নোটের public_id পাঠিয়ে রসিদ — "অজানা বিল", `bills.0.id`-এ; কিছু লেখা হয় না
 *   ৩. পর্দা: টিক আর অঙ্কের ঘর কেবল বিলের সারিতে (`isBill`), নোটে ধরনের নাম, আর নোট থাকলে এক লাইনের কথা
 */
final class TheBillsCardShowsANoteButAppliesOnlyBillsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $customer = app(CustomerService::class)->create(['name_en' => 'Card Note Customer', 'credit_limit' => 0, 'credit_days' => 0]);
        $customer->forceFill(['credit_limit' => '1000000000'])->save();
        $this->customer = $customer;
    }

    /** ১ */
    public function test_the_card_carries_the_note_as_its_own_row_and_the_due_is_lower(): void
    {
        $invoice = $this->confirmedInvoice();
        $note = $this->untiedCredit('80');

        $answer = $this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $this->customer->id]))->assertOk()->json();
        $row = collect($answer['bills'] ?? [])->firstWhere('public_id', $note->public_id);

        $this->assertNotNull($row, '⛔ খোলা বিলে ক্রেডিট নোটের সারি নেই: '.json_encode($answer['bills'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertSame('credit_note', $row['kind'] ?? null, '⛔ নোটের সারির ধরন ক্রেডিট নোট নয়');
        $this->assertSame(0, bccomp((string) $row['outstanding'], '-80', 4), '⛔ ক্রেডিট নোট বকেয়া কমানো (ঋণাত্মক) অঙ্কে নেই');
        $this->assertSame(0, bccomp((string) ($answer['amount'] ?? ''), bcsub((string) $invoice->total, '80', 4), 4),
            '⛔ পক্ষের বকেয়া ক্রেডিট নোটে কমেনি: '.json_encode($answer['amount'] ?? null));
    }

    /** ২ */
    public function test_a_note_cannot_be_applied_on_the_receipt(): void
    {
        $this->confirmedInvoice();
        $note = $this->untiedCredit('80');
        $before = Voucher::query()->count();

        $this->from(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))
            ->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
                'type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'নোট প্রয়োগের চেষ্টা', 'amount' => '50',
                'party_type' => 'customer', 'party_id' => $this->customer->id, 'instrument' => 'cash',
                'to_account_id' => Account::query()->where('money_kind', Account::CASH)->where('is_group', false)->orderBy('code')->firstOrFail()->id,
                'bills' => [['id' => (string) $note->public_id, 'amount' => '10']],
            ]);

        $said = (string) (session('errors')?->getBag('default')->first('bills.0.id') ?? '');
        $this->assertContains($said, [trans('accounts::validation.against_unknown', [], 'bn'), trans('accounts::validation.against_unknown', [], 'en')],
            '⛔ ক্রেডিট নোট রসিদে বিলের মতো প্রয়োগ হতে গেল: '.$said);
        $this->assertSame($before, Voucher::query()->count(), '⛔ নোট প্রয়োগের রসিদ লেখা হলো');
    }

    /** ৩ */
    public function test_the_card_draws_no_tick_or_amount_on_a_note_row(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<template x-if="isBill\(b\)">\s*<input type="checkbox" data-bill-tick/', $html, '⛔ নোটের সারিতেও টিক — নোট এই কাগজে প্রয়োগ নয়');
        $this->assertMatchesRegularExpression('/<template x-if="isBill\(b\)">\s*<input type="text" inputmode="decimal" data-bill-amount/', $html,
            '⛔ নোটের সারিতেও "এই রসিদে কত"-র ঘর');
        $this->assertStringContainsString('data-bill-kind x-show="! isBill(b)" x-text="kindText(b)"', $html, '⛔ নোটের সারিতে ধরনের নাম নেই');
        $this->assertStringContainsString('data-bills-notes x-show="hasNotes"', $html, '⛔ নোট থাকলে বকেয়ায় তার কথার লাইন নেই');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function confirmedInvoice(): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '3', 'rate' => '100']],
        ))->fresh();
    }

    private function untiedCredit(string $amount): Note
    {
        $notes = app(NoteService::class);
        $note = $notes->create([
            'direction' => Note::CREDIT, 'party_kind' => Note::KIND_CUSTOMER, 'party_id' => $this->customer->id,
            'other_account_id' => StandardChart::find(StandardChart::DISCOUNT_GIVEN)?->id, 'trx_date' => now()->toDateString(),
            'amount' => $amount, 'tax_amount' => '0', 'reason' => 'price_correction',
        ]);
        $notes->confirm($note);

        return $note->fresh();
    }
}
