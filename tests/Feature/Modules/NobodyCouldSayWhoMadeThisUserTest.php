<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কেউ বলতে পারত না এই অ্যাকাউন্টটা কে বানিয়েছিল — ১২ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * [[User]] মডেলে [[IsAudited]] ছিল না। ফলে `UserController::store()`
 * একটা গোটা অ্যাকাউন্ট বানাত আর `update()` নাম-ইমেইল-`is_active`
 * বদলাত, অথচ **খাতায় একটা সারিও বসত না**।
 *
 * ⓘ তিনটা কাজ আলাদা করে লেখা হত — `password_set`, `roles_changed`,
 * `scopes_changed`। ⚠️ ঠিক ঐটাই ভুলটাকে এতদিন আড়াল করেছে: নিরীক্ষার
 * পর্দায় ব্যবহারকারীর সারি **দেখা যেত**, তাই মনে হত অডিট আছে। ছিল
 * না — ছিল কেবল তিনটা পিভট-টেবিলের ঘটনা, ব্যবহারকারীর নিজের সারিতে
 * যা ঘটে তার কিছুই নয়।
 *
 * ⛔ অর্থাৎ যে প্রশ্নগুলো নিরীক্ষায় প্রথমে আসে, তার একটারও উত্তর ছিল না:
 *
 *     এই অ্যাকাউন্টটা কে বানাল, আর কবে
 *     কার ইমেইল বদলে দেওয়া হলো (ইমেইলই লগইনের পরিচয়)
 *     কাকে নিষ্ক্রিয় করা হলো, আর কে করল
 *
 * ── ⭐ কেন এই ফাইলটা দুই দিক থেকেই পাহারা দেয় ────────────────────────
 * অডিট বসানোর সহজ ভুলটা হলো **সব** বসিয়ে দেওয়া, আর তখন পাসওয়ার্ডের
 * হ্যাশ খাতায় গিয়ে বসে — অর্থাৎ সারাইটাই একটা নতুন ফাঁস। ⚠️ তাই
 * এখানে দুইটা প্রশ্ন একসাথে করা হয়: **যা যাওয়ার কথা তা যায় তো**, আর
 * **যা যাওয়ার কথা নয় তা বাইরে থেকে গেছে তো**।
 */
class NobodyCouldSayWhoMadeThisUserTest extends TestCase
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

    /**
     * ফর্ম দিয়ে একজনকে বানিয়ে তাঁকে ফেরত দেওয়া।
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        $this->actingAs($this->owner)
            ->post(route('system_admin.user.store'), $this->form($overrides))
            ->assertRedirect(route('system_admin.user.index'));

        return User::query()
            ->where('email', $overrides['email'] ?? 'rahim@abos.test')
            ->firstOrFail();
    }

    /**
     * এই ব্যবহারকারীর খাতার সারিগুলো — পুরনো আগে।
     *
     * ── ⚠️ কেন `withoutGlobalScopes()` ───────────────────────────────
     * [[AuditTrail]] `BelongsToCompany` ব্যবহার করে, অর্থাৎ কোয়েরিটা
     * **চলতি কোম্পানি ধরে ছাঁকা** হয়। ⓘ আর `CompanyContext` স্ট্যাটিক,
     * তাই প্রতিটা HTTP অনুরোধ সেটা বদলে রেখে যায় — পরীক্ষাটা যে
     * প্রসঙ্গে দাবি করছে সেটা তখন আর আগেরটা নয়।
     *
     * ⛔ প্রথম খসড়ায় ছাঁকনিটা চালুই ছিল, আর তাতে একটা দাবি **মিথ্যা
     * সবুজ** হতে পারত: "কোনো সারি বসেনি" আসলে হত "এখান থেকে কোনো সারি
     * দেখা যাচ্ছে না"। ⭐ দুইটা এক নয়, আর এই ফাইলের অর্ধেক পরীক্ষা ঠিক
     * ঐ প্রশ্নটাই করে। তাই গোনাটা হয় গোটা টেবিলে।
     *
     * @return Collection<int, AuditTrail>
     */
    private function trails(User $user, ?string $action = null): Collection
    {
        return AuditTrail::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->with('changes')
            ->orderBy('id')
            ->get();
    }

    /**
     * ফর্মের সব ঘর, কেবল যেগুলো বদলাতে চাই সেগুলো ছাড়া।
     *
     * ⚠️ `update()` পুরো ফর্মটাই দাবি করে — একটা ঘর বাদ দিলে সেটা
     * ভ্যালিডেশনে আটকায়, আর তখন পরীক্ষাটা যা দেখতে চায় তার আগেই
     * থেমে যেত।
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Salesman',
            'email' => 'rahim@abos.test',
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => ['salesman'],
            'companies' => [$this->company->id],
        ], $overrides);
    }

    /* ── যা খাতায় যাওয়ার কথা ───────────────────────────────────────── */

    /**
     * একটা নতুন অ্যাকাউন্ট — কে বানাল, আর কার নামে।
     *
     * ⓘ সারিতে ঘর ধরে ধরে মান নেই, আর সেটা [[IsAudited]]-র ইচ্ছাকৃত
     * সিদ্ধান্ত: নতুন রেকর্ডের মানগুলো রেকর্ডেই আছে, আর রেকর্ড কখনো শক্ত
     * করে মোছা হয় না। ⭐ এখানে যা দরকার তা হলো **ঘটনাটা** — কে, কখন,
     * কার অ্যাকাউন্ট।
     */
    public function test_who_made_this_account_is_now_written_down(): void
    {
        $rahim = $this->makeUser();

        $created = $this->trails($rahim, AuditTrail::CREATED);

        $this->assertCount(1, $created, 'নতুন ব্যবহারকারীর জন্য খাতায় কোনো সারি বসেনি।');
        $this->assertSame($this->owner->id, $created->first()->user_id);
        $this->assertSame('Rahim Salesman', $created->first()->label);
        $this->assertSame($this->company->id, $created->first()->company_id);
    }

    /**
     * ইমেইল বদল — অর্থাৎ লগইনের পরিচয়টাই বদলে যাওয়া।
     *
     * ⚠️ এটা নাম শুধরানোর মতো নয়: ইমেইলই এই ব্যবস্থায় মানুষটার চাবি।
     * বদলে দিলে পুরনো ঠিকানায় আর ঢোকা যায় না, আর নতুনটা যাঁর হাতে
     * তিনিই ঢোকেন। ⭐ তাই পুরনো **ও** নতুন — দুইটা মানই খাতায় থাকতে
     * হবে, কেবল "সম্পাদনা হয়েছে" নয়।
     */
    public function test_an_edited_email_leaves_both_the_old_and_the_new(): void
    {
        $rahim = $this->makeUser();

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([
                'name' => 'Rahim Uddin',
                'email' => 'rahim.uddin@abos.test',
            ]))
            ->assertRedirect(route('system_admin.user.index'));

        $updated = $this->trails($rahim, AuditTrail::UPDATED);

        $this->assertCount(1, $updated);

        $changes = $updated->first()->changes->keyBy('field');

        $this->assertSame('rahim@abos.test', $changes['email']->old_value);
        $this->assertSame('rahim.uddin@abos.test', $changes['email']->new_value);
        $this->assertSame('Rahim Salesman', $changes['name']->old_value);
        $this->assertSame('Rahim Uddin', $changes['name']->new_value);
    }

    /**
     * ⭐ কাউকে বাইরে করে দেওয়া — এই ফাইলের সবচেয়ে দরকারি সারি।
     *
     * ⚠️ `is_active` মিথ্যা হলে একজন মানুষ পরদিন সকালে আর ঢুকতে পারেন
     * না, আর এতদিন কেউ সেটা **নীরবে** করে দিতে পারতেন — কোনো চিহ্ন
     * ছাড়াই। ⓘ মানটা খাতায় বসে `1` → `0` হয়ে, ভাষা-নিরপেক্ষ কাঁচা
     * রূপে (`AuditEngine::stringify()`); পর্দা সেটাকে ব্যবহারকারীর
     * ভাষায় দেখায়।
     */
    public function test_switching_somebody_off_is_written_down_with_who_did_it(): void
    {
        $rahim = $this->makeUser();

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm(['is_active' => '0']))
            ->assertRedirect(route('system_admin.user.index'));

        $this->assertFalse($rahim->fresh()->is_active);

        $updated = $this->trails($rahim, AuditTrail::UPDATED);

        $this->assertCount(1, $updated);
        $this->assertSame($this->owner->id, $updated->first()->user_id);

        $flag = $updated->first()->changes->firstWhere('field', 'is_active');

        $this->assertNotNull($flag, '`is_active` বদলটা খাতায় নেই — এটাই সবচেয়ে দরকারি সারি।');
        $this->assertSame('1', $flag->old_value);
        $this->assertSame('0', $flag->new_value);
    }

    /**
     * ⛔ কাকে কোন প্রতিষ্ঠানে ঢোকানো হলো — `company_user` পিভটের বদল।
     *
     * ── কেন এটা আলাদা করে লিখতে হয় ──────────────────────────────────
     * ⓘ রোল আর দেখার সীমার মতোই, বদলটা ব্যবহারকারীর নিজের সারিতে ঘটে
     * না — ঘটে একটা পিভট টেবিলে, তাই মডেলের অডিট সেটা দেখে না।
     *
     * ⚠️ রোল বলে *কী করতে পারেন*, এই সারিটা বলে *কার বই-খাতায়*। একজন
     * সেলসম্যানকে দ্বিতীয় কোম্পানিতে জুড়ে দিলে তাঁর রোল এক থাকে, অথচ
     * তিনি একটা সম্পূর্ণ ভিন্ন প্রতিষ্ঠানের সংখ্যা দেখতে শুরু করেন।
     */
    public function test_letting_somebody_into_another_company_is_written_down(): void
    {
        $rahim = $this->makeUser();
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([
                'companies' => [$this->company->id, $beta->id],
            ]))
            ->assertRedirect(route('system_admin.user.index'));

        $rows = $this->trails($rahim, 'companies_changed');

        $this->assertCount(2, $rows, 'কোম্পানির অধিকার বদলেছে, অথচ খাতায় সারি নেই।');
        $this->assertSame($this->owner->id, $rows->last()->user_id);

        /*
         * ⓘ প্রথম সারিটা **তৈরির মুহূর্তের**: তার আগে কোনো অধিকারই ছিল
         * না, তাই বাঁ পাশটা খালি। ⭐ `roles_changed` ঠিক একই রকম দেখায়
         * (` → salesman`), আর দুইটা এক রাখাই উদ্দেশ্য — নিরীক্ষার পর্দায়
         * দুই ধরনের অধিকার একই ভাষায় পড়া যায়।
         */
        $this->assertSame(' → TDEPOT', $rows->first()->reason);

        // ⓘ আইডি নয়, কোড — ছয় মাস পরে "৪৫, ৪৬" কিছুই বলে না
        $this->assertSame('TDEPOT → FMART, TDEPOT', $rows->last()->reason);
    }

    /**
     * অধিকার না বদলালে কোনো সারি বসে না।
     *
     * ⚠️ ফর্মে টিকের ক্রম বদলানো অধিকার বদল নয়। ⓘ তালিকা দুইটা তাই কোড
     * ধরে সাজিয়ে মেলানো হয় — নাহলে প্রতিটা সেভে একটা মিথ্যা "কোম্পানির
     * অধিকার বদল" সারি বসত, আর তাতে আসল বদলটাই খুঁজে পাওয়া যেত না।
     */
    public function test_saving_the_same_companies_again_writes_nothing(): void
    {
        $rahim = $this->makeUser();

        $before = $this->trails($rahim, 'companies_changed')->count();

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm())
            ->assertRedirect(route('system_admin.user.index'));

        $this->assertSame($before, $this->trails($rahim, 'companies_changed')->count());
    }

    /* ── যা খাতায় যাওয়ার কথা নয় ────────────────────────────────────── */

    /**
     * ⛔ পাসওয়ার্ডের হ্যাশ কোনোদিন নিরীক্ষার খাতায় বসে না।
     *
     * ── কেন হ্যাশ হলেও নয় ───────────────────────────────────────────
     * bcrypt হ্যাশ পড়ে পাসওয়ার্ড বলা যায় না, তাই প্রথমে মনে হয় ক্ষতি
     * নেই। ⚠️ কিন্তু তখন খাতাটাই প্রতিটা কর্মীর হ্যাশের একটা তালিকা
     * হয়ে যেত, আর অফলাইনে হ্যাশ ভাঙা যায় — সময় নিয়ে, কেউ না জেনে।
     *
     * ⭐ আর অডিট **পড়ার** অনুমতি ব্যবহারকারী **বদলানোর** অনুমতির চেয়ে
     * অনেক বেশি লোকের থাকে। অর্থাৎ ফাঁসটা ঠিক সেই দরজা দিয়ে বেরোত
     * যেটা পাহারা দেওয়ার জন্যই খাতাটা বানানো।
     *
     * ⓘ তিনটা স্তর এটা আটকায় — `AuditEngine::NEVER_LOGGED`, [[User]]-র
     * `auditIgnores()`, আর `created` ঘটনায় ঘর ধরে মান না লেখা। এই
     * পরীক্ষাটা স্তর গোনে না, **ফল** দেখে: টেবিলে হ্যাশ আছে কি নেই।
     */
    public function test_the_password_hash_never_reaches_the_audit_book(): void
    {
        $rahim = $this->makeUser();

        // পাসওয়ার্ড বদলানোর পথটাও — তৈরির পথ আর বদলের পথ এক নয়
        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([
                'password' => 'another-good-secret-7',
            ]))
            ->assertRedirect(route('system_admin.user.index'));

        $this->assertSame(0, AuditFieldChange::query()
            ->whereIn('field', ['password', 'remember_token'])
            ->count(), 'পাসওয়ার্ডের ঘরটাই খাতায় বসে গেছে।');

        /*
         * ⚠️ ঘরের নাম ধরে খোঁজাই যথেষ্ট নয় — কেউ একদিন কলামটার নাম
         * বদলাতে পারেন, আর তখন উপরের দাবিটা সবুজ থাকত অথচ হ্যাশ
         * খাতাতেই বসে থাকত। তাই মানটাও খোঁজা হয়: bcrypt হ্যাশ সবসময়
         * `$2y$` দিয়ে শুরু হয়, ঘরের নাম যা-ই হোক।
         */
        $this->assertSame(0, AuditFieldChange::query()
            ->where(fn ($q) => $q
                ->where('old_value', 'like', '$2y$%')
                ->orWhere('new_value', 'like', '$2y$%'))
            ->count(), 'কোনো একটা ঘরে bcrypt হ্যাশ খাতায় চলে গেছে।');
    }

    /**
     * অথচ ঘটনাটা হারায় না — পাসওয়ার্ড কে বসাল, তা লেখা থাকে।
     *
     * ⚠️ মান বাদ দিতে গিয়ে ঘটনাটাও বাদ পড়লে বাদ দেওয়াটাই একটা নতুন
     * ফাঁক হত: "আমার পাসওয়ার্ড কে বদলেছিল" প্রশ্নটার উত্তর তখন আর
     * কোথাও থাকত না।
     */
    public function test_the_act_of_setting_a_password_is_still_written_down(): void
    {
        $rahim = $this->makeUser();

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $rahim), $this->editForm([
                'password' => 'another-good-secret-7',
            ]));

        $row = $this->trails($rahim, 'password_set')->first();

        $this->assertNotNull($row, 'পাসওয়ার্ড বসানোর ঘটনাটাই খাতা থেকে হারিয়ে গেছে।');
        $this->assertSame($this->owner->id, $row->user_id);
        $this->assertCount(0, $row->changes, 'ঘটনাটার সাথে ঘর ধরে মান যাওয়ার কথা নয়।');
    }

    /**
     * ⛔ রুচি আর যন্ত্রের ঘরগুলো খাতাটা ভরিয়ে দেয় না।
     *
     * ── কেন এই পরীক্ষাটাই আসল সারিটার পাহারা ────────────────────────
     * ⚠️ থিম "দিনে দুবার বদলায়" — কথাটা `WorkspaceController`-এই লেখা,
     * আর সেটা প্রতিটা ব্যবহারকারীর জন্য। ⓘ এগুলো খাতায় তুললে একজন
     * মানুষের ইতিহাস **"theme: light → dark"** সারিতে ভরে যেত, আর তার
     * নিচে চাপা পড়ত ঠিক সেই একটামাত্র সারি যেটার জন্য খাতাটা বানানো:
     * `is_active: 1 → 0`।
     *
     * ⭐ অর্থাৎ এটা "কম লেখা" নয়, **পড়তে পারা**। যে খাতায় সব আছে অথচ
     * কিছুই খুঁজে পাওয়া যায় না, সেটা খাতা নয়।
     *
     * ⓘ লগইনের ইতিহাসও হারায় না — সেটা `login_attempts`-এ নিজেই একটা
     * পূর্ণ, শুধু-যোগের খাতা।
     */
    public function test_a_theme_toggle_does_not_fill_the_audit_book(): void
    {
        $rahim = $this->makeUser();

        $before = $this->trails($rahim)->count();

        $this->actingAs($rahim)->post(route('theme.switch'), ['theme' => 'dark']);
        $this->actingAs($rahim)->post(route('locale.switch'), ['locale' => 'en']);

        /*
         * ⓘ এই দুইটা হুবহু যেভাবে সেবাগুলো লেখে — `CredentialCheck`
         * প্রতিটা সফল লগইনে, আর `AvatarService` ছবি বসানোয়।
         */
        $rahim->forceFill(['last_login_at' => now()])->save();
        $rahim->forceFill(['avatar_path' => 'avatars/rahim.png'])->save();

        $fresh = $rahim->fresh();

        $this->assertSame('dark', $fresh->theme, 'বদলগুলো আদৌ ঘটেনি — পরীক্ষাটা তখন কিছুই দেখছে না।');
        $this->assertSame('en', $fresh->locale);
        $this->assertNotNull($fresh->last_login_at);
        $this->assertSame('avatars/rahim.png', $fresh->avatar_path);

        $this->assertSame($before, $this->trails($rahim)->count(),
            'রুচি বা যন্ত্রের একটা ঘর বদলাতেই খাতায় নতুন সারি বসেছে।');
    }

    /**
     * ⚠️ কোম্পানি বদলানোও খাতায় সারি বসায় না।
     *
     * ⓘ `switchCompany()` ব্যবহারকারীর সারিতে `current_company_id` লেখে,
     * আর মানুষ দিনে বহুবার কোম্পানি বদলান। ⭐ অধিকারটা — অর্থাৎ তিনি
     * **কোথায় ঢুকতে পারেন** — সেটা `companies_changed` আলাদা করে লেখে,
     * যেটা উপরে প্রমাণ করা। দুইটা এক নয়: একটা সিদ্ধান্ত, অন্যটা কেবল
     * কার্সার কোথায় বসল।
     */
    public function test_switching_company_does_not_fill_the_audit_book(): void
    {
        $beta = Company::query()->where('code', 'FMART')->firstOrFail();

        $rahim = $this->makeUser(['companies' => [$this->company->id, $beta->id]]);

        $before = $this->trails($rahim)->count();

        $rahim->switchCompany($beta->id);

        $this->assertSame($beta->id, $rahim->fresh()->current_company_id);
        $this->assertSame($before, $this->trails($rahim)->count());

        // ⓘ `CompanyContext` স্ট্যাটিক — পরের পরীক্ষার জন্য ফিরিয়ে রাখা
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }
}
