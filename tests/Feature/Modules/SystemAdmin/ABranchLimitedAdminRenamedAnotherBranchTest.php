<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\SystemAdmin\Http\Controllers\BranchController;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * শাখা-সীমিত প্রশাসক অন্য শাখার নাম বদলাতেন, বন্ধ করতেন, মুছতেন — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ শাখার দরজা ([[BranchController::yourBranch()]]) দেখত কেবল কোম্পানির চাবি (`company.manage`); শাখা-নাগাল কখনো নয়।
 * ⭐ মালিক: "প্রতিটা শাখা পুরোপুরি আলাদা" (১ অক্টোবর ২০২৬)। ⓘ দাবি দরজার নিজের পাহারাটা ডাকে — প্রশ্নটা নাগালের, চাবির নয়।
 */
final class ABranchLimitedAdminRenamedAnotherBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_outside_the_admins_reach_is_not_found_and_his_own_is(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        CompanyContext::set($company->id, $home->id);

        $other = Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? Branch::query()->create(['company_id' => $company->id, 'code' => 'BRX', 'name_en' => 'Other branch', 'is_active' => true]);

        $admin = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $company->id, fn () => $admin->givePermissionTo('system_admin.company.manage'));
        UserDataScope::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id]);
        app(DataScope::class)->forget();

        $door = new ReflectionMethod(BranchController::class, 'yourBranch');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin->fresh());

        $this->assertSame((int) $home->id, (int) $door->invoke(app(BranchController::class), $request, (int) $home->id)->id,
            'নিজের শাখাতেই ঢোকা গেল না — দাবিটা কিছু মাপছে না।');

        $this->expectException(NotFoundHttpException::class);
        $door->invoke(app(BranchController::class), $request, (int) $other->id);
    }
}
