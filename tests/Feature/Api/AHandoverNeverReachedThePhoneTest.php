<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\DealerScope;
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
 * ⛔ ডিলারের হাতবদল ফোনে পৌঁছাত না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SyncService::dealerKey()]])।
 *
 * ⚠️ বাঁধন বা হাতবদল কেবল `dealer_bindings`-এ লেখে, ডিলারের সারি বদলায় না — তাই জলচিহ্নের পরের টানায় নতুন বিক্রয়কর্মীর ফোনে
 * ডিলার আসত না, আর পুরনোজনের ফোনে ডিলার আর তার বকেয়া থেকে যেত ("বিক্রয়কর্মী কেবল নিজের ডিলার" ভেঙে)। ⭐ এখন ডিলারের তালিকা
 * বদলালে দেখার চাবি বদলায় — যন্ত্র গোড়া থেকে টানে, ক্যাশ মুছে।
 */
final class AHandoverNeverReachedThePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_binding_changes_the_salesmans_view_key(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $scope = app(DealerScope::class);
        $this->assertTrue($scope->walled($sales), 'দৃশ্যটাই বানানো যায়নি — ডেমোর বিক্রয়কর্মী দেয়ালের ভিতরে নন');

        $sync = app(SyncService::class);
        $before = $sync->viewKey($sales);

        $mine = DealerBinding::query()->where('user_id', $sales->id)->pluck('customer_id')->all();
        $dealer = Customer::acrossDealers()->whereNotIn('id', $mine ?: [0])->orderBy('id')->firstOrFail();
        app(DealerBindingService::class)->bind($sales->id, [$dealer->id], now()->toDateString());
        $scope->forget();

        $this->assertNotSame($before, $sync->viewKey($sales), '⛔ নতুন ডিলার বাঁধা হল, অথচ ফোনের দেখার চাবি একই — ডিলার ফোনে আসবে না');
    }
}
