<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Executive\Notifications\MorningSummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকের উত্তর, ১০ অক্টোবর ২০২৬ — মালিকের কেন্দ্র:
 *   ৪. "যাবে alaminsuv@gmail.com এ" — প্রতিদিন সকালে আগের দিনের সারাংশ।
 *   ৬. "অনলি আমি দেখব আর আমার একটা সেকেন্ড রোলেও যাতে দেখতে পারি অনুমতি দিয়ে"।
 *   ১. "সম্পূর্ণ আলাদা হবে" — সিস্টেম প্রশাসনের ঠিক নিচে।
 */
final class OnlyTheOwnerHandsOutHisCentreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /** ⭐ মালিক নিজে দ্বিতীয় রোলে চাবিটা দিতে পারেন। */
    public function test_the_owner_gives_his_second_role_the_centre(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('system_admin.role.store'), [
            'name' => 'মালিকের সহকারী',
            'permissions' => ['executive.view'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Role::query()->where('name', 'মালিকের সহকারী')->firstOrFail()->hasPermissionTo('executive.view'));
    }

    /**
     * ⛔ চাবি হাতে থাকা রোল-রক্ষকও সেটা আরেক রোলে ছড়াতে পারেন না — একই মানুষ, অন্য চাবিতে পারেন।
     */
    public function test_a_role_keeper_holding_the_key_cannot_pass_it_on(): void
    {
        $this->actingAs($this->keeperWith(['system_admin.role.manage', 'executive.view', 'sales.invoice.view']));

        $this->post(route('system_admin.role.store'), [
            'name' => 'ছড়ানো চাবি',
            'permissions' => ['executive.view'],
        ])->assertSessionHasErrors('permissions');

        $this->assertNull(Role::query()->where('name', 'ছড়ানো চাবি')->first(), '⛔ মালিকের কেন্দ্রের চাবি মালিক ছাড়া আরেকজন ছড়াল।');

        $this->post(route('system_admin.role.store'), [
            'name' => 'সাধারণ চাবি',
            'permissions' => ['sales.invoice.view'],
        ])->assertSessionHasNoErrors();
    }

    /** ⭐ সকালের মেইল — গতকালের রাতের হিসাব, বাংলায়, `.env`-এর ঠিকানায়। */
    public function test_the_morning_mail_carries_yesterdays_figures_to_the_owners_address(): void
    {
        Notification::fake();
        config(['abos.executive.mail_to' => 'alaminsuv@gmail.com']);

        Carbon::setTestNow(Carbon::yesterday()->setTime(23, 55));
        $this->artisan('abos:owner-snapshot')->assertSuccessful();
        Carbon::setTestNow();

        $this->artisan('abos:owner-morning-mail')->assertSuccessful();

        Notification::assertSentTo(
            new AnonymousNotifiable,
            MorningSummary::class,
            function (MorningSummary $mail, array $channels, AnonymousNotifiable $to): bool {
                $message = $mail->toMail($to);
                $text = implode("\n", $message->introLines);

                return ($to->routes['mail'] ?? null) === 'alaminsuv@gmail.com'
                    && str_contains($message->subject, Carbon::yesterday()->toDateString())
                    && str_contains($text, 'বিক্রি')
                    && ! str_contains($text, 'রাতের হিসাব পাওয়া যায়নি');
            },
        );
    }

    /** ⓘ ঠিকানা বসানো না থাকলে কিছুই যায় না, ভুলও হয় না — ABOS অনেক ব্যবসায় বিক্রি হয়। */
    public function test_with_no_address_nothing_is_sent(): void
    {
        Notification::fake();
        config(['abos.executive.mail_to' => '']);

        $this->artisan('abos:owner-morning-mail')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** ⭐ সম্পূর্ণ আলাদা ভাঁজ, সিস্টেম প্রশাসনের ঠিক নিচে। */
    public function test_the_centre_sits_right_below_system_administration(): void
    {
        $executive = require base_path('app/Modules/Executive/module.php');
        $system = require base_path('app/Modules/SystemAdmin/module.php');

        $this->assertSame($system['nav']['section'], $executive['nav']['section']);
        $this->assertSame($system['nav']['order'] + 1, $executive['nav']['order']);
    }

    /** @param  list<string>  $keys */
    private function keeperWith(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            $role = Role::create(['name' => 'role-keeper', 'guard_name' => 'web']);
            $role->givePermissionTo(array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys));
            $user->assignRole($role);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
