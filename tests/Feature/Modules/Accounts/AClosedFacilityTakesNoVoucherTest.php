<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\BankFacility;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ বন্ধ ব্যাংক সুবিধার বিপরীতে ভাউচার নয় — অর্থের নিরীক্ষা (fe, ১৪ অক্টোবর ২০২৬)।
 *
 * ⓘ সুবিধা বন্ধ হলেও Accounts-এর ভাউচারে তার বিপরীতে টাকা তোলা যেত। সুবিধার খাত সব ঋণের ভাগের, তাই খাত বন্ধ করা যায় না;
 * প্রশ্নটা কাগজকেই করা হয় ([[\App\Core\Contracts\AcceptsVouchers]])। চালু সুবিধায় আগের মতোই।
 */
final class AClosedFacilityTakesNoVoucherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_closed_facility_refuses_a_voucher_and_an_open_one_takes_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $facility = (new BankFacility)->forceFill([
            'company_id' => $company->id, 'kind' => BankFacility::CC, 'bank' => 'Sonali Bank', 'sanctioned_on' => now()->subYear()->toDateString(),
            'limit_amount' => '500000', 'status' => DocumentStatus::CONFIRMED,
        ]);
        $facility->save();

        $vouchers = app(VoucherService::class);
        $draw = fn () => $vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সুবিধা থেকে তোলা',
            'against_type' => 'bank_facility', 'against_id' => $facility->id,
        ], [
            ['account_id' => (int) Account::query()->where('code', '5202')->value('id'), 'debit' => '1000'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '1000'],
        ]);

        // ⓘ চালু সুবিধা — আগের মতো
        $this->assertTrue($vouchers->post($draw())->isPosted());

        $facility->forceFill(['status' => DocumentStatus::CLOSED, 'closed_on' => now()->toDateString()])->save();
        $closed = $draw();

        try {
            $vouchers->post($closed);
            $this->fail('⛔ বন্ধ সুবিধার বিপরীতে ভাউচার পাকা হল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.against_closed'), $e->errors()['against_id'][0] ?? null);
        }

        $this->assertFalse($closed->fresh()->isPosted());
    }
}
