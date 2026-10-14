<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\CompanyProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\SystemAdmin\Http\Controllers\CompanyController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * কোম্পানির কোড বদলানোর অনুমতি দেখা হত চলতি কোম্পানির ভূমিকায় — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ কাগজ বেরিয়ে যাওয়ার পরে কোড বদলায় কেবল সেই কোম্পানির সুপার অ্যাডমিন। কিন্তু [[CompanyController::mayChangeCode()]] দেখত
 * `$user->roles` — চলতি কোম্পানির দল। ক-তে সুপার অ্যাডমিন মানুষটা খ-এর ছাপা-হয়ে-যাওয়া কোড বদলাতে পারতেন।
 */
final class TheCodeWasJudgedByTheWrongCompanysRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_super_admin_of_the_edited_company_may_change_its_printed_code(): void
    {
        $this->seed(DemoSeeder::class);
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($here->id, $here->defaultBranch()?->id);

        // ⓘ ডেমোর ভরসায় নয় — অন্য কোম্পানিটা এখানেই বানানো, মালিক তার সুপার অ্যাডমিন, আর একটা নম্বর বেরিয়ে গেছে
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $provisioner = app(CompanyProvisioner::class);
        $there = $provisioner->create(['code' => 'TCODE', 'name_en' => 'Code Test Co'],
            ['code' => 'TCD', 'name_en' => 'Code Test Branch', 'is_default' => true], CompanyProvisioner::currentBangladeshiYear());
        $provisioner->grantAccess($there, $owner);
        CompanyContext::forCompany((int) $there->id,
            fn () => NumberSeries::query()->orderBy('id')->firstOrFail()->forceFill(['next_number' => 5])->save());
        $this->assertFalse($there->fresh()->canChangeCode(), 'অন্য কোম্পানিটায় কাগজ বেরোয়নি — দাবিটা কিছু মাপছে না।');

        $local = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $here->id, fn () => $local->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));

        $may = function (User $user) use ($there): bool {
            $request = Request::create('/');
            $request->setUserResolver(fn () => $user->fresh());
            $method = new ReflectionMethod(CompanyController::class, 'mayChangeCode');

            return $method->invoke(app(CompanyController::class), $request, $there);
        };

        $this->assertFalse($may($local), 'এক কোম্পানির সুপার অ্যাডমিন অন্য কোম্পানির ছাপা কোড বদলাতে পারেন।');
        $this->assertTrue($may(User::query()->where('email', 'owner@abos.test')->firstOrFail()),
            'ঐ কোম্পানির সুপার অ্যাডমিন (মালিক) নিজেই পারেন না।');
    }
}
