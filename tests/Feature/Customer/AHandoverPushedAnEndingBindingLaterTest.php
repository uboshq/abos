<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Services\DealerBindingService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ হাতবদল শেষ-দিন-বসানো বাঁধনকে পরে ঠেলত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[DealerBindingService::handover()]])।
 *
 * ⚠️ পাঁচ দিন পরে শেষ হওয়ার কথা এমন বাঁধন বিশ দিন পরের হাতবদলে উনিশ দিন পরে শেষ হত — পুরনো বিক্রয়কর্মী আরও চৌদ্দ দিন
 * ডিলারকে দেখতেন, নতুনজনের পাশাপাশি। [[AnEndedBindingWasOpenedAgainTest]]-এর একই নিয়ম, অন্য দরজা দিয়ে।
 */
final class AHandoverPushedAnEndingBindingLaterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_handover_never_moves_an_end_day_later(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $dealer = Customer::acrossDealers()->orderBy('id')->firstOrFail();
        $service = app(DealerBindingService::class);
        DealerBinding::query()->where('customer_id', $dealer->id)->delete();

        $service->bind($sales->id, [$dealer->id], now()->subDays(10)->toDateString());
        $old = DealerBinding::query()->where('user_id', $sales->id)->where('customer_id', $dealer->id)->firstOrFail();
        $service->end($old, now()->addDays(5)->toDateString());

        $service->handover($sales->id, $owner->id, [$dealer->id], now()->addDays(20)->toDateString());

        $this->assertSame(now()->addDays(5)->toDateString(), $old->fresh()->ends_on->toDateString(),
            '⛔ হাতবদল শেষ দিনটা পরে ঠেলে পুরনো বিক্রয়কর্মীকে আরও দিন দেখাল');
        $this->assertTrue(DealerBinding::query()->where('user_id', $owner->id)->where('customer_id', $dealer->id)
            ->whereDate('starts_on', now()->addDays(20)->toDateString())->exists(), 'নতুনজনের বাঁধন হাতবদলের দিনে শুরু');
    }
}
