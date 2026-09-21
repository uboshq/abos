<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * দুইটা কোম্পানি টিক দেওয়া হয়েছিল, ভূমিকা বসেছিল একটাতে।
 *
 * ── ⓘ মালিকের অভিযোগ, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"abu kawser manage role er onumoti dewa ache, tar poreo keno dekhabe
 * na"* — ফর্মে দুইটা কোম্পানি (DEM, TCL) আর দুইটা ভূমিকা টিক দেওয়া।
 * ⛔ লাইভে মেপে দেখা গেল সারি দুইটাই কেবল DEM-এ, আর তিনি দাঁড়িয়ে
 * TCL-এ — তাই পর্দা ফাঁকা।
 *
 * ── ⚠️ কারণ ─────────────────────────────────────────────────────────
 * অনুমতির ব্যবস্থায় `teams` চালু, আর দলটা কোম্পানি। তাই একবারের
 * `syncRoles()` লিখত কেবল **প্রশাসক তখন যে কোম্পানিতে বসে আছেন** তার
 * নামে — ফর্মে কয়টা কোম্পানি টিক দেওয়া হলো তাতে কিছু যায়-আসত না।
 *
 * ⓘ আর পর্দায় লেখা কথাটা এর উল্টো: *"দুই কোম্পানিতে একই অধিকার
 * থাকবে"*। ⭐ এখন কথাটা সত্যি।
 */
final class TwoCompaniesWereTickedAndTheRoleLandedInOneTest extends TestCase
{
    use RefreshDatabase;

    private Company $here;

    private Company $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $companies = Company::query()->orderBy('id')->take(2)->get();

        $this->assertCount(2, $companies, 'ডেমোতে দুইটা কোম্পানিও নেই — পরীক্ষাটা কিছুই দেখছে না।');

        [$this->here, $this->there] = [$companies[0], $companies[1]];

        // ⓘ প্রশাসক বসে আছেন প্রথম কোম্পানিতে — ঠিক যেভাবে মালিক বসেছিলেন
        CompanyContext::set($this->here->id, $this->here->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * ⭐ দুইটা কোম্পানি টিক দিলে ভূমিকা দুইটাতেই বসে।
     */
    public function test_a_role_lands_in_every_company_that_was_ticked(): void
    {
        $kawser = $this->aUser();

        $this->save($kawser, ['Warehouse'], [$this->here->id, $this->there->id]);

        foreach ([$this->here, $this->there] as $company) {
            $this->assertTrue(
                DB::table('model_has_roles')
                    ->where('model_id', $kawser->id)
                    ->where('company_id', $company->id)
                    ->exists(),
                "{$company->code}-এ ভূমিকাটা বসেনি।",
            );
        }
    }

    /**
     * ⭐ আর দ্বিতীয় কোম্পানিতে দাঁড়ালেও মেনু আসে — এটাই মালিক যা দেখতে চান।
     *
     * ⚠️ কেবল সারি গোনা যথেষ্ট নয়: সারি বসেও অনুমতি না পৌঁছাতে পারে
     * (ভূমিকাটা ঐ কোম্পানিতে খালি হলে)। ⓘ তাই দাবিটা পর্দার দিক থেকে।
     */
    public function test_the_menu_is_there_when_he_stands_in_the_other_company(): void
    {
        $kawser = $this->aUser();

        $this->save($kawser, ['Warehouse'], [$this->here->id, $this->there->id]);

        CompanyContext::set($this->there->id, $this->there->defaultBranch()?->id);

        $fresh = User::query()->findOrFail($kawser->id);

        $this->assertNotSame(
            0,
            count(app(MenuBuilder::class)->forUser($fresh)),
            'দ্বিতীয় কোম্পানিতে দাঁড়ালে মেনু এখনো খালি।',
        );
    }

    /**
     * ⛔ যে কোম্পানির টিক তুলে নেওয়া হলো, সেখানে ভূমিকাও থাকে না।
     *
     * ⚠️ নাহলে কাউকে বের করে দিয়ে পরে আবার ঢোকালে পুরনো ক্ষমতা নীরবে
     * ফিরে আসত।
     */
    public function test_unticking_a_company_takes_the_role_with_it(): void
    {
        $kawser = $this->aUser();

        $this->save($kawser, ['Warehouse'], [$this->here->id, $this->there->id]);
        $this->save($kawser, ['Warehouse'], [$this->here->id]);

        $this->assertFalse(
            DB::table('model_has_roles')
                ->where('model_id', $kawser->id)
                ->where('company_id', $this->there->id)
                ->exists(),
            'টিক তুলে নেওয়ার পরেও ভূমিকাটা রয়ে গেছে।',
        );
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     */
    private function save(User $user, array $roles, array $companyIds): void
    {
        $this->put(route('system_admin.user.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => $roles,
            'companies' => $companyIds,
        ])->assertSessionHasNoErrors();
    }

    private function aUser(): User
    {
        $user = User::query()->create([
            'name' => 'Abu Kawser',
            'email' => 'kawser@abos.test',
            'password' => bcrypt('secret-for-a-test'),
            'is_active' => true,
            'locale' => 'bn',
        ]);

        // ⓘ প্রশাসকের কোম্পানিতেই — নাহলে সম্পাদনার দরজাই খোলে না
        $user->companies()->sync([$this->here->id]);

        return $user->fresh();
    }
}
