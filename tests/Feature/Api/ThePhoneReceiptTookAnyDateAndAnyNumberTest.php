<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Purchase\Sync\GoodsReceiptSync;
use App\Modules\Sales\Sync\CollectionSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ফোনের মাল গ্রহণ যেকোনো তারিখ আর অঙ্ক নিত, আর আদায়ের বণ্টনের অঙ্ক যাচাই হত না (Api/Sync নিরীক্ষা, ১৪ অক্টোবর ২০২৬;
 * [[GoodsReceiptSync::apply()]], [[CollectionSync::apply()]])।
 *
 * ⚠️ "+1 year" তারিখে ভবিষ্যতের রসিদ বসত (ওয়েব আজকের পরে নেয় না); "1e3" পরিমাণ বা বণ্টন `is_numeric` পেরিয়ে bccomp-এ ভাঙত —
 * অপ্রত্যাশিত ভাঙন, তাই উত্তরহীন হয়ে চিরকাল আবার যেত। ⭐ এখন ওয়েবের নিয়মেই কারণসহ ফেরত।
 */
final class ThePhoneReceiptTookAnyDateAndAnyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_receipt_and_collection_meet_the_web_rules_first(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $change = fn (string $type, array $payload) => PushedChange::fromArray([
            'changeId' => (string) Str::uuid(), 'entityType' => $type, 'operation' => 'CREATE', 'entityId' => (string) Str::uuid(),
            'payloadJson' => json_encode($payload),
        ]);

        try {
            app(GoodsReceiptSync::class)->apply($owner, $change('GoodsReceipt', [
                'orderId' => 'no-such-order', 'receivedOn' => now()->addYear()->toDateString(),
                'lines' => [['productId' => 'p', 'receivedQty' => '1e3']],
            ]));
            $this->fail('⛔ ভবিষ্যতের তারিখ আর "1e3"-এর রসিদ যাচাই পেরোল');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receivedOn', $e->errors(), '⛔ ভবিষ্যতের রসিদের তারিখ ধরা পড়েনি');
            $this->assertArrayHasKey('lines.0.receivedQty', $e->errors(), '⛔ "1e3" পরিমাণ ধরা পড়েনি');
        }

        $shop = Customer::query()->orderBy('id')->firstOrFail();

        try {
            app(CollectionSync::class)->apply($owner, $change('Collection', [
                'customerId' => (string) $shop->public_id, 'trxDate' => now()->toDateString(), 'amount' => '100',
                'allocations' => [['invoiceId' => 'no-such-bill', 'amount' => '1e3']],
            ]));
            $this->fail('⛔ "1e3" বণ্টনের আদায় বসল');
        } catch (SyncRejection $e) {
            $this->assertNotSame(__('sales::sync.unknown_invoice'), $e->getMessage(), '⛔ "1e3" বণ্টন যাচাইয়ে ধরা পড়ল না — বিল খুঁজতে চলে গেল');
        }
    }
}
