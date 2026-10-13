<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ হাতধারে মানুষের নাম বাধ্য — লাইভের রাতের যাচাই, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ AVA RCV-0001: টাকা-গ্রহণের ভাউচারে হাতধারের খাত (১১৭০) বেছে নাম ছাড়া ৯,০০০ বসেছিল — কার হাতধার কেউ বলতে পারে না,
 * আর হাতধারের জের মানুষদের যোগের সাথে আর মেলে না। এখন ভাউচার পাকা হওয়ার আগে নাম চায়: সারিতে, নয়তো মাথার পক্ষ "ব্যক্তি"।
 */
final class AHandLoanLineNamesItsPersonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hand_loan_line_without_a_person_is_refused_and_with_one_posts(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $handLoan = StandardChart::find(StandardChart::HAND_LOAN);
        $handLoan->forceFill(['party_types' => ['person']])->save();
        $other = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;
        $vouchers = app(VoucherService::class);

        $unnamed = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'হাতধার'], [
            ['account_id' => $other, 'debit' => '9000'],
            ['account_id' => $handLoan->id, 'credit' => '9000'],
        ]);

        try {
            $vouchers->post($unnamed);
            $this->fail('⛔ নাম ছাড়া হাতধার খাতায় বসল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.hand_loan_needs_person', ['name' => $handLoan->label()]), $e->errors()['lines'][0] ?? null);
        }

        $this->assertFalse($unnamed->fresh()->isPosted());

        // ⓘ নামসহ — বসে
        $person = Person::query()->create(['code' => 'HL-1', 'name_en' => 'Lender', 'name_bn' => 'ধারদাতা', 'is_active' => true]);
        $named = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'হাতধার'], [
            ['account_id' => $other, 'debit' => '9000'],
            ['account_id' => $handLoan->id, 'credit' => '9000', 'party_type' => 'person', 'party_id' => $person->id],
        ]);

        $this->assertTrue($vouchers->post($named)->isPosted(), 'নামসহ হাতধারও আটকাল');
    }
}
