<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⛔ ফোন আদায় লেখে না, "টাকা জমার খবর" পাঠায় — মালিক, ২০ নভেম্বর ২০২৬ (চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই")। গ্রাহকের টাকা ঢোকে
 * কেবল কাউন্টারের রসিদে আর হিসাবের রসিদ ভাউচারে (RCV-…); মাঠের খবর অফিস ব্যাংকে মিলিয়ে রসিদ কাটে ([[DepositClaimService::accept()]])।
 *
 * দাবি (ফোনের আসল দরজায়, দুই চাবিওয়ালা হিসাবরক্ষক — `sales.collection.create` আর `accounts.voucher.create`, আগে যিনি ফোনে আদায় লিখতেন):
 *   · সিঙ্কে পাঠানো নতুন আদায় REJECTED, কথা "টাকা জমার খবর" পাঠান ([[CollectionSync::apply()]]), কাগজের id নেই, কিছুই বসে না;
 *     একই বদল আবার পাঠালে আবার একই ফেরত — কিছুতেই বসে না, বদলের সারিও একটাই
 *   · `POST collections/preview` আর `POST collections/{id}/attachment` — ৪২২, একই কথা; কিছু রাখা বা পাকা হয় না
 *   · `/me`-র `mayCollect` মিথ্যা — হিসাবরক্ষকের, মালিকেরও
 *   · আগের আদায় পড়া আগের মতোই — ফোনের আদায়ের তালিকা, পাতা আর সিঙ্কের টানা
 */
final class ThePhoneSendsADepositReportNotACollectionTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'phone-news';

    private Company $company;

    private User $owner;

    private User $clerk;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $this->clerk->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'),
                ['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->clerk = $this->clerk->fresh();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-NWS', 'name_en' => 'News bank', 'name_bn' => 'খবরের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->create(['code' => 'NWS-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর', 'is_active' => true]);
    }

    /** ⛔ পাঠানো নতুন আদায় REJECTED, "টাকা জমার খবর"-এর কথায়; আবার পাঠালে আবার ফেরত — কোনোবারই কিছু বসে না */
    public function test_a_pushed_collection_is_refused_for_the_deposit_report_and_a_resend_stays_refused(): void
    {
        $this->assertTrue($this->clerk->can('sales.collection.create') && $this->clerk->can('accounts.voucher.create'),
            'প্রস্তুতিটাই ভুল — হিসাবরক্ষকের দুই চাবি নেই, ফেরত চাবির কারণে হতে পারে');
        $said = (string) __('sales::sync.collection_use_deposit_report');
        $papers = Collection::acrossBranches()->count();
        $rows = LedgerEntry::query()->count();
        $change = $this->change('news-1', ['amount' => '450', 'lines' => [], 'confirm' => true]);

        $first = $this->rowOf($this->pushHttp([$change]), 'news-1');

        $this->assertSame(SyncChange::REJECTED, $first['status'] ?? null, '⛔ ফোনের নতুন আদায় ফেরত হয়নি: '.json_encode($first));
        $this->assertSame($said, $first['message'] ?? null, '⛔ ফেরতের কথা "টাকা জমার খবর" পাঠাতে বলেনি');
        $this->assertSame([$said], $first['errors']['_'] ?? null, '⛔ ফেরতে ঘরহীন কারণ নেই (চুক্তি §৭.২)');
        $this->assertArrayNotHasKey('entityId', $first, '⛔ ফেরত উত্তরে কাগজের id এল');
        $this->assertArrayNotHasKey('no', $first, '⛔ ফেরত উত্তরে রসিদের নম্বর এল');
        $this->assertSame([$papers, $rows], [Collection::acrossBranches()->count(), LedgerEntry::query()->count()], '⛔ ফেরত সত্ত্বেও আদায় বা খাতার সারি বসল');

        $kept = SyncChange::query()->withoutGlobalScopes()->where('device_id', self::DEVICE)->where('change_id', 'news-1')->get();
        $this->assertCount(1, $kept, '⛔ বদলের দাগ একটা নয়');
        $this->assertSame([SyncChange::REJECTED, null], [$kept[0]->status, $kept[0]->applied_entity_id], '⛔ বদলের দাগ ফেরত নয়, বা কাগজের id লেখা');

        // ⭐ নেট গেলে আবার চাপা — একই changeId: আবার ফেরত, একই কথা, কিছুই বসে না, দাগও একটাই
        $again = $this->rowOf($this->pushHttp([$change]), 'news-1');

        $this->assertSame(SyncChange::REJECTED, $again['status'] ?? null, '⛔ একই বদল দ্বিতীয়বার ফেরত নয় — '.json_encode($again));
        $this->assertSame($said, $again['message'] ?? null, '⛔ দ্বিতীয়বারের ফেরতের কথা আলাদা');
        $this->assertArrayNotHasKey('entityId', $again, '⛔ দ্বিতীয়বারের উত্তরে কাগজের id এল');
        $this->assertSame([$papers, $rows], [Collection::acrossBranches()->count(), LedgerEntry::query()->count()], '⛔ দ্বিতীয়বারে আদায় বা খাতার সারি বসল');
        $this->assertSame(1, SyncChange::query()->withoutGlobalScopes()->where('device_id', self::DEVICE)->where('change_id', 'news-1')->count(),
            '⛔ একই বদল দ্বিতীয়বার নতুন দাগ পেল');
    }

    /** ⛔ ফোনের প্রিভিউ আর স্লিপের দরজা — ৪২২, সিঙ্কের একই কথা; কিছু রাখা বা পাকা হয় না */
    public function test_the_preview_and_the_slip_doors_answer_422_with_the_same_words(): void
    {
        Storage::fake('local');
        $said = (string) __('sales::sync.collection_use_deposit_report');
        $old = $this->oldCollection(confirm: false);
        $files = Attachment::query()->withoutGlobalScopes()->count();
        $papers = Collection::acrossBranches()->count();
        $rows = LedgerEntry::query()->count();

        $this->phone($this->clerk);
        $preview = $this->postJson('/api/v1/sales/collections/preview', [
            'customerId' => (string) $this->dealer->public_id, 'trxDate' => now()->toDateString(),
            'accountId' => (string) $this->bank->public_id, 'amount' => '300', 'lines' => [],
        ]);
        $this->assertSame(422, $preview->status(), '⛔ ফোনের আদায়ের প্রিভিউ এখনো খোলা ('.$preview->status().')');
        $this->assertSame($said, $preview->json('message'), '⛔ প্রিভিউর ফেরতের কথা "টাকা জমার খবর" নয়');
        $this->assertSame([$said], $preview->json('errors.collection'), '⛔ প্রিভিউর ফেরতে `errors.collection` নেই');

        $this->phone($this->clerk);
        $slip = $this->post('/api/v1/sales/collections/'.$old->public_id.'/attachment',
            ['file' => UploadedFile::fake()->image('slip.png', 40, 30)], ['Accept' => 'application/json']);
        $this->assertSame(422, $slip->status(), '⛔ ফোনের স্লিপ আদায়ে নেওয়া হলো ('.$slip->status().')');
        $this->assertSame($said, $slip->json('message'), '⛔ স্লিপের ফেরতের কথা "টাকা জমার খবর" নয়');
        $this->assertSame([$said], $slip->json('errors.collection'), '⛔ স্লিপের ফেরতে `errors.collection` নেই');

        $this->assertSame(DocumentStatus::DRAFT, Collection::acrossBranches()->whereKey($old->id)->value('status'), '⛔ স্লিপের দরজায় আগের খসড়া পাকা হলো');
        $this->assertSame([$files, $papers, $rows],
            [Attachment::query()->withoutGlobalScopes()->count(), Collection::acrossBranches()->count(), LedgerEntry::query()->count()],
            '⛔ প্রিভিউ বা স্লিপের দরজা সংযুক্তি, আদায় বা খাতার সারি লিখল');
        $this->assertSame([], Storage::disk('local')->allFiles(), '⛔ ফেরত স্লিপের ফাইল জমা হলো');
    }

    /** ⛔ `/me`-র `mayCollect` কারো নয় — দুই চাবিওয়ালা হিসাবরক্ষক আর মালিক, দুজনেরই মিথ্যা (ফোনে "আদায়" বোতাম নেই) */
    public function test_me_says_no_one_may_collect(): void
    {
        foreach (['হিসাবরক্ষক' => $this->clerk, 'মালিক' => $this->owner] as $who => $user) {
            $this->phone($user);
            $me = $this->getJson('/api/v1/me')->assertOk()->json();
            $this->assertArrayHasKey('mayCollect', $me, '⛔ /me-তে `mayCollect` ঘরটাই নেই — পুরনো অ্যাপ তখন বোতাম লুকাতে পারে না');
            $this->assertFalse($me['mayCollect'], "⛔ {$who}-এর ফোনে এখনো আদায়ের ঘর — টাকা ঢোকার কাগজ একটাই");
        }
    }

    /** ⭐ আগের আদায় (সেবা দিয়ে সরাসরি বানানো, পাকা) ফোনে আগের মতোই পড়া যায় — তালিকা, পাতা আর সিঙ্কের টানা */
    public function test_an_old_collection_still_reads_on_the_list_the_page_and_the_pull(): void
    {
        $old = $this->oldCollection(confirm: true);
        $this->assertSame(DocumentStatus::CONFIRMED, $old->status, 'প্রস্তুতিটাই ভুল — আগের আদায় পাকা হয়নি');

        $this->phone($this->clerk);
        $list = $this->getJson('/api/v1/sales/collections')->assertOk()->json('rows');
        $this->assertContains((string) $old->public_id, array_column((array) $list, 'id'), '⛔ আগের পাকা আদায় ফোনের তালিকায় নেই');

        $this->phone($this->clerk);
        $this->getJson('/api/v1/sales/collections/'.$old->public_id)->assertOk()
            ->assertJsonPath('no', $old->document_no)
            ->assertJsonPath('status', DocumentStatus::CONFIRMED)
            ->assertJsonPath('state', 'posted');

        $pulled = null;
        for ($page = 0; $page < 50 && $pulled === null; $page++) {
            $this->phone($this->clerk);
            $body = $this->getJson('/api/v1/sync/sales/pull?deviceId='.self::DEVICE)->assertOk()->json();
            $pulled = collect($body['records'] ?? [])->first(fn (array $r) => ($r['entityType'] ?? null) === 'Collection'
                && ($r['entityId'] ?? null) === (string) $old->public_id);

            if (! ($body['hasMore'] ?? false)) {
                break;
            }
        }

        $this->assertIsArray($pulled, '⛔ আগের আদায় সিঙ্কের টানায় ফোনে এল না');
        $payload = json_decode((string) $pulled['payloadJson'], true);
        $this->assertSame([(string) $old->document_no, DocumentStatus::CONFIRMED], [$payload['documentNo'] ?? null, $payload['status'] ?? null],
            '⛔ টানা আদায়ের নম্বর বা অবস্থা ভুল');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ⓘ আগের আদায় — সেবা দিয়ে সরাসরি (ফোন আর লেখে না); পাকা চাইলে অফিসের হাতে নিশ্চিত */
    private function oldCollection(bool $confirm): Collection
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh());

        $service = app(CollectionService::class);
        $collection = $service->createFrom(new CollectionInput(
            customerId: (int) $this->dealer->id, accountId: (int) $this->bank->id, trxDate: now()->toDateString(), amount: '640.0000',
            narration: 'আগের আদায়',
        ));

        if ($confirm) {
            $collection = $service->confirm($collection->fresh(['lines']));
        }

        return Collection::acrossBranches()->whereKey($collection->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra @return array<string, mixed> — অ্যাপের একটা বদল, `CollectionDraft.toPayload()`-এর আকারে */
    private function change(string $id, array $extra): array
    {
        return [
            'changeId' => $id, 'entityType' => 'Collection', 'entityId' => null, 'operation' => 'CREATE', 'clientVersion' => 1,
            'payloadJson' => json_encode([
                'customerId' => (string) $this->dealer->public_id,
                'trxDate' => now()->toDateString(),
                'accountId' => (string) $this->bank->public_id,
                ...$extra,
            ]),
        ];
    }

    /** @param  list<array<string, mixed>>  $changes @return list<array<string, mixed>> */
    private function pushHttp(array $changes): array
    {
        $this->phone($this->clerk);

        return $this->postJson('/api/v1/sync/sales/push?deviceId='.self::DEVICE, $changes)->assertOk()->json('outcomes');
    }

    /** @param  list<array<string, mixed>>  $outcomes @return array<string, mixed> */
    private function rowOf(array $outcomes, string $changeId): array
    {
        $row = collect($outcomes)->firstWhere('changeId', $changeId);
        $this->assertIsArray($row, 'উত্তরে এই বদলের সারিই নেই: '.$changeId);

        return $row;
    }

    /** ⓘ আসল ফোনের টোকেন — access টোকেনের দুই চাবি (`ACCESS` = `sync` আর `APP`), সিঙ্কের দরজা `abilities:sync` চায় */
    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::ACCESS, AuthController::APP]);
    }
}
