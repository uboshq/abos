<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * মালিক নিজের ফর্মে একটা টিক রেখে বাকি সব কোম্পানির মালিকানা হারাতেন — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ মালিকের নিয়মগুলো কেবল চলতি কোম্পানি দেখত। ক-তে দাঁড়িয়ে নিজের ফর্মে কেবল খ টিক দিলে ক আর গ-র সদস্যপদ আর সুপার অ্যাডমিন
 * মুছে যেত — কোম্পানিগুলো মালিকহীন, আর অ্যাপের ভেতর থেকে ফেরানোর পথ নেই (হস্তান্তরের জন্যও একজন মালিক লাগে)।
 * ⭐ মালিকের নিয়ম: মালিক প্রতিটা কোম্পানির সুপার অ্যাডমিন; কোম্পানি ছাড়ার পথ মালিকানা হস্তান্তর।
 */
final class TheOwnerUntickedHimselfOutOfHisCompaniesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unticking_a_company_where_the_person_is_owner_is_refused_and_nothing_changes(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $owned = fn (): array => DB::table('model_has_roles as mhr')->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', $owner->getMorphClass())->where('mhr.model_id', $owner->id)
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)->orderBy('mhr.company_id')
            ->pluck('mhr.company_id')->map(fn ($id) => (int) $id)->all();

        $before = $owned();
        $this->assertGreaterThan(1, count($before), 'পূর্বশর্ত: মালিক একাধিক কোম্পানির সুপার অ্যাডমিন নন — দাবিটা কিছু মাপছে না।');

        $this->actingAs($owner)->put(route('system_admin.user.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'locale' => 'bn', 'is_active' => '1',
            'roles' => [PermissionSyncer::SUPER_ADMIN_ROLE], 'companies' => [$company->id],
        ])->assertSessionHasErrors('companies');

        $this->assertSame($before, $owned(), 'মালিক অন্য কোম্পানির মালিকানা হারালেন — সেগুলো মালিকহীন।');
    }
}
