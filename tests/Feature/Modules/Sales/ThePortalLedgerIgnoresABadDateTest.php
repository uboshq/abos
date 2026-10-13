<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ পোর্টালের খতিয়ানে ভুল তারিখ ডিফল্টে ফেরে, ৫০০ বা অর্থহীন সময়ে নয় — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা
 * ([[PortalController::range()]])।
 *
 * ⓘ বিপজ্জনক ইনপুট: `from[]=x` (অ্যারে — আগে `(string)`-এ ৫০০), `from=abc` আর `to=2026-02-31` (আগে সরাসরি কোয়েরিতে)।
 */
final class ThePortalLedgerIgnoresABadDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bad_from_or_to_falls_back_to_the_default_and_a_good_one_is_kept(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['portal_enabled' => true, 'portal_password' => 'dealer-pass-1'])->save();
        $this->actingAs($customer->fresh(), 'portal');

        $default = $this->get(route('sales.portal.ledger'))->assertOk()->viewData('from');
        $today = now()->toDateString();

        $this->get(route('sales.portal.ledger').'?from[]=x&to[]=y')->assertOk()
            ->assertViewHas('from', $default)->assertViewHas('to', $today);

        $this->get(route('sales.portal.ledger', ['from' => 'abc', 'to' => '2026-02-31']))->assertOk()
            ->assertViewHas('from', $default)->assertViewHas('to', $today);

        $this->get(route('sales.portal.ledger', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()
            ->assertViewHas('from', '2026-08-01')->assertViewHas('to', '2026-08-31');
    }
}
