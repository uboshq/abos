<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ টাকার খাতে কোনো পক্ষ নয় — লাইভের রাতের যাচাই, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ UB-র দুই টিলে JRN-0110/0111 গ্রাহক আর সরবরাহকারী বসিয়েছিল (ইঞ্জিনের পক্ষ-পাহারার আগে), আর পরের মাইগ্রেশন সেই
 * ধরনগুলো খাতের সেটিংয়ে তুলে দেয়। এখন খাতের সেটিং যা-ই বলুক, ইঞ্জিন টাকার খাতে পক্ষ বসায় না, আর খাত-সম্পাদনা টাকার
 * খাতকে পক্ষ-ধরন দেয় না।
 */
final class NoPartySitsOnAMoneyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_party_on_a_cash_account_is_refused_even_when_the_setting_allows_it(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $cash = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        // ⓘ UB-র টিলের মতো — সেটিং ভুল করে গ্রাহক রাখে
        $cash->forceFill(['party_types' => ['customer']])->save();
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $sourceId = random_int(1, 9_999_999);

        try {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: $sourceId, trxDate: now()->toDateString(), lines: [
                ['account_id' => $cash->id, 'debit' => '500', 'party_type' => 'customer', 'party_id' => $customer->id],
                ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '500'],
            ]);
            $this->fail('⛔ নগদের খাতে গ্রাহকের নাম বসল — গ্রাহকের খাতা বদলাল, পাওনার খাত নয়');
        } catch (PostingException $e) {
            $this->assertStringContainsString('money account', $e->getMessage());
            $this->assertSame(__('core.posting.party_on_money', ['line' => 1, 'code' => $cash->code]), $e->forPeople());
        }

        $this->assertSame(0, LedgerEntry::query()->where('source_id', $sourceId)->where('source_type', 'journal_voucher')->count());

        // ⓘ খাত-সম্পাদনাও টাকার খাতকে পক্ষ-ধরন দেয় না
        $cash->forceFill(['party_types' => null])->save();

        try {
            app(AccountService::class)->update($cash->fresh(), ['party_types' => ['supplier']]);
            $this->fail('⛔ নগদের খাত পক্ষ-ধরন পেল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.money_holds_no_party'), $e->errors()['party_types'][0] ?? null);
        }

        $this->assertNull($cash->fresh()->party_types);
    }
}
