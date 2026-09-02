<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\SyncState;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * একই অর্ডার দুইবার পৌঁছালে একবারই বসে।
 *
 * ── কেন এটাই সিঙ্কের সবচেয়ে জরুরি পরীক্ষা ────────────────────────────
 * মোবাইল নেটওয়ার্কে একই অনুরোধ দুইবার পৌঁছানো **নিয়ম, ব্যতিক্রম নয়**।
 * ফোন অর্ডারটা পাঠায়, সার্ভার বসিয়ে উত্তর দেয়, উত্তরটা পথে হারায় —
 * আর ফোন, যে জানেই না কী হয়েছে, ঠিক কাজটাই করে: আবার পাঠায়।
 *
 * পাহারা না থাকলে দুইটা অর্ডার বসত, আর **কোনো যাচাই লাল হত না**।
 * দুইটাই বৈধ, দুইটাতেই সব ঘর ভরা, দুইটারই ডেবিট-ক্রেডিট মেলে। বইয়ের
 * পরীক্ষা সবুজ থাকত, রেওয়ামিল মিলত — শুধু বিক্রি দ্বিগুণ দেখাত, আর
 * ভুলটা ধরা পড়ত মাস শেষে, যখন কেউ একটা সংখ্যা মেলাতে গিয়ে আটকে যেতেন।
 *
 * ── এই পরীক্ষাটা ইচ্ছে করে ভেঙে দেখা হয়েছে ─────────────────────────
 * [[SyncService::applyOne()]]-এর `$existing !== null` শাখাটা মন্তব্য করে
 * দিলে **`test_the_same_change_pushed_twice_creates_one_order` লাল হয়**
 * (দুইটা অর্ডার), আর বাকিগুলো সবুজ থাকে। সবুজ কিন্তু অন্ধ পাহারা এই
 * রিপোতে অলংকার মাত্র — তাই দেখে নেওয়া হয়েছে।
 */
class TheSameOrderPushedTwiceLandsOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    private Customer $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->salesman);

        $this->shop = Customer::query()->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    /**
     * ⚠️ এটাই আসল পরীক্ষা — বাকিগুলো এর চারপাশ।
     */
    public function test_the_same_change_pushed_twice_creates_one_order(): void
    {
        $before = SalesOrder::query()->count();
        $change = $this->orderChange('local-1756800000000000-0');

        $first = $this->sync()->push($this->salesman, 'phone-a', 'sales', [$change]);
        $second = $this->sync()->push($this->salesman, 'phone-a', 'sales', [$change]);

        $this->assertSame(SyncChange::APPLIED, $first[0]['status']);

        // ফোনের কাছে DUPLICATE আর APPLIED একই মানে — "কিউ থেকে মুছে ফেলো"
        $this->assertSame(SyncChange::DUPLICATE, $second[0]['status']);

        $this->assertSame(
            $before + 1,
            SalesOrder::query()->count(),
            'একই changeId দুইবার এসে দুইটা অর্ডার বসিয়েছে — ঠিক এই ভুলটাই মাস শেষে বিক্রি দ্বিগুণ দেখাত।',
        );
    }

    /**
     * দুইটা আলাদা ফোন একই মাইক্রোসেকেন্ডে শুরু করলেও দুইটাই বসে।
     *
     * চাবিটা ফোনে তৈরি হয় — `local-<মাইক্রোসেকেন্ড>-<ক্রম>` — তাই দুইটা
     * হ্যান্ডসেটের প্রথম বদল দুইটা **হুবহু একই চাবি** পেতে পারে। unique
     * ইনডেক্সটা তাই `(device_id, change_id)` জোড়ায়; বিশ্বজুড়ে unique
     * ধরলে দ্বিতীয় ফোনের আসল অর্ডারটা DUPLICATE বলে ফেলে দেওয়া হত।
     */
    public function test_two_phones_may_generate_the_same_change_id(): void
    {
        $before = SalesOrder::query()->count();
        $collidingId = 'local-1756800000000000-0';

        $this->sync()->push($this->salesman, 'phone-a', 'sales', [$this->orderChange($collidingId)]);
        $outcome = $this->sync()->push($this->salesman, 'phone-b', 'sales', [$this->orderChange($collidingId)]);

        $this->assertSame(SyncChange::APPLIED, $outcome[0]['status']);
        $this->assertSame($before + 2, SalesOrder::query()->count());
    }

    /**
     * প্রত্যাখ্যান নীরবে হারায় না — কারণসহ লেখা থাকে।
     *
     * ফোনের দিকেও একই নিয়ম (`SyncEngine.rejectedItems`)। "০টা অপেক্ষমাণ"
     * দেখে সেলসম্যানের ধরে নেওয়ার কথা যে সব পৌঁছে গেছে, তাই প্রত্যাখ্যাত
     * সারিটা মুছে ফেলা মানে তাঁকে মিথ্যা বলা।
     */
    public function test_a_refused_change_keeps_its_reason(): void
    {
        $outcome = $this->sync()->push($this->salesman, 'phone-a', 'sales', [[
            'changeId' => 'local-1756800000000001-0',
            'entityType' => 'SalesOrder',
            'operation' => 'CREATE',
            'payloadJson' => json_encode([
                'customerId' => 'a-shop-that-does-not-exist',
                'lines' => [['productId' => (string) $this->product->public_id, 'qty' => '1', 'rate' => '10']],
            ]),
            'clientVersion' => 1,
        ]]);

        $this->assertSame(SyncChange::REJECTED, $outcome[0]['status']);
        $this->assertNotEmpty($outcome[0]['message']);

        $row = SyncChange::query()->where('change_id', 'local-1756800000000001-0')->firstOrFail();
        $this->assertSame(SyncChange::REJECTED, $row->status);
        $this->assertNotNull($row->message, 'কারণ ছাড়া প্রত্যাখ্যান মানে সেলসম্যান জানবেন না কী করতে হবে।');
    }

    /**
     * নেট ছাড়া শুধু অর্ডার — মালিকের সিদ্ধান্ত, ২ সেপ্টেম্বর ২০২৬।
     *
     * গ্রাহক ফোন থেকে বসানো যায় না, আর "না"-টা **নীরব উপেক্ষা নয়**:
     * একটা পুরনো বিল্ড যদি এমন কিছু পাঠায় যা আজ আর অনুমোদিত নয়,
     * সেলসম্যান কারণটা পর্দায় দেখবেন।
     */
    public function test_only_orders_may_be_written_offline(): void
    {
        $outcome = $this->sync()->push($this->salesman, 'phone-a', 'customer', [[
            'changeId' => 'local-1756800000000002-0',
            'entityType' => 'Customer',
            'operation' => 'CREATE',
            'payloadJson' => json_encode(['nameEn' => 'A shop from the field']),
            'clientVersion' => 1,
        ]]);

        $this->assertSame(SyncChange::REJECTED, $outcome[0]['status']);
        $this->assertStringContainsString('অর্ডার', $outcome[0]['message']);
    }

    /**
     * ⚠️ অনুমতি না থাকলে রেকর্ড যায় না — সিঙ্কের দরজায় `can:` নেই বলেই
     * এই যাচাইটা সবচেয়ে জরুরি।
     *
     * ছাঁকনিটা [[SyncService::pull()]]-এ, আর চাবিটা চুক্তির পদ্ধতি
     * [[SyncsToDevices::requiredPermission()]] — অর্থাৎ নতুন হ্যান্ডলার
     * লিখতে গিয়ে ওটা **বাদ দেওয়া যায় না**, ক্লাসটাই তৈরি হয় না।
     */
    public function test_a_user_without_the_permission_pulls_nothing(): void
    {
        $withKey = $this->sync()->pull($this->salesman, 'phone-a', 'customer', 100);
        $this->assertNotEmpty($withKey['records'], 'বিক্রয়কর্মীর customer.view আছে — তালিকা আসার কথা।');

        $this->salesman->revokePermissionTo('customer.view');
        $this->salesman->roles()->detach();
        $this->salesman->forgetCachedPermissions();

        $withoutKey = $this->sync()->pull($this->salesman->fresh(), 'phone-a', 'customer', 100);

        $this->assertSame([], $withoutKey['records']);
        $this->assertSame([], $withoutKey['unreadable'], 'অনুমতি না থাকা ব্যর্থতা নয় — unreadable-এ গেলে ফোন চিরকাল "সিঙ্ক চলছে" দেখাত।');
    }

    /**
     * ওয়াটারমার্ক এগোয় কেবল ফোন "পুরোটা পেয়েছি" বললে।
     *
     * ── কেন এটা পরীক্ষা করা দরকার ───────────────────────────────────
     * সার্ভার নিজে থেকে এগিয়ে দিলে একটা হারিয়ে যাওয়া উত্তরের পর
     * রেকর্ডগুলো **চিরতরে** বাদ পড়ত — নীরবে, কারণ ফোনের দিক থেকে
     * সবকিছু স্বাভাবিক দেখাত।
     */
    public function test_the_watermark_moves_only_when_the_phone_says_so(): void
    {
        $first = $this->sync()->pull($this->salesman, 'phone-a', 'customer', 100);
        $this->assertNotEmpty($first['records']);

        // pull-complete ছাড়া দ্বিতীয় ডাকে হুবহু একই ব্যাচ — এটাই একটা
        // হারিয়ে যাওয়া উত্তরের পর আবার চেষ্টা করাকে নিরাপদ করে
        $again = $this->sync()->pull($this->salesman, 'phone-a', 'customer', 100);
        $this->assertCount(count($first['records']), $again['records']);
        $this->assertNull($this->sync()->lastSync('phone-a', 'customer'));

        $this->sync()->recordSuccessfulPull('phone-a', 'customer');

        $this->assertNotNull($this->sync()->lastSync('phone-a', 'customer'));
        $this->assertSame([], $this->sync()->pull($this->salesman, 'phone-a', 'customer', 100)['records']);
    }

    /**
     * কোম্পানি বদলালে ওয়াটারমার্ক শূন্য — নাহলে নতুন কোম্পানির
     * ক্যাটালগের পুরনো অংশটা কোনোদিন নামত না, আর ফোনে দুই কোম্পানির
     * ডেটা মিশে থাকত।
     */
    public function test_switching_company_on_a_handset_clears_its_watermark(): void
    {
        $this->sync()->register($this->salesman, 'phone-a');
        $this->sync()->recordSuccessfulPull('phone-a', 'customer');
        $this->assertNotNull($this->sync()->lastSync('phone-a', 'customer'));

        $other = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->sync()->register($this->salesman, 'phone-a');

        $this->assertSame(
            0,
            SyncState::query()->withoutGlobalScopes()->where('device_id', 'phone-a')->count(),
            'কোম্পানি বদলের পরেও পুরনো ওয়াটারমার্ক থেকে গেছে — টেন্যান্টের দেয়ালে একটা ফুটো।',
        );
    }

    /**
     * একটা পুশ, তারের আকারে — `sync_engine.dart`-এর `flush()` ঠিক এই
     * ছয়টা ঘর পাঠায়।
     *
     * @return array<string, mixed>
     */
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

    private function sync(): SyncService
    {
        return app(SyncService::class);
    }
}
