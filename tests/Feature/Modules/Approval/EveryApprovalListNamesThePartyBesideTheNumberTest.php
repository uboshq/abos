<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PaperParties;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Person;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Sales\Models\Collection;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের
 * নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * "আমার অনুরোধ" আর "সই হয়েছে, পোস্ট হয়নি"-তে কেবল কাজের নাম (আর দ্বিতীয়টায় নম্বর); অনুমোদনের ড্যাশবোর্ডের "এখন যা আটকে
 * আছে"-তে কাঁচা ইংরেজি চাবি ("sales", "collection"), নম্বরই নেই; নিয়মের বাইরের পর্দায় "sales · collection"। আদায়, ভাউচার আর
 * ক্রয়ের বিজ্ঞপ্তিতে পক্ষ নেই — কেবল বিক্রির কাগজ নিজের গ্রাহক বলত। ফোনের সই-বাক্সে পক্ষ কেবল সারাংশের ভিতরে।
 *
 * ⭐ দাবি: প্রতিটা পর্দার একই সারিতে নম্বর আর "নাম · পয়েন্ট" (গ্রাহক), সরবরাহকারীর কাগজে সরবরাহকারী; ২২ সারির পাতায় গ্রাহকের
 * টেবিলে প্রশ্ন দুইটার বেশি নয় (N+1 নয়); আদায়/ভাউচার/ক্রয়ের বিজ্ঞপ্তিতে পক্ষ; ফোনের সারিতে আলাদা `party` ঘর।
 */
final class EveryApprovalListNamesThePartyBesideTheNumberTest extends TestCase
{
    use RefreshDatabase;

    private const POINT = 'কারওয়ান বাজার';

    private Company $company;

    private User $owner;

    private Customer $shop;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->useCompany();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $point = Location::query()->create([
            'company_id' => $this->company->id, 'parent_id' => null, 'code' => 'PT-PN',
            'name_en' => 'Kawran Bazar', 'name_bn' => self::POINT, 'level' => Location::POINT, 'is_active' => true,
        ]);
        $this->shop = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->shop->forceFill(['location_id' => $point->id])->save();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
    }

    public function test_my_requests_name_the_shop_with_its_point_and_the_supplier_beside_the_number(): void
    {
        $this->ask($this->collection('COL-PN-1', $this->shop), 'sales', 'collection', Approval::PENDING);
        $this->ask($this->order('PO-PN-1'), 'purchase', 'order', Approval::PENDING);

        $html = $this->actingAs($this->owner)->get(route('approval.inbox.mine'))->assertOk()->getContent();
        $this->useCompany();

        $this->assertStringContainsString($this->shopLabel(), $this->rowOf($html, 'COL-PN-1', '<tr'),
            '⛔ "আমার অনুরোধ"-এ আদায়ের নম্বরের সারিতে গ্রাহক · পয়েন্ট নেই।');
        $this->assertStringContainsString(e($this->supplier->name()), $this->rowOf($html, 'PO-PN-1', '<tr'),
            '⛔ "আমার অনুরোধ"-এ ক্রয় আদেশের সারিতে সরবরাহকারী নেই।');
    }

    public function test_signed_but_not_posted_names_the_party_beside_the_number(): void
    {
        $this->ask($this->collection('COL-PN-2', $this->shop, DocumentStatus::DRAFT), 'sales', 'collection', Approval::APPROVED,
            ['decided_at' => now()]);

        $html = $this->actingAs($this->owner)->get(route('approval.inbox.signed_unposted'))->assertOk()->getContent();
        $this->useCompany();

        $this->assertStringContainsString($this->shopLabel(), $this->rowOf($html, 'COL-PN-2', '<tr'),
            '⛔ "সই হয়েছে, পোস্ট হয়নি"-তে নম্বরের সারিতে গ্রাহক · পয়েন্ট নেই।');
    }

    public function test_the_dashboard_says_which_paper_is_stuck_by_name_number_and_party_not_by_raw_codes(): void
    {
        $this->ask($this->collection('COL-PN-3', $this->shop), 'sales', 'collection', Approval::PENDING);
        $this->actingAs($this->owner);

        $listing = ApprovalDashboard::dashboard()->listings[0];
        $row = $listing->rows->first(fn (Approval $a) => $a->module === 'sales');
        $this->assertNotNull($row, '⛔ ড্যাশবোর্ডের "এখন যা আটকে আছে"-তে অনুরোধটা নেই।');

        $cells = implode(' ', array_map(function (array $column) use ($row): string {
            $value = ($column['render'])($row);

            return $value instanceof \Illuminate\Contracts\Support\Htmlable ? $value->toHtml() : (string) $value;
        }, $listing->columns));

        $this->assertStringContainsString('COL-PN-3', $cells, '⛔ ড্যাশবোর্ডের সারিতে কাগজের নম্বর নেই।');
        $this->assertStringContainsString($this->shopLabel(), $cells, '⛔ ড্যাশবোর্ডের সারিতে গ্রাহক · পয়েন্ট নেই।');
        $this->assertStringContainsString(e(__('sales::approval.collection')), $cells, '⛔ ড্যাশবোর্ডে কাজের বাংলা নাম নেই।');
        $this->assertStringNotContainsString('>collection<', str_replace(["\n", ' '], '', $cells), '⛔ ড্যাশবোর্ডে কাঁচা চাবি।');
    }

    public function test_the_exceptions_page_names_the_late_paper_with_its_number_and_party(): void
    {
        $this->ask($this->collection('COL-PN-4', $this->shop), 'sales', 'collection', Approval::PENDING,
            ['due_at' => now()->subDays(2), 'escalated_at' => null]);

        $html = $this->actingAs($this->owner)->get(route('approval.exception.index'))->assertOk()->getContent();
        $this->useCompany();

        $row = $this->rowOf($html, 'COL-PN-4', '<li');
        $this->assertStringContainsString($this->shopLabel(), $row, '⛔ নিয়মের বাইরের পর্দায় দেরির কাগজের পাশে গ্রাহক · পয়েন্ট নেই।');
        $this->assertStringContainsString(e(__('sales::approval.collection')), $row, '⛔ নিয়মের বাইরের পর্দায় কাঁচা "sales · collection"।');
    }

    public function test_a_page_of_twenty_two_requests_asks_the_customers_table_at_most_twice(): void
    {
        $customers = Customer::query()->orderBy('id')->limit(11)->get();
        $this->assertGreaterThanOrEqual(2, $customers->count());

        // ⓘ ভিত — খালি পাতা (মেনু, টপবার যা-ই গ্রাহকের টেবিল পড়ুক, সেটা সারির নয়)
        $empty = $this->queriesOfMyRequests();

        $add = function (int $from, int $to) use ($customers): void {
            for ($i = $from; $i < $to; $i++) {
                $paper = $i % 2 === 0
                    ? $this->collection('COL-NP-'.$i, $customers[$i % $customers->count()])
                    : $this->order('PO-NP-'.$i);
                $this->ask($paper, $i % 2 === 0 ? 'sales' : 'purchase', $i % 2 === 0 ? 'collection' : 'order', Approval::PENDING);
            }
        };

        $add(0, 2);
        $two = $this->queriesOfMyRequests();
        $add(2, 22);
        $twentyTwo = $this->queriesOfMyRequests();

        foreach (['customers' => 'গ্রাহকের', 'suppliers' => 'সরবরাহকারীর'] as $table => $whose) {
            $this->assertSame($this->asked($two, $table), $this->asked($twentyTwo, $table),
                "⛔ দুই সারি থেকে বাইশ সারিতে {$whose} টেবিলে প্রশ্ন বেড়েছে — সারি ধরে নাম তোলা (N+1)।");
            $this->assertLessThanOrEqual(2, $this->asked($twentyTwo, $table) - $this->asked($empty, $table),
                "⛔ ২২ সারির পাতায় {$whose} নাম তুলতে দুইটার বেশি প্রশ্ন।");
        }
    }

    /**
     * ⛔ পিছিয়ে না পড়া — ব্যক্তির ধরনটা নিবন্ধিত নয় (MasterData বন্ধ বা ঘোষণা নেই), তবু উত্তোলনের সারিতে ব্যক্তির নাম, আগের মতো;
     * আর নামগুলো একবারে তোলা — এক থেকে তিনটা কাগজে ব্যক্তির টেবিলে প্রশ্ন বাড়ে না।
     */
    public function test_a_person_paper_still_names_the_person_when_the_person_type_is_not_registered(): void
    {
        $registry = app(PartyRegistry::class);
        $types = $registry->all();
        $this->assertArrayHasKey('person', $types, 'ⓘ ভিত — ধরনটা আজ নিবন্ধিত; নইলে দাবিটা কিছু মাপছে না');
        unset($types['person']);
        (function () use ($types): void {
            $this->labels = $types; // ⓘ নিবন্ধনের মনে-রাখা তালিকা — ব্যক্তি বাদ, যেন MasterData ঘোষণা করেনি
        })->call($registry);
        $this->app->instance(PartyRegistry::class, $registry);
        $this->app->forgetInstance(PaperParties::class);
        $this->app->forgetInstance(ApprovalFacts::class);
        $this->assertFalse(app(PartyRegistry::class)->knows('person'));

        $first = $this->person('PN-P1', 'Abdul Karim');
        $this->ask($this->withdrawal('WD-PN-1', $first), 'finance', 'withdrawal', Approval::PENDING);
        $one = $this->queriesOfMyRequests();
        $html = $this->actingAs($this->owner)->get(route('approval.inbox.mine'))->assertOk()->getContent();
        $this->useCompany();
        $this->assertStringContainsString(e($first->name()), $this->rowOf($html, 'WD-PN-1', '<tr'),
            '⛔ ব্যক্তির ধরন নিবন্ধিত না থাকায় উত্তোলনের সারিতে ব্যক্তির নাম হারাল (আগে দেখাত)।');

        foreach (['PN-P2' => 'Rafiq Ahmed', 'PN-P3' => 'Selina Begum'] as $code => $name) {
            $this->ask($this->withdrawal('WD-'.$code, $this->person($code, $name)), 'finance', 'withdrawal', Approval::PENDING);
        }
        $three = $this->queriesOfMyRequests();

        $this->assertSame($this->asked($one, 'mdm_people'), $this->asked($three, 'mdm_people'),
            '⛔ ব্যক্তির নাম সারি ধরে তোলা (N+1) — এক থেকে তিন কাগজে প্রশ্ন বেড়েছে।');
        $this->assertLessThanOrEqual(1, $this->asked($three, 'mdm_people'), '⛔ ব্যক্তির নাম তুলতে একটার বেশি প্রশ্ন।');
    }

    public function test_the_notices_of_a_collection_a_voucher_and_a_purchase_order_name_the_party(): void
    {
        $engine = app(ApprovalEngine::class);

        $collection = $this->ask($this->collection('COL-PN-5', $this->shop), 'sales', 'collection', Approval::PENDING);
        $this->assertStringContainsString($this->shopName(), $engine->noticeLabel($collection->fresh()),
            '⛔ আদায়ের বিজ্ঞপ্তিতে গ্রাহক · পয়েন্ট নেই।');
        $this->assertSame($this->shopName(), $engine->noticeData($collection->fresh())['party'] ?? null,
            '⛔ আদায়ের বিজ্ঞপ্তির মানে `party` নেই।');

        $voucher = $this->ask($this->voucher('RCV-PN-1', $this->shop), 'accounts', 'voucher', Approval::PENDING);
        $this->assertStringContainsString($this->shopName(), $engine->noticeLabel($voucher->fresh()),
            '⛔ ভাউচারের বিজ্ঞপ্তিতে পক্ষ নেই।');

        $order = $this->ask($this->order('PO-PN-2'), 'purchase', 'order', Approval::PENDING);
        $this->assertStringContainsString($this->supplier->name(), $engine->noticeLabel($order->fresh()),
            '⛔ ক্রয় আদেশের বিজ্ঞপ্তিতে সরবরাহকারী নেই।');
    }

    public function test_the_phone_rows_carry_the_party_in_their_own_field(): void
    {
        $asker = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $asker->companies()->attach($this->company->id, ['is_active' => true]);

        $this->ask($this->collection('COL-PN-6', $this->shop), 'sales', 'collection', Approval::PENDING,
            ['requested_by' => $asker->id, 'assigned_to' => $this->owner->id]);
        $this->ask($this->order('PO-PN-3'), 'purchase', 'order', Approval::PENDING);

        Sanctum::actingAs($this->owner, [AuthController::APP]);
        $pending = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows'))->firstWhere('documentNo', 'COL-PN-6');
        $this->assertNotNull($pending, '⛔ সই-বাক্সে আদায়টা নেই।');
        $this->assertSame($this->shopName(), $pending['party'] ?? null, '⛔ সই-বাক্সের সারিতে আলাদা `party` ঘর নেই।');
        $this->assertStringStartsWith($this->shop->name(), (string) $pending['summary'], '⛔ সারাংশ বদলে গেছে — পুরনো অ্যাপ পক্ষ হারাত।');

        $mine = collect($this->getJson('/api/v1/approvals/mine')->assertOk()->json('rows'))->firstWhere('documentNo', 'PO-PN-3');
        // ⓘ সরবরাহকারীর শাখা থাকলে "নাম · শাখা" — পক্ষের এক নিয়ম ([[PartyRegistry::labelsOf()]]; ১৯ অক্টোবর ২০২৬)
        $this->assertSame(app(PartyRegistry::class)->labelsOf([['supplier', $this->supplier->id]])['supplier:'.$this->supplier->id] ?? null,
            $mine['party'] ?? null, '⛔ "আমার অনুরোধ"-এর ফোনের সারিতে সরবরাহকারী নেই।');
    }

    // ── সাহায্যকারী ──────────────────────────────────────────────────────

    private function useCompany(): void
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /** গ্রাহক · পয়েন্ট, পাতায় যেমন ছাপা — অনুরোধের পরে পড়া, কারণ ভাষা ঠিক করে অনুরোধ */
    private function shopLabel(): string
    {
        return e($this->shopName());
    }

    /** ⭐ "নাম · পয়েন্ট · শাখা" — শাখাও (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম); দোকানের শাখা না থাকলে কেবল "নাম · পয়েন্ট" */
    private function shopName(): string
    {
        $branch = (string) $this->shop->fresh('branch')->branchName();

        return $this->shop->name().' · '.self::POINT.($branch === '' ? '' : ' · '.$branch);
    }

    /** @param array<string, mixed> $extra */
    private function ask(Model $paper, string $module, string $action, string $status, array $extra = []): Approval
    {
        return Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => $paper::class,
            'approvable_id' => $paper->getKey(),
            'module' => $module,
            'action' => $action,
            'amount' => '1000',
            'status' => $status,
            'current_level' => 1,
            'requested_by' => $this->owner->id,
            'requested_at' => now(),
            ...$extra,
        ]);
    }

    private function collection(string $no, Customer $customer, string $status = DocumentStatus::CONFIRMED): Collection
    {
        return Collection::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no, 'customer_id' => $customer->id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'status' => $status,
        ]);
    }

    private function order(string $no): PurchaseOrder
    {
        return PurchaseOrder::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no,
            'supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'total' => '1000',
            'created_by' => $this->owner->id,
        ]);
    }

    private function person(string $code, string $name): Person
    {
        return Person::query()->create(['company_id' => $this->company->id, 'code' => $code, 'name_en' => $name,
            'name_bn' => $name, 'is_active' => true]);
    }

    private function withdrawal(string $no, Person $person): Withdrawal
    {
        return Withdrawal::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => $no, 'person_id' => $person->id,
            'amount' => '1000', 'kind' => Withdrawal::DRAWING, 'trx_date' => now()->toDateString(), 'status' => DocumentStatus::DRAFT,
        ]);
    }

    private function voucher(string $no, Customer $customer): Voucher
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->orderByDesc('starts_on')->first()
            ?? FinancialYear::query()->create(['company_id' => $this->company->id, 'name' => '২০২৬-২৭',
                'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString()]);

        return Voucher::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $year->id, 'type' => Voucher::RECEIPT, 'document_no' => $no,
            'trx_date' => now()->toDateString(), 'party_type' => 'customer', 'party_id' => $customer->id,
            'amount' => '1000.0000', 'status' => DocumentStatus::DRAFT,
        ]);
    }

    /** পাতার যে সারিতে লেখাটা আছে — `<tr` বা `<li` ধরে কাটা; না পেলে খালি, আর দাবি তখন নিজেই লাল */
    private function rowOf(string $html, string $needle, string $opens): string
    {
        // ⓘ নম্বরটা পাতার অন্য জায়গাতেও থাকতে পারে (জমার বার্তা) — তাই পক্ষের ঘর-ওয়ালা সারি আগে
        $pieces = array_values(array_filter(explode($opens, $html), fn (string $piece) => str_contains($piece, $needle)));

        foreach ($pieces as $piece) {
            if (str_contains($piece, 'data-party')) {
                return $piece;
            }
        }

        if ($pieces !== []) {
            return $pieces[0];
        }

        $this->fail("⛔ পাতায় {$needle}-এর সারিই নেই।");
    }

    /** @return list<string> "আমার অনুরোধ" পাতা একবার খুলতে যত প্রশ্ন গেল */
    private function queriesOfMyRequests(): array
    {
        $this->actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('approval.inbox.mine'))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->useCompany();

        return $queries;
    }

    /** @param list<string> $queries */
    private function asked(array $queries, string $table): int
    {
        return count(array_filter($queries, fn (string $sql) => preg_match('/\bfrom\s+[`"]?'.$table.'[`"]?(\s|$)/i', $sql) === 1));
    }
}
