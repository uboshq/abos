<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকহীন কোম্পানিতে মালিক ফেরেন — সদস্যপদ, প্রধান শাখা আর সেই কোম্পানির নিজের super_admin (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ লাইভে: ১০ অক্টোবর মালিক ADI-তে দাঁড়িয়ে নিজের ফর্ম save করলেন, AVA-র টিক বাদ পড়ল — AVA-র সদস্যপদ আর super_admin মুছে গেল
 * (audit #384878); AVA-তে আজ কেউ super_admin নন, তাই অ্যাপের ভেতর থেকে ফেরানোর পথ নেই। এখানে FMART দিয়ে সেই ছবিটা বানানো।
 *
 * দাবি: `--dry` কিছু লেখে না কিন্তু কী বসত আর কোন শাখা বলে; আসল দৌড়ে সদস্যপদ (প্রধান শাখা) আর super_admin, খাতায় owner_restored
 * সেই কোম্পানিতেই; দ্বিতীয়বার কিছু বদলায় না; মালিক-তালিকার বাইরের মানুষ, মালিক-আছে কোম্পানি আর প্রধান-শাখা-হীন কোম্পানি — থামে।
 */
final class AnOwnerlessCompanyGetsItsOwnerBackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'FMART')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        config(['abos.owner_emails' => ['owner@abos.test']]);

        // ⓘ AVA-র ছবি: মালিকের সদস্যপদ নেই, আর কোম্পানিতে কেউ super_admin নন
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->delete();
        DB::table('model_has_roles')->where('company_id', $this->company->id)->whereIn('role_id', $this->superRole()->pluck('id'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNotNull($this->main(), '⛔ দৃশ্যটাই ভুল — FMART-এর প্রধান শাখা নেই।');
    }

    public function test_dry_names_what_would_be_set_and_writes_nothing(): void
    {
        [$code, $out] = $this->restore(['--dry' => true]);
        $this->assertSame(0, $code, '⛔ --dry ব্যর্থ হলো।');
        $this->assertStringContainsString('FMART · '.$this->owner->id.' '.$this->owner->name.' · নতুন সদস্যপদ · ডিফল্ট শাখা '.$this->main()->code.' · super_admin দেওয়া · (বসত', $out,
            '⛔ --dry বলে না কী বসত আর কোন শাখা।');

        $this->assertFalse($this->isMember(), '⛔ --dry সদস্যপদ বসিয়ে ফেলল।');
        $this->assertFalse($this->isOwnerHere(), '⛔ --dry super_admin দিয়ে ফেলল।');
        $this->assertSame(0, $this->restoredRows(), '⛔ --dry খাতায় লিখে ফেলল।');
    }

    public function test_the_owner_comes_back_on_the_main_branch_as_super_admin_and_the_book_says_so_once(): void
    {
        $this->assertSame(0, $this->restore()[0], '⛔ মালিকহীন কোম্পানিতে মালিক ফেরানো ব্যর্থ হলো।');

        $row = DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->first();
        $this->assertNotNull($row, '⛔ মালিকের সদস্যপদ বসেনি।');
        $this->assertSame(1, (int) $row->is_active, '⛔ সদস্যপদ বসেছে, কিন্তু বন্ধ।');
        $this->assertSame((int) $this->main()->id, (int) $row->default_branch_id, '⛔ ডিফল্ট শাখা প্রধান শাখা নয়।');
        $this->assertTrue($this->isOwnerHere(), '⛔ মালিক এই কোম্পানির super_admin হননি (বা permission cache পুরনো)।');
        $this->assertSame(1, $this->restoredRows(), '⛔ এই কোম্পানির খাতায় owner_restored সারি নেই।');

        [$again, $said] = $this->restore();
        $this->assertSame(0, $again, '⛔ দ্বিতীয়বার চালানো ব্যর্থ হলো।');
        $this->assertStringContainsString('আগে থেকেই', $said, '⛔ দ্বিতীয়বার চালালে "আগে থেকেই আছে" বলে না — আবার বসাতে গেছে।');
        $this->assertSame(1, $this->restoredRows(), '⛔ দ্বিতীয়বার চালাতে আবার খাতায় লিখল — কিছু বদলানোর কথা ছিল না।');
    }

    public function test_someone_off_the_owner_list_is_refused_and_nothing_changes(): void
    {
        config(['abos.owner_emails' => ['someone-else@abos.test']]);

        $this->assertNotSame(0, $this->restore()[0], '⛔ মালিক-তালিকার বাইরের মানুষের জন্য কমান্ড চলে গেল।');

        $this->assertFalse($this->isMember(), '⛔ মালিক-তালিকার বাইরের মানুষকে সদস্য করা হলো।');
        $this->assertFalse($this->isOwnerHere(), '⛔ মালিক-তালিকার বাইরের মানুষকে super_admin করা হলো।');
    }

    public function test_a_company_that_has_an_owner_today_is_refused(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $other->assignRole($this->superRole()->first()));

        $this->assertNotSame(0, $this->restore()[0], '⛔ মালিক-আছে কোম্পানিতে কমান্ড চলে গেল — পথ হস্তান্তর।');

        $this->assertFalse($this->isOwnerHere(), '⛔ মালিক-আছে কোম্পানিতে দ্বিতীয় মালিক বসানো হলো — পথ হস্তান্তর।');
    }

    public function test_without_a_main_branch_it_stops_instead_of_guessing(): void
    {
        Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->update(['is_default' => false]);

        $this->assertNotSame(0, $this->restore()[0], '⛔ প্রধান শাখা ছাড়াই কমান্ড চলে গেল — শাখা অনুমানে।');

        $this->assertFalse($this->isMember(), '⛔ প্রধান শাখা ছাড়াই অনুমানের শাখায় সদস্যপদ বসল।');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{0: int, 1: string} exit code আর লেখা
     */
    private function restore(array $extra = []): array
    {
        $code = Artisan::call('abos:restore-owner', ['--company' => 'FMART', '--user' => $this->owner->id] + $extra);

        return [$code, Artisan::output()];
    }

    private function superRole()
    {
        return Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }

    private function main(): ?Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->first();
    }

    private function isMember(): bool
    {
        return DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->exists();
    }

    private function isOwnerHere(): bool
    {
        return CompanyContext::forCompany((int) $this->company->id,
            fn () => User::query()->findOrFail($this->owner->id)->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE));
    }

    private function restoredRows(): int
    {
        return AuditTrail::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('auditable_type', User::class)->where('auditable_id', $this->owner->id)
            ->where('action', 'owner_restored')->count();
    }
}
