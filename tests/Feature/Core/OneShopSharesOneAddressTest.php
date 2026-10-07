<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * এক দোকান, এক ঠিকানা — একজনের লগইন আরেকজনের দরজা বন্ধ করত (২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * লগইনের দরজায় `throttle:10,1`, নাম ছাড়া। ⚠️ Laravel লগইন-না-করা অনুরোধের
 * চাবি বানায় `ডোমেইন|IP` দিয়ে — তাই ওয়েবের লগইন, ফোনের লগইন, পাসওয়ার্ড
 * রিসেট আর ফোনের "আমি কি পুরনো?" ডাক **এক থলেতে** গোনা হত, মিনিটে দশ,
 * সফল লগইনসহ। দোকানের সবাই এক wifi-তে: সকালে দশজন ঢুকলে এগারোতম জন সঠিক
 * পাসওয়ার্ডেও ৪২৯ পেতেন। লাইভে মাপা (২৯ সেপ্টেম্বর): ওয়েবের ১২টা চেষ্টার
 * পরে ফোনের **প্রথম** লগইনই ৪২৯।
 *
 * ⭐ এখন লগইনের থলে "IP + টাইপ করা নাম" ধরে (মিনিটে ১০), আর গোটা ঠিকানার
 * একটা উঁচু ছাদ (মিনিটে ১২০); বাকি খোলা দরজাগুলোর প্রত্যেকের নিজের থলে।
 * ⓘ একই নামে আন্দাজ করা এখনো আটকায় — নাম+IP-এর সীমায়, আর [[LoginLock]]-এ।
 */
final class OneShopSharesOneAddressTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_IP = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function webLogin(string $identifier, string $password): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
            ->post(route('login.store'), ['identifier' => $identifier, 'password' => $password]);
    }

    private function signOut(): void
    {
        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();
    }

    public function test_ten_phones_opening_the_app_do_not_block_a_correct_web_login(): void
    {
        /*
         * ⓘ উত্তরটা এখানে ৫০৩ (পরীক্ষার পরিবেশে প্রকাশের মান বসানো নেই) —
         * দাবিটা উত্তর নিয়ে নয়, **গোনা** নিয়ে: আগে প্রতিটা ডাক লগইনের থলেতেই পড়ত।
         */
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
                ->getJson('/api/v1/app/version')->getStatusCode());
        }

        $this->assertNotSame(429, $this->webLogin('sales@abos.test', 'password')->getStatusCode(),
            '⛔ দোকানের ফোনগুলো অ্যাপ খুলতেই সঠিক লগইন ৪২৯ খেল।');
        $this->assertAuthenticated();
    }

    public function test_the_eleventh_person_in_the_shop_still_gets_in(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $user = User::factory()->create(['email' => "clerk{$i}@shop.test", 'password' => Hash::make('password')]);
            $this->webLogin($user->email, 'password');
            $this->signOut();
        }

        $this->assertNotSame(429, $this->webLogin('sales@abos.test', 'password')->getStatusCode(),
            '⛔ দশজন ঢোকার পরে এগারোতম জন সঠিক পাসওয়ার্ডেও ৪২৯।');
        $this->assertAuthenticated();
    }

    public function test_a_web_mistake_does_not_close_the_phone_door(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->webLogin('nobody-'.$i.'@shop.test', 'x');
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
            ->postJson('/api/v1/auth/login', ['identifier' => 'sales@abos.test', 'password' => 'password', 'deviceId' => 'shop-phone-1'])
            ->assertOk();
    }

    /** ⛔ একই নামে আন্দাজ এখনো থামে — সঠিক পাসওয়ার্ডও তখন ঢোকায় না। */
    public function test_guessing_one_name_is_still_stopped(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->webLogin('sales@abos.test', 'guess-'.$i);
        }

        $last = $this->webLogin('sales@abos.test', 'password');

        $this->assertGuest();
        $this->assertTrue($last->getStatusCode() === 429 || $last->isRedirect(),
            'দশটা ভুলের পরে সঠিক পাসওয়ার্ড — প্রত্যাশিত: থামানো।');
    }

    /** ⛔ গোটা ঠিকানারও একটা ছাদ আছে — বহু নাম ঘুরিয়ে এক জায়গা থেকে অসীম চেষ্টা নয়। */
    public function test_one_address_trying_many_names_hits_a_ceiling(): void
    {
        /*
         * ⓘ ঘড়ি থামানো: ১২১টা চেষ্টায় (অচেনা নামেও ডামি হ্যাশ চলে) এক মিনিটের বেশি
         * লাগতে পারে, আর তখন জানালা পেরিয়ে গোনা নতুন করে শুরু হত।
         */
        $this->freezeTime();
        $statuses = [];

        for ($i = 0; $i < 121; $i++) {
            $statuses[] = $this->webLogin('spray-'.$i.'@shop.test', 'x')->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 120), 'ছাদের আগেই থামল — দোকানের জন্য বেশি কড়া।');
        $this->assertSame(429, end($statuses), '⛔ ১২০টা আলাদা নামের পরেও একই ঠিকানা থেকে চেষ্টা চলছে।');
    }
}
