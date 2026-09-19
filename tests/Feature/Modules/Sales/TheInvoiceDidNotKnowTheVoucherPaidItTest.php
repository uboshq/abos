<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলটা জানত না যে রসিদ ভাউচার তার টাকা দিয়ে গেছে।
 *
 * ── ⛔ কী ধরা পড়ল, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────
 * মালিকের নকশা: কাউন্টারের ডিপোজিট হবে **হিসাবের আসল রসিদ ভাউচার**, বিলের
 * সাথে বাঁধা। ⚠️ কিন্তু বিক্রয় বিল নিজের বকেয়া গুনত কেবল বিক্রয়ের
 * "আদায়" দিয়ে। ⛔ ফল: টাকা খাতায় বসত, অথচ বিল "বকেয়া" দেখাত, তাগাদার
 * তালিকায় থাকত, আর গ্রাহকের ধারের সীমা ভরা দেখাত।
 *
 * ── ⭐ এই ফাইলের দাবি ────────────────────────────────────────────────
 * ১০০০ টাকার বিল, কাউন্টারে ৪০০ আদায় → বকেয়া ৬০০। খাতায় বসা ২৫০ টাকার
 * রসিদ ভাউচার → বকেয়া ৩৫০। ⚠️ খসড়া ভাউচার আর বিলের বিপরীতে পরিশোধ
 * (ফেরত) বকেয়া বদলায় না। ⓘ আর তালিকা ও একক পাতা একই অঙ্ক দেখায়।
 */
final class TheInvoiceDidNotKnowTheVoucherPaidItTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $result = app(DirectSaleService::class)->complete(
            [
                'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'deposit' => '400',
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id,
                'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
        );

        $this->invoice = $result['invoice'];
    }

    /**
     * ⭐ খাতায় বসা রসিদ ভাউচার বকেয়া কমায়।
     */
    public function test_a_posted_receipt_voucher_reduces_the_due(): void
    {
        $this->assertSame('600.0000', $this->invoice->fresh()->dueAmount(),
            'শুরুর অবস্থাই ভুল — ১০০০ টাকার বিলে ৪০০ আদায়ের পর ৬০০ বাকি থাকার কথা।');

        $this->voucher(Voucher::RECEIPT, '250', 'confirmed');

        $this->assertSame('350.0000', $this->invoice->fresh()->dueAmount(),
            'রসিদ ভাউচারে ২৫০ এসেছে, অথচ বিল এখনো পুরো বকেয়া দেখাচ্ছে।');
    }

    /**
     * ⭐ খসড়া ভাউচার টাকা নয়, আর পরিশোধ ভাউচার বকেয়া কমায় না।
     */
    public function test_a_draft_or_a_payment_voucher_changes_nothing(): void
    {
        $this->voucher(Voucher::RECEIPT, '100', 'draft');
        $this->voucher(Voucher::PAYMENT, '50', 'confirmed');

        $this->assertSame('600.0000', $this->invoice->fresh()->dueAmount(),
            'খসড়া বা পরিশোধের ভাউচার বকেয়া বদলে দিয়েছে।');
    }

    /**
     * ⭐ তালিকা আর একক পাতা একই অঙ্ক — `withCollected()` আর
     * `collectedAmount()` দুইটাই ভাউচার গোনে।
     */
    public function test_the_list_and_the_page_agree(): void
    {
        $this->voucher(Voucher::RECEIPT, '250', 'confirmed');

        $listed = SalesInvoice::query()->withCollected()->whereKey($this->invoice->id)->firstOrFail();

        $this->assertSame($this->invoice->fresh()->dueAmount(), $listed->dueAmount(),
            'তালিকা এক অঙ্ক আর একক পাতা আরেক অঙ্ক দেখাচ্ছে।');

        $this->assertSame('350.0000', $listed->dueAmount());
    }

    private function voucher(string $type, string $amount, string $status): void
    {
        Voucher::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => (int) FinancialYear::query()->value('id'),
            'type' => $type,
            'document_no' => 'TV-'.$type.'-'.$amount.'-'.$status,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $this->invoice->id,
            'money_account_id' => Account::query()->money()->where('is_group', false)->value('id'),
            'status' => $status,
        ]);
    }
}
