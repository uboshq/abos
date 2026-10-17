<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Support\DeliveryOrderStatus;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ মেনুর "অপেক্ষমাণ / আংশিক / ব্যাক / ইতিহাস" সারি ফোনের নিজের DO-পর্দায়, ওয়েবের একই ছাঁকনিতে — ১৭ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ──────────────────────────────────────────────────
 * মালিক: *"mobile er ta mobile app style e zate bujazay sei vabe koro, direct webpage app e bujajayna"*। এই চারটা সারি
 * ফোনে ওয়েব-পাতা হয়ে খুলত, কারণ ফোনের DO-দরজা (`GET /api/v1/sales/delivery-orders`) ট্যাব চিনত না — ফোনের পর্দা
 * কেবল "সব" দেখাতে পারত।
 *
 * ⭐ দাবি: দরজা `tab` নেয়, আর প্রতিটা ট্যাবে ওয়েবের ডেস্কের ([[DeliveryOrderDeskController::tab()]]) ঠিক একই সারি
 * আর একই মোট দেয়; অচেনা ট্যাব মানে সব।
 */
final class ThePhoneOpenedTheOrderTabAsAWebPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_door_filters_by_the_desks_own_tabs(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        Sanctum::actingAs($owner, [AuthController::APP]);

        $body = ['customer' => (string) Customer::query()->where('name_en', 'Rahim Traders')->value('public_id'), 'lines' => [
            ['product' => (string) Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('public_id'), 'qty' => '2'],
        ]];

        $made = [];
        foreach (['pending', 'history', 'back', 'draft'] as $name) {
            $id = $this->postJson('/api/v1/sales/delivery-orders', $body)->assertCreated()->json('id');
            $made[$name] = DeliveryOrder::query()->where('public_id', $id)->firstOrFail();
        }

        DB::table('sal_delivery_orders')->whereIn('id', array_map(fn ($o) => $o->id, $made))->update(['total' => '100.0000']);
        DB::table('sal_delivery_orders')->where('id', $made['pending']->id)->update(['status' => DeliveryOrderStatus::SUBMITTED]);
        DB::table('sal_delivery_orders')->where('id', $made['history']->id)->update(['status' => DeliveryOrderStatus::INVOICED]);
        DB::table('sal_delivery_orders')->where('id', $made['back']->id)->update([
            'status' => DeliveryOrderStatus::ACCOUNTS_APPROVED,
            'accounts_warnings' => json_encode([['kind' => 'stock_short']]),
        ]);

        $ids = fn (string $tab) => collect($this->getJson('/api/v1/sales/delivery-orders?tab='.$tab)->assertOk()->json('orders'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([(string) $made['pending']->public_id], $ids('pending'), '⛔ "অপেক্ষমাণ" ট্যাব ফোনে ছাঁকেনি');
        $this->assertSame([(string) $made['history']->public_id], $ids('history'), '⛔ "ইতিহাস" ট্যাব ফোনে ছাঁকেনি');
        $this->assertSame([(string) $made['back']->public_id], $ids('back'), '⛔ "ব্যাক" ট্যাব ফোনে ছাঁকেনি');
        $this->assertCount(4, $ids('no-such-tab'), 'অচেনা ট্যাবে সব আসার কথা');

        $this->assertSame('100.00', $this->getJson('/api/v1/sales/delivery-orders?tab=pending')->json('total'),
            '⛔ মোট ট্যাবের সারির নয়');

        // ⭐ প্রতিটা ট্যাবে ওয়েবের ডেস্কের সমান — দুই জায়গায় দুই নিয়ম নয়
        foreach (array_keys(DeliveryOrderDeskController::TABS) as $tab) {
            $this->assertSame(
                DeliveryOrderDeskController::tab(DeliveryOrder::query(), $tab)->count(),
                $this->getJson('/api/v1/sales/delivery-orders?tab='.$tab)->assertOk()->json('count'),
                "⛔ \"{$tab}\" ট্যাবে ফোন আর ওয়েব আলাদা বলে");
        }
    }
}
