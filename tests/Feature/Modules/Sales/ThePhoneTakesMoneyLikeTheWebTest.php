<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনে টাকা আদায় — মালিক, ৭ অক্টোবর ২০২৬: *"এখন অ্যাপে পেমেন্ট অপশন চালু করো"* ([[CollectionSync]],
 * [[CollectionApiController::setup()]])।
 *
 * ⭐ কেবল অফিসের লোক — মালিক, ৭ অক্টোবর ২০২৬: *"এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের জন্য থাকবে payment
 * request"* — মাঠের টাকা যায় স্লিপসহ জমার অনুরোধে। দরজা খাতায় টাকা তোলার চাবিতে ([[CollectionSync::officeMayCollect()]])।
 *
 * ⛔ ২০ নভেম্বর ২০২৬ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") — ফোন আর আদায় লেখে না: টাকা ঢোকে কাউন্টারের রসিদে আর হিসাবের
 * রসিদ ভাউচারে (RCV-…), মাঠ পাঠায় "টাকা জমার খবর" ([[CollectionSync::apply()]], [[CollectionSync::officeMayCollect()]])।
 *
 * দাবি:
 *   · টাকার খাত ওয়েবের ফর্মের একই তালিকা, ধরনসহ; /me-র `mayCollect` কারো নয় — মালিক বা হিসাবরক্ষকেরও না
 *   · SR (আদায়ের চাবি আছে, অফিসের নেই) — ফোনে ঘর নেই, খাতের দরজা ৪০৩, পাঠানো আদায় চাবির কারণে ফেরত, কিছুই বসে না
 *   · দুই চাবিওয়ালার আদায়ও (খাত, মাধ্যম, লেনদেন-নম্বর, "এখনই নিশ্চিত" সহ) "টাকা জমার খবর"-এর কারণে ফেরত — কাগজ, খাতা, প্রাপ্য
 *     কিছুই বদলায় না; একই বদল আবার এলেও ফেরত, কিছু নয়
 *   · আগের পাকা আদায় ফোনে আগের মতোই পড়া যায় (নম্বর আর অবস্থা)
 *   · "1e5", ভবিষ্যতের তারিখ, অচেনা খাত — কারণসহ ফেরত, ৫০০ নয়; "ভালো" আদায়টাও এখন ফেরত
 */
final class ThePhoneTakesMoneyLikeTheWebTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->shop = Customer::query()->orderBy('id')->firstOrFail();
    }

    /** ⛔ ২০ নভেম্বর ২০২৬ থেকে /me-র `mayCollect` সবার মিথ্যা — দুই চাবিওয়ালা হিসাবরক্ষক আর মালিকেরও (ফোনে "আদায়" বোতাম নেই) */
    public function test_the_phone_gets_the_webs_money_accounts_but_no_one_may_collect(): void
    {
        $this->phone($this->owner);
        $accounts = $this->getJson('/api/v1/sales/collections/setup')->assertOk()->json('accounts');
        $this->assertNotEmpty($accounts, 'টাকার কোনো খাত আসেনি।');
        foreach ($accounts as $a) {
            $this->assertContains($a['kind'], ['cash', 'bank', 'mfs']);
        }
        $this->assertFalse($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'), '⛔ মালিকের ফোনে এখনো আদায়ের ঘর — টাকা ঢোকার কাগজ একটাই');

        $this->phone($this->accountant());
        $this->assertTrue($this->accountant()->can('accounts.voucher.create') && $this->accountant()->can('sales.collection.create'),
            'প্রস্তুতিটাই ভুল — হিসাবরক্ষকের দুই চাবি নেই, দাবিটা কিছু মাপছে না');
        $this->assertFalse($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'), '⛔ দুই চাবিওয়ালা হিসাবরক্ষকের ফোনে এখনো আদায়ের ঘর');
    }

    public function test_a_field_salesman_has_no_collection_on_the_phone(): void
    {
        // ⓘ SR — জমার অনুরোধের জন্য আদায়ের চাবি আছে, খাতায় টাকা তোলার নেই
        $sr = $this->person(['sales.collection.create', 'sales.collection.view']);

        $this->phone($sr);
        $this->assertFalse($this->getJson('/api/v1/me')->assertOk()->json('mayCollect'), '⛔ SR-এর ফোনে আদায়ের ঘর।');
        $this->getJson('/api/v1/sales/collections/setup')->assertForbidden();

        $before = \App\Modules\Sales\Models\Collection::query()->count();
        $out = app(SyncService::class)->push($sr->fresh(), 'phone-sr', 'sales', [$this->change('sr-1', [
            'customerId' => (string) $this->shop->public_id, 'amount' => '300', 'trxDate' => now()->toDateString(),
        ])]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ SR-এর ফোন থেকে আদায় বসল।');
        // ⓘ চাবির পাহারা আগে, আগের মতোই (২০ নভেম্বর ২০২৬-এ বদলায়নি)
        $this->assertSame(__('sync.push_needs_permission'), $out[0]['message'] ?? null, '⛔ SR-এর ফেরত চাবির কারণে নয়');

        // ⓘ উল্টোটাও — খাতার চাবি আছে, আদায়ের নেই: ফোনের আদায় নয়
        $clerk = $this->person(['accounts.voucher.create']);
        $out = app(SyncService::class)->push($clerk, 'phone-clerk', 'sales', [$this->change('clerk-1', [
            'customerId' => (string) $this->shop->public_id, 'amount' => '300', 'trxDate' => now()->toDateString(),
        ])]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ আদায়ের চাবি ছাড়া ফোন থেকে আদায় বসল।');
        $this->assertSame(__('sync.push_needs_permission'), $out[0]['message'] ?? null, '⛔ আদায়ের চাবিহীনের ফেরত চাবির কারণে নয়');
        $this->assertSame($before, \App\Modules\Sales\Models\Collection::query()->count());
    }

    private ?User $accountant = null;

    /** ⓘ অফিসের হিসাবরক্ষক — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি */
    private function accountant(): User
    {
        return $this->accountant ??= $this->person(['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create']);
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->owner->current_company_id]);
        $user->companies()->attach($this->owner->current_company_id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->owner->current_company_id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), $keys)));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * ⛔ ২০ নভেম্বর ২০২৬ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") — খাত, মাধ্যম, লেনদেন-নম্বর আর "এখনই নিশ্চিত" সহ ফোনের আদায়ও
     * "টাকা জমার খবর"-এর কারণে ফেরত: কাগজ, খাতার সারি আর গ্রাহকের প্রাপ্য কিছুই বদলায় না; একই বদল আবার পৌঁছালেও ফেরত, কিছু নয়।
     * ⭐ আগের পাকা আদায় (এখানে সেবা দিয়ে সরাসরি বানানো) ফোনে আগের মতোই পড়া যায় — নম্বর আর অবস্থা।
     */
    public function test_a_phone_collection_even_with_confirm_is_refused_and_an_old_one_still_reads(): void
    {
        $account = $this->anAccount();
        $due = $this->shop->outstanding();
        $payload = [
            'customerId' => (string) $this->shop->public_id, 'amount' => '750.50', 'trxDate' => now()->toDateString(),
            'accountId' => (string) $account->public_id, 'instrument' => 'bKash', 'instrumentNo' => 'TRX-7781',
            'narration' => 'দোকানে গিয়ে নেওয়া', 'confirm' => true,
        ];
        $papers = Collection::query()->count();
        $rows = LedgerEntry::query()->count();

        $out = $this->push([$this->change('pay-1', $payload)]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ ফোনের আদায় ফেরত হয়নি: '.json_encode($out));
        $this->assertSame(__('sales::sync.collection_use_deposit_report'), $out[0]['message'] ?? null, '⛔ ফেরতের বার্তা "টাকা জমার খবর" পাঠাতে বলেনি');
        $this->assertArrayNotHasKey('entityId', $out[0], '⛔ ফেরত আদায়ের উত্তরে কাগজের id এল');
        $this->assertSame([$papers, $rows], [Collection::query()->count(), LedgerEntry::query()->count()], '⛔ ফেরত সত্ত্বেও কাগজ বা খাতার সারি বসল');
        $this->assertSame(0, bccomp($due, $this->shop->fresh()->outstanding(), 2), '⛔ ফেরত আদায়ে গ্রাহকের প্রাপ্য বদলাল');

        // ⭐ একই বদল আবার পৌঁছাল — আবার ফেরত, একই কথা, কিছুই বসে না
        $again = $this->push([$this->change('pay-1', $payload)]);
        $this->assertSame(SyncChange::REJECTED, $again[0]['status'], '⛔ একই বদল দ্বিতীয়বার অন্য উত্তর পেল: '.json_encode($again));
        $this->assertSame($out[0]['message'] ?? null, $again[0]['message'] ?? null, '⛔ দ্বিতীয়বারের ফেরতের কথা আলাদা');
        $this->assertSame([$papers, $rows], [Collection::query()->count(), LedgerEntry::query()->count()], '⛔ দ্বিতীয়বারে কাগজ বা খাতার সারি বসল');

        // ⓘ আগের আদায় — সেবা দিয়ে সরাসরি (ফোন আর লেখে না), পাকা; ফোনের রসিদ আগের মতোই: নম্বর আর অবস্থা
        $this->actingAs($this->owner);
        $old = app(CollectionService::class)->createFrom(new CollectionInput(
            customerId: (int) $this->shop->id, accountId: (int) $account->id, trxDate: now()->toDateString(), amount: '750.5000',
            method: CollectionInput::methodName('bKash'), reference: 'TRX-7781', narration: 'দোকানে গিয়ে নেওয়া',
        ));
        $old = app(CollectionService::class)->confirm($old->fresh(['lines']));
        $this->assertSame(DocumentStatus::CONFIRMED, $old->status, 'প্রস্তুতিটাই ভুল — আগের আদায় পাকা হয়নি');

        $this->phone($this->accountant());
        $this->getJson('/api/v1/sales/collections/'.$old->public_id)->assertOk()
            ->assertJsonPath('no', $old->document_no)->assertJsonPath('status', DocumentStatus::CONFIRMED);
    }

    /** "1e5", ভবিষ্যতের তারিখ, অচেনা খাত, দল-খাত — কারণসহ ফেরত, ৫০০ নয়; ⛔ ২০ নভেম্বর ২০২৬ থেকে "ভালো" আদায়টাও ফেরত, কিছুই বসে না */
    public function test_bad_rows_are_refused_with_a_reason_and_so_is_the_good_one(): void
    {
        $base = ['customerId' => (string) $this->shop->public_id, 'trxDate' => now()->toDateString()];
        $before = Collection::query()->count();

        $out = $this->push([
            $this->change('bad-1', $base + ['amount' => '1e5']),
            $this->change('bad-2', ['trxDate' => now()->addDays(3)->toDateString()] + $base + ['amount' => '100']),
            $this->change('bad-3', $base + ['amount' => '100', 'accountId' => '01890000-0000-7000-8000-000000000000']),
            // ⓘ দল-খাতে টাকা নয় — নগদের মা-খাত নিজে
            $this->change('bad-4', $base + ['amount' => '100', 'accountId' => (string) \Illuminate\Support\Facades\DB::table('accounts')->where('company_id', $this->owner->current_company_id)->where('is_group', true)->value('public_id')]),
            $this->change('good-1', $base + ['amount' => '100']),
        ]);

        $this->assertSame(array_fill(0, 5, SyncChange::REJECTED), array_column($out, 'status'), json_encode($out));
        $this->assertSame(array_fill(0, 5, __('sales::sync.collection_use_deposit_report')), array_column($out, 'message'),
            '⛔ কোনো সারির ফেরত "টাকা জমার খবর"-এর কারণে নয়');
        $this->assertSame($before, Collection::query()->count(), '⛔ ফেরত সারির কোনোটা বসল');
    }

    /**
     * ⛔ ফোনে বাছা শাখার বাইরের দোকানের নামে আদায় নয় (অডিট ফোন ⛔৩)। ⓘ ২০ নভেম্বর ২০২৬ থেকে নিজের শাখার দোকানেও নয় — দুটোর উত্তর
     * এক ("টাকা জমার খবর"), তাই অন্য শাখার দোকান আছে কি না জানা যায় না; কিছুই বসে না।
     */
    public function test_a_shop_of_another_branch_than_the_one_in_view_gives_no_collection(): void
    {
        $company = \App\Models\Company::query()->findOrFail($this->owner->current_company_id);
        $home = $company->defaultBranch();
        $other = \App\Models\Branch::query()->where('company_id', $company->id)->whereKeyNot($home->id)->first()
            ?? \App\Models\Branch::query()->create(['company_id' => $company->id, 'code' => 'OOT3', 'name_en' => 'Other branch', 'is_active' => true]);

        $this->accountant()->forceFill(['view_all_branches' => false, 'current_branch_id' => $home->id])->save();
        $this->accountant = $this->accountant->fresh();
        $this->actingAs($this->accountant);
        app(\App\Core\Services\DataScope::class)->forget();
        $this->assertSame((int) $home->id, \App\Core\Support\ViewedBranch::one(), 'প্রস্তুতিটাই ভুল — এক শাখা দেখা হচ্ছে না।');

        $payload = ['customerId' => (string) $this->shop->public_id, 'trxDate' => now()->toDateString(), 'amount' => '100'];

        $before = Collection::acrossBranches()->count();

        $this->shop->forceFill(['branch_id' => $other->id])->save();
        $there = $this->push([$this->change('br-1', $payload)]);
        $this->assertSame(SyncChange::REJECTED, $there[0]['status'], '⛔ অন্য শাখার দোকানের নামে ফোন থেকে আদায় বসল।');

        $this->shop->forceFill(['branch_id' => $home->id])->save();
        $here = $this->push([$this->change('br-2', $payload)]);
        $this->assertSame(SyncChange::REJECTED, $here[0]['status'], '⛔ নিজের শাখার দোকানে ফোন থেকে আদায় বসল: '.json_encode($here));
        $this->assertSame([__('sales::sync.collection_use_deposit_report'), __('sales::sync.collection_use_deposit_report')],
            [$there[0]['message'] ?? null, $here[0]['message'] ?? null], '⛔ অন্য শাখা আর নিজের শাখার দোকানের ফেরতের কথা আলাদা — কে আছেন জানা যায়');
        $this->assertSame($before, Collection::acrossBranches()->count(), '⛔ ফেরত আদায়ের কিছু বসল');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function anAccount(): Account
    {
        $this->phone($this->owner);
        $accounts = collect($this->getJson('/api/v1/sales/collections/setup')->json('accounts'));
        $pick = $accounts->firstWhere('kind', 'mfs') ?? $accounts->firstWhere('kind', 'bank') ?? $accounts->first();

        return Account::query()->where('public_id', $pick['id'])->firstOrFail();
    }

    /** @param  list<array<string, mixed>>  $changes @return list<array<string, mixed>> */
    private function push(array $changes): array
    {
        return app(SyncService::class)->push($this->accountant(), 'phone-pay', 'sales', $changes);
    }

    /** @param  array<string, mixed>  $payload @return array<string, mixed> */
    private function change(string $id, array $payload): array
    {
        return ['changeId' => $id, 'entityType' => 'Collection', 'operation' => 'CREATE', 'payloadJson' => json_encode($payload)];
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }
}
