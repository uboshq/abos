<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\InsuranceClaim;
use App\Modules\Finance\Models\InsurancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ বীমার দাবির খাতা — অর্থ-মডিউলের পরিকল্পনা ৬.৪, ৬ অক্টোবর ২০২৬ (সমন্বয়কের উত্তর প্র৩: খাতায় কেবল টাকা এলে বা
 * লিখিত অনুমোদনে, IAS 37; জমা দেওয়া দাবি তালিকায়)।
 *
 * ── ⭐ পাঁচ ধাপ ──────────────────────────────────────────────────────────────
 *   · জমা ([[lodge()]]) — খাতায় কিছু নয়; দাবিটা কেবল তালিকায়, "সম্ভাব্য সম্পদ"
 *   · লিখিত অনুমোদন ([[approve()]]) — চিঠির নম্বর ছাড়া নয়; অনুমোদিত অঙ্কের যা এখনো আসেনি:
 *     Dr 1152 বীমা দাবি প্রাপ্য / Cr 4370 বীমা দাবি আদায়, অনুমোদনের দিনে
 *   · টাকা এল ([[receive()]]) — রসিদ ভাউচার দাবির বিপরীতে: Dr টাকার খাত / Cr 1152 (অনুমোদন খাতায় থাকলে) বা 4370;
 *     ভাউচারের পর্দা থেকে সরাসরি লেখা রসিদও দাবি নিজে আবার গোনে ([[refresh()]])
 *   · বন্ধ ([[close()]]) — বাকিটা আর আসবে না: অনুমোদনের না-আসা অংশ উল্টো (Dr 4370 / Cr 1152)
 *   · নাকচ ([[reject()]]) — কিছুই আসেনি; অনুমোদন খাতায় থাকলে পুরোটা উল্টো
 *
 * ⛔ অনুমোদন, টাকা আসা আর বন্ধ — তিনটা দাখিলাই অর্থের সইয়ের ছকে ([[FinanceSignature::INSURANCE_CLAIM]]; পুরো-ERP পুনঃঅডিট,
 * ৯ অক্টোবর ২০২৬)। ⓘ আগে অনুমোদন আর বন্ধ সই ছাড়াই খাতায় বসত, আর নগদে টাকা এলে হিসাবের রসিদের ছাড় (নিজের বাক্সে নগদ)
 * খাটায় সেটাও — বীমার টাকা কারও সই ছাড়াই আয় হত। ছক থাকলে খসড়া, শেষ সই [[finishSigned()]] খাতায় বসায়, "না" হলে
 * [[dropRefused()]] দাবিটা আগের অবস্থায় ফেরায়। কোম্পানির সব ছক বন্ধ থাকলে বাকি অ্যাপের মতোই সাথে সাথে খাতায়।
 * সই বাকি থাকলে দাবিতে আর কিছু বসে না ([[assertNothingWaiting()]])।
 */
final class InsuranceClaimService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly FinanceSignature $signature,
    ) {}

    /** @param  array{claim_no?: string|null, incident_on: string, claimed_on: string, incident: string, claimed_amount: string|int|float}  $data */
    public function lodge(InsurancePolicy $policy, array $data): InsuranceClaim
    {
        if (bccomp((string) $data['claimed_amount'], '0', 4) <= 0) {
            throw ValidationException::withMessages(['claimed_amount' => __('finance::insurance_claim.amount_positive')]);
        }

        return InsuranceClaim::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => $policy->branch_id,
            'policy_id' => $policy->id,
            'claim_no' => trim((string) ($data['claim_no'] ?? '')) ?: null,
            'incident_on' => $data['incident_on'],
            'claimed_on' => $data['claimed_on'],
            'incident' => trim($data['incident']),
            'claimed_amount' => $data['claimed_amount'],
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * ⭐ লিখিত অনুমোদন — অনুমোদিত অঙ্কের যা এখনো আসেনি, প্রাপ্য হিসেবে খাতায়।
     *
     * @param  array{approved_amount: string|int|float, approved_on: string, approval_ref: string}  $data
     */
    public function approve(InsuranceClaim $claim, array $data): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $data): InsuranceClaim {
            $this->lockFresh($claim);
            $amount = bcadd((string) $data['approved_amount'], '0', 4);
            $ref = trim((string) ($data['approval_ref'] ?? ''));

            if (! $claim->isOpen() || $claim->approved_amount !== null) {
                throw ValidationException::withMessages(['approved_amount' => __('finance::insurance_claim.not_open_for_approval')]);
            }

            // ⛔ অপেক্ষার রসিদ থাকলে নয় — অনুমোদনের পরে রসিদের খাত বদলায় (৪৩৭০ → ১১৫২), সই পড়লে রসিদটা ভুল খাতে আটকাত
            $this->assertNothingWaiting($claim, 'approved_amount');

            if ($ref === '') {
                throw ValidationException::withMessages(['approval_ref' => __('finance::insurance_claim.approval_ref_required')]);
            }

            if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, (string) $claim->claimed_amount, 4) > 0
                || bccomp($amount, (string) $claim->received_amount, 4) < 0) {
                throw ValidationException::withMessages(['approved_amount' => __('finance::insurance_claim.approved_out_of_range', [
                    'received' => Money::format($claim->received_amount),
                    'claimed' => Money::format($claim->claimed_amount),
                ])]);
            }

            $book = bcsub($amount, (string) $claim->received_amount, 2);
            $voucherId = null;

            if (bccomp($book, '0', 2) > 0) {
                $voucherId = $this->journal($claim, $data['approved_on'], $book, receivable: true,
                    narration: __('finance::insurance_claim.approval_narration', ['claim' => $this->label($claim), 'ref' => $ref]));
            }

            $claim->forceFill([
                'approved_amount' => $amount,
                'approved_on' => $data['approved_on'],
                'approval_ref' => $ref,
                'approval_voucher_id' => $voucherId,
            ])->save();

            return $this->refresh($claim);
        });
    }

    /**
     * ⭐ টাকা এল — দাবির বিপরীতে রসিদ ভাউচার, হিসাবের রসিদের সইয়ের নিয়মে।
     *
     * @param  array{money_account_id: int|string, amount: string|int|float, received_on: string, instrument_no?: string|null}  $data
     * @return array{voucher: Voucher, held: bool}
     */
    public function receive(InsuranceClaim $claim, array $data): array
    {
        return DB::transaction(function () use ($claim, $data): array {
            $this->lockFresh($claim);
            $amount = bcadd((string) $data['amount'], '0', 2);

            if (! $claim->isOpen()) {
                throw ValidationException::withMessages(['amount' => __('finance::insurance_claim.not_open')]);
            }

            // ⛔ অনুমোদন বা আগের রসিদ সইয়ের অপেক্ষায় থাকলে নয় — খাত আর বাকি তখনো ঠিক নয়
            $this->assertNothingWaiting($claim, 'amount');

            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $claim->outstanding(), 4) > 0) {
                throw ValidationException::withMessages(['amount' => __('finance::insurance_claim.amount_over', [
                    'left' => Money::format($claim->outstanding()),
                ])]);
            }

            $money = $this->signature->moneyAccount($data['money_account_id']);

            $voucher = $this->vouchers->create([
                'type' => Voucher::RECEIPT,
                // ⛔ দাবির শাখায় — হেডারের শাখায় নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬): ১১৫২ নইলে শাখা ধরে কখনো মিলত না
                'branch_id' => $claim->branch_id,
                'trx_date' => $data['received_on'],
                'narration' => __('finance::insurance_claim.receipt_narration', ['claim' => $this->label($claim)]),
                'instrument_no' => trim((string) ($data['instrument_no'] ?? '')) ?: null,
                'against_type' => InsuranceClaim::drillSourceType(),
                'against_id' => $claim->id,
            ], [
                ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->expectedAccount($claim)->id, 'debit' => '0', 'credit' => $amount],
            ]);

            // ⛔ অর্থের সই — ছক থাকলে খসড়া; পোস্ট হলে দাবি নিজে আবার গোনে ([[InsuranceClaim::settleWith()]])
            $held = $this->signature->postOrHold($voucher, FinanceSignature::INSURANCE_CLAIM, $amount);

            return ['voucher' => $voucher->fresh(), 'held' => $held];
        });
    }

    /** ⭐ বাকিটা আর আসবে না — অনুমোদনের না-আসা অংশ উল্টো, দাবি নিষ্পন্ন */
    public function close(InsuranceClaim $claim, string $note, string $on): InsuranceClaim
    {
        return $this->finish($claim, $note, $on, InsuranceClaim::SETTLED);
    }

    /** ⭐ নাকচ — কিছুই আসেনি; অনুমোদন খাতায় থাকলে পুরোটা উল্টো */
    public function reject(InsuranceClaim $claim, string $note, string $on): InsuranceClaim
    {
        return $this->finish($claim, $note, $on, InsuranceClaim::REJECTED);
    }

    /**
     * ⭐ পাওয়া টাকা আবার গোনা — দাবির বিপরীতে খাতায় বসা রসিদগুলোর 1152/4370-এর ক্রেডিট থেকে; অবস্থাও সেখান থেকে।
     *
     * ⛔ `$including` (এখন পোস্ট হচ্ছে এমন রসিদ) ভুল খাতে টাকা নিলে থামে — অনুমোদন খাতায় থাকলে 1152, না থাকলে 4370;
     * পোস্টিংয়ের একই লেনদেনে, তাই রসিদটাও খাতায় বসে না। ⓘ `$excluding` — বাতিল হচ্ছে এমন রসিদ।
     */
    public function refresh(InsuranceClaim $claim, ?int $including = null, ?int $excluding = null): InsuranceClaim
    {
        $this->lockFresh($claim);
        $claimHeads = [$this->account(StandardChart::INSURANCE_CLAIM_RECEIVABLE)->id, $this->account(StandardChart::INSURANCE_CLAIM_INCOME)->id];

        if ($including !== null) {
            $this->assertRightHead($claim, $including);
        }

        $receipts = Voucher::query()
            ->where('against_type', InsuranceClaim::drillSourceType())
            ->where('against_id', $claim->id)
            ->where('type', Voucher::RECEIPT)
            ->where(fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)
                ->when($including !== null, fn ($q) => $q->orWhere('id', $including)))
            ->when($excluding !== null, fn ($q) => $q->where('id', '!=', $excluding))
            ->with('lines')
            ->get();

        $received = '0';
        $lastOn = null;

        foreach ($receipts as $receipt) {
            foreach ($receipt->lines as $line) {
                if (in_array((int) $line->account_id, $claimHeads, true)) {
                    $received = bcadd($received, bcsub((string) $line->credit, (string) $line->debit, 4), 4);
                }
            }

            $lastOn = $lastOn === null || $receipt->trx_date->gt($lastOn) ? $receipt->trx_date : $lastOn;
        }

        $claim->forceFill(['received_amount' => $received, 'received_on' => $lastOn?->toDateString()]);

        // ⓘ বন্ধ বা নাকচ দাবি নিজের অবস্থায় থাকে — সেটা মানুষের সিদ্ধান্ত, রসিদের গোনা নয়
        if ($claim->closed_on === null) {
            $claim->status = match (true) {
                bccomp($received, '0', 4) > 0 && bccomp($received, $claim->target(), 4) >= 0 => InsuranceClaim::SETTLED,
                bccomp($received, '0', 4) > 0 => InsuranceClaim::PARTIAL,
                $claim->approved_amount !== null => InsuranceClaim::APPROVED,
                default => InsuranceClaim::LODGED,
            };
        }

        $claim->save();

        return $claim;
    }

    /** রসিদ কোন খাতে টাকা নেবে — অনুমোদন খাতায় থাকলে প্রাপ্য, না থাকলে আয় */
    public function expectedAccount(InsuranceClaim $claim): Account
    {
        return $this->account($claim->approval_voucher_id !== null
            ? StandardChart::INSURANCE_CLAIM_RECEIVABLE
            : StandardChart::INSURANCE_CLAIM_INCOME);
    }

    private function finish(InsuranceClaim $claim, string $note, string $on, string $status): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $note, $on, $status): InsuranceClaim {
            $this->lockFresh($claim);
            $note = trim($note);

            if (! $claim->isOpen()) {
                throw ValidationException::withMessages(['close_note' => __('finance::insurance_claim.not_open')]);
            }

            // ⛔ সইয়ের অপেক্ষার রসিদ বা অনুমোদন থাকলে বন্ধ নয় — সই পড়লে টাকাটা একটা বন্ধ দাবিতে ঢুকত
            $this->assertNothingWaiting($claim, 'close_note');

            if ($note === '') {
                throw ValidationException::withMessages(['close_note' => __('finance::insurance_claim.close_note_required')]);
            }

            if ($status === InsuranceClaim::REJECTED && bccomp((string) $claim->received_amount, '0', 4) > 0) {
                throw ValidationException::withMessages(['close_note' => __('finance::insurance_claim.reject_after_money')]);
            }

            // ⓘ অনুমোদনে খাতায় ওঠা প্রাপ্যের যা আসেনি — উল্টো; অনুমোদন না থাকলে খাতায় কিছুই নেই
            $voucherId = null;

            if ($claim->approval_voucher_id !== null) {
                $left = bcsub((string) $claim->approved_amount, (string) $claim->received_amount, 2);

                if (bccomp($left, '0', 2) > 0) {
                    $voucherId = $this->journal($claim, $on, $left, receivable: false,
                        narration: __('finance::insurance_claim.close_narration', ['claim' => $this->label($claim), 'note' => $note]));
                }
            }

            $claim->forceFill([
                'status' => $status,
                'closed_on' => $on,
                'close_note' => $note,
                'close_voucher_id' => $voucherId,
            ])->save();

            return $claim;
        });
    }

    /**
     * ⛔ রসিদের অ-টাকার ক্রেডিট সারি সবই প্রত্যাশিত খাতে — নইলে ভাউচারের পর্দা থেকে লেখা রসিদ 4100 বিক্রয় বা অন্য
     * খাতে টাকা নিয়ে দাবি "পাওয়া" দেখাত, অথচ প্রাপ্যটা খাতায় ঝুলে থাকত।
     */
    private function assertRightHead(InsuranceClaim $claim, int $voucherId): void
    {
        $expected = $this->expectedAccount($claim);

        $wrong = VoucherLine::query()->where('voucher_id', $voucherId)
            ->where('credit', '>', 0)
            ->whereHas('account', fn ($q) => $q->whereNull('money_kind'))
            ->where('account_id', '!=', $expected->id)
            ->exists();

        if ($wrong) {
            throw ValidationException::withMessages(['against_id' => __('finance::insurance_claim.wrong_head', [
                'code' => $expected->code, 'name' => $expected->name(),
            ])]);
        }
    }

    /** অনুমোদন (Dr 1152 / Cr 4370) বা তার উল্টো (Dr 4370 / Cr 1152) — অর্থের সইয়ের ছকে; ছক থাকলে খসড়া */
    private function journal(InsuranceClaim $claim, string $on, string $amount, bool $receivable, string $narration): int
    {
        $due = $this->account(StandardChart::INSURANCE_CLAIM_RECEIVABLE);
        $income = $this->account(StandardChart::INSURANCE_CLAIM_INCOME);

        $voucher = $this->vouchers->create([
            'type' => Voucher::JOURNAL,
            // ⛔ দাবির শাখায় — অনুমোদন আর বন্ধ একই শাখায় বসে, যে শাখাতেই হেডার থাকুক (পুনঃঅডিট, ৯ অক্টোবর ২০২৬)
            'branch_id' => $claim->branch_id,
            'trx_date' => $on,
            'narration' => $narration,
        ], [
            ['account_id' => ($receivable ? $due : $income)->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => ($receivable ? $income : $due)->id, 'debit' => '0', 'credit' => $amount],
        ]);

        // ⛔ সই ছাড়া খাতায় নয় — পুনঃঅডিট, ৯ অক্টোবর ২০২৬; শেষ সই [[finishSigned()]] বসায়
        $this->signature->postOrHold($voucher, FinanceSignature::INSURANCE_CLAIM, $amount);

        return (int) $voucher->id;
    }

    /** ⭐ শেষ সই পড়ল — অপেক্ষার দাখিলা খাতায় ([[FinishTheFinancePaperOnTheLastSignature]]); দুইবার খবর এলেও একবার */
    public function finishSigned(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $claim = $this->claimOf($voucher);

            if ($claim !== null) {
                $this->lockFresh($claim);
            }

            $this->lockFresh($voucher);

            // ⓘ রসিদ পোস্ট হলে দাবি নিজে আবার গোনে ([[InsuranceClaim::settleWith()]]); অনুমোদন আর বন্ধের সারি আগেই বসানো
            if ($voucher->isDraft()) {
                $this->vouchers->post($voucher);
            }
        });
    }

    /**
     * ⭐ সইকারী "না" বললেন — খসড়া বাতিল, আর দাবি আগের অবস্থায়: অনুমোদন "না" হলে অনুমোদনের ঘরগুলো খালি, বন্ধ "না" হলে
     * দাবি আবার খোলা, রসিদ "না" হলে পাওয়া টাকা আবার গোনা।
     */
    public function dropRefused(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $claim = $this->claimOf($voucher);

            if ($claim !== null) {
                $this->lockFresh($claim);
            }

            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->cancel($voucher, $reason);

            if ($claim === null) {
                return;
            }

            if ((int) $claim->approval_voucher_id === (int) $voucher->id) {
                $claim->forceFill(['approved_amount' => null, 'approved_on' => null, 'approval_ref' => null, 'approval_voucher_id' => null]);
            }

            if ((int) $claim->close_voucher_id === (int) $voucher->id) {
                $claim->forceFill(['closed_on' => null, 'close_note' => null, 'close_voucher_id' => null]);
            }

            $claim->save();
            $this->refresh($claim, excluding: (int) $voucher->id);
        });
    }

    /** ভাউচারটা কোন দাবির — রসিদ বিপরীত ধরে, অনুমোদন বা বন্ধের দাখিলা দাবির নিজের ঘর ধরে */
    private function claimOf(Voucher $voucher): ?InsuranceClaim
    {
        if ($voucher->against_type === InsuranceClaim::drillSourceType()) {
            return InsuranceClaim::query()->find($voucher->against_id);
        }

        return InsuranceClaim::query()
            ->where(fn ($q) => $q->where('approval_voucher_id', $voucher->id)->orWhere('close_voucher_id', $voucher->id))
            ->first();
    }

    /**
     * ⛔ এই দাবির কোনো দাখিলা সইয়ের অপেক্ষায় থাকলে আরেকটা নয় — ভাড়ার চুক্তির একই নিয়ম।
     *
     * ⓘ অপেক্ষা মানে: অনুমোদন বা বন্ধের খসড়া দাখিলা, বা দাবির বিপরীতে এমন খসড়া রসিদ যার সই চাওয়া হয়েছে। ভাউচারের পর্দায়
     * ফেলে রাখা সাধারণ খসড়া দাবি আটকায় না। শাখার দেয়াল ছাড়া খোঁজা — হেডারে অন্য শাখা থাকলে অপেক্ষার ভাউচারটা চোখের
     * আড়ালে থেকে পাহারা ফাঁকি দিত।
     */
    private function assertNothingWaiting(InsuranceClaim $claim, string $field): void
    {
        $own = array_values(array_filter([(int) $claim->approval_voucher_id, (int) $claim->close_voucher_id]));

        $asked = Approval::query()->where('approvable_type', Voucher::class)->where('status', Approval::PENDING)->select('approvable_id');

        $waiting = Voucher::query()->withoutGlobalScope('user-branch')
            ->where('status', DocumentStatus::DRAFT)
            ->where(fn ($q) => $q
                ->where(fn ($v) => $v->where('against_type', InsuranceClaim::drillSourceType())->where('against_id', $claim->id)->whereIn('id', $asked))
                ->when($own !== [], fn ($v) => $v->orWhereIn('id', $own)))
            ->exists();

        if ($waiting) {
            throw ValidationException::withMessages([$field => __('finance::validation.awaits_signature_first')]);
        }
    }

    private function label(InsuranceClaim $claim): string
    {
        return trim(($claim->policy?->policy_no ?? '').' · '.($claim->claim_no ?? '').' · '.$claim->incident_on->format('d/m/Y'), ' ·');
    }

    /** ছকের খাত — না থাকলে (পুরনো কোম্পানি) ছক একবার বসিয়ে নেয় */
    private function account(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find($code);
        }

        if ($account === null) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.chart_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }
}
