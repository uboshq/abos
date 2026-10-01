<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsInPastTheSecondStep;
use Tests\TestCase;

/**
 * ফোনে সব মডিউল দেখাত, আর বন্ধ করার কোনো সুইচ ছিল না — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ── ⭐ মালিকের নিয়ম ─────────────────────────────────────────────────────
 * অ্যাপ সব মডিউলের মেনু বহন করে; **প্রতিটা মডিউলের একটা চালু/বন্ধ সুইচ**,
 * কোম্পানি ধরে, সার্ভারে (`mobile.modules.<code>`, কন্ট্রোল প্যানেলের "মোবাইল
 * অ্যাপ" ট্যাব)। ডিফল্ট চালু: হিসাব, মজুদ, বিক্রয়, ক্রয়, অনুমোদন, প্রশাসন।
 *
 * ── ⛔ মেনু লুকানো দেয়াল নয় (সমন্বয়কের শর্ত) ─────────────────────────────
 * বন্ধ মডিউলের দরজাও সার্ভারে ৪০৩ — সিঙ্ক, রিপোর্ট, কাগজ, অনুমোদন। প্রতিটা
 * দাবি **একই মানুষ, একই টোকেন**: চালু → ঢোকেন, বন্ধ → ফেরেন, আবার চালু → ঢোকেন।
 * সুইচ বদলায় কেবল সেটিংয়ের পথে (কন্ট্রোল প্যানেল), আর প্রতিটা বদল নিরীক্ষায়।
 */
final class ThePhoneShowedEveryModuleTest extends TestCase
{
    use RefreshDatabase;
    use SignsInPastTheSecondStep;

    private const DEVICE = 'handset-modules';

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->token = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'owner@abos.test',
            'password' => 'password',
            'code' => $this->secondStepCode('owner@abos.test'),
            'deviceId' => self::DEVICE,
            'appVersion' => '0.4.2',
            'platform' => 'android',
        ])->assertOk()->json('accessToken');
    }

    /** ফোনের দরজায়, ফোনের টোকেনে — ওয়েবের কোনো সেশন সাথে নয়। */
    private function phone(string $method, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->json($method, $uri);
    }

    /**
     * ⭐ সুইচ বদলানো — সেটিংয়ের পথেই, কন্ট্রোল প্যানেল দিয়ে, মানুষের হাতে।
     *
     * ⚠️ চাবির নাম এখানে হাতে লেখা (`mobile.modules.hr`) — কোডের ধ্রুবক থেকে নয়,
     * যাতে নামটাই দাবির অংশ থাকে।
     *
     * @param  array<string, bool>  $switches
     */
    private function flip(array $switches, ?User $who = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $keys = array_map(fn (string $code) => 'mobile.modules.'.$code, array_keys($switches));

        $response = $this->actingAs($who ?? $this->owner)->put(route('system_admin.control-panel.update'), [
            'scope' => $keys,
            'settings' => array_combine($keys, array_map(fn (bool $on) => $on ? '1' : '0', array_values($switches))),
        ]);

        $this->app['auth']->forgetGuards();
        app(SettingsService::class)->flush();

        return $response;
    }

    /** @return list<string> */
    private function menuCodes(): array
    {
        return array_column($this->phone('GET', '/api/v1/me')->assertOk()->json('menu'), 'code');
    }

    private function assertModuleOff(TestResponse $response, string $code): void
    {
        $response->assertForbidden()
            ->assertJsonPath('reason', 'module_off')
            ->assertJsonPath('module', $code);
    }

    public function test_a_new_company_starts_with_the_owners_six(): void
    {
        $body = $this->phone('GET', '/api/v1/me')->assertOk()->json();

        $this->assertEqualsCanonicalizing(
            ['accounts', 'inventory', 'sales', 'purchase', 'approval', 'system_admin'],
            $body['phoneModules'],
        );

        foreach (array_column($body['menu'], 'code') as $code) {
            $this->assertContains($code, $body['phoneModules'], "the menu carries {$code}, which is off");
        }

        $this->assertNotContains('hr', array_column($body['menu'], 'code'));
        $this->assertNotContains('finance', array_column($body['menu'], 'code'));
    }

    public function test_the_menu_follows_the_switch_for_the_same_token(): void
    {
        $this->assertNotContains('hr', $this->menuCodes(), 'hr is off by default');

        $this->flip(['hr' => true])->assertRedirect();
        $this->assertContains('hr', $this->menuCodes());
        $this->assertContains('hr', $this->phone('GET', '/api/v1/me')->json('phoneModules'));

        $this->flip(['hr' => false])->assertRedirect();
        $this->assertNotContains('hr', $this->menuCodes());

        $this->flip(['hr' => true])->assertRedirect();
        $this->assertContains('hr', $this->menuCodes());
    }

    public function test_the_sync_door_follows_the_switch_not_just_the_menu(): void
    {
        $pull = '/api/v1/sync/hr/pull?deviceId='.self::DEVICE;
        $push = '/api/v1/sync/hr/push?deviceId='.self::DEVICE;

        $this->flip(['hr' => true]);
        $this->phone('GET', $pull)->assertOk();
        $this->assertContains('hr', array_column($this->phone('GET', '/api/v1/sync/capabilities')->json(), 'module'));

        $this->flip(['hr' => false]);
        $this->assertModuleOff($this->phone('GET', $pull), 'hr');
        $this->assertModuleOff($this->phone('POST', $push), 'hr');
        $this->assertNotContains('hr', array_column($this->phone('GET', '/api/v1/sync/capabilities')->json(), 'module'),
            'the phone must not even plan to pull a switched-off module');

        $this->flip(['hr' => true]);
        $this->phone('GET', $pull)->assertOk();
    }

    public function test_a_module_others_stand_on_still_sends_its_data(): void
    {
        /*
         * ⓘ গ্রাহকের নিজের মেনু বন্ধ (ডিফল্ট), কিন্তু বিক্রয় চালু — আর অর্ডার লিখতে
         * গ্রাহকের তালিকা লাগে (Sales `depends_on` customer)। ⛔ তথ্য আটকালে বিক্রয়
         * চালু রেখেও ফোনে অর্ডার লেখা যেত না।
         */
        $this->assertNotContains('customer', $this->menuCodes());
        $this->phone('GET', '/api/v1/sync/customer/pull?deviceId='.self::DEVICE)->assertOk();
    }

    public function test_a_report_follows_the_switch(): void
    {
        $report = '/api/v1/reports/promotion.register';
        $listed = fn (): bool => in_array('promotion.register', array_column($this->phone('GET', '/api/v1/reports')->assertOk()->json(), 'key'), true);

        $this->flip(['promotion' => true]);
        $this->phone('GET', $report)->assertOk();
        $this->assertTrue($listed());

        $this->flip(['promotion' => false]);
        $this->assertModuleOff($this->phone('GET', $report), 'promotion');
        $this->assertModuleOff($this->phone('GET', $report.'/export?format=csv'), 'promotion');
        $this->assertFalse($listed());

        $this->flip(['promotion' => true]);
        $this->phone('GET', $report)->assertOk();
    }

    public function test_a_paper_follows_the_switch(): void
    {
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        app(SalesOrderService::class)->create(
            [
                'customer_id' => Customer::query()->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'ordered_qty' => '10', 'rate' => '200']],
        );
        $order = SalesOrder::query()->latest('id')->firstOrFail();
        $papers = '/api/v1/documents/SalesOrder/'.$order->public_id.'/papers';

        $this->phone('GET', $papers)->assertOk();

        /*
         * ⓘ বিক্রয় একা বন্ধ করলে যথেষ্ট নয় — ক্রয় বিক্রয়ের উপর দাঁড়ায়
         * (Purchase `depends_on` sales), তাই দুইটাই বন্ধ হলে তবে বিক্রয়ের কাগজ বন্ধ।
         */
        $this->flip(['sales' => false, 'purchase' => false]);
        $this->assertModuleOff($this->phone('GET', $papers), 'sales');

        $this->flip(['sales' => true, 'purchase' => true]);
        $this->phone('GET', $papers)->assertOk();
    }

    public function test_approvals_follow_the_switch(): void
    {
        $this->phone('GET', '/api/v1/approvals/pending')->assertOk();

        $this->flip(['approval' => false]);
        $this->assertModuleOff($this->phone('GET', '/api/v1/approvals/pending'), 'approval');

        $this->flip(['approval' => true]);
        $this->phone('GET', '/api/v1/approvals/pending')->assertOk();
    }

    public function test_every_change_is_written_down_who_when_old_and_new(): void
    {
        $this->flip(['hr' => true]);
        $this->flip(['hr' => false]);

        $row = Setting::query()->withoutGlobalScopes()->where('key', 'mobile.modules.hr')->firstOrFail();

        $trail = AuditTrail::query()->withoutGlobalScopes()
            ->forRecord(Setting::class, $row->id)
            ->where('action', AuditTrail::UPDATED)
            ->with('changes')
            ->firstOrFail();

        $change = $trail->changes->keyBy('field')['value'];
        $this->assertSame('1', $change->old_value);
        $this->assertSame('0', $change->new_value);
        $this->assertSame($this->owner->id, $trail->user_id);
        $this->assertNotNull($trail->created_at);
    }

    public function test_someone_without_the_admin_key_cannot_flip_a_switch(): void
    {
        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->flip(['hr' => true], $sales)->assertForbidden();

        $this->assertNotContains('hr', $this->menuCodes());
        $this->assertFalse(Setting::query()->withoutGlobalScopes()->where('key', 'mobile.modules.hr')->exists());
    }

    public function test_switching_administration_off_for_the_phone_never_locks_the_owner_out(): void
    {
        $this->flip(['system_admin' => false])->assertRedirect();
        $this->assertNotContains('system_admin', $this->menuCodes());

        // ⭐ ওয়েবের কন্ট্রোল প্যানেল খোলা থাকে, সুইচটাও সেখানে — ফেরার পথ আছে
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)
            ->get(route('system_admin.control-panel', ['tab' => 'mobile']))
            ->assertOk()
            ->assertSee('mobile.modules.system_admin', false);

        $this->flip(['system_admin' => true])->assertRedirect();
        $this->assertContains('system_admin', $this->menuCodes());
    }
}
