<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কোম্পানি বদলের পরে আগে বসা বদল "নতুন" দেখাত (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬; [[SyncService::applyOne()]])।
 *
 * ⚠️ অর্ডারটা ক কোম্পানিতে বসেছিল, উত্তর হারাল; এর মধ্যে মানুষটা ওয়েবে খ-তে গেলেন। ফোন আবার পাঠাল — "আগে পেয়েছি কি না" খোঁজা
 * কোম্পানির দেয়ালের ভেতরে চলত, তাই আগের সারি দেখত না আর বদলটা খ-তে আবার চালাত (unique key গোটা ব্যবস্থার, তাই শেষে
 * ধাক্কা খেয়ে উত্তরহীন, চিরকাল আবার পাঠানো)। ⭐ এখন খোঁজা দেয়াল ছাড়া — আগের উত্তরটাই ফেরে।
 */
final class AChangeWasAppliedAgainAfterACompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_settled_in_one_company_is_a_duplicate_in_another(): void
    {
        $this->seed(DemoSeeder::class);
        $first = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⓘ একই লেখা, দুবার — ফোন উত্তর হারিয়ে হুবহু একই বদল আবার পাঠায়। ⚠️ আগে এখানে বসা সারির লেখা ছিল `'{}'`, আর পাঠানোটা অন্য:
         * a6-এর "একই চাবি, অন্য লেখা = CONFLICT" নিয়মে (ফোন লাইন-ধরে নিরীক্ষা §৭, ১৫ অক্টোবর ২০২৬; [[AChangedResendIsNotTakenForTheFirstTest]])
         * সেটা ঠিকই CONFLICT, তাই দাবিটা ভুল কারণে লাল হত (মেলানোর পরে, a4, ১৭ অক্টোবর ২০২৬)। প্রশ্নটা কোম্পানি বদল, লেখা বদল নয়।
         */
        $payload = json_encode(['customerId' => 'gone', 'lines' => [['productId' => 'gone', 'qty' => '1']]]);

        SyncChange::query()->withoutGlobalScopes()->create([
            'company_id' => $first->id, 'device_id' => 'switch-device-01', 'change_id' => 'change-0001', 'module' => 'sales',
            'entity_type' => 'SalesOrder', 'entity_id' => 'order-local-1', 'operation' => 'CREATE', 'payload_json' => $payload,
            'status' => SyncChange::APPLIED, 'applied_entity_id' => 'order-server-1', 'user_id' => $owner->id, 'received_at' => now(),
        ]);

        $second = Company::query()->create(['code' => 'SWITCH', 'name_en' => 'Switched Traders']);
        $branch = Branch::query()->create(['company_id' => $second->id, 'code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]);
        CompanyContext::set($second->id, $branch->id);
        $this->actingAs($owner);

        $outcomes = app(SyncService::class)->push($owner, 'switch-device-01', 'sales', [[
            'changeId' => 'change-0001', 'entityType' => 'SalesOrder', 'operation' => 'CREATE', 'entityId' => 'order-local-1',
            'payloadJson' => $payload,
        ]]);

        $this->assertSame(SyncChange::DUPLICATE, $outcomes[0]['status'] ?? null, '⛔ আগে বসা বদল কোম্পানি বদলের পরে আবার চলল');
        $this->assertSame('order-server-1', $outcomes[0]['entityId'] ?? null);
    }
}
