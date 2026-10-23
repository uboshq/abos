<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ যে কোম্পানি একজন মানুষকে হারাল, তার খাতায় কিছুই থাকত না — fe, ২৪ অক্টোবর ২০২৬।
 *
 * ⓘ লাইভে: ১০ অক্টোবর মালিক ADI-তে দাঁড়িয়ে নিজের ফর্ম save করলেন, AVA-র টিক বাদ পড়ল — AVA-র সদস্যপদ আর super admin মুছে গেল।
 * খাতার সারি (`companies_changed`, #384878) বসেছিল কেবল ADI-তে, আর ভূমিকা মোছার কোনো সারিই ছিল না; AVA-র খাতায় কিছু নেই।
 * ⭐ এখন হারানো কোম্পানির নিজের খাতায় `member_removed` (কর্তা, কোথায় দাঁড়িয়ে, আগে কী ছিল) আর `roles_removed` (রোলের নামসহ)।
 */
final class ACompanyThatLostAPersonKnowsWhoTookThemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $standing;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->standing = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($this->standing->id, $this->standing->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_unticked_from_another_company_its_own_book_names_the_person_the_doer_and_what_was_there(): void
    {
        $rahim = $this->makeUser([$this->standing->id, $this->other->id]);
        $this->assertTrue($rahim->companies()->whereKey($this->other->id)->exists(), '⛔ দৃশ্যটাই ভুল — মানুষটা দ্বিতীয় কোম্পানির সদস্যই নন।');

        CompanyContext::set($this->standing->id, $this->standing->defaultBranch()?->id);
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([$this->standing->id]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($rahim->companies()->whereKey($this->other->id)->exists(), '⛔ দৃশ্যটাই ভুল — টিক তোলার পরও সদস্যপদ রয়ে গেছে।');

        $member = $this->trails($rahim, 'member_removed');
        $this->assertCount(1, $member, '⛔ হারানো কোম্পানির খাতায় সদস্যপদ তুলে নেওয়ার সারি নেই — কেউ জানবে না কেন মানুষটা ঢুকতে পারেন না।');
        $this->assertSame((int) $this->other->id, (int) $member->first()->company_id, '⛔ সারিটা হারানো কোম্পানির খাতায় নয়, অন্য খাতায় বসেছে।');
        $this->assertSame((int) $this->owner->id, (int) $member->first()->user_id, '⛔ খাতায় কর্তার নাম নেই।');
        $this->assertStringContainsString('TDEPOT', (string) $member->first()->reason, '⛔ কর্তা কোন কোম্পানিতে দাঁড়িয়ে ছিলেন, খাতায় নেই।');

        $roles = $this->trails($rahim, 'roles_removed');
        $this->assertCount(1, $roles, '⛔ হারানো কোম্পানির রোল মোছার সারি নেই।');
        $this->assertSame((int) $this->other->id, (int) $roles->first()->company_id, '⛔ রোল মোছার সারি হারানো কোম্পানির খাতায় নয়।');
        $this->assertStringContainsString('salesman', (string) $roles->first()->reason, '⛔ কোন রোল গেল, খাতায় নাম নেই।');
    }

    public function test_a_company_the_person_never_belonged_to_gets_no_row(): void
    {
        $rahim = $this->makeUser([$this->standing->id]);

        CompanyContext::set($this->standing->id, $this->standing->defaultBranch()?->id);
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([$this->standing->id]))
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->trails($rahim, 'member_removed'), '⛔ যে কোম্পানির সদস্যই ছিলেন না, সেখানে "সদস্যপদ তুলে নেওয়া" লেখা হলো।');
        $this->assertCount(0, $this->trails($rahim, 'roles_removed'), '⛔ যেখানে রোলই ছিল না, সেখানে "রোল তুলে নেওয়া" লেখা হলো।');
    }

    /** @param  list<int>  $companies */
    private function makeUser(array $companies): User
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.user.store'), [
                'name' => 'Rahim Salesman',
                'email' => 'rahim@abos.test',
                'password' => 'a-long-enough-secret-9', 'password_confirmation' => 'a-long-enough-secret-9',
                'locale' => 'bn',
                'is_active' => '1',
                'roles' => ['salesman'],
                'companies' => $companies,
                'default_branch' => [$this->standing->id => $this->standing->defaultBranch()?->id],
            ])
            ->assertRedirect(route('system_admin.user.index'));

        return User::query()->where('email', 'rahim@abos.test')->firstOrFail();
    }

    /**
     * @param  list<int>  $companies
     * @return array<string, mixed>
     */
    private function editForm(array $companies): array
    {
        return [
            'name' => 'Rahim Salesman',
            'email' => 'rahim@abos.test',
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['salesman'],
            'companies' => $companies,
        ];
    }

    /**
     * ⓘ গোটা টেবিলে — [[AuditTrail]] চলতি কোম্পানি ধরে ছাঁকে, আর এখানে প্রশ্নটাই "কোন কোম্পানির খাতায়"।
     *
     * @return Collection<int, AuditTrail>
     */
    private function trails(User $user, string $action): Collection
    {
        return AuditTrail::query()->withoutGlobalScopes()
            ->where('auditable_type', User::class)->where('auditable_id', $user->id)
            ->where('action', $action)->orderBy('id')->get();
    }
}
