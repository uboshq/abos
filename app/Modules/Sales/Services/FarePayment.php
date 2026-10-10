<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ গাড়ির ভাড়া — এক সেবা, সব দরজা (কাউন্টার, চালানের পরিবহন পর্দা, ট্রিপ, ফোন); মালিক, ৭ অক্টোবর ২০২৬।
 *
 * ⛔ মালিকের কথা: *"এখন ভাড়া তুললে Main Counter থেকে paid দেখায়, কোনো খাত বাছার সুযোগ নেই। কোথা থেকে কে দিল,
 * সেই ব্যবস্থা লাগবে। আর কাউন্টারে বিল করার সময় পূর্ণাঙ্গ খরচ (expense) ইস্যু করে যাতে হয়।"* ⓘ আগে চালান পাকা
 * হলে ভাড়া সরাসরি খাতায় বসত (Dr ৫২১৭ / Cr কোম্পানির প্রধান টিল) — ভাউচার, নম্বর, সই, নিজের টিলের নিয়ম বা
 * TrxID ছাড়া ([[DeliveryChallanService::postTransportCost()]])।
 *
 * ── দুই পথ (fe-র অনুমোদিত নকশা, ৭ অক্টোবর ২০২৬) ──────────────────────────────
 *  - **এখনই দিলাম** (`now`): চালান পাকা হলে পূর্ণাঙ্গ খরচ ভাউচার (EV) — Dr ৫২১৭ গাড়ির ভাড়া / Cr বাছা টাকার খাত,
 *    ভাউচারের সব নিয়মে: নম্বর, সই ([[VoucherApproval]]), নিজের টিল, টাকা আছে কি না, ব্যাংক বা MFS-এর TrxID।
 *    কাউন্টারের জমার মতোই চলে (`origin counter`) — লেখক ≠ পাকাকারী এখানে নয় (সিদ্ধান্ত ক)।
 *  - **পরে দেব** (`due`): চালান পাকা হলে Dr ৫২১৭ / Cr ২১১৬ প্রদেয় পরিবহন, বাহকের নামে — accrual, খরচ ঠিক মাসে
 *    (সিদ্ধান্ত খ)। তাই বাহক বাধ্যতামূলক। পরে দেওয়াটা পরিবহন পর্দার PV।
 *
 * ── ট্রিপ (সিদ্ধান্ত ঘ, ১০ অক্টোবর ২০২৬) ─────────────────────────────────────────
 * এক ট্রাকে কয়েকটা চালান যায়, ভাড়া একটাই — তাই ভাড়া ট্রিপের, একটা ভাউচার, against = ট্রিপ। খাতায় বসে ট্রাক
 * রওনা হলে ([[bookTrip()]]); ট্রিপ বাতিলে ফেরে ([[undoTrip()]])। ট্রিপের ভাড়া সবসময় আমাদের (ভাড়ার ট্রাক) — "কে দেবে"
 * নেই। ⛔ ভাড়াওয়ালা ট্রিপের চালানে আলাদা ভাড়া নয় ([[assertOneFarePerTrip()]]) — একই ট্রাকের ভাড়া দুইবার বসত।
 *
 * ⓘ "কে দিলেন" — নগদে লগইন করা মানুষ, বদলানো যায় না (তার টিল থেকেই টাকা বেরোয়); ব্যাংক বা MFS-এ কোম্পানির যেকোনো
 * মানুষ (সিদ্ধান্ত গ)। ⓘ পুরনো কাগজ (`fare_rule` null) আগের পথেই চলে — এই সেবা তাদের ছোঁয় না।
 */
final class FarePayment
{
    public const RULE = 'voucher';

    public const NOW = 'now';

    public const DUE = 'due';

    /** ট্রিপের পরে-দেব ভাড়ার দেনা — ট্রিপের নিজের দাখিলা-নাম */
    public const TRIP_SOURCE = 'shipment:fare';

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly VoucherApproval $approval,
        private readonly MoneyAccountRule $moneyRule,
    ) {}

    /**
     * দরজা থেকে আসা ঘরগুলো যাচাই করে কাগজে বসানো — চালান পাকা বা ট্রাক রওনা হওয়ার **আগে** (খসড়ায়)।
     *
     * ⓘ ভাড়া আমাদের না হলে (গ্রাহক দেবে, ভাড়া নেই) বা অঙ্ক শূন্য হলে কিছুই বসে না।
     *
     * @param  array{fare_when?: ?string, fare_account_id?: mixed, fare_reference?: ?string, fare_payer_id?: mixed}  $data
     */
    public function stamp(DeliveryChallan|Shipment $paper, array $data): void
    {
        if (! $this->isOurs($paper)) {
            $paper->forceFill(['fare_rule' => null, 'fare_status' => null, 'fare_account_id' => null,
                'fare_reference' => null, 'fare_payer_id' => null])->save();

            return;
        }

        if (($data['fare_when'] ?? self::NOW) === 'later') {
            // ⛔ পরে দেব মানে কারো কাছে দেনা — কার, সেটা না জানলে খাতায় নামহীন দেনা বসত (সিদ্ধান্ত খ)
            if ($paper->carrier_id === null) {
                throw ValidationException::withMessages(['carrier_id' => __('sales::fare.later_needs_carrier')]);
            }

            $paper->forceFill(['fare_rule' => self::RULE, 'fare_status' => self::DUE, 'fare_account_id' => null,
                'fare_reference' => null, 'fare_payer_id' => null])->save();

            return;
        }

        [$account, $reference, $payer] = $this->moneyFrom($data);

        $paper->forceFill([
            'fare_rule' => self::RULE, 'fare_status' => self::NOW, 'fare_account_id' => $account->id,
            'fare_reference' => $reference, 'fare_payer_id' => $payer,
        ])->save();
    }

    /**
     * ⭐ পাকা চালানে প্রথমবার ভাড়া লেখা — চালানের পরিবহন পর্দা থেকে (মালিক, ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ পর্দাটা "নিশ্চিতের পরে, ছাপার আগে" — তাই ভাড়া প্রায়ই চালান পাকা হওয়ার পরে জানা যায়। একই নিয়মে বসে
     * ([[stamp()]]), আর সাথে সাথে খাতায়: এখনই দিলে EV, পরে দিলে বাহকের নামে ২১১৬ ([[DeliveryChallanService::bookFare()]])।
     * ⛔ "বিলে যোগ" এখানে নয় — বিল আগেই খাতায় বসেছে, তার মোট বদলানো যায় না; ওটা কেবল কাউন্টারে।
     * ⛔ আগে ভাড়া লেখা থাকলে (পুরনো বা নতুন নিয়মে) আবার নয় — খরচ দুইবার বসত।
     * ⛔ ভাড়াওয়ালা ট্রিপে থাকা চালানে নয় — ঐ ট্রাকের ভাড়া ট্রিপে একবারই (সিদ্ধান্ত ঘ)।
     *
     * @param  array<string, mixed>  $data  transport_cost, fare_paid_by (us · customer · none), carrier_id, আর stamp()-এর ঘর
     */
    public function recordOnConfirmed(DeliveryChallan $challan, array $data): void
    {
        $who = (string) ($data['fare_paid_by'] ?? '');

        if (! in_array($who, ['us', 'customer', 'none'], true)) {
            throw ValidationException::withMessages(['fare_paid_by' => __('sales::fare.who_pays')]);
        }

        $amount = (string) ($data['transport_cost'] ?? '');

        if ($who === 'us' && (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0)) {
            throw ValidationException::withMessages(['transport_cost' => __('sales::fare.needs_amount')]);
        }

        DB::transaction(function () use ($challan, $data, $who, $amount): void {
            /*
             * ⛔ সারি তালা দিয়ে আসল অবস্থা — পুরো ERP অডিট, ৯ অক্টোবর ২০২৬: *"গাড়ি ভাড়া লেখায় তালা নেই — দুবার চাপলে
             * দুবার"*। ⓘ দুই চাপ একসাথে এলে দুটোই "ভাড়া লেখা নেই" দেখত আর দুটো EV বসাত; এখন দ্বিতীয়টা প্রথমটার পরে
             * সারিটা পায়, আর "আগেই লেখা" দেখে থামে ([[EveryMoneyActionLocksItsRowTest]])।
             */
            $challan = DeliveryChallan::query()->lockForUpdate()->findOrFail($challan->id);

            if ($challan->status !== DocumentStatus::CONFIRMED) {
                throw ValidationException::withMessages(['fare' => __('sales::fare.only_confirmed')]);
            }

            if ($challan->fare_rule !== null || (is_numeric($challan->transport_cost) && bccomp((string) $challan->transport_cost, '0', 4) > 0)) {
                throw ValidationException::withMessages(['fare' => __('sales::fare.already_recorded')]);
            }

            if ($who === 'us' && ($trip = $this->faredTripOf($challan)) !== null) {
                throw ValidationException::withMessages(['fare' => __('sales::fare.challan_on_trip', ['trip' => $trip->document_no])]);
            }

            $challan->forceFill([
                'fare_paid_by' => $who,
                'transport_cost' => $who === 'us' ? $amount : (is_numeric($amount) ? $amount : null),
                'carrier_id' => ($data['carrier_id'] ?? null) ?: $challan->carrier_id,
            ])->save();

            if ($who !== 'us') {
                return;
            }

            $this->stamp($challan->fresh(), $data);
            app(DeliveryChallanService::class)->bookFare($challan->fresh());
        });
    }

    /**
     * ⭐ পরে-দেওয়া ভাড়া দেওয়া — PV: Dr ২১১৬ প্রদেয় পরিবহন (বাহকের নামে) / Cr বাছা খাত (সিদ্ধান্ত খ)।
     *
     * ⓘ হাতে লেখা ভাউচারের পথে ([[VoucherWriter::store()]]) — সই আর লেখক ≠ পাকাকারী পুরো খাটে (সিদ্ধান্ত ক)। সই বা
     * অন্য হাতের অপেক্ষায় থাকলে ভাউচার খসড়া, কাগজে বাঁধা থাকে; বাতিল হলে আবার দেওয়া যায়। চালান আর ট্রিপ — দুটোরই।
     *
     * @return array{0: Voucher, 1: bool|string}  [[VoucherWriter::store()]]-এর একই উত্তর
     */
    public function payDue(DeliveryChallan|Shipment $paper, array $data): array
    {
        return DB::transaction(fn (): array => $this->payDueLocked($paper, $data));
    }

    /**
     * ⛔ সারি তালা দিয়ে আসল অবস্থা — পুরো ERP অডিট, ৯ অক্টোবর ২০২৬: *"বাকি ভাড়া শোধে তালা নেই — দুবার চাপলে দুবার"*।
     * ⓘ দুই চাপ একসাথে এলে দুটোই "বাকি" দেখত আর বাহককে দুবার টাকা যেত; এখন দ্বিতীয়টা প্রথমটার ভাউচার দেখে থামে।
     *
     * @return array{0: Voucher, 1: bool|string}
     */
    private function payDueLocked(DeliveryChallan|Shipment $paper, array $data): array
    {
        $paper = $paper::query()->lockForUpdate()->findOrFail($paper->id);

        if (! $this->isDue($paper)) {
            throw ValidationException::withMessages(['fare' => __('sales::fare.nothing_due')]);
        }

        [$account, $reference, $payer] = $this->moneyFrom($data);
        $payable = StandardChart::find(StandardChart::TRANSPORT_PAYABLE);
        $narration = $paper instanceof Shipment
            ? __('sales::fare.trip_paid_narration', ['trip' => $paper->document_no, 'by' => $this->payee($paper) ?? '—'])
            : __('sales::fare.paid_narration', ['challan' => $paper->document_no, 'by' => $this->payee($paper) ?? '—']);

        $lines = $this->vouchers->twoLineEntry(Voucher::PAYMENT, (int) $account->id, (int) $payable->id, (string) $paper->transport_cost, $narration);

        // ⓘ দেনাটা বাহকের নামে বসেছিল — মোছেও তাঁর নামেই
        foreach ($lines as $i => $line) {
            if ((int) $line['account_id'] === (int) $payable->id) {
                $lines[$i]['party_type'] = 'supplier';
                $lines[$i]['party_id'] = (int) $paper->carrier_id;
            }
        }

        [$voucher, $state] = app(\App\Modules\Accounts\Services\VoucherWriter::class)->store([
            'type' => Voucher::PAYMENT,
            'trx_date' => now()->toDateString(),
            'branch_id' => $paper->branch_id,
            'party_type' => 'supplier',
            'party_id' => (int) $paper->carrier_id,
            'instrument' => $this->instrumentOf($account),
            'instrument_no' => $reference,
            'narration' => $narration,
            'payee_name' => $this->payee($paper),
            'against_type' => $paper::drillSourceType(),
            'against_id' => $paper->id,
        ], $lines, asDraft: false);

        $paper->forceFill(['fare_voucher_id' => $voucher->id, 'fare_account_id' => $account->id,
            'fare_reference' => $reference, 'fare_payer_id' => $payer])->save();

        return [$voucher, $state];
    }

    /** পরে-দেব ভাড়া এখনো দেওয়া হয়নি — ভাউচার নেই, বা যেটা ছিল সেটা বাতিল */
    public function isDue(DeliveryChallan|Shipment $paper): bool
    {
        // ⓘ চালান পাকা থাকলে; ট্রিপ রওনা হলে — ফিরে এসে বন্ধ হলেও ভাড়া বাকি থাকতে পারে
        $live = $paper instanceof Shipment
            ? in_array($paper->status, DocumentStatus::POSTED, true)
            : $paper->status === DocumentStatus::CONFIRMED;

        if ($paper->fare_rule !== self::RULE || $paper->fare_status !== self::DUE || $paper->carrier_id === null || ! $live) {
            return false;
        }

        return $paper->fare_voucher_id === null
            || (Voucher::query()->whereKey($paper->fare_voucher_id)->value('status') === DocumentStatus::CANCELLED);
    }

    /**
     * চালান পাকা হলে (বা ট্রাক রওনা হলে), এখনই দেওয়া ভাড়ার পূর্ণাঙ্গ খরচ ভাউচার — সই লাগলে খসড়া থাকে, নইলে পাকা।
     *
     * ⓘ ডাকে [[DeliveryChallanService::postTransportCost()]] আর [[bookTrip()]], কেবল নতুন নিয়মের `now` কাগজে।
     */
    public function payOnConfirm(DeliveryChallan|Shipment $paper): ?Voucher
    {
        if ($paper->fare_rule !== self::RULE || $paper->fare_status !== self::NOW || ! $this->isOurs($paper)) {
            return null;
        }

        // ⓘ খাতটা খসড়ার সময় যাচাই হয়েছে; পাকা করেন অন্য হেডার-শাখার কেউ হলেও টিলের খাত "নেই" হবে না (কোম্পানির দেয়াল থাকে)
        $account = Account::query()->withoutGlobalScope('viewed-branch-till')->findOrFail((int) $paper->fare_account_id);
        $expense = StandardChart::find(StandardChart::VEHICLE_HIRE);
        $narration = $this->narration($paper);

        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::EXPENSE,
                'trx_date' => $paper->trx_date instanceof \DateTimeInterface ? $paper->trx_date->format('Y-m-d') : (string) $paper->trx_date,
                'branch_id' => $paper->branch_id,
                'instrument' => $this->instrumentOf($account),
                'instrument_no' => $paper->fare_reference,
                'narration' => $narration,
                'expense_account_id' => $expense->id,
                'payee_name' => $this->payee($paper),
                'against_type' => $paper::drillSourceType(),
                'against_id' => $paper->id,
                'origin' => Voucher::ORIGIN_COUNTER,
            ],
            $this->vouchers->twoLineEntry(Voucher::EXPENSE, (int) $account->id, (int) $expense->id, (string) $paper->transport_cost, $narration),
        );

        // ⓘ ছক বসানো থাকলে অনুরোধ লেখা হয়, ভাউচার খসড়া থাকে — টাকা খাতায় বসে না, কাগজ তবু এগোয় ([[DirectPurchaseService]]-এর একই ধাঁচ)
        if ($this->approval->stopping($voucher) === null) {
            $voucher = $this->vouchers->post($voucher);
        }

        $paper->forceFill(['fare_voucher_id' => $voucher->id])->save();

        return $voucher;
    }

    /**
     * চালান বাতিল বা সম্পাদনায় এখনই-দেওয়া ভাড়ার ভাউচারও বাতিল — উল্টো সারি, মোছা নয়।
     *
     * ⓘ পরে-দেওয়া ভাড়ার দেনা (২১১৬) চালানের নিজের দাখিলা, তাই চালানের সাথেই উল্টায়; বাহককে এর মধ্যে দেওয়া PV থাকে
     * (তখন বাহকের কাছে আমাদের অগ্রিম — খাতায় সত্যি)।
     */
    public function undo(DeliveryChallan|Shipment $paper, string $reason, ?string $onDate = null, ?string $paperNo = null): void
    {
        if ($paper->fare_rule !== self::RULE || $paper->fare_status !== self::NOW || $paper->fare_voucher_id === null) {
            return;
        }

        $voucher = Voucher::query()->find((int) $paper->fare_voucher_id);

        if ($voucher === null || $voucher->isCancelled()) {
            return;
        }

        $this->vouchers->cancel($voucher, $reason, $onDate, $paperNo);
    }

    /**
     * ⭐ ট্রাক রওনা — ট্রিপের ভাড়া খাতায় (সিদ্ধান্ত ঘ): এখনই দিলে EV against ট্রিপ, পরে দিলে Dr ৫২১৭ / Cr ২১১৬ বাহকের নামে
     * ট্রিপের নিজের দাখিলা-নামে ([[TRIP_SOURCE]])। ⓘ ডাকে [[ShipmentService::dispatch()]], একই লেনদেনে।
     */
    public function bookTrip(Shipment $trip): void
    {
        if ($trip->fare_rule !== self::RULE || ! $this->isOurs($trip)) {
            return;
        }

        if ($trip->fare_status === self::NOW) {
            $this->payOnConfirm($trip);

            return;
        }

        // ⛔ পরে-দেব ভাড়ার বাহক খসড়ার পরে মুছে গেলে থামা — নামহীন দেনা নয়
        if ($trip->carrier_id === null) {
            throw ValidationException::withMessages(['carrier_id' => __('sales::fare.later_needs_carrier')]);
        }

        $narration = $this->narration($trip);

        app(PostingEngine::class)->post(
            sourceType: self::TRIP_SOURCE,
            sourceId: (int) $trip->id,
            trxDate: $trip->trx_date,
            lines: [
                ['account_id' => StandardChart::find(StandardChart::VEHICLE_HIRE)->id, 'debit' => (string) $trip->transport_cost, 'narration' => $narration],
                ['account_id' => StandardChart::find(StandardChart::TRANSPORT_PAYABLE)->id, 'credit' => (string) $trip->transport_cost,
                    'party_type' => 'supplier', 'party_id' => (int) $trip->carrier_id, 'narration' => $narration],
            ],
            documentNo: $trip->document_no,
            branchId: $trip->branch_id,
        );
    }

    /**
     * ⭐ ট্রিপ বাতিল — ভাড়াও ফেরে: EV বাতিল, বা দেনার দাখিলা উল্টো (মোছা নয়)। ⓘ বাহককে এর মধ্যে দেওয়া PV থাকে —
     * তখন বাহকের কাছে আমাদের অগ্রিম, খাতায় সত্যি ([[undo()]]-এর একই কারণ)।
     */
    public function undoTrip(Shipment $trip, string $reason): void
    {
        $this->undo($trip, $reason);

        /*
         * ⓘ পরে-দেব দেনা বসে কেবল রওনায় ([[bookTrip()]]) — তাই রওনা হওয়া, পরে-দেব, আমাদের ভাড়ার ট্রিপেই উল্টানোর কিছু
         * আছে। খাতা পড়ে খোঁজা লাগে না; বাতিল দুবার হয় না (সারি তালা দিয়ে [[ShipmentService::cancel()]] দেখে)।
         */
        $booked = $trip->dispatched_at !== null && $trip->fare_rule === self::RULE
            && $trip->fare_status === self::DUE && $this->isOurs($trip);

        if ($booked) {
            app(PostingEngine::class)->reverse(
                sourceType: self::TRIP_SOURCE,
                sourceId: (int) $trip->id,
                reversalDate: now(),
                reason: $reason,
                documentNo: $trip->document_no,
            );
        }
    }

    /**
     * ⛔ ভাড়াওয়ালা ট্রিপে নিজের ভাড়াওয়ালা চালান নয় — একই ট্রাকের ভাড়া দুইবার বসত (সিদ্ধান্ত ঘ)।
     *
     * ⓘ ট্রিপে ভাড়া না থাকলে চালানগুলো নিজের ভাড়া রাখে — আগের ট্রিপ যেমন ছিল।
     *
     * @param  iterable<DeliveryChallan>  $challans
     */
    public function assertOneFarePerTrip(Shipment $trip, iterable $challans): void
    {
        if (! $this->isOurs($trip)) {
            return;
        }

        $own = collect($challans)->filter(fn (DeliveryChallan $c) => $this->isOurs($c))->pluck('document_no');

        if ($own->isNotEmpty()) {
            throw ValidationException::withMessages(['challans' => __('sales::fare.trip_challan_has_fare', ['documents' => $own->implode(', ')])]);
        }
    }

    /** ভাড়াটা কি আমাদের খরচ, আর অঙ্ক আছে কি না — ট্রিপের ভাড়া সবসময় আমাদের (ভাড়ার ট্রাক) */
    public function isOurs(DeliveryChallan|Shipment $paper): bool
    {
        $cost = (string) ($paper->transport_cost ?? '0');

        if (! is_numeric($cost) || bccomp($cost, '0', 4) <= 0) {
            return false;
        }

        return $paper instanceof Shipment || ! in_array($paper->fare_paid_by, ['customer', 'none'], true);
    }

    /** চালানটা কোন চালু, ভাড়াওয়ালা ট্রিপে — থাকলে সেই ট্রিপ */
    private function faredTripOf(DeliveryChallan $challan): ?Shipment
    {
        $tripIds = ShipmentLine::query()->where('delivery_challan_id', $challan->id)->pluck('shipment_id');

        return Shipment::query()->whereIn('id', $tripIds)->where('status', '<>', DocumentStatus::CANCELLED)->get()
            ->first(fn (Shipment $trip) => $this->isOurs($trip));
    }

    /**
     * টাকার খাত, TrxID আর কে দিলেন — এখনই দেওয়া আর পরে দেওয়া, দুই পথের একই নিয়ম।
     *
     * @return array{0: Account, 1: ?string, 2: ?int}
     */
    private function moneyFrom(array $data): array
    {
        $accountId = (int) ($data['fare_account_id'] ?? 0);

        // ⛔ Main Counter আর নিজে থেকে বসে না — খাত বাছতেই হবে
        if ($accountId <= 0) {
            throw ValidationException::withMessages(['fare_account_id' => __('sales::fare.needs_account')]);
        }

        // ⓘ অন্য শাখার টিলের খাত এখানেই "নেই" — খাতের নিজের শাখার দেয়াল ([[MoneyAccountRule::assert()]])
        $account = $this->moneyRule->assert($accountId, false, 'fare_account_id');

        if ($account->isCash()) {
            // ⛔ নগদ — কেবল নিজের টিল, আর দিলেন যিনি লগইন করে আছেন (সিদ্ধান্ত গ)
            if (! CashTill::mayUse(auth()->id(), (int) $account->id)) {
                throw ValidationException::withMessages(['fare_account_id' => __('sales::fare.not_your_till')]);
            }

            return [$account, null, auth()->id()];
        }

        $reference = trim((string) ($data['fare_reference'] ?? ''));

        if (mb_strlen($reference) < 4) {
            throw ValidationException::withMessages(['fare_reference' => __('sales::fare.needs_reference')]);
        }

        $payer = (int) ($data['fare_payer_id'] ?? 0) ?: auth()->id();

        if ($payer !== null && ! User::query()->whereKey($payer)
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->exists()) {
            throw ValidationException::withMessages(['fare_payer_id' => __('sales::fare.unknown_payer')]);
        }

        return [$account, $reference, $payer];
    }

    private function instrumentOf(Account $account): string
    {
        return $account->isCash() ? 'cash' : ($account->isMfs() ? 'mfs' : 'transfer');
    }

    private function narration(DeliveryChallan|Shipment $paper): string
    {
        if ($paper instanceof Shipment) {
            $paper->loadMissing('lines.challan');

            return __('sales::fare.trip_narration', [
                'trip' => $paper->document_no,
                'challans' => $paper->lines->map(fn (ShipmentLine $l) => $l->challan?->document_no)->filter()->implode(', ') ?: '—',
                'vehicle' => $paper->vehiclePlate() ?: '—',
                'by' => $this->payee($paper) ?? '—',
            ]);
        }

        return __('sales::fare.narration', [
            'challan' => $paper->document_no,
            'vehicle' => $paper->vehicle_no ?: ($paper->vehicle?->registration_no ?? '—'),
            'by' => $this->payee($paper) ?? '—',
        ]);
    }

    private function payee(DeliveryChallan|Shipment $paper): ?string
    {
        return $paper->carrier?->name() ?? ($paper->carrier_name ?: ($paper->driver_name ?: null));
    }
}
