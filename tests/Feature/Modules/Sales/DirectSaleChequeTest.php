<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কাউন্টারে চেক নেওয়া — টাকা "হাতে চেক" (১১০৪)-এ বসে, চেক নিজে পোস্ট করে না।
 *
 * ── এখানে যা প্রমাণ করা দরকার (A1-এর যাচাই) ─────────────────────────────
 * একটা চেকে বিল শোধ → পাশ → ফেরত, তিন ধাপের পর ১১০৪ শূন্যে ফেরে আর বিল
 * আবার বকেয়া দেখায়। দুইটা আলাদা পথে গোনা এক সত্য — না মিললে একটা মিথ্যা।
 *
 * আর দুইটা পাহারা: (ক) সাধারণ আদায় ১১০৪-এ টাকা বসাতে পারে না (চেক ছাড়া
 * ১১০৪ ফাঁকা থাকে), (খ) আদায়ে-পোস্ট-করা চেকে ChequeService::bounce()
 * নিজে পোস্ট করে না — নইলে ফেরত এলে টাকা দ্বিগুণ কাটত।
 */
class DirectSaleChequeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    private function sales(): DirectSaleService
    {
        return app(DirectSaleService::class);
    }

    /** কোড ধরে একটা খাতের খতিয়ান-ব্যালেন্স (ডেবিট − ক্রেডিট)। */
    private function balanceOf(string $code): string
    {
        $id = Account::query()->where('code', $code)->value('id');

        return LedgerEntry::query()->where('account_id', $id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    private function chqMethod(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', 'CHQ')->firstOrFail();
    }

    /**
     * ১০ × ১০০ = ১০০০ টাকার একটা বিক্রি, পুরোটা একটা চেকে।
     *
     * @return array{challan: mixed, invoice: mixed, change: string}
     */
    private function sellByCheque(string $chequeNo = 'CHQ-77', string $bank = 'City Bank'): array
    {
        return $this->sales()->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'deposits' => [[
                    'payment_method_id' => $this->chqMethod()->id,
                    'amount' => '1000',
                    'reference' => $chequeNo,
                    'ref_date' => '2026-09-20',
                    'bank_name' => $bank,
                ]],
            ],
            [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        );
    }

    /** ★ চেকে বিক্রি — টাকা ১১০৪-এ, বিল শোধ, আর রেজিস্টারে সারি। */
    public function test_a_cheque_sale_lands_in_cheques_in_hand_and_registers(): void
    {
        // ধরনটা ঠিক আছে তো — নইলে পুরো পথটাই ভুল দিকে যেত
        $this->assertSame('cheque', $this->chqMethod()->kind);

        $result = $this->sellByCheque();

        // টাকা "হাতে চেক"-এ, ব্যাংকে বা নগদে নয়
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '1000', 4),
            '১১০৪-এ ১০০০ থাকার কথা।');
        // গ্রাহকের পাওনা মিটেছে (আদায়ের লাইন থেকে)
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '0', 4));
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '0', 4));

        // রেজিস্টারে অ-পোস্টিং সারি
        $cheque = Cheque::query()->where('cheque_no', 'CHQ-77')->firstOrFail();
        $this->assertSame(Cheque::PENDING, $cheque->status);
        $this->assertTrue($cheque->postedByCollection());
        $this->assertSame('City Bank', $cheque->bank_name);
        $this->assertSame('customer', $cheque->party_type);
        $this->assertSame($this->customer->id, $cheque->party_id);
        $this->assertSame(0, bccomp((string) $cheque->amount, '1000', 4));
    }

    /** ★★ ফেরত — ১১০৪ শূন্যে ফেরে, বিল আবার বকেয়া, চেক bounced। */
    public function test_a_bounced_cheque_returns_the_bill_to_due(): void
    {
        $result = $this->sellByCheque();
        $cheque = Cheque::query()->where('cheque_no', 'CHQ-77')->firstOrFail();

        app(CollectionService::class)->bounceReceivedCheque($cheque, 'insufficient funds');

        // টাকা ১১০৪ ছেড়ে গেছে, গ্রাহক আবার দেনাদার, বিল আবার পুরো বকেয়া
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 4),
            'ফেরতের পর ১১০৪ শূন্য হওয়ার কথা।');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::RECEIVABLE), '1000', 4));
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '1000', 4),
            'ফেরতের পর বিল আবার বকেয়া দেখানোর কথা।');

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status);
    }

    /** ★ রেজিস্টারের "ফেরত" দরজা (HTTP) — সরাসরি-বিক্রয়ের চেক বাস্তবে ফেরানো যায়। */
    public function test_the_register_bounce_door_reverses_the_sale(): void
    {
        $result = $this->sellByCheque();
        $cheque = Cheque::query()->where('cheque_no', 'CHQ-77')->firstOrFail();

        // চেকের খাতার bounce-বোতাম collection-চেকে এই Sales-দরজায় পোস্ট করে
        $this->post(route('sales.collection.cheque_bounce', $cheque), ['bounce_reason' => 'returned unpaid'])
            ->assertRedirect();

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 4));
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '1000', 4),
            'দরজা দিয়ে ফেরত দিলে বিল আবার বকেয়া হওয়ার কথা।');
    }

    /** পাশ — টাকা ১১০৪ ছেড়ে ব্যাংকে যায়, বিল শোধই থাকে। */
    public function test_clearing_a_cheque_moves_the_money_to_the_bank(): void
    {
        $result = $this->sellByCheque();
        $cheque = Cheque::query()->where('cheque_no', 'CHQ-77')->firstOrFail();

        $bank = $this->aBankAccount();
        app(ChequeService::class)->clear($cheque, $bank->id);

        // ১১০৪ খালি, ব্যাংকে টাকা, বিল এখনো শোধ
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 4));
        $this->assertSame(0, bccomp($this->balanceOfAccount($bank), '1000', 4));
        $this->assertSame(0, bccomp($result['invoice']->fresh()->dueAmount(), '0', 4));
        $this->assertSame(Cheque::CLEARED, $cheque->fresh()->status);
    }

    /** ★ দ্বিগুণ-দাখিলা পাহারা — আদায়ে-পোস্ট-করা চেকে bounce() নিজে পোস্ট করে না। */
    public function test_cheque_service_bounce_refuses_a_receipt_posted_cheque(): void
    {
        $this->sellByCheque();
        $cheque = Cheque::query()->where('cheque_no', 'CHQ-77')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(ChequeService::class)->bounce($cheque, 'wrong path');
    }

    /** ★ পাহারা — সাধারণ আদায় ১১০৪-এ টাকা বসাতে পারে না, আর picker-এও নেই। */
    public function test_a_plain_collection_cannot_land_in_cheques_in_hand(): void
    {
        // ১১০৪ টাকার খাতের picker-এ কখনো আসে না
        $picker = Account::query()->money()->postable()->active()->pluck('code')->all();
        $this->assertNotContains(StandardChart::CHEQUES_IN_HAND, $picker);

        // পর্দায় না থাকা নিরাপত্তা নয় — সার্ভারে সরাসরি ১১০৪ পাঠালেও থামে
        $chequesInHand = Account::query()->where('code', StandardChart::CHEQUES_IN_HAND)->value('id');

        $this->expectException(ValidationException::class);
        app(CollectionService::class)->create([
            'customer_id' => $this->customer->id,
            'account_id' => $chequesInHand,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
        ], []);
    }

    /** holding-flag থাকলেই কেবল চেক-পথ ১১০৪ ব্যবহার করতে পারে। */
    public function test_the_holding_flag_admits_cheques_in_hand(): void
    {
        $chequesInHand = Account::query()->where('code', StandardChart::CHEQUES_IN_HAND)->value('id');

        $collection = app(CollectionService::class)->create([
            'customer_id' => $this->customer->id,
            'account_id' => $chequesInHand,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'allows_holding' => true,
        ], []);

        $this->assertSame($chequesInHand, $collection->account_id);
    }

    private function balanceOfAccount(Account $account): string
    {
        return LedgerEntry::query()->where('account_id', $account->id)->get()->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );
    }

    /** ক্লিয়ারিং-এর জন্য একটা সত্যিকারের ব্যাংক খাত (১১০২-এর সন্তান)। */
    private function aBankAccount(): Account
    {
        $bank = Account::query()->where('code', StandardChart::BANK)->firstOrFail();

        return Account::query()->create([
            'parent_id' => $bank->id,
            'code' => '110290',
            'name_en' => 'Test Bank A/C',
            'type' => $bank->type,
            'nature' => $bank->nature,
            'is_group' => false,
            'is_bank' => true,
        ]);
    }
}
