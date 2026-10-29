<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Panels\CustomerCardFacts;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Reports\SalesOrderBookReports;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Support\OrderPaperFacts;
use App\Modules\Sales\Support\SalesSigningSheet;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * গ্রাহকের নামের পাশে পয়েন্ট আর শাখা — রিপোর্ট, ছাপা, বিজ্ঞপ্তি, সই আর ফাইলে।
 *
 * ⭐ মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*
 *
 * ⓘ আসল কাগজ, সেবা দিয়ে লেখা: পয়েন্টে বসা এক গ্রাহক (দেখার শাখার — শাখার নাম আছে), তাঁর একটা পাকা বিল আর একটা আদায়।
 * দাবি: (১) রিপোর্টের প্রতিটা গ্রাহকের সারিতে "নাম · পয়েন্ট · শাখা" — ওয়েবে আর ফোনে একই লেখা; পয়েন্টের নিজের কলাম থাকলে
 * সেখানে "পয়েন্ট · শাখা"; (২) বিল আর রসিদের কাগজে পয়েন্ট আর শাখা, গ্রাহকের নামের নিচে, আর কাগজ এক পাতাতেই (A4, A5, রোল);
 * (৩) বিজ্ঞপ্তির পক্ষ আর সইয়ের পাতায় একই লেখা; (৪) রিপোর্টের ফাইলে গ্রাহকের ঘরে পুরো লেখা, নম্বরের ঘর কেবল নম্বর।
 */
final class TheCustomerPlaceReachesReportsPrintsAndNoticesTest extends TestCase
{
    use RefreshDatabase;

    private const POINT_EN = 'Claim Point';

    private const POINT_BN = 'দাবির পয়েন্ট';

    private User $owner;

    private Customer $customer;

    /** "নাম · পয়েন্ট · শাখা" — পর্দার ভাষায় (বাংলা নাম আগে) */
    private string $label;

    /** "পয়েন্ট · শাখা" */
    private string $place;

    private string $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        $area = Location::query()->create([
            'company_id' => $company->id, 'code' => 'T-CPL', 'level' => Location::TERRITORY,
            'name_en' => 'Claim Area', 'name_bn' => 'দাবির এরিয়া', 'is_active' => true,
        ]);
        $point = Location::query()->create([
            'company_id' => $company->id, 'code' => 'P-CPL', 'level' => Location::POINT, 'parent_id' => $area->id,
            'name_en' => self::POINT_EN, 'name_bn' => self::POINT_BN, 'is_active' => true,
        ]);

        // ⓘ দেখার শাখার গ্রাহক — অন্য শাখার পক্ষে বিল লেখা যায় না; ⛔ শূন্য সীমা = বাকি নেই, তাই বড় সীমা (এই দাবি সীমা মাপে না)
        $this->customer = Customer::query()->inViewedBranch()->firstOrFail();
        // ⓘ শাখাও নিজে বসানো — ডেমোর কিছু গ্রাহক শাখাহীন (সব শাখার), আর দেখার শাখার তালিকায় তারাও আসে; তখন দাবির ভিত্তিই থাকত না
        $this->customer->forceFill(['location_id' => $point->id, 'branch_id' => CompanyContext::branchId(), 'credit_limit' => '10000000000'])->save();
        $this->customer = $this->customer->fresh(['location.parent', 'branch']);

        $this->branch = (string) $this->customer->branchName();
        $this->assertNotSame('', $this->branch, '⛔ প্রস্তুতি — গ্রাহকের শাখার নাম নেই, দাবির ভিত্তি নেই।');

        $this->place = self::POINT_BN.' · '.$this->branch;
        $this->label = $this->customer->name().' · '.$this->place;
        $this->assertSame($this->label, $this->customer->nameWithPoint(), '⛔ গ্রাহকের নিজের নিয়ম "নাম · পয়েন্ট · শাখা" নয়।');
    }

    /** ⭐ (১) রিপোর্টের গ্রাহকের ঘর — ওয়েবের ইঞ্জিনে আর ফোনের দরজায় একই "নাম · পয়েন্ট · শাখা" */
    public function test_every_customer_report_row_names_the_point_and_the_branch_on_the_web_and_on_the_phone(): void
    {
        $invoice = $this->anInvoice();
        $today = ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        $engine = app(ReportEngine::class);

        $reports = [
            'sales.by_customer' => ['customer_name', 'customer_id', $this->label],
            SalesOrderBookReports::INVOICE_BOOK => ['customer_name', 'customer_id', $this->label],
            'sales.register' => ['customer_name', 'customer_id', $this->label],
            // ⓘ পয়েন্টের নিজের কলাম — সেখানে "পয়েন্ট · শাখা", নামের ঘর কেবল নাম
            'customer.due_list' => ['point_name', 'party_id', $this->place],
        ];

        $web = [];

        foreach ($reports as $key => [$column, $idKey, $expected]) {
            $rows = collect($engine->run($key, $today, perPage: 500)->rows);
            $row = $rows->first(fn (array $r) => (int) ($r[$idKey] ?? 0) === (int) $this->customer->id);

            $this->assertNotNull($row, "⛔ প্রস্তুতি — {$key}-এ গ্রাহকের সারি নেই।");
            $this->assertSame($expected, $row[$column], "⛔ {$key}: গ্রাহকের ঘরে পয়েন্ট আর শাখা নেই।");
            $web[$key] = $row[$column];
        }

        // ⓘ নামের ঘর এক — পয়েন্টের কলাম থাকা রিপোর্টে নাম দুইবার জায়গা বলে না
        $due = collect($engine->run('customer.due_list', $today, perPage: 500)->rows)
            ->first(fn (array $r) => (int) ($r['party_id'] ?? 0) === (int) $this->customer->id);
        $this->assertStringNotContainsString($this->branch, (string) $due['customer_name'], '⛔ বকেয়ার তালিকায় শাখা দুই ঘরে।');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        foreach ($web as $key => $expected) {
            $phone = $this->getJson('/api/v1/reports/'.$key.'?'.http_build_query($today + ['perPage' => 200]))->assertOk();
            $rows = json_encode($phone->json('rows'), JSON_UNESCAPED_UNICODE);

            $this->assertStringContainsString($expected, (string) $rows, "⛔ ফোনের {$key}: গ্রাহকের ঘর ওয়েবের মতো নয়।");
        }

        $this->assertNotNull($invoice->document_no);
    }

    /** ⭐ (২) বিল আর রসিদ — গ্রাহকের নামের নিচে পয়েন্ট আর শাখা, আর কাগজ এক পাতায় (A4, A5, রোল) */
    public function test_the_bill_and_the_receipt_print_the_point_and_the_branch_and_still_fit_one_sheet(): void
    {
        $invoice = $this->anInvoice();
        $collection = $this->aCollectionOf($invoice, '100');
        $english = self::POINT_EN.' · '.$this->customer->branch->name('en');

        foreach ([null, 'a5', '80mm'] as $paper) {
            $suffix = $paper === null ? '' : '?paper='.$paper;

            foreach ([route('sales.print.invoice', $invoice).$suffix, route('sales.print.receipt', $collection).$suffix] as $url) {
                $response = $this->actingAs($this->owner)->get($url)->assertOk();
                $this->assertSame('application/pdf', $response->headers->get('Content-Type'), "⛔ {$url}: কাগজ PDF হয়নি।");

                $pdf = (string) $response->getContent();
                $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), "⛔ {$url}: শাখা জুড়ে কাগজ এক পাতার বেশি।");
            }
        }

        // ⓘ লেখাটা — নকশার তথ্যেই (সব নকশা একই `point` ঘর পড়ে), ইংরেজি নামে, যেমন নকশাগুলো ছাপে
        $facts = (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))->invoke(app(SalesPrintController::class), $invoice->fresh());
        $this->assertSame($english, $facts['bill_to']['point'], '⛔ বিলের কাগজে পয়েন্টের পাশে শাখা নেই।');
        $this->assertSame($english, OrderPaperFacts::receipt($collection->fresh())['from']['point'],
            '⛔ রসিদের কাগজে পয়েন্টের পাশে শাখা নেই।');
        $this->assertStringContainsString('·', (string) __('sales::print.classic.point', [], 'en'), '⛔ কাগজের ঘরের নাম এখনো কেবল "Point"।');
    }

    /** ⭐ (৩) বিজ্ঞপ্তি আর সই — বিল আর আদায়ের পক্ষ "নাম · পয়েন্ট · শাখা"; সইয়ের পাতার কার্ড আর তথ্যে একই */
    public function test_a_notice_and_the_signing_sheet_name_the_point_and_the_branch(): void
    {
        $invoice = $this->anInvoice();
        $collection = $this->aCollectionOf($invoice, '100');

        $this->assertSame($this->label, $invoice->fresh()->noticeParty(), '⛔ বিলের বিজ্ঞপ্তিতে পয়েন্ট বা শাখা নেই।');
        $this->assertSame($this->label, $collection->fresh()->noticeParty(), '⛔ আদায়ের বিজ্ঞপ্তিতে পয়েন্ট বা শাখা নেই।');

        // ⓘ ঘণ্টি, বিজ্ঞপ্তি কেন্দ্র আর ফোন — তিনটাই ইঞ্জিনের এই দুই লেখা পড়ে
        $approval = new Approval(['module' => 'sales', 'action' => 'collection', 'status' => Approval::PENDING]);
        $approval->setRelation('approvable', $collection->fresh());
        $engine = app(ApprovalEngine::class);
        $this->assertSame($this->label, $engine->noticeData($approval)['party'] ?? null, '⛔ বিজ্ঞপ্তির টেমপ্লেটে পক্ষ পুরো নয়।');
        $this->assertStringContainsString($this->label, $engine->noticeLabel($approval), '⛔ বিজ্ঞপ্তির লেখায় পক্ষ পুরো নয়।');

        $sheet = SalesSigningSheet::ofInvoice($invoice->fresh());
        $this->assertContains($this->label, array_column($sheet['facts'], 'value'), '⛔ সইয়ের পাতার তথ্যে গ্রাহকের পয়েন্ট আর শাখা নেই।');

        $card = collect(CustomerCardFacts::factsFor(CustomerCardFacts::ENTITY, $this->customer->id))->firstWhere('label', 'customer::field.place');
        $this->assertNotNull($card, '⛔ সইয়ের কার্ডে "পয়েন্ট · শাখা" ঘর নেই।');
        $this->assertSame($this->place, $card->value, '⛔ সইয়ের কার্ডে পয়েন্টের পাশে শাখা নেই।');
    }

    /** ⭐ (৪) রিপোর্টের ফাইল — গ্রাহকের ঘরে "নাম · পয়েন্ট · শাখা", বিলের নম্বরের ঘর কেবল নম্বর */
    public function test_a_report_file_carries_the_full_name_while_the_number_cell_stays_the_number(): void
    {
        $invoice = $this->anInvoice();

        $body = ltrim((string) $this->actingAs($this->owner)->get(route('sales.report.show', [
            'slug' => 'invoice-book', 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'export' => 'csv',
        ]))->assertOk()->getContent(), "\xEF\xBB\xBF");

        $line = null;

        foreach (preg_split('/\r\n|\n/', $body) ?: [] as $text) {
            if ($text !== '' && str_contains($text, (string) $invoice->document_no)) {
                $line = str_getcsv($text);
                break;
            }
        }

        $this->assertNotNull($line, '⛔ প্রস্তুতি — ফাইলে বিলের সারি নেই।');
        $this->assertContains($this->label, $line, '⛔ ফাইলের গ্রাহকের ঘরে পয়েন্ট আর শাখা নেই।');
        $this->assertContains((string) $invoice->document_no, $line, '⛔ ফাইলে নম্বরের ঘরে নম্বর ছাড়া আরও কিছু।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function anInvoice(): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => '500']],
        ));
    }

    private function aCollectionOf(SalesInvoice $invoice, string $amount): Collection
    {
        $collection = Collection::query()->create([
            'branch_id' => $invoice->branch_id,
            'document_no' => 'CPL-'.random_int(1000, 9999),
            'customer_id' => $invoice->customer_id,
            'account_id' => Account::query()->postable()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => DocumentStatus::CONFIRMED,
            'narration' => 'হাতে নগদ',
        ]);

        CollectionLine::query()->create([
            'collection_id' => $collection->id,
            'line_no' => 1,
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);

        return $collection;
    }
}
