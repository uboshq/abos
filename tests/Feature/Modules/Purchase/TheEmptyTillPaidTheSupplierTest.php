<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashOnHand;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * খালি নগদ টিল থেকে সরবরাহকারীকে টাকা দেওয়া গেল — লাইভ QA (hp2, TCL), PMT-0001।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ক্রয়ের পরিশোধ নিশ্চিত করলে খাতায় বসত Dr দেনা / Cr টিল — টিলে কত আছে
 * কেউ দেখত না। টিলে ০ থাকলেও ১,০০০ পরিশোধ হয়ে গেল, আর টিলের জের দাঁড়াল
 * −১,০০০। ⓘ খাতা মেলে (ডেবিট = ক্রেডিট), তাই কোনো পরীক্ষা লাল হয়নি — অথচ
 * বাক্সে ঋণাত্মক টাকা থাকে না।
 *
 * ⭐ নিয়ম এক জায়গায় ([[CashOnHand]]): নগদ আর মোবাইল ব্যাংকিংয়ের খাত
 * শূন্যের নিচে নামে না; ব্যাংক নামতে পারে (CC/OD)। প্রতিটা দাবি অঙ্কে:
 * আটকালে খাতায় একটা সারিও বসে না, পরিশোধ খসড়াই থাকে।
 */
final class TheEmptyTillPaidTheSupplierTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    /** ⛔ টিলে যা আছে তার চেয়ে এক টাকা বেশিও নয় — আর আটকালে কিছুই বসে না। */
    public function test_a_payment_larger_than_the_till_is_refused_and_posts_nothing(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $held = app(CashOnHand::class)->balance($till);
        $payment = $this->draft($till, bcadd($held, '1000', 4));
        $rows = LedgerEntry::query()->count();

        try {
            app(PaymentService::class)->confirm($payment);
            $this->fail("⛔ টিলে {$held} থাকতে ".bcadd($held, '1000', 4).' পরিশোধ হয়ে গেল — টিল ঋণাত্মক।');
        } catch (ValidationException) {
            // প্রত্যাশিত
        }

        $this->assertSame($rows, LedgerEntry::query()->count(), '⛔ আটকানো পরিশোধও খাতায় সারি রেখে গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $payment->fresh()->status, '⛔ আটকানো পরিশোধ খসড়া থাকেনি।');
        $this->assertSame($held, app(CashOnHand::class)->balance($till), '⛔ টিলের জের বদলে গেছে।');
    }

    /** ⭐ ঠিক যত আছে তত — পাশ করে, আর টিল শূন্যে নামে, নিচে নয়। */
    public function test_paying_exactly_what_the_till_holds_passes(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $this->putMoneyIn($till, '5000');
        $held = app(CashOnHand::class)->balance($till);

        app(PaymentService::class)->confirm($this->draft($till, $held));

        $this->assertSame('0.0000', app(CashOnHand::class)->balance($till));
    }

    /** ⭐ ব্যাংক খাত পাহারার বাইরে — চলতি ঋণ আইনত ঋণাত্মক হয়। */
    public function test_a_bank_account_may_go_below_zero(): void
    {
        /* ⓘ ডেমোতে কোনো ব্যাংক খাত নেই — আসল পথ দিয়েই একটা খোলা হয়, যাতে money_kind নিজে বসে */
        $bank = app(AccountService::class)->create([
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->firstOrFail()->id,
            'code' => '110299',
            'name_en' => 'Test CC Account',
            'name_bn' => 'পরীক্ষার সিসি হিসাব',
        ]);
        $this->assertTrue($bank->isBank(), 'ⓘ দাবির ভিত্তি: খাতটা সত্যিই ব্যাংকের।');
        $held = app(CashOnHand::class)->balance($bank);

        app(PaymentService::class)->confirm($this->draft($bank, bcadd($held, '1000', 4)));

        $this->assertSame(bcsub('0', '1000', 4), app(CashOnHand::class)->balance($bank),
            '⛔ ব্যাংক খাত আটকে গেছে — CC/OD খাতের পরিশোধ বন্ধ হয়ে যেত।');
    }

    /**
     * ⛔ পিছনের তারিখে: আজ টাকা আছে, সেদিন ছিল না — আটকায়।
     *
     * ⚠️ কেবল আজকের জের দেখলে এই পরিশোধ পাশ করত, আর সেদিনের টিল ঋণাত্মক হত।
     */
    public function test_a_backdated_payment_cannot_spend_money_that_came_later(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill()->account;
        $empty = app(CashOnHand::class)->balance($till, now()->subDays(3)->toDateString());
        $this->putMoneyIn($till, '5000');

        $payment = $this->draft($till, bcadd($empty, '1', 4), now()->subDays(3)->toDateString());

        $this->expectException(ValidationException::class);
        app(PaymentService::class)->confirm($payment);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function draft(Account $account, string $amount, ?string $date = null): Payment
    {
        return app(PaymentService::class)->create([
            'supplier_id' => $this->supplier->id,
            'account_id' => $account->id,
            'trx_date' => $date ?? now()->toDateString(),
            'amount' => $amount,
            'narration' => 'টিলের জের যাচাই',
        ], []);
    }
}
