<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SlipIsNotUsedTwice;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\MoneyMethod;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\CashOnHand;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PaymentLine;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * সরবরাহকারীকে পরিশোধ — টাকা গেছে।
 *
 *     Dr  প্রদেয় হিসাব (2110, সরবরাহকারীর নামে)
 *     Cr  নগদ / ব্যাংক (যেখান থেকে টাকাটা গেল)
 *
 * ── কেন লাইনগুলো বিলের ────────────────────────────────────────────────
 * মাস শেষে এক চেকে সাতটা চালানের টাকা যায়। ভাগটা না রাখলে "কোন বিলটা
 * এখনো বাকি" প্রশ্নের উত্তর থাকত না — সরবরাহকারীর সাথে বসে মেলানোর সময়
 * দুই পক্ষই আন্দাজ করত, আর সেই আন্দাজেই সম্পর্ক নষ্ট হয়।
 *
 * ── আদায়ের আয়না, কিন্তু হুবহু নকল নয় ─────────────────────────────────
 * গঠনটা CollectionService-এর মতোই ইচ্ছাকৃতভাবে: দুইটা পর্দা একই রকম
 * আচরণ করলে ব্যবহারকারীকে দুইবার শিখতে হয় না। তফাত কেবল দিকটায় —
 * টাকা যাচ্ছে, আসছে না, তাই ডেবিট-ক্রেডিট উল্টো।
 */
final class PaymentService
{
    use ReadsTheRowUnderLock;

    /**
     * "চেক" — উপায়ের নাম, আর এটাই একমাত্র উপায় যেটা অন্য পথে যায়।
     *
     * ⓘ মানটা আসে `mdm_payment_methods.kind` থেকে, তাই ক্রেতা নতুন
     * উপায় বানালেও এই শব্দটাই চেকের পথ খোলে।
     */
    private const CHEQUE = 'cheque';

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
        private readonly CashTillService $tills,
        private readonly DocumentApproval $approvals,

        /*
         * ⓘ চেকের নিজের জীবন আছে — লেখা, ভাঙানো, ফেরত আসা। সেই তিনটা
         * ধাপ [[ChequeService]] আগে থেকেই জানে, তাই এখানে দ্বিতীয় একটা
         * বাস্তবায়ন লেখা হয়নি।
         */
        private readonly ChequeService $cheques,

        /*
         * ⓘ নিয়মটা কোরে, দুই সার্ভিসে দুইবার নয় — নাহলে একদিন একটায়
         * বাতিল কাগজ বাদ যেত, অন্যটায় নয়, আর একই স্লিপ এক দরজায়
         * আটকাত অন্য দরজায় ঢুকত।
         */
        private readonly SlipIsNotUsedTwice $slips,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Payment
    {
        return DB::transaction(function () use ($data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? now());
            $year = $this->resolveFinancialYear($trxDate);

            $amount = $this->money($data['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => __('purchase::validation.payment_must_be_positive'),
                ]);
            }

            /*
             * ⛔ একই স্লিপ দুইবার নয় — মালিকের নিয়ম, ৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ নম্বরটা নেওয়ার **আগে**, ইচ্ছাকৃতভাবে: আটকালে সিরিজের
             * একটা নম্বর খরচ হয়ে যেত আর কাগজে ফাঁক পড়ত।
             */
            $this->slips->check(
                table: 'pur_payments',
                slipNo: $data['instrument_no'] ?? null,
                partyId: (int) $data['supplier_id'],
                party: 'supplier_id',
                message: __('purchase::validation.slip_used_twice', [
                    'no' => trim((string) ($data['instrument_no'] ?? '')),
                ]),
            );

            /*
             * ⛔ সরবরাহকারীর টাকা তাঁর শাখায়, নম্বরও সেই শাখার সিরিজে — RCV-0143 / মালিক: *"কোনোভাবেই অন্য শাখায় নয়"* (ec, ১৯
             * অক্টোবর ২০২৬)। ⓘ আগে উপরের শাখা: A-র সরবরাহকারীর পরিশোধ B-তে বসত, আর খাতার দরজা ([[PostingEngine]]) পাকা করতে দিত না।
             * সরবরাহকারীর শাখা না থাকলে আগের মতো ([[CollectionService]]-এর একই নিয়ম)।
             */
            $supplierId = (int) $data['supplier_id'];
            $branchId = $data['branch_id']
                ?? (app(\App\Core\Services\PartyRegistry::class)->branchesOf([['supplier', $supplierId]])['supplier:'.$supplierId] ?? null)
                ?? CompanyContext::branchId();
            $branchId = $branchId === null ? null : (int) $branchId;

            $documentNo = $this->numbers->next('SP', $branchId);

            $payment = Payment::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branchId,
                'financial_year_id' => $year->id,
                'document_no' => $documentNo,
                'supplier_id' => $data['supplier_id'],
                'account_id' => $this->resolveMoneyAccount($data['account_id'] ?? null)->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $amount,
                ...$this->methodFields($data),
                'narration' => $data['narration'] ?? null,
                'proposal_no' => $data['proposal_no'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($payment, $lines);

            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Payment::drillSourceType(),
                    'source_id' => $payment->id,
                ]);

            return $payment->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Payment $payment, array $data, array $lines): Payment
    {
        $this->assertEditable($payment);

        // ⛔ সম্পাদনাতেও একই স্লিপ দুইবার নয় — নিজেকে বাদ রেখে (মালিকের নিয়ম, ৫ সেপ্টেম্বর ২০২৬; Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
        // [[ASlipUsedTwiceThroughAnEditTest]])। ⚠️ আগে কেবল তৈরিতে দেখা হত: খালি স্লিপে লিখে পরে TT-55 বসালে ধরা পড়ত না।
        $this->slips->check(
            table: 'pur_payments',
            slipNo: $data['instrument_no'] ?? null,
            partyId: (int) $payment->supplier_id,
            party: 'supplier_id',
            message: __('purchase::validation.slip_used_twice', ['no' => trim((string) ($data['instrument_no'] ?? ''))]),
            exceptId: (int) $payment->id,
        );

        return DB::transaction(function () use ($payment, $data, $lines) {
            // ⛔ তালা, তারপর "এখনো খসড়া?" আবার — পাশের ট্যাবে পাকা হয়ে গেলে পাকা কাগজের লাইন মুছে নতুন লেখা হত, খাতা আর মজুদ
            // পুরনো লাইনে (Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[AnEditLandedOnAConfirmedPaperTest]])
            $this->lockFresh($payment);
            $this->assertEditable($payment);

            $trxDate = Carbon::parse($data['trx_date'] ?? $payment->trx_date);

            $payment->update([
                'account_id' => $this->resolveMoneyAccount($data['account_id'] ?? $payment->account_id)->id,
                'trx_date' => $trxDate->toDateString(),
                'amount' => $this->money($data['amount'] ?? $payment->amount),
                ...$this->methodFields($data),
                'narration' => $data['narration'] ?? null,
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($payment, $lines);

            return $payment->fresh(['lines']);
        });
    }

    public function confirm(Payment $payment): Payment
    {
        if ($payment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_confirms', ['no' => $payment->document_no]),
            ]);
        }

        $this->assertStillFits($payment);

        // ⛔ ব্যাংক, MFS বা নগদ জমায় রেফারেন্স ছাড়া পোস্ট নয় — নকশা #২ ([[MoneyMethod::assertReadyToPost()]])
        MoneyMethod::assertReadyToPost($payment);

        /*
         * ⛔ সরবরাহকারীর ব্যাংকে টাকা (ট্রান্সফার বা তাঁর হিসাবে নগদ জমা) — তাঁর ব্যাংক তথ্য দুই হাতে সই-পাওয়া না হলে নয়
         * (নকশা #৬; জালিয়াতি ঠেকাতে, SAP vendor master dual control; fe-র [[SupplierBank::assertBankPayable()]])।
         * ⓘ নগদ, MFS আর চেকে প্রাপকের ব্যাংক লাগে না, তাই সেখানে আটকায় না।
         */
        if (in_array($payment->instrument, ['transfer', 'deposit'], true)) {
            app(\App\Modules\Supplier\Services\SupplierBank::class)->assertBankPayable($payment->supplier);
        }

        // টাকা বেরোনোর আগেই সই — ছক বসানো না থাকলে আগের মতোই চলে।
        $print = $this->approvals->assertClear(
            document: $payment,
            module: 'purchase',
            action: 'payment',
            field: 'status',
            amount: (string) $payment->amount,
            reason: $payment->narration,
        );

        return DB::transaction(function () use ($payment, $print) {
            /*
             * ⛔ তালা আগে, তারপর আবার দেখা — ২৯ সেপ্টেম্বর ২০২৬।
             *
             * উপরের দুইটা পরীক্ষা হাতে ধরা মডেল দেখে, তালা ছাড়া। একই
             * পরিশোধ দুই ট্যাবে খোলা থাকলে দুইজনের হাতেই সেটা "খসড়া" —
             * চেকের পথে দ্বিতীয় ডাক আরেকটা চেক লিখত (আলাদা উৎস, তাই
             * খাতার এক-কাগজ-এক-দাখিলা পাহারা চুপ), প্রদেয় দুইবার ডেবিট।
             * আর একই বিলের দুইটা খসড়া একই মুহূর্তে একই বাকি দেখে দুইটাই
             * পাশ করত।
             *
             * ⓘ তাই পরিশোধের সারি আর যে বিলগুলো সে শোধ করে সেগুলোয় তালা,
             * তারপর খাতা থেকে নতুন করে পড়া অবস্থা আর বাকি।
             */
            $locked = Payment::acrossBranches()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== DocumentStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => __('purchase::validation.only_draft_confirms', ['no' => $payment->document_no]),
                ]);
            }

            // ⛔ যাচাই আর তালার মাঝে কাগজ বদলালে কিছুই খাতায় নয় ([[DocumentApproval::assertUnchangedSince()]], ১৩ অক্টোবর ২০২৬)
            $this->approvals->assertUnchangedSince($payment, $print, 'status');

            // ⛔ সব শাখা জুড়ে — বিলগুলো খসড়া লেখার সময়ই দেয়াল পেরিয়ে যাচাই হয়েছে; পাকা যিনি করছেন তাঁর দেখা শাখার বাইরের বিলে
            // তালাও পড়ত না, বাকির যাচাইও নয় (Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[OnePaymentPaidOneBillTwiceTest]])
            PurchaseBill::acrossBranches()
                ->whereKey($payment->lines()->pluck('purchase_bill_id')->filter()->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payment->unsetRelation('lines');
            $this->assertStillFits($payment);
            $this->assertThreeHands($locked);
            $this->assertNotWithheldTwice($payment);

            /*
             * ── চেকে দিলে টাকাটা এখনো যায়নি ──────────────────────────
             *
             * ⛔ আগে এখানে শর্ত ছিল না: `instrument` যা-ই হোক, দাখিলা
             * বসত **Dr প্রদেয় / Cr যে খাত বাছা হয়েছে**। অর্থাৎ চেক
             * লিখলেই ব্যাংক কমে যেত।
             *
             * ⚠️ কিন্তু চেক লেখা আর টাকা যাওয়া এক জিনিস নয়। কাগজটা
             * তিন দিন পকেটে থাকতে পারে, ব্যাংকে গিয়ে ফেরতও আসতে পারে।
             * ততক্ষণ ব্যাংকের খাতা **বাস্তবের চেয়ে কম** দেখাত।
             *
             * ⭐ ঠিক এই ভুলটা উল্টো দিকে একবার ধরা পড়েছে ও সারানো
             * হয়েছে — গৃহীত চেক সরাসরি ব্যাংকে বসত (মাইগ্রেশন:
             * *"হাতে চেক ইতিমধ্যেই ব্যাংকের টাকা ছিল"*)। ক্রয়ের দিকে
             * একই ভুল রয়ে গিয়েছিল।
             *
             * ── কেন পরিশোধ নিজে কিছুই পোস্ট করে না ───────────────────
             * ⛔ দুইটা একসাথে পোস্ট করলে **প্রদেয় দুইবার ডেবিট হত**:
             *
             *     পরিশোধ  Dr প্রদেয়  Cr খাত
             *     চেক      Dr প্রদেয়  Cr ২১১৫
             *
             * তাই চেকের বেলায় পুরো দাখিলাটা চেকের কাগজে যায়, আর
             * পরিশোধ কেবল **কাগজপত্রের হিসাব** রাখে।
             *
             * ⓘ `pur_payment_lines` তবু লেখা হয়, আর সেটা ঠিক: ওগুলো
             * কোন বিলের বকেয়া কতটা মিটল তার হিসাব — **দাখিলা নয়**।
             * বিলের "বাকি কত" ওই সারিগুলো থেকেই গোনা হয়, খতিয়ান থেকে
             * নয়, তাই চেকের পথেও বকেয়া ঠিকঠাক কমে।
             */
            if ($payment->instrument === self::CHEQUE) {
                return $this->settleByCheque($payment);
            }

            $this->assertMoneyIsThere($payment);

            $this->posting->post(
                sourceType: Payment::drillSourceType(),
                sourceId: $payment->id,
                trxDate: $payment->trx_date,
                lines: $this->linesFor($payment),
                documentNo: $payment->document_no,
                branchId: $payment->branch_id,
            );

            $payment->update(['status' => DocumentStatus::CONFIRMED]);
            $this->markWithheldOnPayment($payment);

            return $payment->fresh(['lines']);
        });
    }

    /**
     * ⛔ কর একবারই — যে বিলে বিলেই কাটা, তার লাইনে পরিশোধে কাটা থাকলে থামে (নকশা #৫; fe, ২১ অক্টোবর ২০২৬)। ⓘ খসড়া লেখার পরে
     * কেউ বিলের দাগ বদলালে বা পুরনো খসড়া হলে — পোস্টের মুহূর্তে আবার দেখা।
     */
    private function assertNotWithheldTwice(Payment $payment): void
    {
        foreach ($payment->lines()->with('bill')->get() as $line) {
            $cut = bcadd((string) $line->ait_amount, (string) $line->vds_amount, 4);

            if ($line->bill !== null && bccomp($cut, '0', 4) > 0 && PaymentWithholding::withheldOnBill($line->bill)) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.withheld_twice', ['no' => $line->bill->document_no]),
                ]);
            }
        }
    }

    /** ⓘ পরিশোধে কাটা হলে বিলে দাগ — পরে বিলে আবার কাটার পথ বন্ধ, আর রিপোর্ট জানে কোথায় কাটা */
    private function markWithheldOnPayment(Payment $payment): void
    {
        $billIds = $payment->lines()->whereRaw('ait_amount + vds_amount > 0')->pluck('purchase_bill_id')->filter()->all();

        if ($billIds !== []) {
            PurchaseBill::acrossBranches()->whereKey($billIds)->whereNull('withheld_at')->update(['withheld_at' => 'payment']);
        }
    }

    /**
     * ⭐ চেকের পথে কাটা কর/ভ্যাটের দাখিলা উল্টো — চেক বাতিলে আর চেক ফেরতে ([[UndoThePaymentOfABouncedCheque]])। ⛔ নইলে টাকা না
     * গিয়েও সরকারের কাছে কর দেনা থেকে যেত, আর সরবরাহকারীর দেনা থেকে কাটা অংশ কমেই থাকত (cb, ২২ অক্টোবর ২০২৬)।
     * ⓘ কিছু কাটা না থাকলে কোনো দাখিলাই ছিল না — তখন কিছুই নয়; বিলের দাগও তোলা হয় যদি আর কোনো চালু পরিশোধ না কাটে।
     */
    public function undoChequeWithholding(Payment $payment, Carbon|string $date, string $reason): void
    {
        if (bccomp(bcadd((string) ($payment->ait_amount ?? '0'), (string) ($payment->vds_amount ?? '0'), 4), '0', 4) > 0) {
            $this->posting->reverse(
                sourceType: Payment::drillSourceType(),
                sourceId: $payment->id,
                reversalDate: $date,
                reason: $reason,
            );
        }

        $this->unmarkWithheldOnPayment($payment);
    }

    /**
     * ⓘ পরিশোধ বাতিল হলে বিলের "পরিশোধে কাটা" দাগ ওঠে — যদি আর কোনো চালু পরিশোধ সেই বিলে না কাটে। ⛔ নইলে বাতিলের পরেও
     * দাগটা বিলে কাটার পথ বন্ধ রাখত, অথচ কোথাও কাটা নেই (cb, ২২ অক্টোবর ২০২৬)।
     */
    private function unmarkWithheldOnPayment(Payment $payment): void
    {
        $billIds = $payment->lines()->whereRaw('ait_amount + vds_amount > 0')->pluck('purchase_bill_id')->filter()->unique()->all();

        foreach ($billIds as $billId) {
            $stillCut = PaymentLine::query()
                ->where('purchase_bill_id', $billId)
                ->where('payment_id', '!=', $payment->id)
                ->whereRaw('ait_amount + vds_amount > 0')
                ->whereIn('payment_id', Payment::acrossBranches()->where('status', DocumentStatus::CONFIRMED)->select('id'))
                ->exists();

            if (! $stillCut) {
                PurchaseBill::acrossBranches()->whereKey($billId)->where('withheld_at', 'payment')->update(['withheld_at' => null]);
            }
        }
    }

    /**
     * ⭐ প্রস্তাবক ≠ সইদাতা ≠ টাকাদাতা — টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা, ধাপ খ ১২ (৭ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রস্তাবক = খসড়ার লেখক (প্রস্তাবের PP তৈরিকারী); সইদাতা = এই পরিশোধে 'payment' নিয়মে "হ্যাঁ" দেওয়া মানুষ; টাকাদাতা
     * = এখন যিনি নিশ্চিত করছেন। ⛔ সুইচ চালু থাকলে টাকাদাতা প্রস্তাবক বা সইদাতা নন, আর সইদাতা প্রস্তাবক নন। ⓘ মালিক (সুপার
     * অ্যাডমিন) একা করলে আটকায় না — নিরীক্ষায় "একই মানুষ" দাগ পড়ে। সুইচ বন্ধে আজকের আচরণ অবিকল।
     */
    /**
     * ⭐ শেষ সইয়ে পরিশোধ নিজে পাকা হবে কি না — তিন হাতের নিয়মে না (হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
     * [[ASignedPaymentWaitsForTheThirdHandTest]])।
     *
     * ⓘ শেষ সইয়ের শ্রোতা কাগজ পাকা করে লেখকের (প্রস্তাবকের) নামে ([[SignedPaperFinisher]]); নিয়ম চালু থাকলে প্রস্তাবক নিজে টাকা
     * দিতে পারেন না ([[assertThreeHands()]]), তাই চেষ্টাটা প্রতিবারই "আটকে গেছে" হত। ⓘ এখন চেষ্টাই হয় না — পরিশোধ "সই হয়েছে,
     * পোস্ট হয়নি" তালিকায় তৃতীয় হাতের (ক্যাশিয়ারের) অপেক্ষায়। মালিক নিজে প্রস্তাবক হলে আগের মতো নিজে পাকা (দাগসহ)।
     */
    public function waitsForAThirdHand(Payment $payment): bool
    {
        if (! (bool) app(\App\Core\Services\SettingsService::class)->get('purchase.payment_three_hands', true)) {
            return false;
        }

        $maker = \App\Models\User::query()->find((int) ($payment->created_by ?? 0));

        return ! ($maker instanceof \App\Models\User && $maker->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE));
    }

    private function assertThreeHands(Payment $payment): void
    {
        if (! (bool) app(\App\Core\Services\SettingsService::class)->get('purchase.payment_three_hands', true)) {
            return;
        }

        $actor = (int) (\App\Core\Support\Actor::userId() ?? 0);
        $proposer = (int) ($payment->created_by ?? 0);
        $signers = \App\Models\ApprovalDecision::query()
            ->whereIn('approval_id', \App\Models\Approval::query()->where('approvable_type', Payment::class)
                ->where('approvable_id', $payment->id)->where('action', 'payment')->select('id'))
            ->where('decision', \App\Models\ApprovalDecision::APPROVED)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $clash = match (true) {
            $actor !== 0 && $actor === $proposer => 'proposer_pays',
            $actor !== 0 && in_array($actor, $signers, true) => 'signer_pays',
            $proposer !== 0 && in_array($proposer, $signers, true) => 'proposer_signed',
            default => null,
        };

        if ($clash === null) {
            return;
        }

        $user = auth()->user();
        $owner = $user instanceof \App\Models\User && $user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE);

        if ($owner) {
            app(\App\Core\Engines\Audit\AuditEngine::class)->recordAction($payment, 'three_hands_override', __('purchase::validation.three_hands_'.$clash));

            return;
        }

        throw ValidationException::withMessages(['status' => __('purchase::validation.three_hands_'.$clash, ['no' => $payment->document_no])]);
    }

    public function cancel(Payment $payment, string $reason, Carbon|string|null $onDate = null): Payment
    {
        if ($payment->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.already_cancelled', ['no' => $payment->document_no]),
            ]);
        }

        $date = $onDate === null ? now() : Carbon::parse($onDate);

        return DB::transaction(function () use ($payment, $reason, $date) {
            /*
             * ⚠️ চেকে দেওয়া হয়ে থাকলে দাখিলাটা **চেকের কাগজে**, এখানে
             * নয় — তাই এখানে উল্টো দাখিলা খুঁজলে কিছুই পাওয়া যেত না
             * আর `reverse()` ব্যতিক্রম ছুড়ত।
             *
             * ⓘ চেক বাতিল করলে [[ChequeService::cancel]] নিজেই উল্টো
             * দাখিলা বসায়, আর কাগজটাও বাতিল দেখায় — দুইটাই দরকার,
             * কারণ চেকটা সত্যিই লেখা হয়েছিল।
             */
            if ($payment->status === DocumentStatus::CONFIRMED && $payment->cheque_id !== null) {
                $cheque = $payment->cheque;

                if ($cheque !== null && $cheque->status !== Cheque::CANCELLED) {
                    // ⛔ পরিশোধ এখনই বাতিল, তাই চেকের উল্টোও এখনই — ফেরতের সইয়ে অপেক্ষা নয় ([[ACancelledChequeUndidNothingTest]])
                    $this->cheques->cancel($cheque, $reason, $date, asksItsSignature: false);
                }

                // ⛔ চেকের পথে কাটা অংশের দাখিলা পরিশোধের নিজের — সেটাও উল্টো ([[settleByCheque()]])
                $this->undoChequeWithholding($payment, $date, $reason);
            } elseif ($payment->status === DocumentStatus::CONFIRMED) {
                /*
                 * উল্টো দাখিলা, মুছে ফেলা নয় (নিয়ম ৫)।
                 *
                 * টাকা চলে গিয়েছিল, তারপর ফেরত এসেছে — খাতায় দুইটাই
                 * থাকা উচিত। মুছে ফেললে ব্যাংকের কাগজের সাথে মেলানোর
                 * সময় একটা লেনদেন কম পড়ত।
                 */
                $this->posting->reverse(
                    sourceType: Payment::drillSourceType(),
                    sourceId: $payment->id,
                    reversalDate: $date,
                    reason: $reason,
                );
            }

            $payment->update([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            $this->unmarkWithheldOnPayment($payment);

            return $payment->fresh(['lines']);
        });
    }

    /**
     * চেকে পরিশোধ — কাগজটা লেখা হলো, টাকা এখনো যায়নি।
     *
     * ── দাখিলা কোথায় বসে ────────────────────────────────────────────
     *
     *     চেক লিখলাম   Dr প্রদেয় (সরবরাহকারী)   Cr ইস্যু করা চেক (২১১৫)
     *     চেক ভাঙল     Dr ইস্যু করা চেক          Cr ব্যাংক
     *     চেক ফেরত এল  Dr ইস্যু করা চেক          Cr প্রদেয়
     *
     * ⭐ প্রথম সারিটা এখানেই বসে ([[ChequeService::create]]), আর বাকি
     * দুইটা চেকের রেজিস্টার থেকে — কারণ ওগুলো **পরে ঘটে**, আর কবে
     * ঘটবে তা আজ কেউ জানে না।
     *
     * ⚠️ ব্যাংকের খাত (`account_id`) তবু রাখা হয়, আর সেটা অব্যবহৃত নয়:
     * চেকটা কোন ব্যাংক থেকে লেখা হলো সেটা ভাঙানোর দিন লাগে, আর
     * ব্যাংকে কতগুলো চেক ঝুলে আছে সেই প্রশ্নের উত্তরও ওখান থেকেই আসে।
     *
     * ⛔ নম্বর ছাড়া চেক হয় না — একই সরবরাহকারীকে তিনটা চেক দিলে
     * নম্বরটাই একমাত্র পার্থক্য, আর ব্যাংকের কাগজের সাথে মেলাতেও ওটাই
     * লাগে। ⓘ পর্দা নম্বরটা চায়, কিন্তু API বা ইমপোর্ট এড়িয়ে যেতে
     * পারত, তাই পাহারাটা এখানেও।
     */
    private function settleByCheque(Payment $payment): Payment
    {
        if (blank($payment->instrument_no)) {
            throw ValidationException::withMessages([
                'instrument_no' => __('purchase::validation.cheque_needs_number'),
            ]);
        }

        /*
         * ⛔ চেকে দিলেও কর একবার কাটা (নকশা #৫; cb, ২২ অক্টোবর ২০২৬) — আগে চেকের পথ কাটা AIT/VDS জানতই না: চেক লেখা হত পুরো
         * টাকায়, ২১২১/২১২২-এ কিছু বসত না, আর প্রিভিউ যা দেখাত (নিট) পোস্ট তা করত না। ⭐ এখন চেক নিটে, আর কাটা অংশটা পরিশোধের
         * নিজের দাখিলায়: Dr প্রদেয় (AIT + VDS) / Cr ২১২১ / Cr ২১২২। দুই দাখিলা মিলিয়ে ঠিক [[linesFor()]]।
         */
        $cut = $this->withholdingLines($payment);
        $net = bcsub(bcsub((string) $payment->amount, (string) ($payment->ait_amount ?? '0'), 4), (string) ($payment->vds_amount ?? '0'), 4);

        if (bccomp($net, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('purchase::validation.withheld_takes_all', ['no' => $payment->document_no])]);
        }

        if ($cut !== []) {
            $this->posting->post(
                sourceType: Payment::drillSourceType(),
                sourceId: $payment->id,
                trxDate: $payment->trx_date,
                lines: $cut,
                documentNo: $payment->document_no,
                branchId: $payment->branch_id,
            );
        }

        $cheque = $this->cheques->create([
            'direction' => Cheque::ISSUED,

            /*
             * ⓘ চেকের গায়ে লেখা তারিখ — না দিলে পরিশোধের তারিখ।
             * ⚠️ দুইটা আলাদা হতে পারে, আর সেটাই স্বাভাবিক: আজ লিখে
             * আগামী সপ্তাহের তারিখ বসানো ডিপোতে রোজকার ঘটনা।
             */
            'cheque_date' => $payment->instrument_date ?? $payment->trx_date,
            'received_on' => $payment->trx_date,
            'cheque_no' => $payment->instrument_no,
            'amount' => $net,
            'party_type' => 'supplier',
            'party_id' => $payment->supplier_id,
            'bank_account_id' => $payment->account_id,
            'narration' => $payment->narration
                ?? __('purchase::message.paid_against', ['no' => $payment->document_no]),
        ]);

        $payment->forceFill([
            'cheque_id' => $cheque->id,
            'status' => DocumentStatus::CONFIRMED,
        ])->save();
        $this->markWithheldOnPayment($payment);

        return $payment->fresh(['lines', 'cheque']);
    }

    /**
     * খাতায় বসানোর মুহূর্তে ভাগগুলো এখনো খাটে কি না।
     *
     * ── কেন তৈরির সময়ের পরীক্ষাটা যথেষ্ট নয় ─────────────────────────
     * একই বিলের বিপরীতে দুইটা খসড়া পরিশোধ লেখা যায় — দুইজন আলাদা
     * মানুষ, বা একজনই ভুলে দুইবার। তৈরির সময় দুইটাই বৈধ ছিল, কারণ
     * তখন কোনোটাই খাতায় বসেনি। কিন্তু দুইটাই নিশ্চিত হলে বিলে তার
     * মোটের চেয়ে বেশি টাকা বসে যেত, আর সরবরাহকারীর খাতা অতিরিক্ত
     * শোধ দেখাত।
     *
     * টাকা নড়ে নিশ্চিত করার মুহূর্তে, তাই শেষ পাহারাটাও এখানেই।
     */
    private function assertStillFits(Payment $payment): void
    {
        /*
         * ⛔ বিলপ্রতি যোগ, সব শাখা জুড়ে (Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[OnePaymentPaidOneBillTwiceTest]])। ⚠️ আগে লাইন ধরে
         * আলাদা করে মেলানো হত — একই বিল দুই লাইনে ১,০০০ + ১,০০০ দিলে প্রতিটা ১,০০০-এর বাকির ভেতরে পড়ত, বিলে বসত ২,০০০;
         * আর সম্পর্কটা দেয়াল দিয়ে পড়ত, অন্য শাখা দেখানো অবস্থায় বিল "নেই" হয়ে যাচাই বাদ পড়ত।
         */
        $byBill = [];

        foreach ($payment->lines()->get() as $line) {
            $id = (int) $line->purchase_bill_id;

            if ($id > 0) {
                $byBill[$id] = bcadd($byBill[$id] ?? '0', (string) $line->amount, 4);
            }
        }

        foreach ($byBill as $billId => $amount) {
            $bill = PurchaseBill::acrossBranches()->find($billId);

            if ($bill === null) {
                continue;
            }

            // এই পরিশোধটা এখনো খসড়া, তাই নিজের ভাগটা বাকির হিসাবে নেই
            $due = $bill->dueAmount();

            if (bccomp($amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.over_allocated', [
                        'no' => $bill->document_no,
                        'due' => Money::format($due),
                    ]),
                ]);
            }
        }
    }

    /**
     * বিলভিত্তিক ভাগ।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Payment $payment, array $lines): void
    {
        $payment->lines()->delete();

        $allocated = '0';
        // ⭐ উৎসে কর আর ভ্যাট — একবারই ([[PaymentWithholding]]; নকশা #৫, #২৫)
        $ait = '0';
        $vds = '0';
        $supplier = $payment->supplier()->first();
        $lineNo = 0;
        // ⓘ এই পরিশোধেই একই বিলে আগের লাইনে যা বসল — বাকি থেকে সেটাও বাদ ([[assertStillFits()]]-এর একই কারণ)
        $usedByBill = [];

        foreach ($lines as $line) {
            $billId = (int) ($line['purchase_bill_id'] ?? 0);
            $amount = $this->money($line['amount'] ?? null);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $bill = PurchaseBill::query()->whereKey($billId)->first();

            if ($bill === null) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.unknown_bill')]);
            }

            if ((int) $bill->supplier_id !== (int) $payment->supplier_id) {
                throw ValidationException::withMessages(['lines' => __('purchase::validation.bill_other_supplier')]);
            }

            if ($bill->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.bill_not_confirmed', ['no' => $bill->document_no]),
                ]);
            }

            /*
             * একটা বিলে তার বকেয়ার চেয়ে বেশি বসানো যায় না।
             *
             * বসালে ওই বিলটা "অতিরিক্ত শোধ" দেখাত আর অন্যটা বাকি থেকে
             * যেত, অথচ মোট মিলে যেত — ভুলটা মোটে ধরা পড়ত না, শুধু
             * বকেয়ার তালিকায় ভুল বিল উঠত।
             *
             * এই পরিশোধের নিজের লাইনগুলো আগেই মুছে ফেলা হয়েছে, তাই
             * সম্পাদনার সময় নিজের পুরনো ভাগটা দুইবার গোনা হয় না।
             */
            $due = bcsub($bill->dueAmount(), $usedByBill[$bill->id] ?? '0', 4);

            if (bccomp($amount, $due, 4) > 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.over_allocated', [
                        'no' => $bill->document_no,
                        'due' => Money::format(bccomp($due, '0', 4) > 0 ? $due : '0'),
                    ]),
                ]);
            }

            $usedByBill[$bill->id] = bcadd($usedByBill[$bill->id] ?? '0', $amount, 4);
            $cut = PaymentWithholding::forLine($bill, $supplier, $amount);

            PaymentLine::create([
                'company_id' => $payment->company_id,
                'payment_id' => $payment->id,
                'purchase_bill_id' => $bill->id,
                'amount' => $amount,
                'ait_amount' => $cut['ait'],
                'vds_amount' => $cut['vds'],
                'line_no' => ++$lineNo,
            ]);

            $allocated = bcadd($allocated, $amount, 4);
            $ait = bcadd($ait, $cut['ait'], 4);
            $vds = bcadd($vds, $cut['vds'], 4);
        }

        $payment->forceFill(['ait_amount' => $ait, 'vds_amount' => $vds])->save();

        if (bccomp($allocated, (string) $payment->amount, 4) > 0) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.allocation_over_amount', [
                    'allocated' => Money::format($allocated),
                    'amount' => Money::format($payment->amount),
                ]),
            ]);
        }
    }

    /**
     * টাকাটা কোথা থেকে গেল — নগদ বা ব্যাংক জাতীয় খাত থেকেই কেবল।
     *
     * যেকোনো খাত নিতে দিলে কেউ ভুল করে "ক্রয়" খাত থেকে পরিশোধ বসাত,
     * আর তখন খরচ দুইবার গোনা হত।
     */
    /**
     * টিল বা ওয়ালেটে যা নেই তা দেওয়া যায় না — লাইভ QA (hp2, TCL), PMT-0001, ২৭ সেপ্টেম্বর ২০২৬।
     *
     * ⛔ আগে খালি টিল থেকে ১,০০০ পরিশোধ হয়ে গেল আর টিল দাঁড়াল −১,০০০ — খাতা
     * মিলল, বাক্স মিলল না। ⓘ নিয়মটা এক জায়গায় ([[CashOnHand]]): নগদ আর
     * মোবাইল ব্যাংকিং শূন্যের নিচে নয়, ব্যাংক (CC/OD) নামতে পারে।
     *
     * ⚠️ তালা আগে, মাপা পরে, আর দুইটাই পোস্টের একই লেনদেনে — নাহলে দুইটা
     * পরিশোধ একই মুহূর্তে একই জের দেখে দুইটাই পাশ করত।
     */
    /**
     * ⭐ খাতায় যা যাবে — পোস্ট আর পোস্টের আগের প্রিভিউ দুটোই এখান থেকে, তাই প্রিভিউ যা দেখায় খাতায় ঠিক তাই বসে (নকশা #১০,
     * SAP-এর "Simulate"; মালিক, ২১ অক্টোবর ২০২৬)।
     *
     *   Dr প্রদেয় (সরবরাহকারী)              পুরো টাকা
     *   Dr ব্যাংক বা MFS চার্জ (৫২১০/৫২১১)   চার্জ — আমাদের খরচ (নকশা #৯)
     *   Cr টাকার খাত                        টাকা + চার্জ
     *
     * ⓘ চার্জ ছাড়া আগের দুই সারি হুবহু — পুরনো নিয়ম এক টাকাও বদলায়নি।
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(Payment $payment): array
    {
        $amount = bcadd((string) $payment->amount, '0', 4);
        $charge = $this->money($payment->charge_amount ?? '0');
        $ait = bcadd((string) ($payment->ait_amount ?? '0'), '0', 4);
        $vds = bcadd((string) ($payment->vds_amount ?? '0'), '0', 4);
        $no = (string) $payment->document_no;

        $lines = [[
            'account_id' => $this->account(StandardChart::PAYABLE)->id,
            'debit' => $amount,
            'party_type' => 'supplier',
            'party_id' => $payment->supplier_id,
            'narration' => __('purchase::message.against_payable', ['no' => $no]),
        ]];

        // ⭐ কেটে রাখা কর সরকারের দায় — AIT ২১২১, VDS ২১২২ (নকশা #৫; fe, ২১ অক্টোবর ২০২৬)। ⓘ ক্রম নকশার: দেনা, AIT, VDS, চার্জ, টাকার খাত
        foreach (['ait' => [$ait, StandardChart::TAX_DEDUCTED_AT_SOURCE], 'vds' => [$vds, StandardChart::VDS_PAYABLE]] as $kind => [$value, $code]) {
            if (bccomp($value, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => $this->account($code)->id,
                    'credit' => $value,
                    'narration' => __('purchase::message.withheld_'.$kind, ['no' => $no]),
                ];
            }
        }

        if (bccomp($charge, '0', 4) > 0) {
            $lines[] = [
                'account_id' => $this->chargeHead((int) $payment->account_id)->id,
                'debit' => $charge,
                'narration' => __('purchase::message.charge_on_payment', ['no' => $no]),
            ];
        }

        // ⓘ টাকার খাত থেকে যায় নিট আর চার্জ: মোট − AIT − VDS + চার্জ
        // ⓘ চেকে টাকা এখনো যায়নি — নিট বসে "দেওয়া চেক" (২১১৫)-এ, চেকের কাগজ যেমন বসায় ([[settleByCheque()]], [[ChequeService]])
        $lines[] = [
            'account_id' => $payment->instrument === self::CHEQUE ? $this->account(StandardChart::CHEQUES_ISSUED)->id : $payment->account_id,
            'credit' => bcadd(bcsub(bcsub($amount, $ait, 4), $vds, 4), $charge, 4),
            'narration' => __('purchase::message.money_out', ['no' => $no]),
        ];

        return $lines;
    }

    /**
     * ⓘ চেকের পথে পরিশোধের নিজের দাখিলা — কেবল কাটা অংশ: Dr প্রদেয় (AIT + VDS) / Cr ২১২১ / Cr ২১২২; কিছু না কাটলে ফাঁকা।
     *
     * @return list<array<string, mixed>>
     */
    private function withholdingLines(Payment $payment): array
    {
        $heads = [$this->account(StandardChart::TAX_DEDUCTED_AT_SOURCE)->id, $this->account(StandardChart::VDS_PAYABLE)->id];
        $taxes = array_values(array_filter(
            $this->linesFor($payment),
            fn (array $line) => in_array((int) $line['account_id'], $heads, true),
        ));

        if ($taxes === []) {
            return [];
        }

        $cut = array_reduce($taxes, fn (string $sum, array $line) => bcadd($sum, (string) $line['credit'], 4), '0');

        return [[
            'account_id' => $this->account(StandardChart::PAYABLE)->id,
            'debit' => $cut,
            'party_type' => 'supplier',
            'party_id' => $payment->supplier_id,
            'narration' => __('purchase::message.against_payable', ['no' => (string) $payment->document_no]),
        ], ...$taxes];
    }

    /**
     * চার্জের খাত — ব্যাংকে ৫২১০, MFS-এ ৫২১১ ([[VoucherService::chargeAccountFor()]]-এর একই নিয়ম)।
     * ⛔ নগদে চার্জ হয় না — নগদ খাতে চার্জ লিখলে থামে, নিঃশব্দে ফেলে দেওয়া হয় না।
     */
    private function chargeHead(int $moneyAccountId): Account
    {
        $money = Account::query()->find($moneyAccountId);
        $code = match (true) {
            $money?->isMfs() === true => StandardChart::MFS_CHARGES,
            $money?->isBank() === true => StandardChart::BANK_CHARGES,
            default => null,
        };

        if ($code === null) {
            throw ValidationException::withMessages(['charge_amount' => __('purchase::validation.charge_needs_bank_or_mfs')]);
        }

        return $this->account($code);
    }

    /**
     * ⓘ পদ্ধতির ঘরগুলো — কেবল বাছা পদ্ধতির ([[MoneyMethod::onlyItsOwn()]]); বাকিগুলো ফাঁকা বা শূন্য।
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function methodFields(array $data): array
    {
        $kept = MoneyMethod::onlyItsOwn([
            'instrument' => $data['instrument'] ?? null,
            'instrument_no' => $data['instrument_no'] ?? null,
            'instrument_date' => $data['instrument_date'] ?? null,
            'from_bank' => $data['from_bank'] ?? null,
            'transfer_mode_id' => $data['transfer_mode_id'] ?? null,
            'value_date' => $data['value_date'] ?? null,
            'deposit_slip_no' => $data['deposit_slip_no'] ?? null,
            'charge_amount' => $data['charge_amount'] ?? null,
        ]);
        // ⓘ খালি চার্জের ঘর মানে শূন্য — [[money()]] খালি লেখাকে ভুল ধরে
        $kept['charge_amount'] = $this->money(blank($kept['charge_amount'] ?? null) ? '0' : $kept['charge_amount']);

        return $kept;
    }

    private function assertMoneyIsThere(Payment $payment): void
    {
        $account = Account::query()->findOrFail($payment->account_id);
        $cash = app(CashOnHand::class);

        if (! $cash->guards($account)) {
            return;
        }

        $cash->lock($account);

        // ⓘ খাত থেকে বেরোয় টাকা আর চার্জ দুটোই ([[linesFor()]]) — চার্জ বাদে মাপলে খাত ঋণাত্মক হতে পারত
        $out = bcadd(bcsub(bcsub((string) $payment->amount, (string) ($payment->ait_amount ?? '0'), 4), (string) ($payment->vds_amount ?? '0'), 4),
            $this->money($payment->charge_amount ?? '0'), 4);
        $short = $cash->shortfall($account, $out, $payment->trx_date?->toDateString());

        if ($short !== null) {
            throw ValidationException::withMessages([
                'amount' => __('purchase::validation.not_enough_money_in', [
                    'account' => $account->name(),
                    'held' => Money::format(bcsub($out, $short, 4)),
                    'amount' => Money::format($out),
                ]),
            ]);
        }
    }

    private function resolveMoneyAccount(mixed $accountId): Account
    {
        $account = blank($accountId)
            ? $this->tills->ensurePrimaryTill()->account
            : Account::query()->postable()->whereKey((int) $accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.unknown_account'),
            ]);
        }

        /*
         * গ্রুপ খাত থেকে টাকা বেরোয় না।
         *
         * ডিফল্ট ছিল ১১০১ "হাতে নগদ" — একটা মাথা, খাত নয়। ওখানে বসানো
         * সারি কোনো ব্যালেন্সে দেখাত না, কারণ `Account::balanceOn()`
         * গ্রুপের নিজের সারি গোনে না। ফলে সরবরাহকারীকে নগদে পরিশোধ
         * করলে টাকাটা কোনো কাউন্টার থেকেই কমত না — না টিলে, না
         * ড্যাশবোর্ডে, না দিনশেষের গণনায়।
         *
         * আদায়ের পথেও হুবহু একই ভুল ছিল; দুইটাই একসাথে সারানো, কারণ
         * একটা সারালে অন্যটা রয়ে গেলে দুই দিক আর মিলত না।
         */
        if ($account->is_group) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.group_takes_no_money', ['name' => $account->name()]),
            ]);
        }

        $money = StandardChart::MONEY_PARENTS;

        $isMoney = Account::query()->whereKey($account->parent_id)->whereIn('code', $money)->exists();

        if (! $isMoney) {
            throw ValidationException::withMessages([
                'account_id' => __('purchase::validation.not_a_money_account', ['name' => $account->name()]),
            ]);
        }

        /*
         * ⛔ নগদ কেবল নিজের বাক্স থেকে — ভাউচারের একই নিয়ম ([[VoucherService::assertCashLandsInOwnTill()]]; মালিক ২১/২৮ সেপ্টেম্বর
         * ২০২৬; Purchase নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[ASupplierWasPaidFromSomeoneElsesTillTest]])। ⚠️ পর্দা কেবল অন্য শাখার বাক্স লুকাত,
         * সার্ভার কিছুই দেখত না: সহকর্মীর বাক্সের খাত পাঠালে তাঁর সই ছাড়াই তাঁর বাক্স খালি হত। ⓘ ব্যাংক খাতে এই নিয়ম নেই।
         */
        if ($account->isCash() && ! \App\Modules\Accounts\Models\CashTill::mayUse(auth()->id(), (int) $account->id)) {
            throw ValidationException::withMessages([
                'account_id' => \App\Modules\Accounts\Models\CashTill::heldByAnyone()
                    ? __('accounts::validation.cash_not_your_till', ['account' => $account->label()])
                    : __('accounts::validation.no_till_of_your_own'),
            ]);
        }

        return $account;
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages(['amount' => __('purchase::validation.not_a_number')]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => __('purchase::validation.negative_amount')]);
        }

        return bcadd($value, '0', 4);
    }

    private function assertEditable(Payment $payment): void
    {
        if ($payment->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.only_draft_edits', ['no' => $payment->document_no]),
            ]);
        }
    }

    private function account(string $code): Account
    {
        $account = Account::query()->postable()->where('code', $code)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.missing_account', ['code' => $code]),
            ]);
        }

        return $account;
    }

    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('purchase::validation.no_financial_year', ['date' => $date->toDateString()]),
            ]);
        }

        return $year;
    }
}
