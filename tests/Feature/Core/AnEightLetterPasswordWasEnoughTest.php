<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * আট অক্ষরই যথেষ্ট ছিল — নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ────────────────────────────────────────────────────
 * পাসওয়ার্ড বসানোর প্রতিটা দরজা চাইত অন্তত ৮ অক্ষর, অক্ষর ও সংখ্যা
 * (সেটআপ ১০)। ⚠️ আর কোনো দরজাই জিজ্ঞেস করত না পাসওয়ার্ডটা কোনো ফাঁস
 * হওয়া তালিকায় আছে কি না — অর্থাৎ `password1234` সব দরজায় গৃহীত হত,
 * অথচ আক্রমণকারীর তালিকার প্রথম পাতাতেই ওটা থাকে।
 *
 * ── ⭐ কেন প্রতিটা দরজা আলাদা করে ──────────────────────────────────────
 * নিয়ম এক জায়গায় কড়া হলে বাকিগুলো পুরনো নিয়মেই থেকে যায় — এই রিপোতে
 * ঠিক এটাই দুইবার ঘটেছে ([[NoDoorIsWeakerThanTheStaffDoorTest]])। ⓘ তাই
 * প্রতিটা দরজায় একই তিনটা প্রশ্ন, একই মানুষ, একই ক্রমে: ১১ অক্ষর ফেরে,
 * ফাঁস হওয়াটা ফেরে, ১২ অক্ষর বসে।
 *
 * ── ⚠️ নেটওয়ার্ক ছোঁয়া হয় না ─────────────────────────────────────────
 * ফাঁসের তালিকা (Have I Been Pwned) নকল করা, আর বাইরের যেকোনো অনুরোধ
 * নিষেধ — পরীক্ষা তাই ইন্টারনেট ছাড়াও একই উত্তর দেয়। ⓘ আর নকলটা
 * সত্যিই ডাকা হয়েছে কি না সেটাও দেখা হয়, নাহলে "ফাঁস হওয়াটা ফিরল"
 * অন্য কোনো কারণেও ঘটতে পারত।
 */
final class AnEightLetterPasswordWasEnoughTest extends TestCase
{
    use RefreshDatabase;

    /** এগারো অক্ষর, অক্ষর আর সংখ্যা দুইটাই — কেবল দৈর্ঘ্যে এক কম। */
    private const ELEVEN = 'depot-key-7';

    /** বারো অক্ষর — নিয়ম মানে, ফাঁসের তালিকায় নেই। */
    private const TWELVE = 'depot-key-7a';

    /** বারো অক্ষর, অক্ষর আর সংখ্যা — কিন্তু প্রতিটা ফাঁসের তালিকায় আছে। */
    private const BREACHED = 'password1234';

    /** যিনি বসান (প্রশাসক)। */
    private User $actor;

    /** যাঁর পাসওয়ার্ড বদলায়। */
    private User $target;

    /** ডিলারের দরজায় যাঁর চাবি বসে। */
    private ?Customer $dealer = null;

    /**
     * পাসওয়ার্ড বসানোর প্রতিটা দরজা।
     *
     * ⓘ ডিলারের পোর্টাল-চাবিও (`CustomerPortalController`) — সেটা `User`
     * নয়, কিন্তু সবচেয়ে বাইরের দরজা, আর পিছনে নিজের খতিয়ান।
     *
     * @return array<string, array{string}>
     */
    public static function doors(): array
    {
        return [
            'staff account made by an admin' => ['staff_create'],
            'staff account edited by an admin' => ['staff_edit'],
            'my own profile' => ['profile'],
            'the reset link' => ['reset'],
            'the first owner at setup' => ['setup'],
            'a dealer portal key' => ['dealer_portal'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $breached = strtoupper(sha1(self::BREACHED));

        /*
         * ⚠️ [[TestCase::setUp()]] সবার জন্য একটা "ফাঁস হয়নি" নকল বসায়, আর
         * Http-এর নকলে **আগে বসানোটাই জেতে** — তাই এখানে নতুন করে শুরু, নাহলে
         * নিচের ফাঁস-হওয়া উত্তরটা কখনো পৌঁছাত না (প্রথম রানে ঠিক তাই হয়েছিল)।
         */
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response(
                substr($breached, 5).":3861493\r\n0018A45C4D1DEF81644B54AB7F969B88D65:1",
            ),
        ]);
    }

    #[DataProvider('doors')]
    public function test_eleven_letters_and_a_leaked_password_are_refused_and_twelve_is_accepted(string $door): void
    {
        $this->prepare($door);

        $this->send($door, self::ELEVEN)->assertSessionHasErrors('password');
        $this->assertFalse($this->opens($door, self::ELEVEN), "{$door}: ১১ অক্ষরের পাসওয়ার্ড বসে গেছে।");

        $this->send($door, self::BREACHED)->assertSessionHasErrors('password');
        $this->assertFalse($this->opens($door, self::BREACHED), "{$door}: ফাঁস হওয়া পাসওয়ার্ড বসে গেছে।");

        $this->send($door, self::TWELVE)->assertSessionHasNoErrors();
        $this->assertTrue($this->opens($door, self::TWELVE), "{$door}: ১২ অক্ষরের ভালো পাসওয়ার্ড বসেনি।");

        /*
         * ⭐ ফাঁসের তালিকা সত্যিই জিজ্ঞেস করা হয়েছে — আর কেবল প্রথম পাঁচ
         * অক্ষরের hash দিয়ে, পুরো পাসওয়ার্ড বা পুরো hash নয়।
         */
        $prefix = substr(strtoupper(sha1(self::TWELVE)), 0, 5);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === "https://api.pwnedpasswords.com/range/{$prefix}");
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), self::TWELVE)
            || str_contains($r->url(), strtoupper(sha1(self::TWELVE))));
    }

    /* ── দরজাগুলো ──────────────────────────────────────────────────── */

    private function prepare(string $door): void
    {
        if ($door === 'setup') {
            // ⓘ সেটআপের দরজা কেবল একদম খালি ব্যবস্থায় খোলে — সিডার নয়
            return;
        }

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actor = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->target = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        if ($door === 'dealer_portal') {
            $this->dealer = Customer::query()->create([
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'code' => 'KEY-12',
                'name_en' => 'Key Twelve Traders',
                'status' => DocumentStatus::CONFIRMED,
                'is_active' => true,
            ]);
        }
    }

    private function send(string $door, string $password): TestResponse
    {
        $company = Company::query()->where('code', 'TDEPOT')->first();

        return match ($door) {
            'staff_create' => $this->actingAs($this->actor)->post(route('system_admin.user.store'), [
                'name' => 'Notun Karmi',
                'email' => 'notun-karmi@abos.test',
                'password' => $password,
                'locale' => 'bn',
                'is_active' => '1',
                'roles' => ['salesman'],
                'companies' => [$company->id],
                'default_branch' => [$company->id => $company->defaultBranch()?->id],
            ]),

            'staff_edit' => $this->actingAs($this->actor)->put(route('system_admin.user.update', $this->target), [
                'name' => $this->target->name,
                'email' => $this->target->email,
                'password' => $password,
                'locale' => 'bn',
                'is_active' => '1',
                'roles' => ['salesman'],
                'companies' => [$company->id],
            ]),

            'profile' => $this->actingAs($this->target)->put(route('profile.password'), [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ]),

            'reset' => $this->post(route('password.store'), [
                'token' => PasswordBroker::createToken($this->target),
                'email' => $this->target->email,
                'password' => $password,
                'password_confirmation' => $password,
            ]),

            'dealer_portal' => $this->actingAs($this->actor)->post(route('customer.portal.store', $this->dealer), [
                'password' => $password,
                'password_confirmation' => $password,
            ]),

            'setup' => $this->post(route('system_admin.setup.store'), [
                'name' => 'Al-Amin Shuvo',
                'email' => 'first@example.test',
                'password' => $password,
                'password_confirmation' => $password,
                'company_name' => 'Trade Depot',
                'branch_name' => 'Head Office',
            ]),
        };
    }

    /** পাসওয়ার্ডটা কি সত্যিই ঐ মানুষের ঘরে বসেছে? */
    private function opens(string $door, string $password): bool
    {
        if ($door === 'dealer_portal') {
            $hash = Customer::query()->withoutGlobalScopes()->whereKey($this->dealer->id)->value('portal_password');

            return $hash !== null && Hash::check($password, $hash);
        }

        $email = match ($door) {
            'staff_create' => 'notun-karmi@abos.test',
            'setup' => 'first@example.test',
            default => $this->target->email,
        };

        $hash = User::query()->where('email', $email)->value('password');

        return $hash !== null && Hash::check($password, $hash);
    }
}
