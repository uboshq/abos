<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * এক বিক্রি, এক পাতা, এক নিশ্চিতকরণ — ২৮ সেপ্টেম্বর ২০২৬ ([[ApprovalBundles]] · [[CounterSaleBundle]])।
 *
 * ── ⛔ মালিকের কথা ───────────────────────────────────────────────────────
 * *"অ্যাপ্রভাল করার সময় পরিবহন দেখাচ্ছে না, পরিবহনের ভাড়াও না, জমা টাকার
 * হিসাবও না … এক নজরে সব দেখে এক ক্লিকে সব অনুমোদন … এটা কনফার্মেশন"*।
 *
 * ── ⓘ কী মাপা হয় ─────────────────────────────────────────────────────────
 *   ১. সইকারী বিক্রির যেকোনো একটা অনুরোধ খুললে পুরো বিক্রি দেখেন — চালান ও বিলের
 *      নম্বর, প্রতিটা পণ্য, পরিবহন, ভাড়া, জমার লেনদেন নম্বর ও স্লিপ, বাকি — আর
 *      বোতাম একটাই, "নিশ্চিত", যেটা সব অনুরোধে যায়।
 *   ২. যিনি সবগুলোয় সই দিতে পারেন, এক চাপে সব সই হয় — প্রতিটার নিজের সিদ্ধান্ত-সারি —
 *      আর শেষ সইয়ে বিক্রি নিজেই শেষ।
 *   ৩. **একই মানুষ দুইবার** — প্রথমে কোনো ছকে নেই: কিছুই সই হয় না; তারপর ছকে
 *      বসানো হলে একই চাপে সব।
 *   ৪. ⛔ বিপজ্জনক ইনপুট — চালান আর বিল তাঁর, জমাটা অন্যের: কিছুই সই হয় না
 *      (যেগুলো তাঁর সেগুলোও না), আর বার্তা জমার কাগজের নম্বর বলে।
 *   ৫. সাধারণ অনুরোধ (বিক্রির নয়) আগের মতোই — সাধারণ বোতাম, আর এই দরজায় ৪০৪।
 *
 * ── ⚠️ দৃশ্যটা কীভাবে বানানো ─────────────────────────────────────────────
 * বিক্রিটা কাউন্টারের আসল দরজা দিয়ে (`sales.direct.store`) — জমার ছক থাকায় পুরোটা
 * খসড়া হয়ে সইয়ে আটকায় ([[DirectSaleService::hold()]])। চালানের অনুরোধ বসে ঠিক
 * যে ডাকে পণ্য-কোড বসায় ([[DocumentApproval::assertClear()]])। ⓘ বিলের অনুরোধ
 * ইঞ্জিন দিয়ে সরাসরি — কাউন্টারের পথ আজ বিলের উপর কোনো অনুরোধ দাঁড় করিয়ে রাখে না,
 * অথচ দলটা [[HeldCounterSaleFinisher::latestApprovals()]] বিলের অনুরোধও গোনে; তিন
 * ধরনের কাগজই দলে থাকলে "সব" কথাটা সত্যিই মাপা হয়।
 *
 * ⚠️ প্রত্যাশিত লেখা __() / Money::format() / মডেলের মান থেকে, **অনুরোধের পরে** —
 * মিডলওয়্যার ব্যবহারকারীর ভাষা বসায়।
 */
final class OneSaleOnePageOneConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $signer;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    /** @var list<Product> */
    private array $products;

    /** @var list<string> */
    private array $temp = [];

    private const CARRIER = 'Karnaphuli Movers';

    private const VEHICLE = 'DHA-GA-11-2233';

    private const DRIVER_PHONE = '01711223344';

    private const FREIGHT = '350';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->maker = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->maker);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        // ⛔ ১ অক্টোবর ২০২৬ থেকে শূন্য সীমা মানে বাকি নেই (মালিকের চূড়ান্ত কথা) — তাই এই পরীক্ষার গ্রাহকের সত্যিকারের বড় সীমা
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->products = Product::query()->orderBy('id')->limit(2)->get()->all();

        $this->assertCount(2, $this->products, 'ডেমো ডেটায় দুইটা পণ্য নেই — "প্রতিটা পণ্য" দাবি কিছু মাপত না।');

        $this->signer = $this->decider('Bundle Signer');
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ১. পুরো বিক্রি এক পাতায় ─────────────────────────────────────────────

    public function test_any_one_paper_of_the_sale_opens_the_whole_sale_with_one_confirm_button(): void
    {
        $this->flows(depositSigner: $this->signer, paperSigner: $this->signer);
        $sale = $this->heldSale();

        $slip = $this->attachSlip($sale['voucher']);

        foreach ($sale['approvals'] as $approval) {
            $html = $this->page($this->signer, $approval);

            $this->assertStringContainsString('data-sale-bundle', $html,
                "অনুরোধ #{$approval->id} ({$approval->approvable_type}) খুললে বিক্রির পাতা আসেনি।");

            $this->assertConfirmForm($html, $approval);
        }

        // ⓘ পুরো বিষয়বস্তু — জমার অনুরোধ খুলে (চালান বা বিল নয়), যাতে "অন্য কাগজের" তথ্য সত্যিই দলের
        $html = $this->page($this->signer, $this->approvalOf($sale['approvals'], Voucher::class));
        $bundle = $this->bundleOf($html);

        $invoice = $sale['invoice']->fresh();
        $challan = $sale['challan']->fresh();
        $voucher = $sale['voucher']->fresh();

        $this->assertStringContainsString(e(__('approval::field.bundle_title')), $html, 'পাতার শিরোনাম "নিশ্চিতকরণ" নয়।');

        $this->assertFact($bundle, __('approval::field.bill_no'), (string) $invoice->document_no);
        $this->assertFact($bundle, __('approval::field.challan_no'), (string) $challan->document_no);
        $this->assertFact($bundle, __('approval::field.transport'), self::CARRIER.' · '.self::VEHICLE);
        $this->assertFact($bundle, __('sales::field.transport_cost'), Money::format(self::FREIGHT));

        // ⓘ কাউন্টারের চালকের ফোন — 63ae82e2 থেকে চালানে লেখা হয় (আগে নীরবে হারাত)
        $this->assertFact($bundle, __('approval::field.driver_phone'), self::DRIVER_PHONE);

        foreach ($this->products as $product) {
            $label = $product->code.' - '.$product->fresh()->name();

            $this->assertStringContainsString(e($label), $bundle, "পণ্য `{$product->code}` বিক্রির পাতায় নেই।");
        }

        $this->assertNotSame('', (string) $voucher->instrument_no, 'প্রস্তুতিটাই ভুল — জমার লেনদেন নম্বর বসেনি।');
        $this->assertSame($sale['reference'], (string) $voucher->instrument_no, 'প্রস্তুতিটাই ভুল — পাঠানো নম্বরটা ভাউচারে নেই।');
        $this->assertStringContainsString(e($voucher->instrument_no), $bundle, 'জমার লেনদেন নম্বর বিক্রির পাতায় নেই।');

        $href = preg_quote(e(route('attachment.download', $slip)), '~');
        $this->assertMatchesRegularExpression('~<a\b[^>]*href="'.$href.'"~', $bundle, 'জমার স্লিপের লিংক বিক্রির পাতায় নেই।');

        $due = bcsub((string) $invoice->total, (string) $voucher->fresh()->totals()['debit'], 4);
        $this->assertSame(1, bccomp($due, '0', 4), 'প্রস্তুতিটাই ভুল — বাকি শূন্য, দাবিটা কিছু মাপত না।');
        $this->assertFact($bundle, __('approval::field.due_left'), Money::format($due));
    }

    // ── ২. এক চাপে সব, আর বিক্রি নিজেই শেষ ────────────────────────────────

    /*
     * ⚠️ ২৮ সেপ্টেম্বর ২০২৬, প্রথম লেখায় লাল — পণ্যের দোষ, দাবির নয়: চালানের ছক থাকলে
     * [[DeliveryChallanService::confirm()]] চালানে `lines.product`, `lines.orderLine` তুলে
     * তারপর ছাপ নেয়, আর [[DirectSaleService::finishHeld()]] নেয় কেবল `lines`, `warehouse`
     * দিয়ে — ছাপ মেলে না, লেনদেনের ভিতরে নতুন অনুরোধ, ফেরত-গড়ানো, আর বিক্রি নীরবে
     * "আরও সই বাকি"। ⓘ পাশের [[TheSaleWaitedForAButtonAfterItsLastSignatureTest]]-এর
     * চালানের দাবিও একই কারণে লাল। ⛔ দাবিটা ঢিলা করা হয়নি।
     */

    public function test_one_press_signs_every_paper_and_the_sale_finishes_itself(): void
    {
        $this->flows(depositSigner: $this->signer, paperSigner: $this->signer);
        $sale = $this->heldSale();

        $this->confirm($this->signer, $sale['approvals'][0])
            ->assertRedirect(route('approval.inbox.index'));

        $this->assertSame([], $this->errorsOn('confirm'), 'সব সই দেওয়ার অধিকার থাকলেও ভুল ফিরেছে।');

        $this->assertAllSignedBy($sale['approvals'], $this->signer);

        $this->assertFinished($sale);
    }

    // ── ৩. একই মানুষ, দুইবার ───────────────────────────────────────────────

    public function test_the_same_person_signs_nothing_until_the_flows_name_them_then_everything(): void
    {
        $this->flows(depositSigner: $this->signer, paperSigner: $this->signer);
        $sale = $this->heldSale();

        $person = $this->decider('Not Yet A Signer');

        $this->confirm($person, $sale['approvals'][0]);

        $this->assertNotSame([], $this->errorsOn('confirm'), '⛔ ছকে না থাকা মানুষের চাপে কোনো ভুল ফেরেনি।');
        $this->assertNothingSigned($sale['approvals']);
        $this->assertSame('draft', $sale['invoice']->fresh()->status);

        // ⭐ চাবি: একই মানুষ এবার তিন ছকের প্রতিটায় সইকারী
        foreach (ApprovalFlow::query()->whereIn('id', $this->flowIds)->get() as $flow) {
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $person->id,
            ]);
        }

        $this->freshEngine();

        $this->confirm($person, $sale['approvals'][0])
            ->assertRedirect(route('approval.inbox.index'));

        $this->assertSame([], $this->errorsOn('confirm'), 'ছকে বসানোর পরও চাপ ফেরানো হয়েছে।');
        $this->assertAllSignedBy($sale['approvals'], $person);
        $this->assertFinished($sale);
    }

    // ── ৪. ⛔ একটা কাগজ অন্যের ─────────────────────────────────────────────

    public function test_one_paper_that_is_someone_elses_stops_the_whole_confirmation_and_is_named(): void
    {
        $other = $this->decider('Deposit Desk');

        $this->flows(depositSigner: $other, paperSigner: $this->signer);
        $sale = $this->heldSale();

        $engine = app(ApprovalEngine::class);
        $voucherApproval = $this->approvalOf($sale['approvals'], Voucher::class);

        // ⓘ প্রস্তুতি: চালান আর বিল সত্যিই তাঁর, জমাটা সত্যিই নয় — নাহলে দাবিটা অন্য কিছু মাপত
        foreach ($sale['approvals'] as $approval) {
            $this->assertSame(
                $approval->id !== $voucherApproval->id,
                $engine->canDecide($approval->fresh(), $this->signer),
                "প্রস্তুতিটাই ভুল — অনুরোধ #{$approval->id}-এ সইয়ের অধিকার উল্টো।",
            );
        }

        // ⓘ তিনি যেটায় সই দিতে পারেন সেটা খুলেই চাপেন — পাতা যেখান থেকে পাঠায়
        $this->confirm($this->signer, $this->approvalOf($sale['approvals'], DeliveryChallan::class));

        $errors = $this->errorsOn('confirm');
        $this->assertCount(1, $errors, '⛔ অন্যের কাগজ দলে থাকলেও চাপটা কোনো ভুল ছাড়াই গেছে।');

        $voucherNo = (string) $sale['voucher']->fresh()->document_no;
        $this->assertNotSame('', $voucherNo, 'প্রস্তুতিটাই ভুল — জমার ভাউচারের নম্বর নেই।');
        $this->assertStringContainsString($voucherNo, $errors[0], 'বার্তা বলে না কোন কাগজটা তাঁর নয়।');
        $this->assertStringNotContainsString((string) $sale['challan']->fresh()->document_no, $errors[0],
            'বার্তা তাঁর নিজের কাগজকেও "আপনার নয়" বলছে।');

        $this->assertNothingSigned($sale['approvals']);
        $this->assertSame('draft', $sale['invoice']->fresh()->status, '⛔ আধা-সইয়ে বিক্রিটা শেষ হয়ে গেছে।');
    }

    // ── ৫. সাধারণ অনুরোধ আগের মতো ─────────────────────────────────────────

    public function test_a_plain_request_keeps_the_plain_button_and_the_confirm_door_is_not_found(): void
    {
        $approval = $this->plainHeldReceipt($this->signer);

        $html = $this->page($this->signer, $approval);

        $this->assertStringNotContainsString('data-sale-bundle', $html, 'বিক্রির নয় এমন অনুরোধে বিক্রির পাতা দেখানো হচ্ছে।');
        $this->assertStringNotContainsString(e(route('approval.inbox.confirm_all', $approval->id)), $html,
            'সাধারণ অনুরোধে "সব নিশ্চিত"-এর দরজা দেখানো হচ্ছে।');

        $approve = preg_quote(e(route('approval.inbox.approve', $approval->id)), '~');
        $this->assertMatchesRegularExpression('~<form\b[^>]*action="'.$approve.'"~', $html, 'সাধারণ সইয়ের ফর্মটা নেই।');
        $this->assertSame(__('approval::action.approve'), $this->submitLabel($html, route('approval.inbox.approve', $approval->id)),
            'সাধারণ অনুরোধের বোতামের লেখা বদলে গেছে।');

        $this->confirm($this->signer, $approval)->assertNotFound();

        $this->assertSame(Approval::PENDING, $approval->fresh()->status, '৪০৪-এর পরেও অনুরোধটা নড়েছে।');
        $this->assertSame(0, ApprovalDecision::query()->where('approval_id', $approval->id)->count());
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    /** @var list<int> */
    private array $flowIds = [];

    /**
     * তিন ছক — কাউন্টারের জমা, চালান, আর বিল (বিলের ধরন ধরে, যাতে ডেমোর মালিক-ছক না মেশে)।
     */
    private function flows(User $depositSigner, User $paperSigner): void
    {
        $this->flowIds = [
            $this->flow(VoucherApproval::MODULE, VoucherApproval::COUNTER_DEPOSIT, '', $depositSigner),
            $this->flow('sales', 'challan', '', $paperSigner),
            $this->flow('sales', 'discount', class_basename(SalesInvoice::class), $paperSigner),
        ];

        $this->freshEngine();
    }

    private function flow(string $module, string $action, string $documentType, User $signer): int
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'module' => $module,
            'action' => $action,
            'document_type' => $documentType,
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $signer->id,
        ]);

        return (int) $flow->id;
    }

    /**
     * কাউন্টারের আসল দরজায় একটা বিক্রি — ব্যাংকের জমা সইয়ে আটকায়, তারপর চালান আর বিলের অনুরোধ।
     *
     * @return array{invoice: SalesInvoice, challan: DeliveryChallan, voucher: Voucher, reference: string, approvals: list<Approval>}
     */
    private function heldSale(): array
    {
        $this->actingAs($this->maker);

        $deposit = $this->bank('600');

        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposits' => [$deposit],
            'carrier_name' => self::CARRIER,
            'vehicle_no' => self::VEHICLE,
            'driver_name' => 'Rafiq Driver',
            'driver_phone' => self::DRIVER_PHONE,
            'transport_cost' => self::FREIGHT,
            'lines' => array_map(
                fn (Product $p) => ['product_id' => $p->id, 'qty' => '5', 'rate' => '100'],
                $this->products,
            ),
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা সইয়ের অপেক্ষায় থাকার কথা।');

        $challanId = $invoice->lines()->with('challanLine')->first()?->challanLine?->delivery_challan_id;

        /*
         * ⚠️ সারিসহ, ঠিক যেভাবে [[DirectSaleService::finishHeld()]] তোলে (`lines`, `warehouse`)।
         * ⓘ চালানের ছাপ ([[DocumentFingerprint]]) হাতের কপিতে তোলা সম্পর্ক ধরে — অন্যভাবে
         * তুললে শেষ সইয়ের পরে finishHeld ছাপ মেলাত না, নতুন অনুরোধ বসাত, আর বিক্রি
         * "আরও সই বাকি"-তে আটকে থাকত (প্রথম চালানোয় ঠিক তাই হয়েছিল)।
         */
        $challan = DeliveryChallan::acrossBranches()->with(['lines', 'warehouse'])->findOrFail($challanId);

        $voucher = Voucher::acrossBranches()
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->id)
            ->sole();

        $this->freshEngine();

        // ⓘ চালানের অনুরোধ — পণ্য-কোড যে ডাকে বসায়, ঠিক সেটাই
        try {
            app(DocumentApproval::class)->assertClear(
                document: $challan,
                module: 'sales',
                action: 'challan',
                field: 'status',
                amount: (string) $challan->total,
                reason: $challan->narration,
            );
        } catch (HeldForApproval) {
            // ⓘ প্রত্যাশিত — অনুরোধ বসেছে
        }

        app(ApprovalEngine::class)->request(
            document: $invoice,
            module: 'sales',
            action: 'discount',
            amount: (string) $invoice->total,
            userId: $this->maker->id,
        );

        $approvals = Approval::query()
            ->where('status', Approval::PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('approvable_type', Voucher::class)->where('approvable_id', $voucher->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', DeliveryChallan::class)->where('approvable_id', $challan->id))
                ->orWhere(fn ($w) => $w->where('approvable_type', SalesInvoice::class)->where('approvable_id', $invoice->id)))
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount(3, $approvals, 'দৃশ্যটাই বানানো যায়নি — জমা, চালান আর বিল, তিনটা অনুরোধ অপেক্ষায় থাকার কথা।');

        return [
            'invoice' => $invoice,
            'challan' => $challan,
            'voucher' => $voucher,
            'reference' => (string) $deposit['reference'],
            'approvals' => $approvals,
        ];
    }

    /** @param  list<Approval>  $approvals */
    private function approvalOf(array $approvals, string $type): Approval
    {
        foreach ($approvals as $approval) {
            if ($approval->approvable_type === $type) {
                return $approval;
            }
        }

        $this->fail("দলে {$type}-এর অনুরোধ নেই।");
    }

    /** @param  array{invoice: SalesInvoice, challan: DeliveryChallan, voucher: Voucher}  $sale */
    private function assertFinished(array $sale): void
    {
        $invoice = $sale['invoice']->fresh();

        $this->assertSame('confirmed', $invoice->status, 'সব সইয়ের পরেও বিলটা খসড়া — বিক্রি নিজে শেষ হয়নি।');
        $this->assertSame('confirmed', $sale['challan']->fresh()->status, 'চালান খসড়া থেকে গেছে।');
        $this->assertSame([], $invoice->heldCounterDeposits()->withoutGlobalScope('user-branch')->get()->all(),
            'জমাটা খাতায় ওঠেনি।');
    }

    /** @param  list<Approval>  $approvals */
    private function assertAllSignedBy(array $approvals, User $user): void
    {
        foreach ($approvals as $approval) {
            $this->assertSame(Approval::APPROVED, $approval->fresh()->status,
                "অনুরোধ #{$approval->id} ({$approval->approvable_type}) সই হয়নি।");

            $decisions = ApprovalDecision::query()->where('approval_id', $approval->id)->get();

            $this->assertCount(1, $decisions, "অনুরোধ #{$approval->id}-এ ঠিক একটা সিদ্ধান্ত-সারি নেই।");
            $this->assertSame((int) $user->id, (int) $decisions[0]->user_id, "অনুরোধ #{$approval->id}-এর সিদ্ধান্ত অন্যের নামে।");
            $this->assertSame(ApprovalDecision::APPROVED, $decisions[0]->decision);
        }
    }

    /** @param  list<Approval>  $approvals */
    private function assertNothingSigned(array $approvals): void
    {
        foreach ($approvals as $approval) {
            $this->assertSame(Approval::PENDING, $approval->fresh()->status,
                "⛔ অনুরোধ #{$approval->id} ({$approval->approvable_type}) সই হয়ে গেছে — সব-বা-কিছুই-না ভেঙেছে।");
        }

        $this->assertSame(0, ApprovalDecision::query()
            ->whereIn('approval_id', array_map(fn (Approval $a) => $a->id, $approvals))
            ->count(), '⛔ কোনো একটা অনুরোধে সিদ্ধান্ত-সারি লেখা হয়েছে।');
    }

    private function assertConfirmForm(string $html, Approval $approval): void
    {
        $action = route('approval.inbox.confirm_all', $approval->id);

        $this->assertMatchesRegularExpression('~<form\b[^>]*action="'.preg_quote(e($action), '~').'"~', $html,
            "অনুরোধ #{$approval->id}-এর পাতায় \"সব নিশ্চিত\"-এর ফর্ম নেই।");

        $this->assertSame(__('approval::action.confirm_all'), $this->submitLabel($html, $action),
            'নিশ্চিতের বোতামের লেখা "নিশ্চিত" নয়।');

        $this->assertStringNotContainsString(e(route('approval.inbox.approve', $approval->id)), $html,
            '⛔ বিক্রির পাতায় একক সইয়ের ফর্মটাও রয়ে গেছে।');
    }

    /** ⓘ ফর্মের ভিতরের জমা-বোতামের লেখা — কেবল "লেখাটা কোথাও আছে" নয়। */
    private function submitLabel(string $html, string $action): string
    {
        $form = '~<form\b[^>]*action="'.preg_quote(e($action), '~').'"[^>]*>(.*?)</form>~s';

        $this->assertMatchesRegularExpression($form, $html);
        preg_match($form, $html, $m);

        preg_match_all('~<button\b[^>]*type="submit"[^>]*>(.*?)</button>~s', $m[1], $buttons);

        $this->assertCount(1, $buttons[1], 'ফর্মে ঠিক একটা জমা-বোতাম নেই।');

        return html_entity_decode(trim(strip_tags($buttons[1][0])), ENT_QUOTES);
    }

    private function page(User $user, Approval $approval): string
    {
        return $this->actingAs($user)
            ->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->getContent();
    }

    private function confirm(User $user, Approval $approval): \Illuminate\Testing\TestResponse
    {
        $this->freshEngine();

        return $this->actingAs($user)
            ->from(route('approval.inbox.show', $approval->id))
            ->post(route('approval.inbox.confirm_all', $approval->id));
    }

    /** ⓘ বিক্রির অংশটুকু — মাথার অঙ্ক বা ইতিহাস যেন দাবিকে সবুজ না করে। */
    private function bundleOf(string $html): string
    {
        $at = strpos($html, 'data-sale-bundle');

        $this->assertNotFalse($at, 'বিক্রির পাতাটাই আঁকা হয়নি (data-sale-bundle নেই)।');

        return substr($html, $at);
    }

    /** ঘরের লেবেল আর তার ঠিক পরের মান। */
    private function assertFact(string $html, string $label, string $value): void
    {
        $pattern = '~<dt[^>]*>\s*'.preg_quote(e($label), '~').'\s*</dt>\s*<dd[^>]*>\s*'
            .preg_quote(e($value), '~').'\s*</dd>~u';

        $this->assertMatchesRegularExpression($pattern, $html, "ঘর \"{$label}\"-এর পাশে \"{$value}\" নেই।");
    }

    /**
     * ⓘ এই অ্যাপের session JSON-এ, তাই ভুলের থলে কখনো ViewErrorBag, কখনো তার সাজানো রূপ।
     *
     * @return list<string>
     */
    private function errorsOn(string $key): array
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return array_values($errors->getBag('default')->get($key));
        }

        return is_array($errors) ? array_values($errors['default']['messages'][$key] ?? []) : [];
    }

    private function decider(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id,
        ]);
        $user->companies()->syncWithoutDetaching([$this->company->id]);

        // ⓘ দরজার চাবি — ছকে থাকা আর চাবি থাকা আলাদা; এই দাবিগুলো ছক মাপে
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'approval.decide', 'guard_name' => 'web']));

        return $user;
    }

    /**
     * ⓘ জমার স্লিপ — ভাউচারের পাতার ফর্ম যা পাঠায় তাই (`source_type` হাতে লেখা নয়)।
     */
    private function attachSlip(Voucher $voucher): Attachment
    {
        $body = $this->actingAs($this->maker)
            ->get(route('accounts.voucher.show', $voucher))
            ->assertOk()
            ->getContent();

        $action = preg_quote(e(route('attachment.store')), '/');
        $this->assertMatchesRegularExpression('/<form\b[^>]*action="'.$action.'"[^>]*>(.*?)<\/form>/s', $body,
            'জমার ভাউচারের পাতায় কাগজ তোলার ফর্ম নেই।');
        preg_match('/<form\b[^>]*action="'.$action.'"[^>]*>(.*?)<\/form>/s', $body, $form);
        preg_match('/name="source_type" value="([^"]*)"/', $form[1], $type);
        preg_match('/name="kind" value="([^"]*)"/', $form[1], $kind);

        $this->assertSame('slip', $kind[1] ?? null, 'জমার ভাউচারের পাতা স্লিপের দরজা দেয় না (kind=slip নেই)।');

        $this->actingAs($this->maker)
            ->from(route('accounts.voucher.show', $voucher))
            ->post(route('attachment.store'), [
                'source_type' => html_entity_decode($type[1] ?? ''),
                'source_id' => $voucher->id,
                'kind' => 'slip',
                'file' => $this->png('bank-slip.png'),
            ])
            ->assertSessionHasNoErrors();

        return Attachment::query()
            ->where('source_entity_id', $voucher->id)
            ->where('original_name', 'bank-slip.png')
            ->latest('id')
            ->firstOrFail();
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));

        $path = tempnam(sys_get_temp_dir(), 'slip');
        $this->temp[] = $path;
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** @return array<string, mixed> */
    private function bank(string $amount): array
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-BUNDLE',
                'name_en' => 'BANK-BUNDLE',
                'name_bn' => 'BANK-BUNDLE',
                'money_kind' => Account::BANK,
            ])->save();
        }

        return ['amount' => $amount, 'account_id' => $bank->id, 'reference' => 'TRX-'.strtoupper(uniqid())];
    }

    /**
     * বিক্রির নয় এমন অনুরোধ — হাতে লেখা ব্যাংকের রসিদ, "পোস্ট" চাপে ছকে আটকায়।
     */
    private function plainHeldReceipt(User $signer): Approval
    {
        $this->flow(VoucherApproval::MODULE, Voucher::RECEIPT, '', $signer);
        $this->freshEngine();

        $bank = Account::query()->find($this->bank('1')['account_id']);
        $other = Account::query()
            ->where('company_id', $this->company->id)
            ->postable()->active()
            ->whereKeyNot($bank->id)
            ->whereNull('money_kind')
            ->orderBy('code')
            ->firstOrFail();

        $this->actingAs($this->maker);

        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'PLAIN-NOT-A-SALE',
            'instrument' => 'transfer',
        ], [
            ['account_id' => $bank->id, 'debit' => '2500', 'credit' => '0'],
            ['account_id' => $other->id, 'debit' => '0', 'credit' => '2500'],
        ]);

        $this->actingAs($this->maker)
            ->from(route('accounts.voucher.show', $voucher))
            ->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'TRX-PLAIN-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status, 'প্রস্তুতিটাই ভুল — রসিদটা ছকে আটকায়নি।');

        return Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->firstOrFail();
    }

    /**
     * ⚠️ ইঞ্জিন ছকগুলো জমিয়ে রাখে, আর রুট তার কন্ট্রোলারকে (ইঞ্জিনসহ) — না ঝাড়লে
     * পরের অনুরোধ নতুন ধাপটা দেখত না, আর লালটা বাগের মতো দেখাত।
     */
    private function freshEngine(): void
    {
        $this->app->forgetInstance(ApprovalEngine::class);
        $this->app->forgetScopedInstances();

        foreach (['approval.inbox.show', 'approval.inbox.confirm_all'] as $name) {
            app('router')->getRoutes()->getByName($name)?->flushController();
        }
    }
}
