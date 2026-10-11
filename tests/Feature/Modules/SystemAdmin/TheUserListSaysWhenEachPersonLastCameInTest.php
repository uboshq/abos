<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\RoleLabel;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ব্যবহারকারীর তালিকা বলে কে শেষ কবে ঢুকেছেন, আর রোলের চিপ বাংলায় পড়া যায়।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা, ১০ অক্টোবর ২০২৬ (ধাপ C, ব্যবহারকারী) ─────────────────
 * মালিক: *"ebar porda gulo plan onuzayi kaj suro koro"*। ⛔ কিন্তু ২২ সেপ্টেম্বরের নমুনা (প্রতিটা তথ্য নিজের কলামে) আর ৩ অক্টোবরের
 * টুলবার আগের নির্দেশ, তাই fe-র সীমা: কেবল রোলের চিপ আর শেষ লগইনের কলাম; সারির বোতাম ⋯-এ লুকানো নয়।
 *
 * ⓘ রোল-ছাঁচের নাম (Warehouse, Field Sales…) ভিতরে ইংরেজিই থাকে — অনুমতি আর কোড ঐ নাম পড়ে — কেবল দেখানো নামটা বাংলা।
 */
final class TheUserListSaysWhenEachPersonLastCameInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = $this->company->defaultBranch() ?? Branch::query()
            ->where('company_id', $this->company->id)->firstOrFail();

        CompanyContext::set($this->company->id, $this->branch->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());
    }

    /** ⭐ ঢুকেছেন যিনি, তাঁর সময়; কখনো না ঢোকা জন বলে "কখনো ঢোকেননি" — ড্যাশ নয়, কারণ ড্যাশ পড়ে "জানা নেই"। */
    public function test_the_last_sign_in_has_its_own_column(): void
    {
        $came = $this->member('came@abos.test', ['last_login_at' => '2026-10-09 14:35:00']);
        $never = $this->member('never@abos.test', ['last_login_at' => null]);

        $html = $this->list();

        $this->assertStringContainsString('শেষ ঢোকা', $html, '⛔ তালিকায় «শেষ ঢোকা» কলাম নেই।');

        $this->assertStringContainsString(DateFormat::formatWithTime($came->fresh()->last_login_at), $this->rowOf($html, $came),
            '⛔ যিনি ঢুকেছেন তাঁর সারিতে শেষ ঢোকার সময় নেই।');

        $this->assertStringContainsString('কখনো ঢোকেননি', $this->rowOf($html, $never),
            '⛔ কখনো না ঢোকা ব্যবহারকারীর সারি সেটা বলে না।');
        $this->assertStringNotContainsString('কখনো ঢোকেননি', $this->rowOf($html, $came),
            '⛔ যিনি ঢুকেছেন তাঁকেও "কখনো ঢোকেননি" বলা হচ্ছে।');
    }

    /**
     * ⭐ মডিউলের রোল-ছাঁচ চিপে বাংলায়; ভিতরের নাম অপরিবর্তিত।
     *
     * ⓘ সব ছাঁচ মাপা হয় ([[RoleTemplateRegistry::all()]]), কেবল একটা নয় — নতুন মডিউল একটা ছাঁচ আনলে আর তার বাংলা নাম না থাকলে
     * এই দাবিই প্রথম বলে। ASM · RSM · DSM ইচ্ছা করে ইংরেজিতে, তাই তালিকায় বাদ।
     */
    public function test_every_role_template_reads_in_bengali(): void
    {
        app()->setLocale('bn');

        $missing = collect(array_keys(app(\App\Core\Services\RoleTemplateRegistry::class)->all()))
            ->reject(fn (string $name) => in_array($name, ['ASM', 'RSM', 'DSM'], true))
            ->filter(fn (string $name) => RoleLabel::for($name) === $name || preg_match('/[A-Za-z]/', RoleLabel::for($name)))
            ->values()->all();

        $this->assertSame([], $missing, '⛔ এই রোল-ছাঁচগুলোর বাংলা নাম নেই (lang/bn/core.php → role): '.implode(', ', $missing));
    }

    public function test_the_chip_shows_the_bengali_name_and_the_role_keeps_its_own(): void
    {
        $user = $this->member('keeper@abos.test');
        $role = Role::findOrCreate('Warehouse', 'web');
        $user->assignRole($role);

        $row = $this->rowOf($this->list(), $user);

        $this->assertStringContainsString('গুদাম', $row, '⛔ চিপে রোলের বাংলা নাম নেই।');
        $this->assertSame('Warehouse', $role->fresh()->name, '⛔ রোলের ভিতরের নামটাই বদলে গেছে।');
        $this->assertTrue($user->fresh()->hasRole('Warehouse'), '⛔ ভিতরের নামে আর রোলটা খুঁজে পাওয়া যায় না।');
    }

    /** ⛔ সারির কাজ লেখাসহ বোতামেই থাকে, ⋯-এ নয় — মালিক ⋯ চাননি। */
    public function test_the_row_actions_stay_as_buttons(): void
    {
        $user = $this->member('buttons@abos.test');

        $row = $this->rowOf($this->list(), $user);

        $this->assertStringContainsString(route('system_admin.user.edit', $user->id), $row, '⛔ সারিতে সম্পাদনার বোতাম নেই।');
        $this->assertStringContainsString(__('core.action.edit', [], 'bn'), $row, '⛔ সম্পাদনার বোতামে লেখা নেই।');
        $this->assertStringNotContainsString('aria-haspopup', $row, '⛔ সারির কাজ ⋯-এ লুকানো।');
    }

    /** @param array<string, mixed> $extra */
    private function member(string $email, array $extra = []): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->branch->id,
        ]);
        $user->forceFill($extra)->save();

        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function list(): string
    {
        return (string) $this->get(route('system_admin.user.index'))->assertOk()->getContent();
    }

    /** একজনের সারি — `<tr>` থেকে `</tr>`, তাঁর ইমেইল ধরে। */
    private function rowOf(string $html, User $user): string
    {
        $at = strpos($html, 'mailto:'.$user->email);
        $this->assertNotFalse($at, "⛔ তালিকায় {$user->email}-এর সারিই নেই।");

        $start = strrpos(substr($html, 0, $at), '<tr');
        $end = strpos($html, '</tr>', $at);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
