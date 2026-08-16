<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * টাকা কে বদলাল জানা যায়, কে ঢুকল জানা যায় না।
 *
 * ── আগে যা ছিল ──────────────────────────────────────────────────────
 * `users.last_login_at` — একটাই সংখ্যা, আর কেবল **শেষ সফল** ঢোকার সময়।
 * পরেরটা আগেরটাকে ঢেকে দেয়। ব্যর্থ চেষ্টা একটাও কোথাও লেখা হত না।
 *
 * ফল: অডিট বলতে পারে কোন বিলে ছাড় বসেছে আর কে বসিয়েছে, কিন্তু সেই
 * লোকটা আদৌ ঢুকেছিল কি না, কোথা থেকে — কিছুই বলতে পারে না।
 */
class WhoGotInTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function tryLogin(string $identifier, string $password): TestResponse
    {
        return $this->post(route('login.store'), [
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }

    // ── সফল ঢোকা ────────────────────────────────────────────────────

    /** ঢুকলে খাতায় একটা সারি। */
    public function test_getting_in_is_written_down(): void
    {
        $this->tryLogin($this->owner->email, 'password');

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertTrue($row->succeeded);
        $this->assertSame($this->owner->id, $row->user_id);
        $this->assertNull($row->reason);
    }

    /**
     * প্রতিটা ঢোকা আলাদা সারি — শেষেরটা আগেরটাকে ঢাকে না।
     *
     * `last_login_at` ঠিক এই কারণেই যথেষ্ট ছিল না: "গত সপ্তাহে ইনি কবে
     * কবে ঢুকেছিলেন" প্রশ্নের উত্তর ওই ঘরটায় কোনোদিন ছিল না।
     */
    public function test_every_login_is_kept_not_just_the_last(): void
    {
        $this->tryLogin($this->owner->email, 'password');
        $this->post(route('logout'));
        $this->tryLogin($this->owner->email, 'password');

        $this->assertSame(2, LoginAttempt::query()->where('succeeded', true)->count());
    }

    // ── ব্যর্থ চেষ্টা — এটাই আসল কাজ ────────────────────────────────

    /**
     * ভুল পাসওয়ার্ডের চেষ্টাও খাতায় ওঠে।
     *
     * এটাই সবচেয়ে জরুরি সারি: একই নামে পঁচিশটা মানে কেউ পাসওয়ার্ড
     * আন্দাজ করছে, আর আজ পর্যন্ত ওই পঁচিশটার একটাও কোথাও লেখা হত না।
     */
    public function test_a_wrong_password_is_written_down(): void
    {
        $this->tryLogin($this->owner->email, 'not-the-password');

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertFalse($row->succeeded);
        $this->assertSame(LoginAttempt::WRONG_PASSWORD, $row->reason);
        $this->assertSame($this->owner->id, $row->user_id);
    }

    /** অচেনা নামে চেষ্টাও — আর সেখানে যা টাইপ করা হয়েছিল সেটাই সূত্র। */
    public function test_an_unknown_name_is_written_down(): void
    {
        $this->tryLogin('admin', 'letmein');

        $row = LoginAttempt::query()->latestFirst()->firstOrFail();

        $this->assertFalse($row->succeeded);
        $this->assertSame(LoginAttempt::UNKNOWN, $row->reason);
        $this->assertNull($row->user_id);
        $this->assertSame('admin', $row->identifier);
        $this->assertSame('admin', $row->who(), 'অচেনা নামটাই একমাত্র সূত্র, আর সেটা দেখা যায় না।');
    }

    /** বন্ধ অ্যাকাউন্টে চেষ্টা — সরানো কর্মী এখনো চেষ্টা করছেন। */
    public function test_a_switched_off_account_is_written_down(): void
    {
        $this->owner->forceFill(['is_active' => false])->save();

        $this->tryLogin($this->owner->email, 'password');

        $this->assertSame(LoginAttempt::INACTIVE,
            LoginAttempt::query()->latestFirst()->firstOrFail()->reason);
    }

    /**
     * পর্দা এক কথা বলে, খাতা আরেকটা — আর দুইটাই ঠিক।
     *
     * ── কেন এই পরীক্ষাটা ────────────────────────────────────────────
     * লগইনের পাতা তিনটা ক্ষেত্রেই একই বার্তা দেয়, নাহলে বার্তা পড়ে
     * কেউ ব্যবহারকারীর তালিকা বের করে ফেলত। খাতা লেখার সময় ভুল করে
     * ওই সুরক্ষাটা ভেঙে ফেলা সহজ — তাই দুইটা দাবিই একসাথে।
     */
    public function test_the_screen_still_gives_nothing_away(): void
    {
        $unknown = $this->tryLogin('nobody-at-all', 'x');
        $wrong = $this->tryLogin($this->owner->email, 'x');

        $message = __('auth.failed');

        $unknown->assertSessionHasErrors(['identifier' => $message]);
        $wrong->assertSessionHasErrors(['identifier' => $message]);

        // অথচ খাতায় দুইটা আলাদা কারণ
        $reasons = LoginAttempt::query()->pluck('reason')->all();

        $this->assertContains(LoginAttempt::UNKNOWN, $reasons);
        $this->assertContains(LoginAttempt::WRONG_PASSWORD, $reasons);
    }

    /**
     * পাসওয়ার্ড কখনো খাতায় বসে না — ভুলটাও নয়।
     *
     * মানুষ প্রায়ই নিজের আসল পাসওয়ার্ড ভুল ঘরে টাইপ করে, আর তখন
     * খাতাটাই পাসওয়ার্ডের তালিকা হয়ে যেত।
     */
    public function test_no_password_ever_reaches_the_journal(): void
    {
        $this->tryLogin($this->owner->email, 'SuperSecret123');

        foreach (LoginAttempt::query()->get() as $row) {
            foreach ($row->getAttributes() as $value) {
                $this->assertStringNotContainsString('SuperSecret123', (string) $value,
                    'পাসওয়ার্ডটা খাতায় বসে গেছে।');
            }
        }
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** @param  list<string>  $extra */
    private function clerk(array $extra = []): User
    {
        Permission::findOrCreate('governance.audit.view', 'web');

        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo($extra);

        return $user->fresh();
    }

    /** খাতাটা পড়া যায়, আর ব্যর্থ কারণটা সেখানে লেখা। */
    public function test_the_journal_can_be_read(): void
    {
        $this->tryLogin('admin', 'letmein');

        $this->actingAs($this->clerk(['governance.audit.view']))
            ->get(route('governance.login.index'))
            ->assertOk()
            ->assertSee('admin')
            ->assertSee(__('governance::message.why_unknown'));
    }

    /** অনুমতি ছাড়া নয়। */
    public function test_the_journal_is_behind_a_permission(): void
    {
        $this->actingAs($this->clerk())
            ->get(route('governance.login.index'))
            ->assertForbidden();
    }

    // ── সেশন ────────────────────────────────────────────────────────

    /**
     * নিজের খোলা লগইনগুলো দেখা যায় — অনুমতি ছাড়াই।
     *
     * "আমি কোথায় কোথায় লগইন আছি" প্রতিটা ব্যবহারকারীর নিজের প্রশ্ন।
     * চাবির পেছনে রাখলে যাঁর সবচেয়ে বেশি দরকার — যে কর্মী কাউন্টারে
     * লগইন রেখে এসেছেন — তিনিই পৌঁছাতে পারতেন না।
     */
    public function test_anyone_can_see_their_own_sessions(): void
    {
        $clerk = $this->clerk();

        $this->actingAs($clerk)->get(route('governance.session.index'))->assertOk();

        /*
         * চলতি সেশনের সারিটা হাতে বসানো।
         *
         * ── কেন ────────────────────────────────────────────────────
         * পরীক্ষায় সেশন ডাটাবেজে লেখা হয় না, তাই তালিকাটা খালি আসে।
         * পর্দাটা ঠিকই কাজ করে — সারি না থাকলে দেখানোর কিছু নেই।
         * সারিটা বসিয়ে তবেই "এই যন্ত্রটি" চিহ্নটা যাচাই করা যায়, আর
         * ওই চিহ্নটাই সবচেয়ে জরুরি: নাহলে কেউ নিজেকেই বের করে দিয়ে
         * ভাবতেন কিছু ভেঙেছে।
         */
        DB::table('sessions')->insert([
            'id' => session()->getId(),
            'user_id' => $clerk->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($clerk)
            ->get(route('governance.session.index'))
            ->assertOk()

            /*
             * যন্ত্রটা মানুষের ভাষায় — পুরো user-agent স্ট্রিং নয়।
             *
             * যিনি দেখছেন তিনি জানতে চান "এটা কি আমার ফোন, নাকি
             * কাউন্টারের কম্পিউটার" — ওইটুকুই।
             */
            ->assertSee('Windows')
            ->assertSee('Chrome');

        /*
         * "এই যন্ত্রটি" চিহ্নটা এখানে যাচাই করা হয় না — ইচ্ছাকৃতভাবে।
         *
         * পরীক্ষার হার্নেসে প্রতিটা অনুরোধের সেশন-id আলাদা, তাই
         * চিহ্নটা কখনো মিলত না — অথচ আসল ব্রাউজারে মেলে। এখানে জোর
         * করে মেলাতে গেলে পরীক্ষাটা হার্নেসের একটা কৌশল প্রমাণ করত,
         * পর্দার আচরণ নয়।
         *
         * চিহ্নটার আসল দাবিটা — চলতি সেশনটা আলাদা ভাবে চেনা হয় —
         * প্রমাণিত হয় `test_signing_out_everywhere_else_keeps_this_one`
         * দিয়ে: ওখানে বাকি সব মুছে যায় আর নিজেরটা থেকে যায়।
         */
    }

    /**
     * অন্য জায়গার লগইন বন্ধ করা যায়।
     *
     * পাসওয়ার্ড বদলালেও পুরনো সেশন চলতেই থাকে — অর্থাৎ আজ যাঁকে ছাঁটাই
     * করা হলো, তাঁর খোলা ব্রাউজারটা কাল সকালেও ঢুকতে পারত।
     */
    public function test_another_session_can_be_ended(): void
    {
        $clerk = $this->clerk();

        $this->actingAs($clerk)->get(route('governance.session.index'))->assertOk();

        // অন্য একটা যন্ত্রে খোলা লগইন
        DB::table('sessions')->insert([
            'id' => 'another-device-session',
            'user_id' => $clerk->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($clerk)
            ->delete(route('governance.session.destroy', ['id' => 'another-device-session']))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'another-device-session']);
    }

    /**
     * অন্য কারও সেশন বন্ধ করা যায় না।
     *
     * id-টা অনুরোধেই আসে, তাই এই শর্তটা না থাকলে যে কেউ যে কারও
     * সেশনের id বসিয়ে তাঁকে বের করে দিতে পারতেন।
     */
    public function test_you_cannot_end_somebody_elses_session(): void
    {
        $clerk = $this->clerk();

        DB::table('sessions')->insert([
            'id' => 'the-owners-session',
            'user_id' => $this->owner->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($clerk)
            ->delete(route('governance.session.destroy', ['id' => 'the-owners-session']))
            ->assertRedirect();

        // তৃতীয় আর্গুমেন্টটা কানেকশনের নাম, বার্তার নয় — তাই দাবিটা
        // আলাদা করে লেখা
        $this->assertTrue(
            DB::table('sessions')->where('id', 'the-owners-session')->exists(),
            'অন্য কারও সেশন বন্ধ হয়ে গেছে।'
        );
    }

    /**
     * "বাকি সব জায়গা" — নিজের সব খোলা লগইন যায়, অন্যের একটাও নয়।
     *
     * ── কেন নিজেরটা বাদ যায় তা এখানে যাচাই করা হয় না ────────────────
     * পরীক্ষার হার্নেস চলতি সেশনের সারিটা ডাটাবেজে লেখেই না, তাই
     * "নিজেরটা থেকে গেল" দাবিটা এখানে প্রমাণ করা যায় না — সারিটাই
     * নেই। জোর করে একটা সারি বসিয়ে দাবিটা লিখলে সেটা হার্নেসের একটা
     * কৌশল প্রমাণ করত, কোডের আচরণ নয়।
     *
     * প্রথমে ঠিক সেই ভুলটাই করেছিলাম, আর `assertDatabaseHas`-এর
     * তৃতীয় আর্গুমেন্ট (কানেকশনের নাম, বার্তার নয়) সেটা ঢেকে রেখে
     * পরীক্ষাটা সবুজ দেখাচ্ছিল।
     *
     * যেটা এখানে সত্যিই যাচাই হয়, আর যেটাই আসল ঝুঁকি: কোয়েরিটা
     * **ব্যবহারকারী ধরে বাঁধা** — নাহলে একজনের বোতাম চাপা মানে গোটা
     * দোকানের সবাই বেরিয়ে যেত।
     */
    public function test_signing_out_everywhere_else_leaves_other_people_alone(): void
    {
        $clerk = $this->clerk();

        foreach (['clerk-phone', 'clerk-counter'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $clerk->id,
                'ip_address' => '10.0.0.9',
                'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        DB::table('sessions')->insert([
            'id' => 'somebody-elses',
            'user_id' => $this->owner->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($clerk)->delete(route('governance.session.others'))->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'clerk-phone']);
        $this->assertDatabaseMissing('sessions', ['id' => 'clerk-counter']);

        $this->assertTrue(
            DB::table('sessions')->where('id', 'somebody-elses')->exists(),
            'একজনের বোতাম চাপায় অন্য কেউ বেরিয়ে গেছে।'
        );
    }
}
