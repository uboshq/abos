<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\HeldCounterSaleFinisher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * সব সই হয়ে গেল, অথচ বিক্রিটা একটা বোতামের অপেক্ষায় বসে রইল।
 *
 * ── ⛔ লাইভে, ২৮ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * INV-0005: চালানের সই আর জমার সই দুইটাই অনুমোদিত, অথচ বিল আর RCV-0005
 * খসড়া — কারণ কাউন্টারের আটকে থাকা বিক্রি শেষ হত কেবল কেউ বিলের পাতায়
 * "নিশ্চিত" চাপলে। ⭐ মালিকের সিদ্ধান্ত ১ (২৭ সেপ্টেম্বর): *"সব সহ শেষ হলে
 * নিজে থেকেই পোস্ট হবে"*।
 *
 * ⓘ দাবিগুলো: শেষ সইয়ে সব একসাথে খাতায়; একটা সই বাকি থাকলে কিছুই নয়;
 * সইকারী ≠ বানানেওয়ালা হলেও নগদের তালা আর শাখার সীমা ঠিক মানুষের নামে;
 * বাকির দেয়াল থামালে সই থাকে, বিল আটকে থাকে; বানানেওয়ালা নিষ্ক্রিয় হলে
 * আটকে থাকে; অডিটে সত্যি কথা; আর পুরনো আটকে থাকাগুলোর এককালীন কমান্ড।
 */
final class TheSaleWaitedForAButtonAfterItsLastSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $signer;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->maker = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->maker);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '0'])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⓘ সইকারী আলাদা মানুষ — কোনো নগদ বাক্স নেই। ⚠️ একই মানুষ হলে
         * "কার নামে চলল" প্রশ্নটা কিছুই মাপত না।
         */
        $this->signer = User::query()->create([
            'name' => 'Signing Manager',
            'email' => 'signer-'.uniqid().'@abos.test',
            'password' => Hash::make('secret-secret'),
            'is_active' => true,
        ]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);

        $this->flow();
    }

    public function test_the_last_signature_finishes_the_whole_sale(): void
    {
        $floor = $this->floor();
        $invoice = $this->hold([$this->bank('1000')]);

        $this->sign($this->approvalsOf($invoice));

        $invoice = $invoice->fresh();

        $this->assertSame('confirmed', $invoice->status, 'শেষ সইয়ের পরেও বিলটা খসড়া — বোতামের অপেক্ষায়।');
        $this->assertSame('confirmed', $this->challanOf($invoice)->status, 'চালান খসড়া থেকে গেছে।');
        $this->assertSame(bcsub($floor, '10', 4), $this->floor(), 'বিক্রি শেষ, অথচ গুদাম থেকে মাল কমেনি।');
        $this->assertSame([], $this->draftDeposits($invoice), 'জমাটা খাতায় ওঠেনি।');
        $this->assertSame('0.0000', $invoice->dueAmount());
    }

    public function test_nothing_moves_while_one_signature_is_still_due(): void
    {
        $floor = $this->floor();
        $invoice = $this->hold([$this->bank('600'), $this->bank('400')]);

        $approvals = $this->approvalsOf($invoice);
        $this->assertCount(2, $approvals, 'দৃশ্যটাই বানানো যায়নি — দুইটা সই চাওয়ার কথা।');

        Log::spy();

        $this->sign([$approvals[0]]);

        $this->assertSame('draft', $invoice->fresh()->status, 'একটা সই বাকি, অথচ বিক্রি শেষ হয়ে গেছে।');

        /*
         * ⓘ সই বাকি থাকা স্বাভাবিক অবস্থা, ব্যর্থতা নয় — লগে কোনো সতর্কবার্তা নয়।
         * ⚠️ অপেক্ষা না দেখে সোজা শেষ করতে গেলে ভিতরের পাহারা থামাত ঠিকই, কিন্তু
         * প্রতিটা আংশিক সইয়ে লগে *"সই-হওয়া বিক্রি শেষ হয়নি"* উঠত — মিথ্যা শোরগোল।
         */
        Log::shouldNotHaveReceived('warning', ['A signed counter sale was not finished automatically', \Mockery::any()]);
        $this->assertSame($floor, $this->floor(), 'সই বাকি থাকতেই মাল বেরিয়েছে।');
        $this->assertCount(2, $this->draftDeposits($invoice), 'অর্ধেক জমা খাতায় উঠে গেছে।');

        $this->sign([$approvals[1]]);

        $this->assertSame('confirmed', $invoice->fresh()->status, 'দ্বিতীয় সইয়ের পরেও বিক্রি শেষ হয়নি।');
    }

    /**
     * ⭐ লাইভের পথ — জমার সই, তারপর চালানের নিজের সই (INV-0003, INV-0005, ২৮ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ চালানের ছক থাকলে জমার সইয়ে বিক্রি শেষ করতে গিয়ে চালান সই চায়
     * ([[DirectSaleService::finishHeld()]], 9973eed8)। ⚠️ সেটা অপেক্ষা, ব্যর্থতা নয় —
     * অনুরোধ বসে, লগে শোরগোল নয়; আর চালানের সই পড়লে বিক্রি নিজেই শেষ।
     */
    public function test_the_deposit_signature_then_the_challans_own_signature_finish_it(): void
    {
        $this->flow('sales', 'challan');
        $invoice = $this->hold([$this->bank('1000')]);

        Log::spy();
        $this->sign($this->approvalsOf($invoice));

        $this->assertSame('draft', $invoice->fresh()->status, 'চালানের সই ছাড়াই বিক্রি শেষ হয়ে গেছে।');
        Log::shouldNotHaveReceived('warning', ['A signed counter sale was not finished automatically', \Mockery::any()]);

        $challan = $this->challanOf($invoice);
        $pending = Approval::query()
            ->where('approvable_type', DeliveryChallan::class)
            ->where('approvable_id', $challan->id)
            ->where('status', Approval::PENDING)
            ->get();

        $this->assertCount(1, $pending, 'চালানের সইয়ের অনুরোধটা বসেনি — বিক্রিটা চিরকাল আটকে থাকত।');

        $this->sign($pending->all());

        $this->assertSame('confirmed', $invoice->fresh()->status, 'চালানের সইয়ের পরেও বিক্রি নিজে শেষ হয়নি।');
        $this->assertSame('confirmed', $this->challanOf($invoice)->status);
    }

    /**
     * ⭐ একই কাগজ, একই ছাপ — হাতে কোন সম্পর্ক তোলা আছে তাতে কিছু যায় আসে না।
     *
     * ⓘ [[DirectSaleService::finishHeld()]] চালান দেখে `lines`+`warehouse` নিয়ে,
     * [[DeliveryChallanService::confirm()]] `lines.product`+`lines.orderLine`+`warehouse`
     * নিয়ে। ⛔ ছাপ দুই রকম হলে দ্বিতীয় প্রশ্ন নতুন সই চাইত আর বিক্রি আটকে থাকত।
     * ⚠️ আর উল্টো দিকটাও: সারি সত্যিই বদলালে ছাপ বদলায় — নাহলে দাবিটা অন্ধ।
     */
    public function test_a_paper_has_one_fingerprint_whatever_happens_to_be_loaded(): void
    {
        $invoice = $this->hold([$this->bank('1000')]);
        $challan = $this->challanOf($invoice);
        $print = app(\App\Core\Engines\Approval\DocumentFingerprint::class);

        $bare = $print->of(DeliveryChallan::acrossBranches()->findOrFail($challan->id));
        $finishing = $print->of(DeliveryChallan::acrossBranches()->with(['lines', 'warehouse'])->findOrFail($challan->id));
        $confirming = $print->of(DeliveryChallan::acrossBranches()
            ->with(['lines.product', 'lines.orderLine', 'warehouse'])->findOrFail($challan->id));

        $this->assertSame($bare, $finishing, 'চালানের ছাপ বদলাল কেবল কোন সম্পর্ক তোলা ছিল তার জন্য।');
        $this->assertSame($finishing, $confirming, 'শেষ করা আর পাকা করা — দুই জায়গায় একই চালানের দুই ছাপ।');

        $voucher = $invoice->heldCounterDeposits()->withoutGlobalScope('user-branch')->firstOrFail();

        $this->assertSame(
            $print->of(Voucher::acrossBranches()->findOrFail($voucher->id)),
            $print->of(Voucher::acrossBranches()->with('lines.account')->findOrFail($voucher->id)),
            'ভাউচারের ছাপ বদলাল কেবল কোন সম্পর্ক তোলা ছিল তার জন্য।',
        );

        // ⚠️ সারি সত্যিই বদলালে ছাপও বদলায়
        $line = $challan->lines()->firstOrFail();
        $line->forceFill(['delivered_qty' => bcadd((string) $line->delivered_qty, '1', 4)])->save();

        $afterLine = $print->of(DeliveryChallan::acrossBranches()->findOrFail($challan->id));

        $this->assertNotSame($bare, $afterLine,
            'চালানের সারির পরিমাণ বদলাল, অথচ ছাপ একই — সইয়ের পর মাল বাড়ানো ধরা পড়ত না।');

        // ⚠️ ফ্রি মালও কাগজের অংশ — সইয়ের পর উপহার বাড়ালে সইটা আর খাটে না
        \App\Modules\Sales\Models\DeliveryChallanGiftLine::query()->create([
            'delivery_challan_id' => $challan->id,
            'product_id' => $this->product->id,
            'qty' => '1',
            'line_no' => 1,
        ]);

        $this->assertNotSame($afterLine, $print->of(DeliveryChallan::acrossBranches()->findOrFail($challan->id)),
            'সইয়ের পর উপহারের সারি বসল, অথচ ছাপ একই — ফ্রি মাল বাড়ানো ধরা পড়ত না।');

        /*
         * ⓘ যে কাগজ কিছু ঘোষণা করে না (বিল), তার সারিও ছাপে — `lines()` আছে বলে।
         * ⛔ নাহলে ঘোষণা-না-করা প্রতিটা কাগজে সইয়ের পর সারি বদলানো নীরবে চলত।
         */
        $bill = $print->of(SalesInvoice::acrossBranches()->findOrFail($invoice->id));
        $invoiceLine = $invoice->lines()->firstOrFail();
        $invoiceLine->forceFill(['rate' => bcadd((string) $invoiceLine->rate, '1', 4)])->save();

        $this->assertNotSame($bill, $print->of(SalesInvoice::acrossBranches()->findOrFail($invoice->id)),
            'বিলের সারির দর বদলাল, অথচ ছাপ একই — ঘোষণা-না-করা কাগজের সারি ছাপের বাইরে।');
    }

    /**
     * ⭐ ফাঁদ ক — নগদের তালা। মিশ্র জমা: নগদ বানানেওয়ালার নিজের বাক্সে (সই চায়
     * না), ব্যাংকেরটা সই চায়। ⛔ সইকারীর নামে চললে নগদটা "আপনার বাক্স নয়"।
     */
    public function test_cash_in_the_makers_till_posts_although_the_signer_holds_no_till(): void
    {
        /*
         * ⚠️ বাক্সটা বানানেওয়ালার হেফাজতে — নইলে তালাটা খোলাই থাকে: কারও
         * হেফাজতে কোনো বাক্স না থাকলে [[CashTill::mayUse()]] সবাইকে ঢুকতে দেয়,
         * আর দাবিটা কিছুই মাপত না (প্রথম খসড়ায় ঠিক তাই হয়েছিল — মিউট্যান্ট
         * "সইকারীর নামে চালাও" বেঁচে গিয়েছিল)।
         */
        app(CashTillService::class)->ensurePrimaryTill()->forceFill(['holder_id' => $this->maker->id])->save();

        $invoice = $this->hold([$this->cash('300'), $this->bank('700')]);

        $this->sign($this->approvalsOf($invoice));

        $this->assertSame('confirmed', $invoice->fresh()->status,
            'মিশ্র জমার বিক্রি সইয়ের পরে শেষ হয়নি — নগদের তালা সইকারীর বাক্স দেখেছে।');
        $this->assertSame([], $this->draftDeposits($invoice));
    }

    /**
     * ⭐ ফাঁদ গ — শাখার সীমা। সইকারী কেবল অন্য একটা শাখার; ⛔ তাঁর নামে চললে
     * চালানটাই "নেই" হত।
     */
    public function test_a_signer_from_another_branch_still_finishes_the_sale(): void
    {
        $other = Branch::query()->create([
            'company_id' => $this->company->id,
            'code' => 'FAR',
            'name_en' => 'Far Branch',
            'is_active' => true,
            'is_default' => false,
        ]);

        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->signer->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $other->id,
        ]);

        $invoice = $this->hold([$this->bank('1000')]);

        $this->sign($this->approvalsOf($invoice));

        $this->assertSame('confirmed', $invoice->fresh()->status,
            'অন্য শাখার সইকারীর সইয়ে বিক্রি শেষ হয়নি — কাজটা তাঁর সীমায় চলেছে।');
    }

    /**
     * ⭐ ফাঁদ খ — শেষ করা থামলে সই মুছে যায় না। বাকির দেয়াল: সীমা ১০০ টাকা,
     * জমা ৫০০, বিল ১০০০।
     */
    public function test_a_refused_finish_keeps_the_signature_and_leaves_the_sale_held(): void
    {
        $invoice = $this->hold([$this->bank('500')]);

        $this->customer->forceFill(['credit_limit' => '100'])->save();

        $approvals = $this->approvalsOf($invoice);
        $this->sign($approvals);

        $this->assertSame(Approval::APPROVED, $approvals[0]->fresh()->status,
            'বিক্রি শেষ করা থামল, আর সঙ্গে সইটাও ফেরত গেল।');
        $this->assertSame('draft', $invoice->fresh()->status, 'বাকির দেয়াল পেরিয়ে বিক্রি শেষ হয়ে গেছে।');
    }

    public function test_an_inactive_maker_leaves_the_sale_held(): void
    {
        $invoice = $this->hold([$this->bank('1000')]);

        $this->maker->forceFill(['is_active' => false])->save();

        $this->sign($this->approvalsOf($invoice));

        $this->assertSame('draft', $invoice->fresh()->status,
            'নিষ্ক্রিয় মানুষের নামে বিক্রি শেষ হয়েছে — তাঁর বাক্স এখন কারও হেফাজতে নেই।');
    }

    public function test_the_audit_says_it_was_automatic_and_who_signed(): void
    {
        $invoice = $this->hold([$this->bank('1000')]);

        $this->sign($this->approvalsOf($invoice));

        $row = AuditTrail::query()
            ->where('action', HeldCounterSaleFinisher::AUDIT_ACTION)
            ->where('auditable_id', $invoice->id)
            ->first();

        $this->assertNotNull($row, 'অডিটে কোথাও লেখা নেই যে বিক্রিটা নিজে শেষ হয়েছে।');
        $this->assertSame($this->signer->id, (int) $row->user_id, 'অডিটের সারি সইকারীর নামে নয়।');
        $this->assertStringContainsString($this->signer->name, (string) $row->reason);
        $this->assertStringContainsString($this->maker->name, (string) $row->reason);
    }

    public function test_the_signer_is_logged_in_again_after_the_sale_finishes(): void
    {
        $invoice = $this->hold([$this->bank('1000')]);

        $this->sign($this->approvalsOf($invoice));

        $this->assertSame($this->signer->id, auth()->id(), 'বিক্রি শেষ করার পরে লগইন বানানেওয়ালার নামেই রয়ে গেছে।');
    }

    /**
     * ⭐ পুরনো আটকে থাকা — সই এই কাজের আগে পড়েছিল (ঘটনা ছোড়া হয়নি)।
     * `--dry-run` কিছুই লেখে না; আসল চালানো শেষ করে।
     */
    public function test_the_one_off_command_finishes_old_signed_sales_and_the_dry_run_writes_nothing(): void
    {
        $invoice = $this->hold([$this->bank('1000')]);

        Event::fake([ApprovalDecided::class]);
        $this->sign($this->approvalsOf($invoice));
        $this->assertSame('draft', $invoice->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা আটকে থাকার কথা।');

        Artisan::call('abos:finish-signed-counter-sales', ['--dry-run' => true, '--company' => 'TDEPOT']);
        $out = Artisan::output();

        $this->assertStringContainsString($invoice->document_no, $out);
        $this->assertStringContainsString('WOULD FINISH', $out);
        $this->assertSame('draft', $invoice->fresh()->status, '--dry-run বিক্রিটা শেষ করে ফেলেছে।');

        Artisan::call('abos:finish-signed-counter-sales', ['--company' => 'TDEPOT']);

        $this->assertSame('confirmed', $invoice->fresh()->status, 'কমান্ড পুরনো সই-হওয়া বিক্রিটা শেষ করেনি।');
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    /** @param  list<array<string, mixed>>  $deposits */
    private function hold(array $deposits): SalesInvoice
    {
        $this->actingAs($this->maker);

        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', // ⓘ ধাপ ৫ — নিশ্চিতে পরিবহন লাগে ([[TransportRule]]); এই দাবি অন্য কিছু মাপে
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposits' => $deposits,

            /*
             * ⓘ মাল কীভাবে যাবে — একটা গাড়ির নম্বর ([[TransportRule]])। এই দাবিগুলো
             * পরিবহন মাপে না; ⚠️ নম্বরটা চালানের পুরনো ঘরে বসে, তাই খসড়া থেকে পাকা
             * হওয়ার পথে হারায় না।
             */
            'vehicle_no' => 'DHA-GA-11-2233',

            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame('draft', $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা সইয়ের অপেক্ষায় থাকার কথা।');

        return $invoice;
    }

    /** @param  list<Approval>  $approvals */
    private function sign(array $approvals): void
    {
        $this->actingAs($this->signer);

        foreach ($approvals as $approval) {
            app(ApprovalEngine::class)->approve($approval->fresh(), $this->signer);
        }
    }

    /** @return list<Approval> */
    private function approvalsOf(SalesInvoice $invoice): array
    {
        $ids = Voucher::acrossBranches()
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->id)
            ->pluck('id');

        return Approval::query()
            ->where('approvable_type', Voucher::class)
            ->whereIn('approvable_id', $ids)
            ->where('status', Approval::PENDING)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return list<Voucher> */
    private function draftDeposits(SalesInvoice $invoice): array
    {
        return $invoice->heldCounterDeposits()->withoutGlobalScope('user-branch')->get()->all();
    }

    private function challanOf(SalesInvoice $invoice): DeliveryChallan
    {
        $id = $invoice->lines()->with('challanLine')->first()?->challanLine?->delivery_challan_id;

        return DeliveryChallan::acrossBranches()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function bank(string $amount): array
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-AUTO',
                'name_en' => 'BANK-AUTO',
                'name_bn' => 'BANK-AUTO',
                'money_kind' => Account::BANK,
            ])->save();
        }

        return ['amount' => $amount, 'account_id' => $bank->id, 'reference' => 'TRX-'.uniqid()];
    }

    /** ⓘ নগদ — বানানেওয়ালার প্রধান টিলে (নিজের বাক্সের নগদ সই চায় না) */
    private function cash(string $amount): array
    {
        return ['amount' => $amount, 'account_id' => app(CashTillService::class)->ensurePrimaryTill()->account_id];
    }

    private function flow(string $module = VoucherApproval::MODULE, string $action = VoucherApproval::COUNTER_DEPOSIT): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id,
            'module' => $module,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $this->signer->id,
        ]);
    }

    private function floor(): string
    {
        return app(StockService::class)->floorQty($this->product, $this->warehouse);
    }
}
