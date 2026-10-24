<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\TrackingNotices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের ঘণ্টার খবর বলে কোথায় খোলে — কোন কাগজ (`paper`) আর অ্যাপের গন্তব্য (`destination`), ওয়েবের ঠিকানা ভাঙা ছাড়া
 * (চুক্তি `docs/Contract — ফোনের খবর কোথায় খোলে.md`, a4-এর অ্যাপ 0.4.49; ২৪ অক্টোবর ২০২৬)।
 *
 * ⭐ দাবি:
 *   · ডেলিভারির ধাপের খবরে পুরনো ঘরগুলো যেমন ছিল তেমন (পুরনো অ্যাপ ভাঙে না), আর যোগ: `may_open`, `paper {type, public_id}`,
 *     `destination` = tracking; বিস্তারিতের দরজাও একই কথা বলে
 *   · কাগজটা আর না থাকলে খবর তালিকায় থাকে, কিন্তু `url` = null, `may_open` = false, কাগজের `public_id` = null, গন্তব্য `list`
 */
final class ThePhoneNoticeSaysWhereItLeadsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_a_delivery_notice_keeps_its_old_fields_and_names_its_paper_and_where_it_leads(): void
    {
        [$writer, $challan, $bell] = $this->dispatchedNotice();

        Sanctum::actingAs($writer->fresh(), [AuthController::APP]);
        $item = $this->itemOf($bell);

        // ⛔ পুরনো ঘর — নাম, ধরন আর মানে যেমন ছিল
        $old = [
            'id' => (string) $bell->public_id,
            'type' => TrackingNotices::TYPE,
            'title' => (string) $bell->title,
            'body' => (string) ($bell->body ?? ''),
            'url' => route('sales.tracking.show', ['challan', $challan->public_id]),
            'read' => false,
            'at' => $bell->created_at?->toIso8601String(),
            'priority' => (string) $bell->priority,
            'category' => (string) $bell->category,
            'module' => $bell->module,
            'archived' => false,
        ];

        foreach ($old as $key => $value) {
            $this->assertArrayHasKey($key, $item, "⛔ পুরনো ঘর '{$key}' তালিকা থেকে হারাল — পুরনো অ্যাপ ভাঙে।");
            $this->assertSame($value, $item[$key], "⛔ পুরনো ঘর '{$key}'-এর মান বা ধরন বদলাল — পুরনো অ্যাপ ভাঙে।");
        }

        $this->assertTrue($item['may_open'] ?? null, '⛔ নিজের নাগালের চালানের খবর বলল খোলা যায় না।');
        $this->assertSame(['type' => 'delivery_challan', 'public_id' => (string) $challan->public_id], $item['paper'] ?? null,
            '⛔ খবর বলল না কোন কাগজের — অ্যাপকে ওয়েবের ঠিকানা ভাঙতে হত।');
        $this->assertSame('tracking', $item['destination'] ?? null, '⛔ ডেলিভারির ধাপের খবর ট্র্যাকিংয়ে নিয়ে যায় না।');

        // ⓘ বিস্তারিতের দরজাও একই কথা বলে
        $detail = $this->getJson('/api/v1/notifications/'.$bell->public_id)->assertOk()->json('data');
        $this->assertSame($item['paper'], $detail['paper'] ?? null, '⛔ বিস্তারিত আর তালিকা দুই কাগজের কথা বলে।');
        $this->assertSame('tracking', $detail['destination'] ?? null, '⛔ বিস্তারিত আর তালিকা দুই গন্তব্য বলে।');
        $this->assertSame($old['url'], $detail['url'] ?? null, '⛔ বিস্তারিতের ঠিকানা বদলাল।');
    }

    public function test_a_notice_whose_paper_is_gone_has_no_url_and_says_it_may_not_open(): void
    {
        [$writer, $challan, $bell] = $this->dispatchedNotice();

        Sanctum::actingAs($writer->fresh(), [AuthController::APP]);
        $this->assertTrue($this->itemOf($bell)['may_open'] ?? null, 'প্রস্তুতিটাই ভুল — কাগজ থাকতেই খবরটা খোলা যায় না বলছে।');

        // ⓘ কাগজটা মুছে গেল (নরম মোছা) — খবরটা ঘণ্টায় থাকে, কিন্তু খোলার কিছু নেই
        DeliveryChallan::query()->withoutGlobalScopes()->whereKey($challan->id)->update(['deleted_at' => now()]);

        $item = $this->itemOf($bell);
        $this->assertNull($item['url'] ?? 'missing', '⛔ খোলা যায় না এমন কাগজের ঠিকানা ফোনে গেল।');
        $this->assertFalse($item['may_open'] ?? null, '⛔ মোছা কাগজের খবর বলল খোলা যায়।');
        $this->assertSame(['type' => 'delivery_challan', 'public_id' => null], $item['paper'] ?? null,
            '⛔ খোলা যায় না এমন কাগজের public_id বাইরে গেল।');
        $this->assertSame('list', $item['destination'] ?? null, '⛔ খোলা যায় না এমন খবর অ্যাপকে কোথাও নিয়ে যেতে বলল।');
        $this->assertSame((string) $bell->public_id, $item['id'] ?? null, '⛔ খবরটাই তালিকা থেকে হারাল।');

        // ⭐ কেন খোলা যায় না — চাবিটাই, লেখা নয় (a4, fe, ২৪ অক্টোবর ২০২৬); ফোনের পুশেও একই
        $this->assertSame('gone', $item['closed'] ?? null, '⛔ মোছা কাগজের খবর ফোনকে বলল না "কাগজটা আর নেই" (closed = gone)');
        $this->assertSame('gone', app(\App\Core\Notifications\PhoneNoticeTarget::class)->pushFields($bell->fresh(), $writer->fresh())['closed'] ?? null,
            '⛔ মোছা কাগজের পুশ বলল না "কাগজটা আর নেই"');
    }

    /** ⭐ খোলা গেলে কারণ নেই; নাগালের বাইরে গেলে "reach" — `may_open` মিথ্যা হলে `closed` কখনো ফাঁকা নয় */
    public function test_the_phone_is_told_why_a_notice_does_not_open(): void
    {
        [$writer, $challan, $bell] = $this->dispatchedNotice();

        Sanctum::actingAs($writer->fresh(), [AuthController::APP]);
        $item = $this->itemOf($bell);
        $this->assertTrue($item['may_open'] ?? null, 'প্রস্তুতিটাই ভুল — কাগজ থাকতেই খবরটা খোলা যায় না বলছে।');
        $this->assertArrayHasKey('closed', $item, '⛔ ফোনের খবরে closed ঘরটাই নেই');
        $this->assertNull($item['closed'], '⛔ খোলা যায় এমন খবরেও ফোনকে বন্ধের কারণ বলা হল');

        // ⓘ লেখককে চালানের শাখার বাইরের এক শাখায় সীমিত করা — কাগজ আছে, কিন্তু নাগালের বাইরে
        $other = \App\Models\Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('id', '<>', (int) $challan->branch_id)->orderBy('id')->firstOrFail();
        \App\Models\UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $writer->id,
            'scope_type' => \App\Models\UserDataScope::BRANCH, 'scope_id' => $other->id]);
        app(\App\Core\Services\DataScope::class)->forget();

        $item = $this->itemOf($bell);
        $this->assertFalse($item['may_open'] ?? null, 'প্রস্তুতিটাই ভুল — শাখার বাইরে সীমিত লেখক তবু কাগজ খুলতে পারেন বলছে।');
        $this->assertSame('reach', $item['closed'] ?? null, '⛔ নাগালের বাইরের কাগজের খবর ফোনকে বলল না "নাগালের বাইরে" (closed = reach)');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * চালানের লেখক, চালান, আর মালিক রওনা দেওয়ায় লেখকের ঘণ্টার খবর।
     *
     * @return array{0: User, 1: DeliveryChallan, 2: Notification}
     */
    private function dispatchedNotice(): array
    {
        $writer = $this->member();

        $this->actingAs($writer);
        $challan = $this->challan();
        Notification::query()->delete();

        $this->actingAs($this->owner);
        app(DeliveryStageService::class)->move($challan->fresh(), DeliveryStage::DISPATCHED, ['note' => 'test']);

        $bell = Notification::query()->where('user_id', $writer->id)->where('type', TrackingNotices::TYPE)->first();
        $this->assertNotNull($bell, 'প্রস্তুতিটাই ভুল — চালানের লেখক রওনার খবর পাননি।');

        return [$writer, $challan, $bell];
    }

    /** @return array<string, mixed> */
    private function itemOf(Notification $bell): array
    {
        $rows = $this->getJson('/api/v1/notifications')->assertOk()->json('data');
        $item = collect($rows)->firstWhere('id', (string) $bell->public_id);
        $this->assertIsArray($item, 'প্রস্তুতিটাই ভুল — খবরটা ফোনের তালিকায় নেই।');

        return $item;
    }

    private function member(): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    private function challan(): DeliveryChallan
    {
        $service = app(DeliveryChallanService::class);

        return $service->confirm($service->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'delivered_qty' => '5', 'rate' => '10']]));
    }
}
