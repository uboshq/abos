<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Posting\PostingException;
use App\Core\Services\SettingsService;
use App\Core\Support\AmountInWords;
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
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Sync\CollectionSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ আদায়ের রসিদের কথা — নম্বর, অবস্থা, কথায় টাকা, রসিদের PDF, কোন কাগজে কত (a5-র উত্তর ৩; a4/collect-easy a3a030302:
 * `CollectionSaved.of(landed, outcome.details)`; মালিকের নকশা ১০০-এর দফা ৪, ৭, ১৫, ১৭, ১৯)।
 *
 * ⛔ ২০ নভেম্বর ২০২৬ (মালিক, চূড়ান্ত: "টাকা ঢোকার কাগজ একটাই") — ফোন আর আদায় লেখে না: পাঠানো নতুন আদায় "টাকা জমার খবর"-এর
 * কারণে REJECTED, স্লিপের দরজা ৪২২। তাই রসিদের কথার দাবিগুলো এখন আগের আদায়ে (এখানে সেবা দিয়ে সরাসরি বানানো, ফোন আগে যেমন
 * বসাত — [[CollectionInput::fromPhone()]] আর [[CollectionService]]), সিঙ্কের নিজের উত্তর-বানানো দিয়ে ([[CollectionSync::answerFor()]]
 * — একই বদল আবার এলে পুরনো বসা আদায়ের উত্তর এখান থেকেই) আর ফোনের আদায়ের পাতায়:
 *   · `no`, `state` (paper_states-এর চাবি), `amount_words`, `receipt_pdf`, `applied` (ক্রেডিট ঋণাত্মক), `unapplied`
 *   · একই changeId দুবার — দুবারই ফেরত, একই কথা, কিছুই বসে না (দফা ৭)
 *   · ছাড়ে মালিকের সই — `state: waiting` (দফা ৪)
 *   · আগের আদায়ে স্লিপ — ৪২২, কিছুই রাখা বা পাকা হয় না (দফা ১৫)
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
     * দফা ১৭, ১৯ — রসিদের সব কথা, সেই আদায়েরই: নম্বর, `posted`, কথায় টাকা, রসিদের ঠিকানা, কোন কাগজে কত (বিলে পুরো অঙ্ক, ক্রেডিট
     * ঋণাত্মক — যোগ = পাওয়া টাকা), অপ্রয়োগকৃত। দুই আদায় — প্রতিটার উত্তর নিজের কথা বলে।
     */
    public function test_item_17_each_collection_answers_with_its_own_receipt(): void
    {
        $prior = $this->paper(['amount' => '500', 'lines' => []]);
        $bill = $this->bill('INV-ANS-1', '1200');

        $paper = $this->paper(['amount' => '700', 'lines' => [
            ['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1200.00'],
            ['kind' => 'credit', 'id' => $prior->public_id, 'amount' => '-500.00'],
        ]]);
        $other = $this->paper(['amount' => '300', 'lines' => []]);

        $row = $this->answerOf($paper);

        $this->assertSame(DocumentStatus::CONFIRMED, $paper->status, 'প্রস্তুতিটাই ভুল — আদায় পাকা হয়নি');
        $this->assertSame($paper->document_no, $row['no'] ?? null, '⛔ দফা ১৭: উত্তরে নম্বর নেই');
        $this->assertSame('posted', $row['state'] ?? null, '⛔ দফা ১৭: উত্তরের অবস্থা paper_states-এর `posted` নয়');
        $this->assertSame(AmountInWords::of('700.0000', app()->getLocale()), $row['amount_words'] ?? null, '⛔ দফা ১৭: কথায় টাকা নেই বা ভুল');
        $this->assertSame(route('api.documents.pdf', ['type' => 'Collection', 'id' => $paper->public_id]), $row['receipt_pdf'] ?? null,
            '⛔ দফা ১৭: রসিদের ঠিকানা নেই, বা ফোনের টোকেনের দরজা নয়');
        $this->assertSame([
            ['kind' => 'bill', 'no' => 'INV-ANS-1', 'amount' => '1200.0000'],
            ['kind' => 'credit', 'no' => (string) $prior->document_no, 'amount' => '-500.0000'],
        ], $row['applied'] ?? null, '⛔ দফা ১৭: কোন কাগজে কত — বিলে পুরো অঙ্ক আর ক্রেডিট ঋণাত্মক নয় (যোগ পাওয়া টাকার সমান নয়)');
        $this->assertSame('0.0000', $row['unapplied'] ?? null, '⛔ দফা ১৭: অপ্রয়োগকৃত ভুল');

        $second = $this->answerOf($other);
        $this->assertSame($other->document_no, $second['no'] ?? null, '⛔ দফা ১৯: দ্বিতীয় আদায়ের উত্তরে অন্য আদায়ের কথা — উত্তর নিজের নয়');
        $this->assertSame('300.0000', $second['unapplied'] ?? null, '⛔ দফা ১৯: দ্বিতীয় আদায়ের অপ্রয়োগকৃত নিজের নয়');
    }

    /**
     * কথায় টাকা যিনি চাইছেন তাঁর ভাষায় (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬) — ফোনের আদায়ের পাতায়: ইংরেজির মানুষ ইংরেজি কথা
     * পান, বাংলার মানুষ বাংলা। ⓘ ভাষা মানুষের `users.locale` থেকে ([[ResolveCompanyContext]]); সই-করা মানুষের বেলায় `Accept-Language`
     * তাঁর বাছাই উল্টায় না (a4/api-locale-এর একই ক্রম), তাই শিরোনামটা ইচ্ছে করে উল্টো ভাষায় পাঠানো।
     */
    public function test_the_amount_in_words_follows_the_readers_own_language(): void
    {
        $this->assertNotSame(AmountInWords::of('700.0000', 'en'), AmountInWords::of('700.0000', 'bn'), 'দুই ভাষার কথা একই — দাবিটা কিছু মাপছে না');
        $paper = $this->paper(['amount' => '700', 'lines' => []]);
        $page = '/api/v1/sales/collections/'.$paper->public_id;

        $this->clerk->forceFill(['locale' => 'en'])->save();
        $this->phone();
        $english = $this->getJson($page, ['Accept-Language' => 'bn'])->assertOk()->json();
        $this->assertSame(AmountInWords::of('700.0000', 'en'), $english['amount_words'] ?? null, '⛔ ইংরেজির মানুষ কথায় টাকা ইংরেজিতে পাননি');

        $this->clerk->forceFill(['locale' => 'bn'])->save();
        $this->phone();
        $bangla = $this->getJson($page, ['Accept-Language' => 'en'])->assertOk()->json();
        $this->assertSame(AmountInWords::of('700.0000', 'bn'), $bangla['amount_words'] ?? null, '⛔ বাংলার মানুষ কথায় টাকা বাংলায় পাননি');
    }

    /**
     * দফা ১৭ — রসিদের PDF ফোন নিজের টোকেনেই খোলে (a4-এর অ্যাপ 5802d83c9, ২২ অক্টোবর ২০২৬): আগে ঠিকানাটা ওয়েবের ছাপার, ব্রাউজারের লগইন
     * চাইত। ⓘ টোকেনে ২০০ আর আসল PDF; টোকেন ছাড়া ৪০১; আদায় দেখার চাবি নেই তো ৪০৩; অন্য কোম্পানির আদায় ৪০৪ ("আছে" বলাও খবর) —
     * ফোনের নথির দরজার নিয়ম ([[DocumentApiController]])।
     */
    public function test_item_17_the_receipt_pdf_opens_with_the_phones_token_and_only_for_whom_it_may(): void
    {
        $paper = $this->paper(['amount' => '640', 'lines' => []]);
        $url = (string) ($this->answerOf($paper)['receipt_pdf'] ?? '');
        $this->assertStringContainsString('/api/v1/documents/Collection/'.$paper->public_id.'/pdf', $url, '⛔ রসিদের ঠিকানা ফোনের দরজার নয়');

        $this->phone();
        $pdf = $this->get($url, ['Accept' => 'application/json'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), '⛔ টোকেনে রসিদ এল না — PDF-এর বাইট নয়');

        $this->app['auth']->forgetGuards();
        $this->get($url, ['Accept' => 'application/json'])->assertUnauthorized();

        $stranger = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $stranger->givePermissionTo(
            array_map(fn (string $k) => \Spatie\Permission\Models\Permission::findOrCreate($k, 'web'), ['sales.collection.create', 'accounts.voucher.create'])));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger->fresh(), [AuthController::ACCESS, AuthController::APP]);
        $this->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        \Illuminate\Support\Facades\DB::table('sal_collections')->where('public_id', $paper->public_id)->update(['company_id' => $other->id]);
        $this->phone();
        $gone = $this->get($url, ['Accept' => 'application/json'])->assertNotFound();
        $this->assertStringStartsNotWith('%PDF', (string) $gone->getContent(), '⛔ অন্য কোম্পানির রসিদের বাইট এল');
    }

    /**
     * দফা ৭ — নেট গেলে আবার চাপলে (একই changeId): ⛔ ২০ নভেম্বর ২০২৬ থেকে দুবারই ফেরত ("টাকা জমার খবর"), একই কথা, কোনো আদায় বা
     * খাতার সারি নয়, উত্তরে কাগজের id-ও নয় — ফোন ফেরত দেখে আর পাঠায় না।
     */
    public function test_item_7_the_same_change_twice_is_refused_twice_and_writes_nothing(): void
    {
        $papers = Collection::acrossBranches()->count();
        $rows = LedgerEntry::query()->count();

        $first = $this->rowOf($this->pushHttp([$this->change('twice-1', ['amount' => '450', 'lines' => []])]), 'twice-1');
        $again = $this->rowOf($this->pushHttp([$this->change('twice-1', ['amount' => '450', 'lines' => []])]), 'twice-1');

        foreach (['প্রথমবার' => $first, 'দ্বিতীয়বার' => $again] as $when => $row) {
            $this->assertSame(SyncChange::REJECTED, $row['status'] ?? null, "⛔ দফা ৭: {$when} ফোনের আদায় ফেরত হয়নি — ".json_encode($row));
            $this->assertSame(CollectionSync::useTheDepositReport(), $row['message'] ?? null, "⛔ দফা ৭: {$when} ফেরতের কথা \"টাকা জমার খবর\" নয়");
            $this->assertArrayNotHasKey('entityId', $row, "⛔ দফা ৭: {$when} ফেরত উত্তরে কাগজের id এল");
            $this->assertArrayNotHasKey('no', $row, "⛔ দফা ৭: {$when} ফেরত উত্তরে রসিদের নম্বর এল");
        }

        $this->assertSame([CollectionSync::useTheDepositReport()], $again['errors']['_'] ?? null, '⛔ দফা ৭: দ্বিতীয়বারের ফেরতে ঘরহীন কারণ নেই (চুক্তি §৭.২)');
        $this->assertSame([$papers, $rows], [Collection::acrossBranches()->count(), LedgerEntry::query()->count()],
            '⛔ দফা ৭: ফেরত বদলেও আদায় বা খাতার সারি বসল');
    }

    /** দফা ৪ — আদায়ে ছাড়: খাতায় নয়, মালিকের সইয়ের অপেক্ষায় — উত্তরে `state: waiting` */
    public function test_item_4_a_discount_waits_for_the_owner_and_says_waiting(): void
    {
        app(OwnerSignsDiscounts::class)->ensure($this->company);
        $bill = $this->bill('INV-ANS-D', '1000');

        $paper = $this->paper(['amount' => '980',
            'difference' => ['kind' => 'discount', 'amount' => '20'],
            'lines' => [['kind' => 'bill', 'id' => $bill->public_id, 'amount' => '1000.00']]]);
        $row = $this->answerOf($paper);

        $this->assertSame(DocumentStatus::DRAFT, $paper->status, '⛔ দফা ৪: ছাড় মালিকের সই ছাড়াই পোস্ট হলো');
        $this->assertSame('waiting', $row['state'] ?? null, '⛔ দফা ৪: উত্তর বলল না যে সইয়ের অপেক্ষায় (`waiting`)');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $paper->id)->count(),
            '⛔ দফা ৪: সইয়ের আগে খাতায় সারি');
    }

    /**
     * দফা ১৫ — ⛔ ২০ নভেম্বর ২০২৬ থেকে ফোনের স্লিপের দরজা আদায় নেয় না (স্লিপ যায় "টাকা জমার খবর"-এর সাথে): স্লিপ-বাধ্যতামূলক
     * কোম্পানিতে খসড়ায় থামা আগের আদায়ে `file` পাঠালেও ৪২২, কারণ "টাকা জমার খবর"; আদায় খসড়াই থাকে, খাতায় কিছু নয়, কোনো
     * সংযুক্তি রাখা হয় না। ⓘ আগের দ্বিতীয় দাবি ("খসড়া রাখুন" স্লিপে পোস্ট হয় না) এর ভিতরেই — স্লিপে কিছুই পোস্ট হয় না।
     */
    public function test_item_15_a_slip_for_an_old_collection_is_refused_and_posts_nothing(): void
    {
        Storage::fake('local');
        app(SettingsService::class)->set('sales.collection.attachment_required', true);

        $paper = $this->paper(['amount' => '600', 'lines' => []]);
        $this->assertSame('draft', $this->answerOf($paper)['state'] ?? null, 'প্রস্তুতিটাই ভুল — স্লিপ ছাড়া আদায় পোস্ট হলো');
        $kept = Attachment::query()->withoutGlobalScopes()->count();

        $this->phone();
        $answer = $this->post('/api/v1/sales/collections/'.$paper->public_id.'/attachment',
            ['file' => UploadedFile::fake()->image('slip.png', 40, 30)], ['Accept' => 'application/json']);

        $this->assertSame(422, $answer->status(), '⛔ দফা ১৫: ফোনের স্লিপ আদায়ে নেওয়া হলো ('.$answer->status().')');
        $this->assertSame(CollectionSync::useTheDepositReport(), $answer->json('message'), '⛔ দফা ১৫: স্লিপের ফেরতের কথা "টাকা জমার খবর" নয়');
        $this->assertSame([CollectionSync::useTheDepositReport()], $answer->json('errors.collection'), '⛔ দফা ১৫: ফেরতের `errors.collection` নেই');

        $this->assertSame(DocumentStatus::DRAFT, Collection::acrossBranches()->whereKey($paper->id)->value('status'), '⛔ দফা ১৫: স্লিপের দরজায় আদায় পোস্ট হলো');
        $this->assertSame(0, LedgerEntry::query()->where('source_type', Collection::drillSourceType())->where('source_id', $paper->id)->count(),
            '⛔ দফা ১৫: ফেরত স্লিপের পরে খাতায় সারি');
        $this->assertSame($kept, Attachment::query()->withoutGlobalScopes()->count(), '⛔ দফা ১৫: ফেরত স্লিপের সংযুক্তি রাখা হলো');
        $this->assertSame([], Storage::disk('local')->allFiles(), '⛔ দফা ১৫: ফেরত স্লিপের ফাইল জমা হলো');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /**
     * ⓘ আগের আদায় — ফোন ২০ নভেম্বর ২০২৬-এর আগে যেমন বসাত: অ্যাপের আকারের আবেদন ([[CollectionInput::fromPhone()]]), একই সেবা, আর
     * "এখনই নিশ্চিত" — অনুমোদন বা টাকার নিয়মে আটকালে খসড়া (সিঙ্কের পুরনো আচরণ)। পাঠানো মানুষটা হিসাবরক্ষক।
     *
     * @param  array<string, mixed>  $extra
     */
    private function paper(array $extra, bool $confirm = true): Collection
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->clerk->fresh());

        $service = app(CollectionService::class);
        $collection = $service->createFrom(CollectionInput::fromPhone([
            'customerId' => (string) $this->dealer->public_id,
            'trxDate' => now()->toDateString(),
            'accountId' => (string) $this->bank->public_id,
            ...$extra,
        ]));

        if ($confirm) {
            try {
                $collection = $service->confirm($collection->fresh(['lines']));
            } catch (ValidationException|PostingException) {
                // ⓘ খসড়া থাকল — আগের সিঙ্কের একই আচরণ
            }
        }

        return Collection::acrossBranches()->whereKey($collection->id)->firstOrFail();
    }

    /** @return array<string, mixed> — সিঙ্কের নিজের উত্তর-বানানো ([[CollectionSync::answerFor()]]) */
    private function answerOf(Collection $paper): array
    {
        return app(CollectionSync::class)->answerFor((string) $paper->public_id);
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

    /**
     * ⓘ আসল ফোনের টোকেন — লগইনে access টোকেন দুই চাবি পায় ([[AuthController]]: `ACCESS` = `sync` আর `APP`)। আগে শুধু `APP`
     * দেওয়া হত, আর সিঙ্কের দরজা (`routes/api.php`-এর `abilities:sync`) ৪০৩ দিত — Mac-এ সাত দাবি লাল, কোডের দোষ নয়।
     */
    private function phone(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->clerk->fresh(), [AuthController::ACCESS, AuthController::APP]);
    }
}
