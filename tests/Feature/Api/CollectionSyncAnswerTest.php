<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ ফোনের আদায় বসার সাথে সাথে রসিদের কথা — সিঙ্কের উত্তরে, সেই বদলের নিজের সারিতে (a5-র উত্তর ৩; a4/collect-easy a3a030302:
 * `CollectionSaved.of(landed, outcome.details)`; মালিকের নকশা ১০০-এর দফা ৪, ৭, ১৫, ১৭, ১৯)।
 *
 * ⓘ দাবিগুলো ফোনের আসল দরজায় (`POST /api/v1/sync/sales/push?deviceId=`), উত্তরের সারি changeId ধরে মেলানো — অ্যাপ যেভাবে মেলায়:
 *   · `no`, `state` (paper_states-এর চাবি), `amount_words`, `receipt_pdf`, `applied` (ক্রেডিট ঋণাত্মক), `unapplied`
 *   · একই changeId দুবার — একটাই আদায়, একই উত্তর (দফা ৭)
 *   · ছাড়ে মালিকের সই — `state: waiting` (দফা ৪)
 *   · স্লিপের ঘরের নাম `file` (a5-র উত্তর ৫); কোম্পানি স্লিপ চাইলে স্লিপ পৌঁছালে তবে পোস্ট (দফা ১৫)
 */
final class CollectionSyncAnswerTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'phone-answer';

    private Company $company;

    private User $clerk;

    private Customer $dealer;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->clerk = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $this->clerk->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'),
                ['sales.collection.create', 'sales.collection.view', 'accounts.voucher.create'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->clerk = $this->clerk->fresh();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-ANS', 'name_en' => 'Answer bank', 'name_bn' => 'উত্তরের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
        ]);

        $this->dealer = Customer::query()->create(['code' => 'ANS-1', 'name_en' => 'Karim Store', 'name_bn' => 'করিম স্টোর', 'is_active' => true]);
    }

    /**
     * দফা ১৭, ১৯ — উত্তরের সারিতে রসিদের সব কথা, সেই বদলেরই: নম্বর, `posted`, কথায় টাকা, রসিদের ঠিকানা, কোন কাগজে কত (বিলে পুরো
     * অঙ্ক, ক্রেডিট ঋণাত্মক — যোগ = পাওয়া টাকা), অপ্রয়োগকৃত। একই পুশে দুই বদল — প্রতিটা সারি নিজের আদায়ের কথা বলে।
     */
    public function test_item_17_each_push_row_carries_its_own_receipt_answer(): void
    {
        $prior = $this->rowOf($this->pushHttp([$this->change('ans-0', ['amount' => '500', 'lines' => []])]), 'ans-0');
        $priorNo = Collection::acrossBranches()->where('public_id', $prior['entityId'])->value('document_no');
        $bill = $this->bill('INV-ANS-1', '1200');

        $out = $this->pushHttp([
            $this->change('ans-1', ['amount' => '700', 'lines' => [
                ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00'],
                ['kind' => 'credit', 'id' => $prior['entityId'], 'amount' => '-500.00'],
            ]]),
            $this->change('ans-2', ['amount' => '300', 'lines' => []]),
        ]);

        $row = $this->rowOf($out, 'ans-1');
        $paper = Collection::acrossBranches()->where('public_id', $row['entityId'])->firstOrFail();

        $this->assertSame(SyncChange::APPLIED, $row['status'], '⛔ দফা ১৭: আদায় বসেনি — '.json_encode($row));
        $this->assertSame($paper->document_no, $row['no'] ?? null, '⛔ দফা ১৭: উত্তরের সারিতে নম্বর নেই');
        $this->assertSame('posted', $row['state'] ?? null, '⛔ দফা ১৭: উত্তরের অবস্থা paper_states-এর `posted` নয়');
        $this->assertSame(AmountInWords::of('700.0000', 'bn'), $row['amount_words'] ?? null, '⛔ দফা ১৭: কথায় টাকা নেই বা ভুল');
        $this->assertSame(route('sales.print.receipt', $paper->id), $row['receipt_pdf'] ?? null, '⛔ দফা ১৭: রসিদের ঠিকানা নেই');
        $this->assertSame([
            ['kind' => 'bill', 'no' => 'INV-ANS-1', 'amount' => '1200.0000'],
            ['kind' => 'credit', 'no' => (string) $priorNo, 'amount' => '-500.0000'],
        ], $row['applied'] ?? null, '⛔ দফা ১৭: কোন কাগজে কত — বিলে পুরো অঙ্ক আর ক্রেডিট ঋণাত্মক নয় (যোগ পাওয়া টাকার সমান নয়)');
        $this->assertSame('0.0000', $row['unapplied'] ?? null, '⛔ দফা ১৭: অপ্রয়োগকৃত ভুল');

        $other = $this->rowOf($out, 'ans-2');
        $this->assertSame(Collection::acrossBranches()->where('public_id', $other['entityId'])->value('document_no'), $other['no'] ?? null,
            '⛔ দফা ১৯: দ্বিতীয় বদলের সারিতে অন্য আদায়ের কথা — উত্তর নিজের সারিতে নয়');
        $this->assertSame('300.0000', $other['unapplied'] ?? null, '⛔ দফা ১৯: দ্বিতীয় সারির অপ্রয়োগকৃত নিজের নয়');
    }

    /**
     * কথায় টাকা যিনি পাঠালেন তাঁর ভাষায় (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — একই পুশ: ইংরেজির মানুষ ইংরেজি কথা পান, বাংলার মানুষ
     * বাংলা। ⓘ ভাষা মানুষের `users.locale` থেকে ([[ResolveCompanyContext]]); সই-করা মানুষের বেলায় `Accept-Language` তাঁর বাছাই উল্টায় না
     * (a4/api-locale-এর একই ক্রম), তাই শিরোনামটা ইচ্ছে করে উল্টো ভাষায় পাঠানো।
     */
    public function test_the_amount_in_words_follows_the_senders_own_language(): void
    {
        $this->assertNotSame(AmountInWords::of('700.0000', 'en'), AmountInWords::of('700.0000', 'bn'), 'দুই ভাষার কথা একই — দাবিটা কিছু মাপছে না');

        $this->clerk->forceFill(['locale' => 'en'])->save();
        $this->withHeader('Accept-Language', 'bn');
        $english = $this->rowOf($this->pushHttp([$this->change('lang-en', ['amount' => '700', 'lines' => []])]), 'lang-en');

        $this->assertSame(AmountInWords::of('700.0000', 'en'), $english['amount_words'] ?? null, '⛔ ইংরেজির মানুষ কথায় টাকা ইংরেজিতে পাননি');

        $this->clerk->forceFill(['locale' => 'bn'])->save();
        $this->withHeader('Accept-Language', 'en');
        $bangla = $this->rowOf($this->pushHttp([$this->change('lang-bn', ['amount' => '700', 'lines' => []])]), 'lang-bn');

        $this->assertSame(AmountInWords::of('700.0000', 'bn'), $bangla['amount_words'] ?? null, '⛔ বাংলার মানুষ কথায় টাকা বাংলায় পাননি');
    }

    /** দফা ৭ — নেট গেলে আবার চাপলে (একই changeId) একটাই আদায়, আর উত্তরে একই নম্বর ও রসিদ */
    public function test_item_7_the_same_change_twice_gives_one_collection_and_the_same_answer(): void
    {
        $first = $this->rowOf($this->pushHttp([$this->change('twice-1', ['amount' => '450', 'lines' => []])]), 'twice-1');
        $count = Collection::query()->count();

        $again = $this->rowOf($this->pushHttp([$this->change('twice-1', ['amount' => '450', 'lines' => []])]), 'twice-1');

        $this->assertSame($count, Collection::query()->count(), '⛔ দফা ৭: একই changeId-তে দ্বিতীয় আদায় বসল');
        $this->assertSame($first['entityId'], $again['entityId'], '⛔ দফা ৭: দ্বিতীয়বার অন্য আদায় ফিরল');
        $this->assertSame($first['no'] ?? null, $again['no'] ?? null, '⛔ দফা ৭: দ্বিতীয়বারের উত্তরে নম্বর নেই — ফোন রসিদ দেখাতে পারে না');
        $this->assertSame($first['receipt_pdf'] ?? null, $again['receipt_pdf'] ?? null, '⛔ দফা ৭: দ্বিতীয়বারের উত্তরে রসিদ নেই');
    }

    /** দফা ৪ — ফোনের আদায়ে ছাড়: খাতায় নয়, মালিকের সইয়ের অপেক্ষায় — উত্তরে `state: waiting` */
    public function test_item_4_a_phone_discount_waits_for_the_owner_and_says_waiting(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-ANS-D', '1000');

        $row = $this->rowOf($this->pushHttp([$this->change('disc-1', ['amount' => '980',
            'difference' => ['kind' => 'discount', 'amount' => '20'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.00']]])]), 'disc-1');

        $paper = Collection::acrossBranches()->where('public_id', $row['entityId'])->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $paper->status, '⛔ দফা ৪: ফোনের ছাড় মালিকের সই ছাড়াই পোস্ট হলো');
        $this->assertSame('waiting', $row['state'] ?? null, '⛔ দফা ৪: উত্তর বলল না যে সইয়ের অপেক্ষায় (`waiting`)');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $paper->id)->count(),
            '⛔ দফা ৪: সইয়ের আগে খাতায় সারি');
    }

    /**
     * দফা ১৫ (a5-র উত্তর ৫) — স্লিপের ঘরের নাম `file`: অন্য নামে ৪২২, `file`-এ ২০১। কোম্পানি স্লিপ চাইলে ফোনের "পোস্ট" স্লিপ
     * ছাড়া খসড়ায় থামে (`draft`), আর অ্যাপ বসার পরে স্লিপ পাঠালে তখন পোস্ট হয় — অ্যাপে খসড়া পোস্টের আর কোনো বোতাম নেই।
     */
    public function test_item_15_the_slip_field_is_file_and_a_required_slip_posts_when_it_arrives(): void
    {
        Storage::fake('local');
        app(SettingsService::class)->set('sales.collection.attachment_required', true);

        $row = $this->rowOf($this->pushHttp([$this->change('slip-1', ['amount' => '600', 'lines' => []])]), 'slip-1');
        $this->assertSame('draft', $row['state'] ?? null, '⛔ দফা ১৫: স্লিপ ছাড়া ফোনের আদায় পোস্ট হলো');

        $door = '/api/v1/sales/collections/'.$row['entityId'].'/attachment';
        $this->phone();
        $wrong = $this->post($door, ['slip' => UploadedFile::fake()->image('slip.png', 40, 30)], ['Accept' => 'application/json']);
        $this->assertSame(422, $wrong->status(), '⛔ দফা ১৫: অন্য নামের ঘরে (`slip`) স্লিপ নেওয়া হলো — অ্যাপ পাঠায় `file`');
        $this->assertArrayHasKey('file', (array) $wrong->json('errors'), '⛔ দফা ১৫: ভুলের কথায় `file` ঘরের নাম নেই');

        $this->phone();
        $right = $this->post($door, ['file' => UploadedFile::fake()->image('slip.png', 40, 30)], ['Accept' => 'application/json']);

        $paper = Collection::acrossBranches()->where('public_id', $row['entityId'])->firstOrFail();
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status, '⛔ দফা ১৫: স্লিপ পৌঁছানোর পরেও ফোনের "পোস্ট" চাওয়া আদায় খসড়াই রইল');
        $this->assertSame(2, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $paper->id)->count(),
            '⛔ দফা ১৫: স্লিপের পরে খাতায় টাকা আর গ্রাহকের দুই সারি নেই');
        $this->assertSame(201, $right->status(), '⛔ দফা ১৫: `file` ঘরের স্লিপ নেওয়া হয়নি');
        $this->assertSame('posted', $right->json('state'), '⛔ দফা ১৫: স্লিপের উত্তর নতুন অবস্থা (`posted`) বলেনি');
    }

    /** দফা ১৫ — কেবল "পোস্ট" চাওয়া আদায় স্লিপে পোস্ট হয়; "খসড়া রাখুন" (`confirm: false`) স্লিপ পেলেও খসড়া */
    public function test_item_15_a_slip_does_not_post_a_paper_the_phone_kept_as_a_draft(): void
    {
        Storage::fake('local');
        app(SettingsService::class)->set('sales.collection.attachment_required', true);

        $row = $this->rowOf($this->pushHttp([$this->change('keep-1', ['amount' => '600', 'lines' => [], 'confirm' => false])]), 'keep-1');

        $this->phone();
        $answer = $this->post('/api/v1/sales/collections/'.$row['entityId'].'/attachment', ['file' => UploadedFile::fake()->image('slip.png', 40, 30)],
            ['Accept' => 'application/json']);

        $this->assertSame(DocumentStatus::DRAFT, Collection::acrossBranches()->where('public_id', $row['entityId'])->value('status'),
            '⛔ দফা ১৫: "খসড়া রাখুন" বলা আদায় স্লিপ পেয়ে নিজে পোস্ট হয়ে গেল');
        $this->assertSame([201, 'draft'], [$answer->status(), $answer->json('state')], '⛔ দফা ১৫: স্লিপ নেওয়া হয়নি, বা উত্তর খসড়া বলেনি');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra @return array<string, mixed> — অ্যাপের একটা বদল, `CollectionDraft.toPayload()`-এর আকারে */
    private function change(string $id, array $extra): array
    {
        return [
            'changeId' => $id, 'entityType' => 'Collection', 'entityId' => null, 'operation' => 'CREATE', 'clientVersion' => 1,
            'payloadJson' => json_encode([
                'customerId' => (string) $this->dealer->public_id,
                'trxDate' => now()->toDateString(),
                'accountId' => (string) $this->bank->public_id,
                'confirm' => true,
                ...$extra,
            ]),
        ];
    }

    /** @param  list<array<string, mixed>>  $changes @return list<array<string, mixed>> */
    private function pushHttp(array $changes): array
    {
        $this->phone();

        return $this->postJson('/api/v1/sync/sales/push?deviceId='.self::DEVICE, $changes)->assertOk()->json('outcomes');
    }

    /** @param  list<array<string, mixed>>  $outcomes @return array<string, mixed> */
    private function rowOf(array $outcomes, string $changeId): array
    {
        $row = collect($outcomes)->firstWhere('changeId', $changeId);
        $this->assertIsArray($row, 'উত্তরে এই বদলের সারিই নেই: '.$changeId);

        return $row;
    }

    private function bill(string $no, string $total): SalesInvoice
    {
        return SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $this->dealer->id,
            'branch_id' => CompanyContext::branchId(), 'trx_date' => now()->subDays(5)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'subtotal' => $total, 'discount' => '0', 'tax' => '0', 'total' => $total, 'status' => DocumentStatus::CONFIRMED]);
    }

    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->clerk->fresh(), [AuthController::APP]);
    }
}
