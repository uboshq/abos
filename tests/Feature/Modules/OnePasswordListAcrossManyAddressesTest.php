<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Security\LoginLock;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই অ্যাকাউন্টে বারবার ভুল পাসওয়ার্ড — কিছুক্ষণ পর দরজা বন্ধ।
 *
 * ── কেন IP-র সীমাটা যথেষ্ট ছিল না ────────────────────────────────────
 * লগইনে পাহারা ছিল `throttle:10,1` — **IP ধরে** মিনিটে দশবার। ওটা এক
 * মেশিন থেকে চালানো আক্রমণ ধীর করে, কিন্তু আজকের সাধারণ আক্রমণটা তা
 * নয়: একটাই পাসওয়ার্ড-তালিকা বহু ঠিকানা থেকে চালানো হয়, আর তখন
 * প্রতিটা ঠিকানা মিনিটে দশবারের নিচেই থাকে।
 *
 * ফলে পাহারাটা থাকা অবস্থাতেই একটা অ্যাকাউন্টে দিনে হাজার হাজার চেষ্টা
 * করা যেত — আর `routes/auth.php`-এর মন্তব্য দাবি করত তার উপরে আরেকটা
 * স্তর (ক্যাপচা) আছে, যেটা কোডে কোথাও ছিল না।
 *
 * এই পরীক্ষাগুলো তাই **ঠিকানা বদলে বদলে** চেষ্টা করে, কারণ ঠিক ওই
 * ফাঁকটাই বন্ধ করা হয়েছে।
 */
class OnePasswordListAcrossManyAddressesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * ব্যর্থ চেষ্টাগুলো সরাসরি খাতায় বসানো।
     *
     * HTTP দিয়ে করলে `throttle:10,1` আগে থামিয়ে দিত, আর তখন পরীক্ষাটা
     * IP-র সীমাটাই মাপত — যেটা আগেও ছিল। তালাটা খাতা পড়ে সিদ্ধান্ত নেয়,
     * তাই খাতাটাই সাজানো হয়।
     */
    private function failedTries(string $identifier, int $times, string $reason = LoginAttempt::WRONG_PASSWORD): void
    {
        for ($i = 0; $i < $times; $i++) {
            LoginAttempt::create([
                'company_id' => null,
                'user_id' => null,
                'identifier' => $identifier,
                'succeeded' => false,
                'reason' => $reason,
                // প্রতিবার আলাদা ঠিকানা — ঠিক যেভাবে আসল আক্রমণটা আসে
                'ip_address' => '203.0.113.'.($i + 1),
            ]);
        }
    }

    private function lock(): LoginLock
    {
        return app(LoginLock::class);
    }

    public function test_eight_wrong_passwords_shut_the_door(): void
    {
        $this->failedTries('owner@abos.test', LoginLock::TRIES - 1);
        $this->assertNull($this->lock()->locked('owner@abos.test'),
            'সীমার নিচে তালা পড়া উচিত নয় — মানুষ ভুল করে।');

        $this->failedTries('owner@abos.test', 1);
        $this->assertNotNull($this->lock()->locked('owner@abos.test'));
    }

    /**
     * তালাটা টাইপ করা নামের উপর, অ্যাকাউন্টের উপর নয়।
     *
     * আসল নামেই কেবল তালা পড়লে "তালা পড়ল কি না" দেখেই আক্রমণকারী
     * ব্যবহারকারীর তালিকা বের করে ফেলতেন — কোন নামগুলো আছে আর কোনগুলো
     * নেই, দুইটা আলাদা আচরণ দিয়েই বোঝা যেত।
     */
    public function test_a_name_that_does_not_exist_locks_the_same_way(): void
    {
        $this->failedTries('admin', LoginLock::TRIES, LoginAttempt::UNKNOWN);

        $this->assertNotNull($this->lock()->locked('admin'));
    }

    /**
     * বড়-ছোট হরফ বদলে তালা ফাঁকি দেওয়া যায় না।
     *
     * নাহলে একবার `owner`, একবার `Owner` লিখলেই দুইটা আলাদা গোনা হত,
     * আর সীমাটা কার্যত দ্বিগুণ হয়ে যেত।
     */
    public function test_changing_the_case_does_not_dodge_the_lock(): void
    {
        $this->failedTries('Owner@ABOS.test', LoginLock::TRIES);

        $this->assertNotNull($this->lock()->locked('owner@abos.test'));
    }

    /**
     * সফল লগইন গোনাটা মুছে দেয়।
     *
     * ঘড়ির জানালা ধরে গুনলে সফল লগইনের পরেও পুরনো ব্যর্থতাগুলো গোনায়
     * থাকত — কেউ সাতবার ভুল করে অষ্টমবারে ঢুকে, বেরিয়ে এসে আবার একবার
     * ভুল করলেই তালা পড়ত। "তুমি যে তুমিই" প্রমাণ হয়ে গেলে গোনা নতুন
     * করে শুরু হওয়াই স্বাভাবিক।
     */
    public function test_getting_in_once_clears_the_count(): void
    {
        $this->failedTries('owner@abos.test', LoginLock::TRIES);
        $this->assertNotNull($this->lock()->locked('owner@abos.test'));

        LoginAttempt::create([
            'company_id' => null,
            'user_id' => $this->user->id,
            'identifier' => 'owner@abos.test',
            'succeeded' => true,
            'reason' => null,
            'ip_address' => '198.51.100.7',
        ]);

        $this->assertNull($this->lock()->locked('owner@abos.test'));
    }

    /**
     * দুই ধাপের কোড না দেওয়া গোনায় ধরা হয় না।
     *
     * ওটা লগইনের **স্বাভাবিক প্রথম ধাপ** — পর্দা দুই ধাপে, তাই প্রথম
     * চেষ্টায় কোড থাকেই না। গোনায় ধরলে দুই-ধাপের লগইন ব্যবহার করা
     * প্রতিটা মানুষ আটবার লগইন করার পর নিজেই তালাবদ্ধ হতেন — অর্থাৎ
     * নিরাপত্তা বাড়াতে গিয়ে যাঁরা সবচেয়ে সতর্ক তাঁদেরই শাস্তি হত।
     */
    public function test_a_missing_two_step_code_is_not_a_failed_password(): void
    {
        $this->failedTries('owner@abos.test', LoginLock::TRIES * 2, LoginAttempt::NEEDS_CODE);

        $this->assertNull($this->lock()->locked('owner@abos.test'));
    }

    /**
     * তালা পড়লে পাসওয়ার্ড আর যাচাই করাই হয় না।
     *
     * সঠিক পাসওয়ার্ড দিলেও ঢোকা যায় না — তালার মানেই তাই। আর যাচাই
     * না করার দ্বিতীয় কারণ: প্রতিটা bcrypt যাচাই সার্ভারের সময় খায়,
     * তাই তালাবদ্ধ অবস্থাতেও চেষ্টা চালিয়ে গেলে ওটাই একটা আক্রমণ হয়ে
     * দাঁড়াত।
     */
    public function test_the_right_password_still_does_not_open_a_locked_door(): void
    {
        $this->failedTries('owner@abos.test', LoginLock::TRIES);

        $response = $this->post(route('login.store'), [
            'identifier' => 'owner@abos.test',
            'password' => 'Owner#2026',
        ]);

        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();

        // আর চেষ্টাটা খাতায় ওঠে, কারণ তালা পড়ার পরেও চেষ্টা চলা মানে
        // ওটা মানুষের ভুল নয়
        $this->assertSame(1, LoginAttempt::query()
            ->where('reason', LoginAttempt::LOCKED)->count());
    }
}
