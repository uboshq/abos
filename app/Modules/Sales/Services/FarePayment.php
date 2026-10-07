<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\MoneyAccountRule;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Sales\Models\DeliveryChallan;
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
 * ⓘ "কে দিলেন" — নগদে লগইন করা মানুষ, বদলানো যায় না (তার টিল থেকেই টাকা বেরোয়); ব্যাংক বা MFS-এ কোম্পানির যেকোনো
 * মানুষ (সিদ্ধান্ত গ)। ⓘ পুরনো কাগজ (`fare_rule` null) আগের পথেই চলে — এই সেবা তাদের ছোঁয় না।
 */
final class FarePayment
{
    public const RULE = 'voucher';

    public const NOW = 'now';

    public const DUE = 'due';

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly VoucherApproval $approval,
        private readonly MoneyAccountRule $moneyRule,
    ) {}

    /**
     * দরজা থেকে আসা ঘরগুলো যাচাই করে চালানে বসানো — চালান পাকা হওয়ার **আগে** (খসড়ায়)।
     *
     * ⓘ ভাড়া আমাদের না হলে (গ্রাহক দেবে, ভাড়া নেই) বা অঙ্ক শূন্য হলে কিছুই বসে না।
     *
     * @param  array{fare_when?: ?string, fare_account_id?: mixed, fare_reference?: ?string, fare_payer_id?: mixed}  $data
     */
    public function stamp(DeliveryChallan $challan, array $data): void
    {
        if (! $this->isOurs($challan)) {
            $challan->forceFill(['fare_rule' => null, 'fare_status' => null, 'fare_account_id' => null,
                'fare_reference' => null, 'fare_payer_id' => null])->save();

            return;
        }

        if (($data['fare_when'] ?? self::NOW) === 'later') {
            // ⛔ পরে দেব মানে কারো কাছে দেনা — কার, সেটা না জানলে খাতায় নামহীন দেনা বসত (সিদ্ধান্ত খ)
            if ($challan->carrier_id === null) {
                throw ValidationException::withMessages(['carrier_id' => __('sales::fare.later_needs_carrier')]);
            }

            $challan->forceFill(['fare_rule' => self::RULE, 'fare_status' => self::DUE, 'fare_account_id' => null,
                'fare_reference' => null, 'fare_payer_id' => null])->save();

            return;
        }

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

            $payer = auth()->id();
            $reference = null;
        } else {
            $reference = trim((string) ($data['fare_reference'] ?? ''));

            if (mb_strlen($reference) < 4) {
                throw ValidationException::withMessages(['fare_reference' => __('sales::fare.needs_reference')]);
            }

            $payer = (int) ($data['fare_payer_id'] ?? 0) ?: auth()->id();

            if ($payer !== null && ! User::query()->whereKey($payer)
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))->exists()) {
                throw ValidationException::withMessages(['fare_payer_id' => __('sales::fare.unknown_payer')]);
            }
        }

        $challan->forceFill([
            'fare_rule' => self::RULE, 'fare_status' => self::NOW, 'fare_account_id' => $account->id,
            'fare_reference' => $reference, 'fare_payer_id' => $payer,
        ])->save();
    }

    /**
     * চালান পাকা হলে, এখনই দেওয়া ভাড়ার পূর্ণাঙ্গ খরচ ভাউচার — সই লাগলে খসড়া থাকে, নইলে পাকা।
     *
     * ⓘ ডাকে [[DeliveryChallanService::postTransportCost()]], কেবল নতুন নিয়মের `now` চালানে।
     */
    public function payOnConfirm(DeliveryChallan $challan): ?Voucher
    {
        if ($challan->fare_rule !== self::RULE || $challan->fare_status !== self::NOW || ! $this->isOurs($challan)) {
            return null;
        }

        // ⓘ খাতটা খসড়ার সময় যাচাই হয়েছে; পাকা করেন অন্য হেডার-শাখার কেউ হলেও টিলের খাত "নেই" হবে না (কোম্পানির দেয়াল থাকে)
        $account = Account::query()->withoutGlobalScope('viewed-branch-till')->findOrFail((int) $challan->fare_account_id);
        $expense = StandardChart::find(StandardChart::VEHICLE_HIRE);
        $narration = $this->narration($challan);

        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::EXPENSE,
                'trx_date' => $challan->trx_date instanceof \DateTimeInterface ? $challan->trx_date->format('Y-m-d') : (string) $challan->trx_date,
                'branch_id' => $challan->branch_id,
                'instrument' => $account->isCash() ? 'cash' : ($account->isMfs() ? 'mfs' : 'transfer'),
                'instrument_no' => $challan->fare_reference,
                'narration' => $narration,
                'expense_account_id' => $expense->id,
                'payee_name' => $this->payee($challan),
                'against_type' => DeliveryChallan::drillSourceType(),
                'against_id' => $challan->id,
                'origin' => Voucher::ORIGIN_COUNTER,
            ],
            $this->vouchers->twoLineEntry(Voucher::EXPENSE, (int) $account->id, (int) $expense->id, (string) $challan->transport_cost, $narration),
        );

        // ⓘ ছক বসানো থাকলে অনুরোধ লেখা হয়, ভাউচার খসড়া থাকে — টাকা খাতায় বসে না, চালান তবু এগোয় ([[DirectPurchaseService]]-এর একই ধাঁচ)
        if ($this->approval->stopping($voucher) === null) {
            $voucher = $this->vouchers->post($voucher);
        }

        $challan->forceFill(['fare_voucher_id' => $voucher->id])->save();

        return $voucher;
    }

    /**
     * চালান বাতিল বা সম্পাদনায় এখনই-দেওয়া ভাড়ার ভাউচারও বাতিল — উল্টো সারি, মোছা নয়।
     *
     * ⓘ পরে-দেওয়া ভাড়ার দেনা (২১১৬) চালানের নিজের দাখিলা, তাই চালানের সাথেই উল্টায়; বাহককে এর মধ্যে দেওয়া PV থাকে
     * (তখন বাহকের কাছে আমাদের অগ্রিম — খাতায় সত্যি)।
     */
    public function undo(DeliveryChallan $challan, string $reason, ?string $onDate = null, ?string $paperNo = null): void
    {
        if ($challan->fare_rule !== self::RULE || $challan->fare_status !== self::NOW || $challan->fare_voucher_id === null) {
            return;
        }

        $voucher = Voucher::query()->find((int) $challan->fare_voucher_id);

        if ($voucher === null || $voucher->isCancelled()) {
            return;
        }

        $this->vouchers->cancel($voucher, $reason, $onDate, $paperNo);
    }

    /** ভাড়াটা কি আমাদের খরচ, আর অঙ্ক আছে কি না */
    public function isOurs(DeliveryChallan $challan): bool
    {
        $cost = (string) ($challan->transport_cost ?? '0');

        return is_numeric($cost) && bccomp($cost, '0', 4) > 0
            && ! in_array($challan->fare_paid_by, ['customer', 'none'], true);
    }

    private function narration(DeliveryChallan $challan): string
    {
        return __('sales::fare.narration', [
            'challan' => $challan->document_no,
            'vehicle' => $challan->vehicle_no ?: ($challan->vehicle?->registration_no ?? '—'),
            'by' => $this->payee($challan) ?? '—',
        ]);
    }

    private function payee(DeliveryChallan $challan): ?string
    {
        return $challan->carrier?->name() ?? ($challan->carrier_name ?: ($challan->driver_name ?: null));
    }
}
