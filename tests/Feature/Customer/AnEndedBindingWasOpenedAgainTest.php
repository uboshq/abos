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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ হাতবদলে শেষ হওয়া বাঁধন "শেষ" বোতামে আবার খুলত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[DealerBindingService::end()]])।
 *
 * ⚠️ `end()` আগের শেষ দিন দেখত না — হাতবদলে গতকাল শেষ হওয়া বাঁধনে এক মাস পরের দিন বসালে পুরনো বিক্রয়কর্মী সেই মাস পর্যন্ত
 * ডিলারকে আবার দেখতেন, নতুনজনের পাশাপাশি — "বিক্রয়কর্মী কেবল নিজের ডিলার" ভেঙে।
 */
final class AnEndedBindingWasOpenedAgainTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ended_binding_cannot_be_pushed_open_again(): void
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
        $service->handover($sales->id, $owner->id, [$dealer->id], now()->toDateString());

        $old = DealerBinding::query()->where('user_id', $sales->id)->where('customer_id', $dealer->id)->firstOrFail();
        $endedOn = $old->ends_on->toDateString();

        try {
            $service->end($old, now()->addDays(30)->toDateString());
        } catch (ValidationException) {
            // ⭐ এটাই চাওয়া
        }

        $this->assertSame($endedOn, $old->fresh()->ends_on->toDateString(), '⛔ শেষ হওয়া বাঁধন আবার খুলে এক মাস পরে গেল');
    }
}
