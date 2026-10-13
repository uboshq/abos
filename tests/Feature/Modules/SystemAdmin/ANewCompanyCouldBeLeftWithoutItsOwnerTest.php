<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * নতুন কোম্পানি মালিক ছাড়া থেকে যেতে পারত — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ [[CompanyProvisioner::create()]] নিজের লেনদেনে কমিট করত, আর মালিকের অধিকার (`grantAccess()`) চলত তার বাইরে। দ্বিতীয়টা ভাঙলে
 * কোম্পানি থাকত সদস্য আর সুপার অ্যাডমিন ছাড়া — আর [[WholeDatabaseAccess]] সব কোম্পানি গোনে বলে মালিক সাথে সাথে কোম্পানি বানানো
 * আর পুরো ব্যাকআপ হারাতেন। ⓘ প্রভিশনার `final`, তাই ভাঙা ঘটানো যায় না; দাবি মাপে কাঠামো: কোম্পানির সারি থেকে মালিকের ভূমিকা পর্যন্ত
 * লেনদেন একবারও শুরুর স্তরে নামে না — অর্থাৎ মাঝে কোনো কমিট নেই, আর ভাঙলে সব একসাথে ফেরে।
 */
final class ANewCompanyCouldBeLeftWithoutItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_company_and_its_owner_role_are_written_in_one_transaction(): void
    {
        $this->seed(DemoSeeder::class);
        $here = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($here->id, $here->defaultBranch()?->id);

        $base = DB::transactionLevel(); // ⓘ RefreshDatabase নিজেই একটা খোলে
        $seen = [];
        DB::listen(function (QueryExecuted $q) use (&$seen): void {
            $seen[] = ['sql' => strtolower($q->sql), 'level' => DB::transactionLevel()];
        });

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail())
            ->post(route('system_admin.company.store'), [
                'code' => 'ONETX', 'name_en' => 'One transaction Ltd', 'branch_code' => 'ONETX-HO', 'branch_name_en' => 'Head office',
                'year_name' => '2026-27', 'year_starts_on' => '2026-07-01', 'year_ends_on' => '2027-06-30',
            ])->assertSessionHasNoErrors();

        $company = collect($seen)->search(fn ($q) => str_starts_with($q['sql'], 'insert into `companies`'));
        $owner = collect($seen)->search(fn ($q) => str_starts_with($q['sql'], 'insert into `model_has_roles`'));

        $this->assertNotFalse($company, 'পূর্বশর্ত: কোম্পানির সারি বসার কোয়েরি চোখে পড়েনি।');
        $this->assertNotFalse($owner, 'পূর্বশর্ত: মালিকের ভূমিকা বসার কোয়েরি চোখে পড়েনি।');

        $lowest = collect(array_slice($seen, $company, $owner - $company + 1))->min('level');

        $this->assertGreaterThan($base, $lowest,
            'কোম্পানি কমিট হয়ে গেছে মালিকের ভূমিকার আগেই — দ্বিতীয়টা ভাঙলে কোম্পানি মালিকহীন থেকে যেত।');
    }
}
