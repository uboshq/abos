<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাঠ থেকে আদায় সিঙ্ক — খসড়া হিসেবে বসে, আর লেখার পাহারা পড়ার সমান।
 *
 * দুইটা দাবি সবচেয়ে জরুরি: (১) আদায় নিশ্চিত নয়, খসড়া — অফিস নগদ মিলিয়ে
 * বসায়; (২) যাঁর আদায় বসানোর অনুমতি নেই তাঁর push নীরবে হারায় না,
 * REJECTED হয়ে ফেরে — নাহলে সেলসম্যান ভাবতেন সিঙ্ক হয়ে গেছে।
 */
class CollectionSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
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

    /** ★ মাঠের আদায় খসড়া হিসেবে বসে — অফিস নগদ মিলিয়ে নিশ্চিত করে। */
    public function test_a_field_collection_lands_as_a_draft(): void
    {
        $before = Collection::query()->count();

        $out = $this->sync()->push($this->salesman, 'phone-a', 'sales', [
            $this->change('c-1', ['customerId' => (string) $this->shop->public_id, 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::APPLIED, $out[0]['status']);
        $this->assertSame($before + 1, Collection::query()->count());

        $collection = Collection::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $collection->status, 'মাঠের আদায় নিশ্চিত নয়, খসড়া হওয়ার কথা।');
        $this->assertSame($this->shop->id, $collection->customer_id);
        $this->assertSame(0, bccomp((string) $collection->amount, '500', 4));
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

    /** অচেনা গ্রাহক — প্রত্যাখ্যান, কিছুই বসে না। */
    public function test_an_unknown_customer_is_refused(): void
    {
        $out = $this->sync()->push($this->salesman, 'phone-a', 'sales', [
            $this->change('c-3', ['customerId' => 'no-such-public-id', 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
    }

    /**
     * ★ পড়ার চেয়ে লেখার পাহারা কম নয় — অনুমতি ছাড়া push REJECTED, কিছুই বসে না।
     *
     * গার্ডটা ভেঙে দেখা: অনুমতিহীন একজনের হয়ে push করলে সৎ প্রত্যাখ্যান
     * আসে, আর সেলসম্যান জানেন কিছুই সংরক্ষণ হয়নি — নীরবে হারায় না।
     */
    public function test_a_push_without_permission_is_rejected_and_records_nothing(): void
    {
        // সত্যিকারের সেলসম্যানের কাছে চাবিটা আছে — তাই উপরের happy path খাটে
        $this->assertTrue($this->salesman->can('sales.collection.create'));

        $stranger = User::factory()->create();
        $before = Collection::query()->count();

        $out = $this->sync()->push($stranger, 'phone-z', 'sales', [
            $this->change('c-4', ['customerId' => (string) $this->shop->public_id, 'amount' => '500']),
        ]);

        $this->assertSame(SyncChange::REJECTED, $out[0]['status']);
        $this->assertSame($before, Collection::query()->count(), 'অনুমতি ছাড়া push-এও একটা আদায় বসে গেছে।');
    }
}
