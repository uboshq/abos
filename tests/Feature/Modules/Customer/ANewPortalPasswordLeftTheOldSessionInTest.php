<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের নতুন পাসওয়ার্ড পুরনো সেশন বের করত না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[EnsurePortalStillOpen]])।
 *
 * ⚠️ পাসওয়ার্ড ফাঁস → মালিক নতুনটা বসালেন, পর্দা বলল "পুরনোটা আর চলবে না" — অথচ যিনি আগেই ঢুকে আছেন তিনি খাতা, বকেয়া দেখতেন
 * আর আদেশ দিতেন। ⭐ এখন পরের অনুরোধেই লগইনে ফেরত।
 */
final class ANewPortalPasswordLeftTheOldSessionInTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_password_sends_the_open_session_out(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'PASS-1',
            'name_en' => 'Leaked Password Shop', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => Hash::make('old-pass-2026')])->save();

        $this->post(route('sales.portal.login.attempt'), ['code' => 'PASS-1', 'password' => 'old-pass-2026'])
            ->assertRedirect(route('sales.portal.home'));
        $this->get(route('sales.portal.ledger'))->assertOk();

        Customer::query()->whereKey($customer->id)->update(['portal_password' => Hash::make('new-pass-2026')]);
        // ⓘ পরের অনুরোধ গার্ড নতুন করে পড়ে — সত্যিকারের ব্রাউজারের মতো
        $this->app['auth']->forgetGuards();

        $this->get(route('sales.portal.ledger'))->assertRedirect(route('sales.portal.login'));
        $this->assertFalse(Auth::guard('portal')->check(), '⛔ নতুন পাসওয়ার্ডের পরেও পুরনো সেশন খোলা');
    }
}
