<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Sync\CustomerDueSync;
use App\Modules\Customer\Sync\CustomerSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ মেয়াদ পেরোনো অস্থায়ী সীমা ফোনে থেকে যেত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerSync::temporaryLimitEndedSince()]])।
 *
 * ⚠️ অস্থায়ী সীমা শেষ হলে গ্রাহকের সারিতে কিছুই বদলায় না, তাই জলচিহ্নের পরের টানায় সারিটা আসত না — সেলসম্যান মেয়াদ পেরোনো
 * ৫০ হাজার দেখে অর্ডার নিতেন, আসল সীমা ০। ⭐ এখন গ্রাহক আর বকেয়া দুই টানাতেই সারিটা আবার যায়, নতুন সীমাসহ।
 */
final class AnEndedTemporaryLimitStayedOnThePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_limit_that_ended_since_the_last_pull_goes_to_the_phone_again(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $customer = Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => 'ENDED-X',
            'name_en' => 'Season Over Shop', 'credit_limit' => '0', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $customer->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->subDay()->toDateString()])->save();
        // ⓘ সারিটা শেষবার বদলেছিল তিন দিন আগে — শেষ টানা পরশু, মেয়াদ শেষ হলো কাল রাতে
        DB::table('customers')->where('id', $customer->id)->update(['updated_at' => now()->subDays(3)]);
        $since = now()->subDays(2)->startOfDay()->addHours(10);

        foreach ([CustomerSync::class, CustomerDueSync::class] as $sync) {
            $batch = app($sync)->pull($owner, $since, 500);
            $mine = collect($batch->records)->first(fn ($r) => $r->entityId === (string) $customer->public_id);

            $this->assertNotNull($mine, "⛔ {$sync}: মেয়াদ পেরোনো সীমার গ্রাহক ফোনে আবার গেল না");
        }
    }
}
