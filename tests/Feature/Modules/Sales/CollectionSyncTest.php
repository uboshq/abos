<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাঠ থেকে আদায় সিঙ্ক — ⛔ ২০ নভেম্বর ২০২৬ থেকে আর বসে না (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই" — রসিদ ভাউচার;
 * মাঠ পাঠায় "টাকা জমার খবর")। লেখার পাহারা পড়ার সমান আগের মতোই।
 *
 * দুইটা দাবি সবচেয়ে জরুরি: (১) ফোনের নতুন আদায় কারণসহ REJECTED, কিছুই বসে না — বার্তা "টাকা জমার খবর" দেখায়;
 * (২) যাঁর আদায় বসানোর অনুমতি নেই তাঁর push নীরবে হারায় না, আগের অনুমতির কারণেই REJECTED হয়ে ফেরে — নাহলে
 * সেলসম্যান ভাবতেন সিঙ্ক হয়ে গেছে।
 */
class CollectionSyncTest extends TestCase
{
    use RefreshDatabase;
    // ⓘ এই পরীক্ষার প্রশ্ন ডিলারের দেয়াল নয় — ডেমোর বিক্রয়কর্মীর চিহ্ন তোলা (⛔১৬, ৪ অক্টোবর ২০২৬)
    use \Tests\Concerns\TakesTheDealerWallOffTheDemoSalesman;

    private Company $company;

    private User $salesman;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->takeTheDealerWallOffTheDemoSalesman();

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        /*
         * ⓘ ফোনের আদায় এখন কেবল অফিসের লোকের — খাতায় টাকা তোলার চাবিও লাগে (মালিক, ৭ অক্টোবর ২০২৬; মাঠের টাকা যায়
         * স্লিপসহ জমার অনুরোধে)। এই ফাইলের প্রশ্ন সিঙ্কের আচরণ, তাই পাঠানো মানুষটার দুই চাবিই আছে।
         */
        CompanyContext::forCompany($this->company->id, fn () => $this->salesman->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), ['sales.collection.create', 'accounts.voucher.create'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->salesman = $this->salesman->fresh();
        $this->actingAs($this->salesman);

        $this->shop = Customer::query()->firstOrFail();
    }

    private function sync(): SyncService
    {
        return app(SyncService::class);
    }

    /** @param array<string, mixed> $payload */
    private function change(string $id, array $payload, string $op = 'CREATE'): array
    {
        return array_filter([
            'changeId' => $id,
            'entityType' => 'Collection',
            'operation' => $op,
            'entityId' => $op === 'CREATE' ? null : 'some-server-id',
            'payloadJson' => json_encode($payload),
        ], fn ($v) => $v !== null);
    }

    /**
     * ★ মাঠের আদায় আর বসে না — খসড়াও নয় (মালিক, ২০ নভেম্বর ২০২৬: "টাকা ঢোকার কাগজ একটাই")। দুই চাবিওয়ালা মানুষের পাঠানো
     * নতুন আদায় REJECTED, বার্তা "টাকা জমার খবর" পাঠাতে বলে, আর কোনো আদায় লেখা হয় না।
     */
    public function test_a_field_collection_is_refused_and_points_to_the_deposit_report(): void
    {
        $before = Collection::query()->count();

        $out = $this->sync()->push($this->salesman, 'phone-a', 'sales', [
            $this->change('c-1', ['customerId' => (string) $this->shop->public_id, 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ ফোনের নতুন আদায় ফেরত হয়নি: '.json_encode($out[0]));
        $this->assertSame(__('sales::sync.collection_use_deposit_report'), $out[0]['message'] ?? null,
            '⛔ ফেরতের বার্তা "টাকা জমার খবর" পাঠাতে বলেনি');
        $this->assertArrayNotHasKey('entityId', $out[0], '⛔ ফেরত আদায়ের উত্তরে কাগজের id এল');
        $this->assertSame($before, Collection::query()->count(), '⛔ ফেরত সত্ত্বেও একটা আদায় বসল');
    }

    /** আদায় অফলাইনে সংশোধন নয় — নেটওয়ার্কে। */
    public function test_a_collection_cannot_be_edited_offline(): void
    {
        $before = Collection::query()->count();

        $out = $this->sync()->push($this->salesman, 'phone-a', 'sales', [
            $this->change('c-2', ['customerId' => (string) $this->shop->public_id, 'amount' => '500'], 'UPDATE'),
        ]);

        // সংশোধন = দ্বন্দ্ব (CONFLICT) — অফিস ইতিমধ্যে বিলে বসিয়ে থাকতে পারে
        $this->assertSame(SyncChange::CONFLICT, $out[0]['status']);
        $this->assertSame($before, Collection::query()->count());
    }

    /**
     * অচেনা গ্রাহক — প্রত্যাখ্যান, কিছুই বসে না। ⓘ ২০ নভেম্বর ২০২৬ থেকে কারণ একটাই ("টাকা জমার খবর") — গ্রাহক খোঁজার আগেই
     * ফেরে, তাই চেনা আর অচেনা গ্রাহকের উত্তর এক, কে আছেন জানা যায় না।
     */
    public function test_an_unknown_customer_is_refused(): void
    {
        $before = Collection::query()->count();

        $out = $this->sync()->push($this->salesman, 'phone-a', 'sales', [
            $this->change('c-3', ['customerId' => 'no-such-public-id', 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertSame(__('sales::sync.collection_use_deposit_report'), $out[0]['message'] ?? null,
            '⛔ অচেনা গ্রাহকের ফেরত অন্য কারণে — চেনা গ্রাহকের উত্তর থেকে আলাদা');
        $this->assertSame($before, Collection::query()->count());
    }

    /**
     * ★ পড়ার চেয়ে লেখার পাহারা কম নয় — অনুমতি ছাড়া push REJECTED, কিছুই বসে না।
     *
     * গার্ডটা ভেঙে দেখা: অনুমতিহীন একজনের হয়ে push করলে সৎ প্রত্যাখ্যান
     * আসে, আর সেলসম্যান জানেন কিছুই সংরক্ষণ হয়নি — নীরবে হারায় না।
     */
    public function test_a_push_without_permission_is_rejected_and_records_nothing(): void
    {
        // সত্যিকারের সেলসম্যানের কাছে চাবিটা আছে — তাই উপরে তাঁর ফেরত "টাকা জমার খবর"-এর, চাবির নয়
        $this->assertTrue($this->salesman->can('sales.collection.create'));

        $stranger = User::factory()->create();
        $before = Collection::query()->count();

        $out = $this->sync()->push($stranger, 'phone-z', 'sales', [
            $this->change('c-4', ['customerId' => (string) $this->shop->public_id, 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        // ⓘ চাবির পাহারা আগে, আগের মতোই (২০ নভেম্বর ২০২৬-এ বদলায়নি) — চাবিহীন মানুষ চাবির কারণই পান
        $this->assertSame(__('sync.push_needs_permission'), $out[0]['message'] ?? null, '⛔ চাবিহীন মানুষের ফেরত চাবির কারণে নয়');
        $this->assertSame($before, Collection::query()->count(), 'অনুমতি ছাড়া push-এও একটা আদায় বসে গেছে।');
    }
}
