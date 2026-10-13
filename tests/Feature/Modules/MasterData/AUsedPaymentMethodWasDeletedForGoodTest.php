<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MasterData;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\MasterData\Services\MasterListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কাগজে লেখা পরিশোধ-পদ্ধতি চিরতরে মুছে যেত — মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[MasterListService::delete()]] "ব্যবহৃত কি না" দেখত কেবল ডাটাবেজের সম্পর্কে। পরিশোধ-পদ্ধতির দিকে কোনো সম্পর্ক নেই —
 * ভাউচার আর আদায় কোডটা লেখে (`instrument`)। ফলে ব্যবহৃত পদ্ধতি নিষ্ক্রিয় না হয়ে মুছে যেত, আর একই কোডে নতুন সারি পুরনো
 * কাগজের মানে বদলে দিত। ⓘ পাশের [[MasterListService::inUse()]] কোডটাও দেখে — এখন মোছাও সেটাই জিজ্ঞেস করে।
 */
final class AUsedPaymentMethodWasDeletedForGoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_method_written_on_a_voucher_is_deactivated_not_deleted(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $method = PaymentMethod::query()->where('is_default', false)->firstOrFail();

        app(StandardChart::class)->install();
        $cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $expense = (int) Account::query()->postable()->active()->where('type', 'expense')->value('id');
        app(VoucherService::class)->create(['type' => 'payment', 'trx_date' => now()->toDateString(),
            'narration' => 'Paid by the method', 'instrument' => $method->code],
            [['account_id' => $expense, 'debit' => '100', 'credit' => '0'], ['account_id' => $cash, 'debit' => '0', 'credit' => '100']]);

        $deleted = app(MasterListService::class)->delete($method);

        $this->assertFalse($deleted, 'ভাউচারে লেখা পদ্ধতি মুছে গেল — কোডটা খালি হলো।');
        $kept = PaymentMethod::query()->withTrashed()->find($method->id);
        $this->assertNotNull($kept, 'সারিটাই নেই।');
        $this->assertFalse((bool) $kept->is_active, 'মোছার বদলে নিষ্ক্রিয়ও হয়নি।');
    }
}
