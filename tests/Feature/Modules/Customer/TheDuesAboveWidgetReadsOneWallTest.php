<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Dashboard\CustomerWidgets;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerMetrics;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ "বকেয়া সীমার উপরে" — এক দেয়াল, এক কোয়েরি (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, গ্রাহক ১৪; [[CustomerWidgets]])।
 *
 * ⓘ ময়মনসিংহের একটা দোকানের বাকি ময়মনসিংহে ১১,১১১ আর নেত্রকোনায় ৭৭,৭৭৭। হেডারে "ময়মনসিংহ" বাছা থাকলে আগে ঘরটা গ্রাহক বাছত তাঁর
 * নিজের শাখা ধরে কিন্তু বকেয়া পড়ত গোটা কোম্পানির খাতা থেকে — ৮৮,৮৮৮; আর প্রতিটা গ্রাহক মেমরিতে তুলে আলাদা কোয়েরি।
 */
final class TheDuesAboveWidgetReadsOneWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_widget_counts_the_viewed_branchs_ledger_in_one_pass(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        $ntk = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'NTK')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        // ⓘ খালি খাতা থেকে — কেবল এই দোকান
        DB::table('ledger_entries')->where('party_type', Customer::drillSourceType())->delete();

        $shop = Customer::query()->create(['company_id' => $company->id, 'code' => 'WID-1', 'name_en' => 'Two Branch Shop', 'branch_id' => $mms->id, 'is_active' => true]);
        $receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $income = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');

        foreach ([[$mms, '11111'], [$ntk, '77777']] as [$branch, $amount]) {
            app(PostingEngine::class)->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
                ['account_id' => $receivable, 'debit' => $amount, 'party_type' => Customer::drillSourceType(), 'party_id' => $shop->id],
                ['account_id' => $income, 'credit' => $amount],
            ], branchId: $branch->id);
        }

        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $mms->id])->save();
        $this->actingAs($owner->fresh());

        DB::enableQueryLog();
        $widget = (new \ReflectionMethod(CustomerWidgets::class, 'receivableAbove'))->invoke(null, '1000');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(Money::format('11111'), $widget->value, '⛔ দেখার শাখার বাইরের বকেয়াও ঘরে মিশল');
        $this->assertSame(Money::format(app(CustomerMetrics::class)->dues($owner->fresh(), now()->toDateString())['amount']), $widget->value,
            '⛔ ঘর আর হোমের "বাজারে বকেয়া" দুই কথা বলে');
        $this->assertLessThanOrEqual(5, $queries, '⛔ প্রতিটা গ্রাহকের জন্য আলাদা কোয়েরি: '.$queries);
    }
}
