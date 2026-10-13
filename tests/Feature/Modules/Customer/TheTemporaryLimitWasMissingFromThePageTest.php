<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ গ্রাহকের পাতা আর সারাংশ চলতি অস্থায়ী সীমা দেখাত না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; summary.blade, show.blade)।
 *
 * ⚠️ দেয়াল আর "আর কত দেওয়া যায়" অস্থায়ী সীমা মানে ([[Customer::effectiveCreditLimit()]]), কিন্তু পাতা দেখত কেবল মূল সীমা: মূল ০
 * আর অস্থায়ী ৫০ হাজারে সারাংশ বলত "সীমা নেই", আর গ্রাহকের পাতায় সীমার ঘরটাই উঠত না — কাউন্টার অথচ ৫০ হাজার পর্যন্ত বাকি দিত।
 */
final class TheTemporaryLimitWasMissingFromThePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_pages_show_the_live_temporary_limit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
        app(SettingsService::class)->set('customer.credit_limit_enabled', true);

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'TEMP-X',
            'name_en' => 'Season Shop', 'credit_limit' => '0', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->addDays(10)->toDateString()])->save();

        $shown = Money::format('50000');

        $this->actingAs($owner)->get(route('customer.summary', $customer))->assertOk()
            ->assertDontSee('data-figure="available-credit-none"', false)
            ->assertSee('data-figure="credit-limit">'.$shown, false);

        $this->get(route('customer.show', $customer))->assertOk()
            ->assertSee('data-figure="credit-limit">'.$shown, false);
    }
}
