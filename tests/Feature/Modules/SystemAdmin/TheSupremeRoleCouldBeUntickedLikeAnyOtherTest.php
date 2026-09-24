<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * সুপ্রিম কর্তৃত্বের রোলটা আর দশটা টিকের মতোই তোলা যেত।
 *
 * ── ⭐ মালিকের কথা, ২৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"role kokonoi edite kora zabena"* — ⓘ নাম, পদবি, ইমেইল, মোবাইল সব
 * বদলানো যাবে, কেবল রোলটা নয়।
 *
 * ── ⛔ ফাঁকটা কোথায় ছিল, আর কতটা ────────────────────────────────────
 * ⓘ [[UserPolicy::update()]] বলে অ-মালিক মালিকের খাতাই খুলতে পারেন না,
 * তাই `system_admin.user.manage` ধরা যে কেউ এটা করতে পারতেন না। ⚠️ কিন্তু
 * **দুইটা পথ খোলা ছিল**, আর দুইটাই সত্যি:
 *
 *   ⓵ দুইজন মালিক থাকলে একজন অন্যজনের টিক তুলে দিতে পারতেন।
 *   ⓶ মালিক **নিজের** টিকটাও তুলতে পারতেন, যদি আরেকটা চাবিওয়ালা রোল
 *      রেখে দিতেন — আর দুর্ঘটনা হিসেবে এটাই বেশি সম্ভব।
 *
 * ⛔ আগের পাহারা দুইটা এর একটাও ধরত না:
 * [[UserController::assertNotLockingThemselvesOut()]] কেবল দেখে চাবিটা
 * থাকছে কি না, কোন রোলে তা নয়; আর [[Ownership::assertCompanyKeepsAnOwner()]]
 * কেবল **শেষ** মালিককে বাঁচায়।
 *
 * ── ⓘ তবু দরজাটা একমুখী নয় ─────────────────────────────────────────
 * নামানোর পথ আছে — মালিকানা হস্তান্তর ([[Ownership::transfer()]]), যে
 * লেনদেনের ভিতরেই গুনে দেখে কোম্পানিটা মালিকহীন হয়নি। ⭐ অর্থাৎ নামানো
 * একটা **হস্তান্তর**, সম্পাদনা নয়।
 */
final class TheSupremeRoleCouldBeUntickedLikeAnyOtherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $owner;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'LOCK', 'name_en' => 'Locked Co']);
        CompanyContext::set($this->company->id);

        $this->branch = Branch::query()->create([
            'company_id' => $this->company->id, 'code' => 'HQ',
            'name_en' => 'Head office', 'is_active' => true,
        ]);

        foreach (['system_admin.user.manage', 'accounts.voucher.create'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->owner = $this->person('owner@lock.test');
        $this->second = $this->person('second@lock.test');

        CompanyContext::forCompany($this->company->id, function (): void {
            Role::findOrCreate(PermissionSyncer::SUPER_ADMIN_ROLE)
                ->givePermissionTo(['system_admin.user.manage', 'accounts.voucher.create']);

            /* ⓘ চাবিওয়ালা দ্বিতীয় একটা রোল — নিচের ⓶ নম্বর পথটার জন্য লাগে */
            Role::findOrCreate('user_admin')->givePermissionTo('system_admin.user.manage');
            Role::findOrCreate('accountant')->givePermissionTo('accounts.voucher.create');

            $this->owner->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE);
            $this->second->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE);
        });
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->switchCompany($this->company->id);

        return $user;
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(User $user, array $roles, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => $roles,
            'companies' => [$this->company->id],
            'default_branch' => [$this->company->id => $this->branch->id],
        ], $overrides);
    }

    private function stillOwner(User $user, string $why): void
    {
        CompanyContext::forCompany($this->company->id, function () use ($user, $why): void {
            $this->assertTrue(
                $user->fresh()?->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE) ?? false,
                $why,
            );
        });
    }

    /** ⛔ পথ ⓵ — এক মালিক অন্য মালিকের টিক তোলেন। */
    public function test_one_owner_cannot_unseat_another(): void
    {
        $this->actingAs($this->second)
            ->put(route('system_admin.user.update', $this->owner),
                $this->form($this->owner, ['user_admin']))
            ->assertSessionHasErrors('roles');

        $this->stillOwner($this->owner, 'একজন মালিক অন্যজনকে নামিয়ে দিতে পেরেছেন।');
    }

    /**
     * ⛔ পথ ⓶ — মালিক নিজের টিকটাই তোলেন।
     *
     * ⚠️ দুর্ঘটনা হিসেবে এটাই বেশি সম্ভব, আর আগের পাহারাটা এটাকে **বৈধ**
     * বলত: ⓘ [[UserController::assertNotLockingThemselvesOut()]] কেবল দেখে
     * `system_admin.user.manage` চাবিটা থাকছে কি না — আর `user_admin`
     * রোলেও ওটা আছে। ⛔ তাই টিকটা তুলে দিলেও সে চুপ করে থাকত।
     */
    public function test_the_owner_cannot_unseat_themselves_either(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner),
                $this->form($this->owner, ['user_admin']))
            ->assertSessionHasErrors('roles');

        $this->stillOwner($this->owner, 'মালিক নিজের রোলটাই তুলে ফেলতে পেরেছেন।');
    }

    /**
     * ⭐ পাল্টা-দাবি — সাধারণ রোল আগের মতোই ওঠানামা করে।
     *
     * ⚠️ এটা না থাকলে "সব রোল বদল আটকে দাও" লিখেও উপরের দুইটা সবুজ হত,
     * আর তালাটা দেয়াল হয়ে যেত — মালিকের পাতায় আর কোনো রোলই বসানো যেত না।
     */
    public function test_ordinary_roles_still_go_on_and_come_off(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner),
                $this->form($this->owner, [PermissionSyncer::SUPER_ADMIN_ROLE, 'accountant']))
            ->assertSessionHasNoErrors();

        CompanyContext::forCompany($this->company->id, function (): void {
            $this->assertTrue($this->owner->fresh()?->hasRole('accountant') ?? false,
                'মালিকের পাতায় একটা সাধারণ রোল বসানোই গেল না।');
        });

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner),
                $this->form($this->owner, [PermissionSyncer::SUPER_ADMIN_ROLE]))
            ->assertSessionHasNoErrors();

        CompanyContext::forCompany($this->company->id, function (): void {
            $this->assertFalse($this->owner->fresh()?->hasRole('accountant') ?? true,
                'সাধারণ রোলটা আর তোলাই যাচ্ছে না — তালাটা দেয়াল হয়ে গেছে।');
        });
    }

    /**
     * ⭐ পাল্টা-দাবি — মালিকের নাম-ইমেইল-মোবাইল ঠিকই সংরক্ষণ হয়।
     *
     * ⓘ মালিক ওগুলো বদলাতে চান; তালাটা কেবল রোলের।
     */
    public function test_the_owners_own_details_still_save(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner),
                $this->form($this->owner, [PermissionSyncer::SUPER_ADMIN_ROLE], [
                    'name' => 'নতুন নাম',
                    'email' => 'renamed@lock.test',
                    'mobile' => '01700000000',
                ]))
            ->assertSessionHasNoErrors();

        $fresh = $this->owner->fresh();

        $this->assertSame('নতুন নাম', $fresh?->name);
        $this->assertSame('renamed@lock.test', $fresh?->email);
        $this->assertSame('01700000000', $fresh?->mobile);
    }

    /**
     * ⭐ আর পর্দাটা তালাটা **দেখায়**, আর লুকানো ঘরটাও পাঠায়।
     *
     * ── ⚠️ কেন লুকানো ঘরটা আলাদা করে মাপা ───────────────────────────
     * ⓘ `disabled` চেকবক্স ব্রাউজার **পাঠায়ই না**। ⛔ শুধু নিষ্ক্রিয় করলে
     * সংরক্ষণের সময় রোলটা তালিকায় থাকত না, সার্ভার সেটাকে *"তুলে নেওয়া
     * হয়েছে"* পড়ত — অর্থাৎ নামউচ্চারণ করতে চাওয়া পর্দাটাই নামিয়ে দিত,
     * আর উপরের সার্ভার-পাহারাটা মালিকের **প্রতিটা** সংরক্ষণ আটকে দিত।
     *
     * ⚠️ এটাই এখানকার বাগের চেনা আকার: কাজটা হয়েছে, জোড়াটা নয়।
     */
    public function test_the_screen_shows_the_lock_and_still_sends_the_role(): void
    {
        $page = $this->actingAs($this->owner)
            ->get(route('system_admin.user.edit', $this->owner))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<input type="hidden" name="roles[]" value="'.PermissionSyncer::SUPER_ADMIN_ROLE.'">',
            (string) $page,
            'লুকানো ঘরটা নেই — সংরক্ষণ করলেই সার্ভার ভাবত রোলটা তুলে নেওয়া হয়েছে।',
        );

        $this->assertMatchesRegularExpression(
            '/name="roles\[\]" value="'.PermissionSyncer::SUPER_ADMIN_ROLE.'"[^>]*disabled/',
            (string) $page,
            'টিকটা এখনো তোলা যায় — পর্দায় কোনো তালা নেই।',
        );
    }
}
