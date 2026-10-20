<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Core\Services\PermissionOverrides;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummaries;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Widgets\QuickActionsSummary;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ "দ্রুত কাজ" উইজেট — মালিকের নিয়ম: যে বোতামের চাবি নেই, সেটা আসবেই না (a6, ২০ অক্টোবর ২০২৬; [[QuickActionsSummary]])।
 *
 * দাবি (আসল দরজায়, `GET /api/v1/widgets/summary`):
 *  - কেবল আদেশের চাবি: order আর সবার নোটিশ বোর্ড — আর কিছু নয়; ভাউচারের চাবি দিলে voucher যোগ হয়।
 *  - আদায়ের চাবি একা যথেষ্ট নয় — খাতায় টাকা তোলার চাবিও লাগে (ফোনের /me-র mayCollect)।
 *  - কোনো চাবি নেই: উইজেট আছে, বোতাম কেবল notice (নোটিশ বোর্ড সবার) — খালি নয়, উধাও নয়।
 *  - ফোনে বিক্রয় বন্ধ: order, collect, scan সরে যায়; voucher, approve, notice থাকে।
 */
final class AQuickButtonComesOnlyWithItsKeyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        WidgetSummaries::$testing = [QuickActionsSummary::class];
    }

    protected function tearDown(): void
    {
        WidgetSummaries::$testing = [];
        parent::tearDown();
    }

    public function test_only_the_order_key_gives_only_the_order_button_and_the_voucher_key_adds_voucher(): void
    {
        $sr = $this->staff('sales.order.create');

        $actions = $this->actions($sr);
        $this->assertSame(['order', 'notice'], array_column($actions, 'key'), '⛔ চাবি ছাড়া বোতাম এল।');
        $this->assertSame('/sales/orders/create', $actions[0]['link']);

        $this->grant($sr, 'accounts.voucher.create');
        $actions = $this->actions($sr);
        $this->assertSame(['order', 'voucher', 'notice'], array_column($actions, 'key'), '⛔ ভাউচারের চাবি দিয়েও বোতাম এল না, বা ক্রম ভুল।');
        $this->assertSame('/accounts/voucher-list', $actions[1]['link']);
    }

    public function test_collecting_needs_both_the_collection_key_and_the_books_key(): void
    {
        $sr = $this->staff('sales.collection.create');
        $this->assertSame(['notice'], array_column($this->actions($sr), 'key'), '⛔ খাতায় টাকা তোলার চাবি ছাড়াই আদায়ের বোতাম।');

        $this->grant($sr, 'accounts.voucher.create');
        $this->assertSame(['collect', 'voucher', 'notice'], array_column($this->actions($sr), 'key'));
    }

    public function test_nobody_with_no_keys_gets_only_the_notice_board(): void
    {
        $nobody = $this->staff();

        $widgets = $this->widgets($nobody);
        $this->assertArrayHasKey('quick_actions', $widgets, 'উইজেটটাই নেই — সিদ্ধান্ত: থাকে, কেবল নোটিশ বোর্ডসহ।');
        $this->assertSame([['key' => 'notice', 'link' => '/home/notices']], $widgets['quick_actions']['actions'],
            '⛔ চাবি ছাড়া মানুষ অন্য বোতাম পেলেন।');
    }

    public function test_a_module_switched_off_on_the_phone_takes_its_buttons_away(): void
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertSame(['order', 'collect', 'voucher', 'scan', 'approve', 'notice'], array_column($this->actions($owner), 'key'),
            'প্রস্তুতিটাই ভুল — মালিক সব পারেন।');

        app(SettingsService::class)->set(PhoneModules::key('sales'), false);
        app()->forgetInstance(SettingsService::class);
        app()->forgetInstance(PhoneModules::class);

        $this->assertSame(['voucher', 'approve', 'notice'], array_column($this->actions($owner), 'key'),
            '⛔ ফোনে বিক্রয় বন্ধ, তবু বিক্রয়ের বোতাম — বা অন্য মডিউলের বোতামও গেল।');
    }

    private function staff(string ...$keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $this->grant($user, ...$keys);

        return $user;
    }

    private function grant(User $user, string ...$keys): void
    {
        CompanyContext::forCompany($this->company->id, function () use ($user, $keys) {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionOverrides::class)->forget();
    }

    /** @return list<array{key: string, link: string}> */
    private function actions(User $user): array
    {
        return $this->widgets($user)['quick_actions']['actions'];
    }

    /** @return array<string, mixed> */
    private function widgets(User $user): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return (array) $this->getJson('/api/v1/widgets/summary?keys=quick_actions')->assertOk()->json('widgets');
    }
}
