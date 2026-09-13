<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * যিনি কোনোদিন ওয়েবে ঢোকেননি, তিনি অ্যাপেও ঢুকতে পারতেন না।
 *
 * ── কী ঘটেছিল, ১৩ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * লাইভে প্রথম বিক্রয়কর্মীর অ্যাকাউন্ট বানানো হলো, আর অ্যাপের দরজায়:
 *
 *     SQLSTATE[23000]: Column 'company_id' cannot be null
 *     (insert into `sync_devices` …)
 *
 * `users.current_company_id` ঘরটা খালি থাকে **যতক্ষণ না কেউ একবার
 * কোম্পানি বাছেন**, আর সেটা ঘটে ওয়েবে ঢোকার সময়
 * ([[ResolveCompanyContext]] খালি দেখলে প্রথম কোম্পানিটা বেছে নেয়,
 * আর সেখানকার মন্তব্যেই লেখা *"প্রথমবার লগইন"*)।
 *
 * ⛔ API-তে ওই পতনের পথটা ছিল না। অর্থাৎ মাঠের যে কর্মী **কোনোদিন
 * ওয়েবে ঢোকেন না** — ঠিক যাঁর জন্য অ্যাপটা বানানো — তিনি প্রথম
 * লগইনেই ৫০০ পেতেন, আর বার্তায় কোনো কারণ থাকত না।
 *
 * ⭐ একই প্রশ্ন, দুই জায়গায় দুই উত্তর — আজকের গোটা দিনের রোগ, এবার
 * ওয়েব আর API-র মাঝখানে।
 */
class TheFirstPhoneLoginHadNoCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'A-quiet-depot-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /**
     * এইমাত্র বানানো একজন — কোম্পানিতে আছেন, কিন্তু কিছুই বাছেননি।
     *
     * ⚠️ `current_company_id` ইচ্ছাকৃতভাবে `null`, কারণ ঠিক ওই অবস্থাটাই
     * পরীক্ষা করা হচ্ছে। একটা ফ্যাক্টরি ওটা ভরে দিলে পরীক্ষাটা কিছুই
     * মাপত না।
     */
    private function freshFieldUser(): User
    {
        $company = Company::query()->firstOrFail();

        $user = User::query()->create([
            'name' => 'নতুন বিক্রয়কর্মী',
            'email' => 'brand.new@abos.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);

        $user->companies()->syncWithoutDetaching([$company->id]);

        $this->assertNull($user->fresh()->current_company_id,
            'সেটআপটাই ভুল — ঘরটা ভরা থাকলে নিচের দাবিটা কিছুই প্রমাণ করে না।');

        return $user;
    }

    /** @return array<string, mixed> */
    private function loginAs(string $identifier, string $password): array
    {
        return [
            'identifier' => $identifier,
            'password' => $password,
            'deviceId' => 'handset-first-login',
        ];
    }

    /**
     * ⭐ প্রথম লগইনেই ঢোকা যায়, আর কোম্পানিটা সারিতে বসে যায়।
     *
     * ⓘ দ্বিতীয় দাবিটা বাদ দেওয়া যায় না: টোকেন পাওয়া গেল অথচ পছন্দটা
     * সংরক্ষিত হলো না — এমন হলে প্রতিবার লগইনে আবার বাছা হত, আর ওয়েব
     * ও অ্যাপ দুই কোম্পানিতে বসে থাকতে পারত।
     */
    public function test_a_user_who_never_opened_the_web_can_still_sign_in_on_the_phone(): void
    {
        $user = $this->freshFieldUser();

        $response = $this->postJson('/api/v1/auth/login',
            $this->loginAs('brand.new@abos.test', self::PASSWORD));

        $response->assertOk();

        $this->assertNotNull($user->fresh()->current_company_id,
            'ঢোকা গেল, কিন্তু কোম্পানির পছন্দটা বসল না — পরের লগইনে আবার একই প্রশ্ন।');

        // হ্যান্ডসেটের সারিটাই আগে ভাঙত, তাই সেটাও গুনে দেখা
        $this->assertDatabaseHas('sync_devices', [
            'device_id' => 'handset-first-login',
            'user_id' => $user->id,
            'company_id' => $user->fresh()->current_company_id,
        ]);
    }

    /**
     * ⛔ কোনো কোম্পানিতেই নেই — ৪০৩, ৫০০ নয়।
     *
     * ⚠️ পার্থক্যটা তুচ্ছ নয়: ৫০০ বলে "আমাদের কিছু ভেঙেছে", আর
     * সেলসম্যান তখন বারবার চেষ্টা করেন। ৪০৩ বলে কী হয়েছে, আর বার্তাটা
     * বলে কাকে বলতে হবে।
     */
    public function test_somebody_in_no_company_is_told_so_instead_of_getting_a_server_error(): void
    {
        User::query()->create([
            'name' => 'কারো দলে নেই',
            'email' => 'nowhere@abos.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login',
            $this->loginAs('nowhere@abos.test', self::PASSWORD));

        $response->assertForbidden();

        // বার্তাটা অনুবাদ থেকে আসে — চাবির নাম পর্দায় উঠলে সেটাও ব্যর্থতা
        $this->assertSame(__('auth.no_company'), $response->json('message'));

        $this->assertDatabaseMissing('sync_devices', ['device_id' => 'handset-first-login']);
    }

    /**
     * যিনি আগেই একটা কোম্পানি বেছে রেখেছেন, তাঁরটা বদলায় না।
     *
     * ⓘ পতনের পথটা কেবল **খালি বা অচল** অবস্থায় চলা উচিত। সবসময়
     * চললে একজন ডেমো কোম্পানিতে বসে থাকা ব্যবহারকারী প্রতিবার লগইনে
     * প্রথম কোম্পানিতে ফিরে যেতেন।
     */
    public function test_an_existing_choice_is_left_alone(): void
    {
        $user = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $chosen = (int) $user->current_company_id;

        $this->assertGreaterThan(0, $chosen, 'ডেমোর কর্মীর কোম্পানি বসানো নেই — দাবিটা তখন ফাঁকা।');

        $this->postJson('/api/v1/auth/login',
            $this->loginAs('sales@abos.test', 'password'))->assertOk();

        $this->assertSame($chosen, (int) $user->fresh()->current_company_id);
    }
}
