<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * পাহারাগুলো ছিল, প্রমাণ ছিল না।
 *
 * ── কী ঘটেছিল ───────────────────────────────────────────────────────
 * HP-র পরীক্ষক দুইবার লিখেছেন — ১৪ আগস্টের রিপোর্টে দুইটা আলাদা
 * আইটেমে — যে তিনি অনুমতির নিয়মগুলো যাচাই করতে পারছেন না: *"একটা
 * সীমিত-অনুমতির টেস্ট ইউজার দরকার... সাইডবারে কোথাও Users/Roles
 * ম্যানেজমেন্ট স্ক্রিন খুঁজে পাওয়া যায়নি (`/system/users` → 404)"*।
 *
 * অর্থাৎ ABOS-এর প্রতিটা রুটে অনুমতি বসানো ছিল, প্রতিটা মেনু সারি
 * অনুমতি ধরে ছাঁকা হত — কিন্তু **চালু সাইটে একজন non-owner
 * ব্যবহারকারীই ছিল না**, কারণ বানানোর কোনো পথ ছিল না। যে নিরাপত্তা
 * কখনো পরখ করা যায়নি, সেটা নিরাপত্তা নয়, আশা।
 *
 * ── এই ফাইলটা কী প্রমাণ করে ─────────────────────────────────────────
 * পর্দা দিয়ে একজন সীমিত-অনুমতির ব্যবহারকারী **সত্যিই বানানো যায়**, আর
 * তারপর তাঁকে দিয়ে ঢুকে দেখা হয় নিষেধগুলো সত্যিই কাজ করে। শেষেরটাই
 * আসল: ব্যবহারকারী বানানো গেল অথচ নিষেধ কাজ করল না — সেটা আগের
 * অবস্থার চেয়েও খারাপ, কারণ তখন সবাই ভাবত যাচাই হয়ে গেছে।
 */
class TheGuardsWereNeverProvenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Salesman',
            'email' => 'rahim@abos.test',
            'password' => 'a-long-enough-secret-9',
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['salesman'],
            'companies' => [$this->company->id],
            'default_branch' => [$this->company->id => $this->company->defaultBranch()?->id],
        ], $overrides);
    }

    // ── পর্দাটা আছে ─────────────────────────────────────────────────

    /** যে ঠিকানাটা পরীক্ষকের কাছে ৪০৪ দিত, সেটা এখন খোলে। */
    public function test_the_screen_the_tester_could_not_find_now_opens(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.user.index'))
            ->assertOk()
            ->assertSee($this->owner->name);
    }

    /**
     * পাসওয়ার্ডে কেবল দৈর্ঘ্য যথেষ্ট নয় — অক্ষর ও সংখ্যা দুইটাই লাগে।
     *
     * ── কেন এই পরীক্ষাটা আছে ────────────────────────────────────────
     * নিয়মটা ৩১ আগস্ট ২০২৬-এ বসানো হয়েছে, আর নিয়ম বসানো মানে কিছু
     * প্রমাণ হয় না — কেউ একদিন `Password::min(8)` লিখে বাকিটা মুছে
     * দিলে কোনো পরীক্ষা লাল হত না, আর `12345678` আবার চলত।
     *
     * এই লগইনের পিছনে টাকার খাতা, আর পাহারা এক স্তরের: IP ধরে মিনিটে
     * দশবার, তার উপরে অ্যাকাউন্ট ধরে তালা ([[LoginLock]])। দুর্বল
     * পাসওয়ার্ড ধরার তৃতীয় কোনো জাল নেই।
     */
    public function test_a_password_without_a_number_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.user.store'), $this->form([
                'password' => 'onlylettershere',
            ]))
            ->assertSessionHasErrors('password');

        $this->assertNull(User::query()->where('email', 'rahim@abos.test')->first(),
            'নিয়ম ভাঙা পাসওয়ার্ডে ব্যবহারকারী তৈরি হওয়া উচিত নয়।');
    }

    /** ছোট পাসওয়ার্ডও নয় — সংখ্যা থাকলেও। */
    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.user.store'), $this->form([
                'password' => 'ab1',
            ]))
            ->assertSessionHasErrors('password');
    }

    /** পর্দা দিয়ে সত্যিই একজন ব্যবহারকারী তৈরি হয়, আর তিনি ঢুকতে পারেন। */
    public function test_a_user_made_here_can_actually_sign_in(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.user.store'), $this->form())
            ->assertRedirect(route('system_admin.user.index'));

        $rahim = User::query()->where('email', 'rahim@abos.test')->first();

        $this->assertNotNull($rahim, 'ব্যবহারকারী তৈরি হয়নি।');
        $this->assertTrue(Hash::check('a-long-enough-secret-9', $rahim->password),
            'পাসওয়ার্ডটা হ্যাশ হয়ে বসেনি — লগইন করা যেত না।');
        $this->assertTrue($rahim->hasRole('salesman'));
        $this->assertTrue($rahim->companies->contains($this->company->id),
            'কোম্পানির অধিকার বসেনি — লগইন করে খালি পর্দা আসত।');
    }

    // ── আর নিষেধগুলো সত্যিই কাজ করে ─────────────────────────────────

    /**
     * **এটাই সেই পরীক্ষা যেটা পরীক্ষক করতে চেয়েছিলেন।**
     *
     * বিক্রয়কর্মী হিসাবের পর্দায় ঢুকতে পারেন না — দাবিটা এতদিন কেবল
     * কোডে লেখা ছিল, কেউ কোনোদিন চালিয়ে দেখেনি।
     */
    public function test_a_salesman_is_refused_the_accounts_screens(): void
    {
        $rahim = $this->makeSalesman();

        $this->actingAs($rahim)->get(route('accounts.dashboard'))->assertForbidden();
        $this->actingAs($rahim)->get(route('accounts.integrity'))->assertForbidden();
    }

    /** অথচ নিজের কাজের পর্দাগুলো তাঁর জন্য খোলা — নাহলে নিষেধটাই অতিরিক্ত। */
    public function test_the_same_salesman_can_open_his_own_screens(): void
    {
        $rahim = $this->makeSalesman();

        $this->actingAs($rahim)->get(route('sales.invoice.index'))->assertOk();
    }

    /** ব্যবহারকারী-ব্যবস্থাপনার পর্দাটাও তাঁর জন্য বন্ধ — নাহলে তিনি নিজেকে মালিক বানাতে পারতেন। */
    public function test_a_salesman_cannot_reach_user_management(): void
    {
        $rahim = $this->makeSalesman();

        $this->actingAs($rahim)->get(route('system_admin.user.index'))->assertForbidden();
        $this->actingAs($rahim)->get(route('system_admin.role.index'))->assertForbidden();

        $this->actingAs($rahim)
            ->post(route('system_admin.user.store'), $this->form(['email' => 'x@abos.test']))
            ->assertForbidden();

        $this->assertNull(User::query()->where('email', 'x@abos.test')->first(),
            'নিষেধ সত্ত্বেও ব্যবহারকারী তৈরি হয়ে গেছে।');
    }

    // ── নিজের পায়ে কুড়াল নয় ────────────────────────────────────────

    /** নিজেকে নিষ্ক্রিয় করা যায় না — করলে ঠিক করার কেউ থাকত না। */
    public function test_nobody_can_deactivate_themselves(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner), [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'locale' => 'bn',
                'is_active' => '0',
                'roles' => ['owner'],
                'companies' => [$this->company->id],
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    /** নিজের কাছ থেকে ব্যবস্থাপনার চাবিটা সরানো যায় না। */
    public function test_nobody_can_take_the_key_out_of_their_own_pocket(): void
    {
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $this->owner), [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'locale' => 'bn',
                'is_active' => '1',
                'roles' => ['salesman'],
                'companies' => [$this->company->id],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
    }

    // ── রোল ─────────────────────────────────────────────────────────

    /** নতুন রোল বানানো যায়, আর তাতে ঠিক যা টিক দেওয়া হলো তাই বসে। */
    public function test_a_depot_can_make_a_role_of_its_own(): void
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.role.store'), [
                'name' => 'store_keeper',
                'permissions' => ['sales.challan.view', 'sales.challan.create'],
            ])
            ->assertRedirect(route('system_admin.role.index'));

        $role = Role::query()->where('name', 'store_keeper')->first();

        $this->assertNotNull($role);
        $this->assertSame(['sales.challan.create', 'sales.challan.view'],
            $role->permissions->pluck('name')->sort()->values()->all());
    }

    /**
     * সেই রোলটা সত্যিই কাজ করে — টিক দেওয়াটা খোলে, না-দেওয়াটা খোলে না।
     *
     * রোল বানানো গেল অথচ অনুমতিগুলো কার্যকর হলো না — সেটা সবচেয়ে
     * বিপজ্জনক ফল, কারণ পর্দা দেখে মনে হত সব ঠিক আছে।
     */
    public function test_a_role_made_here_really_decides_what_opens(): void
    {
        $this->actingAs($this->owner)->post(route('system_admin.role.store'), [
            'name' => 'store_keeper',
            'permissions' => ['sales.challan.view'],
        ]);

        $this->actingAs($this->owner)->post(route('system_admin.user.store'), $this->form([
            'email' => 'karim@abos.test',
            'roles' => ['store_keeper'],
        ]));

        $karim = User::query()->where('email', 'karim@abos.test')->firstOrFail();

        $this->actingAs($karim)->get(route('sales.challan.index'))->assertOk();
        $this->actingAs($karim)->get(route('sales.invoice.index'))->assertForbidden();
    }

    /**
     * মালিকের রোলে হাত দেওয়া যায় না — ঠিকানা জানা থাকলেও নয়।
     *
     * পর্দায় লিংকটা নেই, কিন্তু লিংক না থাকা কোনো বাধা নয়: ঠিকানাটা
     * অনুমান করা যায়, আর তখন কেউ মালিকের অনুমতি কেটে দিতে পারতেন।
     */
    public function test_the_owner_role_cannot_be_edited_even_by_address(): void
    {
        $owner = Role::query()->where('name', 'owner')->firstOrFail();
        $before = $owner->permissions->count();

        $this->actingAs($this->owner)
            ->put(route('system_admin.role.update', $owner), [
                'name' => 'owner',
                'permissions' => ['sales.invoice.view'],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame($before, $owner->fresh()->permissions->count(),
            'মালিকের রোল থেকে অনুমতি কেটে ফেলা গেছে।');
    }

    // ── খাতা ────────────────────────────────────────────────────────

    /**
     * রোল বদল খাতায় ওঠে।
     *
     * রোল বসে `model_has_roles` টেবিলে, ব্যবহারকারীর নিজের সারিতে নয় —
     * তাই মডেলের অডিট ওটা দেখে না। কেউ কাউকে মালিক বানিয়ে দিলে
     * ইতিহাসে কোনো চিহ্নই থাকত না, অথচ ওটাই সবচেয়ে বড় বদল।
     */
    public function test_a_change_of_role_leaves_a_trace(): void
    {
        $rahim = $this->makeSalesman();

        $this->actingAs($this->owner)->put(route('system_admin.user.update', $rahim), [
            'name' => $rahim->name,
            'email' => $rahim->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['accountant'],
            'companies' => [$this->company->id],
        ]);

        $trail = AuditTrail::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $rahim->id)
            ->where('action', 'roles_changed')
            // সর্বশেষটা — তৈরির সময়ও একটা সারি বসে (কিছুই → salesman),
            // আর প্রথমটা ধরলে টেস্টটা ভুল প্রশ্ন করত
            ->latest('id')
            ->first();

        $this->assertNotNull($trail, 'রোল বদলের কোনো চিহ্ন খাতায় নেই।');
        $this->assertStringContainsString('accountant', (string) $trail->reason);
    }

    /**
     * পাসওয়ার্ড কখনো খাতায় ওঠে না।
     *
     * বসানো হয়েছে সেটা ওঠে, কী বসানো হয়েছে সেটা নয় — নাহলে অডিটের
     * খাতাটাই একটা পাসওয়ার্ডের তালিকা হয়ে যেত।
     */
    public function test_the_password_itself_never_reaches_the_journal(): void
    {
        $rahim = $this->makeSalesman();

        $this->actingAs($this->owner)->put(route('system_admin.user.update', $rahim), [
            'name' => $rahim->name,
            'email' => $rahim->email,
            'password' => 'another-long-secret-7',
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['salesman'],
            'companies' => [$this->company->id],
        ]);

        $trails = AuditTrail::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $rahim->id)
            ->with('changes')
            ->get();

        $this->assertTrue($trails->contains(fn ($t) => $t->action === 'password_set'),
            'পাসওয়ার্ড বসানোর কোনো চিহ্ন নেই।');

        foreach ($trails as $trail) {
            foreach ($trail->changes as $change) {
                $this->assertNotSame('password', $change->field,
                    'পাসওয়ার্ডের ঘরটাই অডিটে লেখা হয়েছে।');

                $this->assertStringNotContainsString('another-long-secret',
                    (string) $change->new_value.(string) $change->old_value,
                    'পাসওয়ার্ডটা অডিটের খাতায় বসে গেছে।');
            }
        }
    }

    private function makeSalesman(): User
    {
        $this->actingAs($this->owner)->post(route('system_admin.user.store'), $this->form());

        return User::query()->where('email', 'rahim@abos.test')->firstOrFail();
    }
}
