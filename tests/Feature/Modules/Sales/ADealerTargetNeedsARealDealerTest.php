<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CustomerTarget;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ডিলারের লক্ষ্য কেবল এই কোম্পানির না-মোছা ডিলারে — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা
 * ([[CustomerTargetService::setOne()]])।
 *
 * ⓘ বিপজ্জনক ইনপুট: `target[<মুছে ফেলা ডিলারের id>][amount]`। পাতার চাবিগুলো সরাসরি গ্রাহকের id, আর কখনো যাচাই
 * হত না — লক্ষ্য বসে যেত এমন ডিলারের নামে যাকে কোনো তালিকা আর দেখায় না (অন্য কোম্পানির id-ও একইভাবে চলত)।
 */
final class ADealerTargetNeedsARealDealerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_target_for_a_deleted_dealer_is_refused_and_a_live_one_is_kept(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $live = Customer::query()->create(['code' => 'TGT-LIVE', 'name_en' => 'Live Dealer', 'name_bn' => 'Live Dealer', 'is_active' => true]);
        $gone = Customer::query()->create(['code' => 'TGT-GONE', 'name_en' => 'Gone Dealer', 'name_bn' => 'Gone Dealer', 'is_active' => true]);
        $gone->delete();

        $this->actingAs($owner)->post(route('sales.customer_target.store'), [
            'month' => now()->startOfMonth()->toDateString(),
            'target' => [
                $live->id => ['amount' => '1000'],
                $gone->id => ['amount' => '5000'],
            ],
        ])->assertSessionHasErrors('target');

        $this->assertSame(0, CustomerTarget::query()->where('customer_id', $gone->id)->count(),
            '⛔ মুছে ফেলা ডিলারের নামে লক্ষ্য বসে গেল।');
        $this->assertSame(0, CustomerTarget::query()->where('customer_id', $live->id)->count(),
            'একটা ভুল সারি থাকলে গোটা মাস ফেরে — অর্ধেক বসানো নয়।');

        $this->actingAs($owner)->post(route('sales.customer_target.store'), [
            'month' => now()->startOfMonth()->toDateString(),
            'target' => [$live->id => ['amount' => '1000']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, CustomerTarget::query()->where('customer_id', $live->id)->count());
    }
}
