<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\CompanyProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * নতুন কোম্পানি — খোলা মানে চালু হওয়া, শুধু একটা সারি বসা নয়।
 *
 * ── এই ফাইলটা কেন আছে ───────────────────────────────────────────────
 * কোম্পানি এতদিন বসত কেবল সিডারে, অর্থাৎ ABOS চালু করতে কাউকে কমান্ড
 * লাইনে যেতে হত। পরীক্ষায় ধাপ ১ ঠিক এখানেই আটকেছিল।
 *
 * পর্দাটা লেখার পর আসল ঝুঁকি একটাই: কোম্পানিটা তৈরি হবে, কিন্তু
 * **কাজ করবে না**। সিরিজ, ছক, একক — এর একটাও বাদ পড়লে সব দেখতে ঠিক
 * থাকে, আর ধরা পড়ে অনেক পরে, প্রথম বিল লিখতে গিয়ে।
 *
 * তাই এখানকার পরীক্ষাগুলো "সারিটা বসেছে কি না" নিয়ে নয় — "এই কোম্পানিতে
 * সত্যিই কাজ করা যায় কি না" নিয়ে।
 */
class CompanySetupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    /**
     * পর্দা দিয়ে খোলা কোম্পানিতে সব কিছু থাকে।
     *
     * এটাই এই ফাইলের মূল পরীক্ষা। বাকিগুলো এর প্রান্ত।
     */
    public function test_a_company_made_from_the_screen_is_ready_to_work_in(): void
    {
        $this->post(route('system_admin.company.store'), [
            'code' => 'RAHIM',
            'name_en' => 'Rahim Distribution',
            'name_bn' => 'রহিম ডিস্ট্রিবিউশন',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertRedirect();

        $company = Company::query()->where('code', 'RAHIM')->firstOrFail();

        CompanyContext::forCompany($company->id, function () {
            $this->assertSame(1, Branch::query()->count(), 'শাখা ছাড়া লেনদেন কোথায় বসবে তা বলা যায় না');
            $this->assertSame(1, FinancialYear::query()->where('is_current', true)->count());

            // সিরিজ না থাকলে প্রথম ডকুমেন্টের নম্বরই বসে না
            $this->assertGreaterThan(0, DB::table('number_series')->count());

            // ছক না থাকলে কোনো দাখিলার খাত নেই
            $this->assertGreaterThan(0, Account::query()->count());

            // মাস্টার তালিকা না থাকলে পণ্যই বানানো যায় না
            $this->assertGreaterThan(0, Unit::query()->count());
            $this->assertGreaterThan(0, Tax::query()->count());
            $this->assertGreaterThan(0, PaymentTerm::query()->count());
            $this->assertGreaterThan(0, ReasonCode::query()->count());
        });
    }

    /**
     * যিনি বানালেন, তিনি ঢুকতে পারেন।
     *
     * এটা না হলে নতুন কোম্পানিটা তালিকায় দেখা যেত কিন্তু সুইচারে আসত
     * না — আর কেন আসছে না তার কোনো ব্যাখ্যাও পর্দায় থাকত না।
     */
    public function test_whoever_creates_a_company_can_switch_into_it(): void
    {
        $this->post(route('system_admin.company.store'), [
            'code' => 'NEWCO',
            'name_en' => 'New Company',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertRedirect();

        $company = Company::query()->where('code', 'NEWCO')->firstOrFail();

        $this->assertTrue(
            $this->owner->fresh()->companies->contains('id', $company->id),
            'যিনি কোম্পানিটা বানালেন তিনিই ওখানে ঢুকতে পারেন না।',
        );
    }

    /**
     * সিডার আর পর্দা — একই রেসিপি।
     *
     * ── কেন এটা পরীক্ষা করা হয় ─────────────────────────────────────
     * রেসিপিটা আগে দুই জায়গায় ছিল। একদিন একটায় নতুন ধাপ যোগ হত আর
     * অন্যটায় না, আর তখন পর্দা দিয়ে বানানো কোম্পানিগুলো নীরবে
     * অসম্পূর্ণ থাকত — অথচ ডেমোতে সব ঠিক দেখাত, তাই কেউ ধরত না।
     *
     * দুইটার খাতের সংখ্যা মিলিয়ে দেখলেই ফাঁকটা ধরা পড়ে।
     */
    public function test_the_seeder_and_the_screen_build_the_same_company(): void
    {
        $demo = Company::query()->where('code', 'FMART')->firstOrFail();

        $counts = fn (Company $c) => CompanyContext::forCompany($c->id, fn () => [
            'accounts' => Account::query()->count(),
            'units' => Unit::query()->count(),
            'taxes' => Tax::query()->count(),
            'terms' => PaymentTerm::query()->count(),
            'reasons' => ReasonCode::query()->count(),
            'series' => DB::table('number_series')->count(),
        ]);

        $this->post(route('system_admin.company.store'), [
            'code' => 'SAME',
            'name_en' => 'Same Recipe Ltd',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertRedirect();

        $made = Company::query()->where('code', 'SAME')->firstOrFail();

        $this->assertSame($counts($demo), $counts($made),
            'সিডার আর পর্দা দুই রকম কোম্পানি বানাচ্ছে — রেসিপি আবার দুই জায়গায় চলে গেছে।');
    }

    /** একটা শাখা পরে যোগ করা যায়। */
    public function test_a_branch_can_be_added_afterwards(): void
    {
        $company = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->post(route('system_admin.company.branch.store', $company->id), [
            'code' => 'CTG',
            'name_en' => 'Chattogram',
            'name_bn' => 'চট্টগ্রাম',
        ])->assertRedirect();

        CompanyContext::forCompany($company->id, function () {
            $this->assertTrue(Branch::query()->where('code', 'CTG')->exists());
        });
    }

    /**
     * যে কোম্পানিতে এখন বসে আছি সেটাই বন্ধ করা যায় না।
     *
     * করলে ব্যবহারকারী ঠিক ওই মুহূর্তে এমন একটা কোম্পানিতে থাকতেন যেটা
     * আর নেই, আর পরের ক্লিকেই সব পর্দা ভাঙত।
     */
    public function test_the_company_you_are_working_in_cannot_be_switched_off(): void
    {
        $current = Company::query()->where('code', 'TDEPOT')->firstOrFail();

        $this->post(route('system_admin.company.toggle', $current->id))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($current->fresh()->is_active);
    }

    /** একই কোড দুইবার বসে না — কোডটাই কাগজে ছাপা হয়। */
    public function test_two_companies_cannot_share_a_code(): void
    {
        $this->post(route('system_admin.company.store'), [
            'code' => 'TDEPOT',
            'name_en' => 'Another Depot',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertSessionHasErrors('code');
    }

    /**
     * অর্থবছর জুলাই থেকে জুন — বাংলাদেশের নিয়মে।
     *
     * ফর্মে তারিখ দুইটা আগে থেকে বসানো থাকে যাতে কেউ ভুল করে ক্যালেন্ডার
     * বছর না বসায়; বসালে প্রতিটা রিপোর্টের সময়সীমা কর বিভাগের সাথে
     * অমিল হত।
     */
    public function test_the_suggested_year_runs_july_to_june(): void
    {
        $inJanuary = CompanyProvisioner::currentBangladeshiYear(Carbon::create(2027, 1, 15));
        $inAugust = CompanyProvisioner::currentBangladeshiYear(Carbon::create(2026, 8, 15));

        // জানুয়ারি ২০২৭ এখনো ২০২৬-২০২৭ বছরের ভেতরে
        $this->assertSame('2026-2027', $inJanuary['name']);
        $this->assertSame('2026-07-01', $inJanuary['starts_on']);
        $this->assertSame('2027-06-30', $inJanuary['ends_on']);

        // আগস্ট ২০২৬ নতুন বছরের শুরুতে, একই বছর
        $this->assertSame('2026-2027', $inAugust['name']);
    }

    /** এই পর্দাটা প্রতিষ্ঠানের মালিকের — অন্য কারো নয়। */
    public function test_a_salesman_cannot_open_the_company_screen(): void
    {
        $role = Role::findOrCreate('shop-hand');
        $role->syncPermissions(Permission::query()->where('name', 'like', 'sales.%')->get());

        $hand = User::query()->create([
            'name' => 'দোকানের লোক',
            'email' => 'hand@abos.test',
            'password' => bcrypt('password'),
            'current_company_id' => CompanyContext::id(),
        ]);

        $hand->assignRole($role);

        $this->actingAs($hand)
            ->get(route('system_admin.company.index'))
            ->assertForbidden();
    }
}
