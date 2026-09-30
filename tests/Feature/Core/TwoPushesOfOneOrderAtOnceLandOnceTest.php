<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ফোনের একই অর্ডার **একই মুহূর্তে** দুইবার এলে দুইটা অর্ডার বসত — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল (নিরাপত্তা-অডিট ২৯ সেপ্টেম্বর, খোঁজ ৯) ──────────────
 * [[TheSameOrderPushedTwiceLandsOnceTest]] মাপে **পরপর** দুইবার — তখন দ্বিতীয়টা
 * আগের দাগ দেখে ফেরে। কিন্তু দুর্বল নেটে ফোন উত্তর না পেয়ে আবার পাঠায়, আর
 * প্রথমটা তখনো চলছে: দুইজনেই "আগে আসেনি" দেখে, দুইজনেই অর্ডার বসায়।
 * `(device_id, change_id)` unique-টা দ্বিতীয় **দাগ** আটকাত, কিন্তু অর্ডারটা ততক্ষণে
 * নিজের লেনদেনে পাকা হয়ে গেছে — ফল: দুইটা অর্ডার, আর ফোন পেত ৫০০।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * দুইটা সত্যিকারের একসাথে-অনুরোধ PHPUnit-এ চালানো যায় না, তাই মাঝখানের মুহূর্তটা
 * বানানো হয়: অর্ডারটা তৈরি হওয়ামাত্র "অন্য অনুরোধটা" একই দাগ বসিয়ে ফেলে — ঠিক
 * যেটা একসাথে-চলা দ্বিতীয় অনুরোধ করত। তখন এই অর্ডারটা ফিরে যেতে হবে, আর ফোন পাবে
 * "আগেই এসেছে", ৫০০ নয়।
 */
final class TwoPushesOfOneOrderAtOnceLandOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $salesman;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->salesman);

        $this->shop = Customer::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    public function test_the_second_of_two_simultaneous_pushes_leaves_no_order_behind(): void
    {
        $changeId = 'local-1759200000000000-0';
        $before = SalesOrder::query()->count();

        /*
         * ⓘ "অন্য অনুরোধ" — অর্ডার তৈরির মুহূর্তে দাগটা বসিয়ে ফেলে, ঠিক যেন সে
         * এক পলক আগে শেষ করেছে। ⚠️ একবারই, নাহলে নিজের অর্ডারেও চলত।
         *
         * ⚠️ আলাদা সংযোগে — অন্য অনুরোধ নিজের লেনদেনে পাকা করে। একই সংযোগে বসালে এই
         * অনুরোধের রোলব্যাকে সেটাও মুছে যেত, আর মাপটা বাস্তব থাকত না।
         */
        config(['database.connections.the_other_request' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('the_other_request');

        $raced = false;
        SalesOrder::created(function () use (&$raced, $changeId, $other) {
            if ($raced) {
                return;
            }
            $raced = true;

            /*
             * ⓘ বাইরের-চাবির যাচাই বন্ধ কেবল এই এক সারির জন্য — এই অনুরোধের লেনদেন
             * ব্যবহারকারী আর কোম্পানির সারিতে তালা ধরে আছে, তাই যাচাইটা অপেক্ষায় আটকে
             * যেত; আসল অন্য অনুরোধের লেনদেন ওই তালা একই মুহূর্তে ধরে থাকে না।
             */
            $other->statement('SET FOREIGN_KEY_CHECKS=0');
            $other->table('sync_changes')->insert([
                'company_id' => CompanyContext::id(),
                'device_id' => 'phone-a',
                'change_id' => $changeId,
                'module' => 'sales',
                'entity_type' => 'SalesOrder',
                'operation' => 'CREATE',
                'payload_json' => '{}',
                'client_version' => 1,
                'status' => SyncChange::APPLIED,
                'applied_entity_id' => 'the-other-request',
                'user_id' => $this->salesman->id,
                'received_at' => now(),
                'public_id' => (string) Str::ulid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $other->statement('SET FOREIGN_KEY_CHECKS=1');
        });

        // ⓘ আলাদা সংযোগের সারি পরীক্ষার রোলব্যাকে মোছে না — নিজেই মুছতে হয়
        $this->beforeApplicationDestroyed(fn () => $other->table('sync_changes')
            ->where('device_id', 'phone-a')->where('change_id', $changeId)->delete());

        $outcome = app(SyncService::class)->push($this->salesman, 'phone-a', 'sales', [$this->orderChange($changeId)]);

        $this->assertSame(SyncChange::DUPLICATE, $outcome[0]['status'],
            '⛔ একসাথে-আসা দ্বিতীয় পাঠানোটা "আগেই এসেছে" বলল না।');
        $this->assertSame('the-other-request', $outcome[0]['entityId'] ?? null,
            'ফোনের কাছে যে অর্ডারটা সত্যি বসেছে, তার চাবিটাই ফিরতে হবে।');
        $this->assertSame($before, SalesOrder::query()->count(),
            '⛔ একসাথে-আসা দুই পাঠানোয় দ্বিতীয় অর্ডারটা থেকে গেল — মাস শেষে বিক্রি দ্বিগুণ দেখাত।');
        /*
         * ⓘ গোনাটা অন্য সংযোগে — এই সংযোগ পরীক্ষার নিজের লেনদেনের পুরনো ছবি দেখে,
         * অন্যজনের পাকা সারিটা সেখানে নেই (REPEATABLE READ)।
         */
        $this->assertSame(1, $other->table('sync_changes')
            ->where('device_id', 'phone-a')->where('change_id', $changeId)->count(),
            'একই বদলের দাগ ঠিক একটা থাকার কথা — অন্য অনুরোধেরটা।');
    }

    public function test_an_ordinary_push_still_lands(): void
    {
        $before = SalesOrder::query()->count();

        $outcome = app(SyncService::class)->push($this->salesman, 'phone-a', 'sales', [$this->orderChange('local-1759200000000001-0')]);

        $this->assertSame(SyncChange::APPLIED, $outcome[0]['status']);
        $this->assertSame($before + 1, SalesOrder::query()->count());
    }

    /** @return array<string, mixed> */
    private function orderChange(string $changeId): array
    {
        return [
            'changeId' => $changeId,
            'entityType' => 'SalesOrder',
            'entityId' => null,
            'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'customerId' => (string) $this->shop->public_id,
                'trxDate' => now()->toDateString(),
                'lines' => [[
                    'productId' => (string) $this->product->public_id,
                    'qty' => '2',
                    'rate' => '100',
                ]],
            ]),
            'clientVersion' => 1,
        ];
    }
}
