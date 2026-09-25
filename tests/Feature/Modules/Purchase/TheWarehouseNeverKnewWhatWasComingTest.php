<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * গুদাম জানত না কী আসার কথা।
 *
 * ── ⛔ ক্রয় মডিউলে একটাও sync হ্যান্ডলার ছিল না ──────────────────────
 * যন্ত্র পেত পণ্য, মজুদ, ক্রেতা, বকেয়া, বিক্রয়াদেশ, আদায়, হাজিরা —
 * ⓘ কিন্তু **কী আসার কথা** সেটা নয়। ⚠️ গুদামের লোক ট্রাকের পাশে
 * দাঁড়িয়ে কাগজ খুঁজতেন, আর না পেলে যা নামছে তা-ই বুঝে নিতেন।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. নিশ্চিত আদেশ যন্ত্রে পৌঁছায়, সারিসহ
 *   ২. খসড়া আদেশ পৌঁছায় **না** — কেউ এখনো কাউকে কিছু বলেনি
 *   ৩. বাতিল আদেশও পৌঁছায়, যাতে যন্ত্র সারিটা **সরাতে** পারে
 *   ৪. চাবি ছাড়া কিছুই পৌঁছায় না
 *   ৫. যন্ত্র থেকে আদেশ ঠেলা যায় না
 *
 * ⓘ (৩) সহজে ভুল হয়: ছেঁকে বাদ দেওয়া আর মুছে ফেলা এক জিনিস নয়।
 * ⛔ বাতিল আদেশ ছাঁকনিতে পড়ে গেলে আগে পাঠানো সারিটা যন্ত্রে **চিরকাল**
 * "আসার কথা" হয়ে বসে থাকত, আর ছাঁকনিটা সম্পূর্ণ নীরব।
 *
 * ── ⭐ কেন আসল HTTP পথে হাঁটা হয় ────────────────────────────────────
 * ⚠️ [[TheSyncPushNeverWorkedForARealPhoneTest]]-এর দামি শিক্ষা:
 * হ্যান্ডলারটা সরাসরি ডাকলে টোকেন, ability আর `deviceId` — তিনটা স্তরের
 * একটাও চলত না, আর যে বাগটা ধরতে পরীক্ষাটা লেখা সেটাই ধরা পড়ত না।
 */
final class TheWarehouseNeverKnewWhatWasComingTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'warehouse-handset-01';

    private string $token = '';

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->supplier = Supplier::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();
    }

    // ── ১ ও ২ · কোনটা যায়, কোনটা যায় না ─────────────────────────────

    public function test_a_confirmed_order_reaches_the_device_with_its_lines(): void
    {
        $order = $this->anOrder(confirm: true);

        $record = $this->recordFor($order);

        $this->assertNotNull($record,
            'নিশ্চিত ক্রয়াদেশটা যন্ত্রে পৌঁছায়নি — গুদামের লোক তাহলে আগের '
            .'মতোই কাগজ খুঁজবেন।');

        $this->assertSame($order->document_no, $record['payload']['documentNo'] ?? null);

        $this->assertCount(1, $record['payload']['lines'] ?? [],
            'আদেশটা গেল, সারিগুলো গেল না — নম্বরটা একা কিছু বলে না, '
            .'প্রশ্নটা "কী কত আসছে"।');

        $this->assertSame(0, bccomp(
            (string) ($record['payload']['lines'][0]['orderedQty'] ?? '0'), '12', 4));
    }

    public function test_a_draft_order_stays_on_the_server(): void
    {
        /*
         * ⛔ খসড়া মানে কেউ এখনো সরবরাহকারীকে কিছু বলেনি। ⚠️ ওটা যন্ত্রে
         * গেলে গুদামের লোক এমন মালের অপেক্ষা করতেন যার অর্ডারই হয়নি।
         */
        $order = $this->anOrder(confirm: false);

        $this->assertNull($this->recordFor($order),
            'খসড়া ক্রয়াদেশও যন্ত্রে চলে গেছে।');
    }

    // ── ৩ · বাতিল হলে যন্ত্রকে জানাতেই হয় ────────────────────────────

    public function test_a_cancelled_order_is_still_sent_so_the_device_can_drop_it(): void
    {
        $order = $this->anOrder(confirm: true);

        app(PurchaseOrderService::class)->cancel($order, 'সরবরাহকারী দিতে পারছেন না');

        $record = $this->recordFor($order);

        $this->assertNotNull($record,
            'বাতিল আদেশটা ছাঁকনিতে পড়ে গেছে — আগে পাঠানো সারিটা যন্ত্রে '
            .'চিরকাল "আসার কথা" হয়ে বসে থাকত, আর কেউ টের পেত না।');

        $this->assertSame(DocumentStatus::CANCELLED, $record['payload']['status'] ?? null,
            'সারিটা গেল, কিন্তু অবস্থাটা নয় — যন্ত্র তাহলে বুঝবে কী করে '
            .'ওটা সরাতে হবে?');
    }

    // ── ৪ · চাবি ছাড়া কিছুই নয় ───────────────────────────────────────

    public function test_without_the_order_key_nothing_comes_across(): void
    {
        $order = $this->anOrder(confirm: true);

        $this->loginAs('sales@abos.test');

        $this->assertNull($this->recordFor($order),
            'ক্রয়াদেশ দেখার চাবি নেই এমন ব্যবহারকারীর যন্ত্রেও আদেশটা '
            .'পৌঁছেছে — দর ও সরবরাহকারী দুইটাই ব্যবসার গোপন কথা।');
    }

    // ── ৫ · ঠেলা যায় না ──────────────────────────────────────────────

    public function test_a_device_cannot_push_an_order_back(): void
    {
        /*
         * ⛔ দর, ছাড়, কর আর অনুমোদনের ছক — চারটাই আদেশের সাথে বাঁধা,
         * আর চারটাই ক্রয় বিভাগের সিদ্ধান্ত। ⚠️ অফলাইনে বসানো একটা দর
         * পরে সংশোধন করার নীরব উপায় নেই: আদেশটা ততক্ষণে সরবরাহকারীর
         * কাছে চলে গেছে।
         */
        $this->loginAs('owner@abos.test');

        /*
         * ⛔ এই দাবিটা আগে **ভুল কারণে সবুজ** ছিল — ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ মোড়কটা ভুল ছিল (`entityId` + `payload`, অথচ দরকার
         * `changeId` + `payloadJson`), তাই গোটা ব্যাচটা **ব্যাখ্যাই করা
         * যেত না**, আর কিছুই তৈরি হত না। ⓘ দাবিটা তখন সত্যি হত, কিন্তু
         * যে কারণে হওয়ার কথা সে কারণে নয়।
         *
         * ⛔ ঠিক এই ফাঁদেই *"আমার নিজের দাবি কখনো খোলা দরজা ধরে না"* —
         * পরীক্ষাটা নিজের ভুলে পাশ করছিল, আর হ্যান্ডলারের প্রত্যাখ্যানটা
         * কোনোদিন চলতই না।
         */
        $response = $this->withToken($this->token)->postJson(
            '/api/v1/sync/purchase/push?deviceId='.self::DEVICE,
            [[
                'changeId' => (string) Str::uuid7(),
                'entityType' => 'PurchaseOrder',
                'operation' => 'create',
                'payloadJson' => json_encode([
                    'documentNo' => 'PO-FAKE',
                ], JSON_THROW_ON_ERROR),
                'updatedAt' => now()->toIso8601String(),
            ]],
        );

        $this->assertSame(0, PurchaseOrder::query()->where('document_no', 'PO-FAKE')->count(),
            'যন্ত্র থেকে ঠেলা একটা আদেশ সত্যিই তৈরি হয়ে গেছে — তার দর, '
            .'ছাড় আর করের কোনোটাই ক্রয় বিভাগ দেখেনি।');

        $this->assertTrue($response->status() < 500, implode("\n", [
            'ঠেলাটা প্রত্যাখ্যান হওয়ার কথা, সার্ভার ভাঙার নয় — প্রত্যাখ্যানও',
            'একটা উত্তর, আর ফোনকে সেটা পড়তে পারতে হয়।',
            '',
            'সার্ভার দিয়েছে '.$response->status().': '.$response->getContent(),
        ]));
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function anOrder(bool $confirm): PurchaseOrder
    {
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $order = app(PurchaseOrderService::class)->create(
            [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString(),
                'expected_on' => now()->addDays(3)->toDateString(),
            ],
            [['product_id' => $this->product->id, 'ordered_qty' => '12', 'rate' => '100', 'tax' => '0']],
        );

        return $confirm
            ? app(PurchaseOrderService::class)->confirm($order)
            : $order;
    }

    /**
     * যন্ত্র যা টেনে পায় — আসল HTTP পথে।
     *
     * @return array<string, mixed>|null
     */
    private function recordFor(PurchaseOrder $order): ?array
    {
        if ($this->token === '') {
            $this->loginAs('owner@abos.test');
        }

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/sync/purchase/pull?deviceId='.self::DEVICE);

        $this->assertTrue($response->status() < 500, implode("\n", [
            'টানাটাই ভেঙেছে — নিচের কোনো দাবিই তাহলে সিঙ্কের কথা বলছে না।',
            '',
            'সার্ভার দিয়েছে '.$response->status().': '.$response->getContent(),
        ]));

        foreach ($this->recordsIn($response->json()) as $record) {
            if (($record['entityType'] ?? null) === 'PurchaseOrder'
                && ($record['entityId'] ?? null) === (string) $order->public_id) {
                /*
                 * ⭐ তারে পেলোডটা একটা **JSON স্ট্রিং**, নেস্টেড অবজেক্ট
                 * নয় ([[SyncRecord::toArray()]]-এ `payloadJson`)।
                 *
                 * ⓘ কারণটা ওখানেই লেখা: ফোনের কিউ আর ক্যাশ দুইটাই
                 * পেলোডকে অস্বচ্ছ স্ট্রিং হিসেবে রাখে, তাই আজকের অ্যাপ
                 * যে ঘরগুলো চেনে না সেগুলোও অক্ষত থেকে যায়।
                 *
                 * ⚠️ পরীক্ষাটা প্রথমে `payload` খুঁজছিল আর `null` পেত —
                 * ⛔ রেকর্ডটা ঠিকই এসেছিল, কেবল দাবিটা ভুল ঘরে তাকাত।
                 */
                $record['payload'] = json_decode((string) ($record['payloadJson'] ?? '{}'), true) ?: [];

                return $record;
            }
        }

        return null;
    }

    /**
     * উত্তরের ভিতর থেকে রেকর্ডের তালিকা।
     *
     * ⓘ মোড়কটা কোন নামে আসে তা একটা সার্ভারের সিদ্ধান্ত, আর সেটা
     * বদলালে পরীক্ষাটা **নীরবে সবুজ** হয়ে যেত (খালি তালিকা মানে
     * "রেকর্ডটা নেই")। ⚠️ তাই যে আকারই আসুক, সারিগুলো খুঁজে বের করা হয়।
     *
     * @param  mixed  $body
     * @return list<array<string, mixed>>
     */
    private function recordsIn($body): array
    {
        if (! is_array($body)) {
            return [];
        }

        if (isset($body[0]) && is_array($body[0])) {
            return $body;
        }

        foreach ($body as $value) {
            if (is_array($value) && isset($value[0]['entityType'])) {
                return $value;
            }
        }

        return [];
    }

    private function loginAs(string $email): void
    {
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', [
            // ডেমোর সবার পাসওয়ার্ড `password` — README দেখুন
            'identifier' => $email,
            'password' => 'password',
            'deviceId' => self::DEVICE,
            'appVersion' => '0.1.0',
            'platform' => 'android',
        ]);

        $this->token = (string) $login->json('accessToken');

        $this->assertNotSame('', $this->token, implode("\n", [
            "লগইনই হয়নি ({$email}) — নিচের কোনো দাবিই তাহলে সিঙ্কের কথা বলছে না।",
            '',
            'সার্ভার দিয়েছে '.$login->status().': '.$login->getContent(),
        ]));

        $this->app['auth']->forgetGuards();
    }
}
