<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * জমা — টাকা সরিয়ে রাখা, আর তার হিসাব।
 *
 * ── কোন দাখিলা কখন, এক নজরে ──────────────────────────────────────────
 * চারটা ঘটনা, আর "কার নামে" ঘরটা প্রতিটার অর্ধেক ঠিক করে:
 *
 *   খোলা / কিস্তি   ব্যবসার নামে → Dr ১১৬০ জমা      · Cr নগদ/ব্যাংক
 *                   মালিকের নামে → Dr ৩২০০ উত্তোলন · Cr নগদ/ব্যাংক
 *
 *   মুনাফা তোলা     ব্যবসার নামে → Dr নগদ/ব্যাংক · Cr ৪৩১০ মুনাফা আয়
 *                   মালিকের নামে → Dr নগদ/ব্যাংক · Cr ৩২০০ উত্তোলন
 *
 *   ভাঙা / মেয়াদ    ব্যবসার নামে → Dr নগদ · Cr ১১৬০ মূলধন · Cr ৪৩১০ বাড়তি
 *                   মালিকের নামে → Dr নগদ · Cr ৩২০০ পুরোটা
 *
 * ── মালিকের কাগজের মুনাফা কেন উত্তোলন কমায় ───────────────────────────
 * সঞ্চয়পত্রটা মালিকের, তাই মুনাফাটাও মালিকের। সেটা ব্যবসার হিসাবে
 * জমা পড়া মানে মালিক নিজের টাকা ব্যবসায় ঢোকাচ্ছেন — অর্থাৎ তাঁর
 * উত্তোলন কমছে।
 *
 * আয় হিসেবে লিখলে ব্যবসার মুনাফা ফুলে যেত এমন টাকায় যা ব্যবসা কখনো
 * উপার্জন করেনি — আর ওই মুনাফার উপর কর বসত।
 */
final class DepositService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * টাকা রাখা হলো।
     *
     * ── কেন এখানে খসড়া নেই, মূলধনে ছিল ──────────────────────────────
     * মূলধনে কথা আর টাকা প্রায়ই আলাদা দিনে — "মালিক পাঁচ লাখ দেবেন"।
     * জমায় ওটা নেই: ব্যাংকের রসিদ হাতে না এলে কেউ FD খুলেছে বলে না।
     * খসড়া রাখলে সেটা এমন একটা ধাপ হত যা সবাই এড়িয়ে যেত।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): Deposit
    {
        return DB::transaction(function () use ($data) {
            $kind = DepositKind::query()->findOrFail($data['kind_id']);
            $from = $this->money($data['funded_from_account_id']);

            $this->assertSane($kind, $data);

            $deposit = Deposit::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('DEP'),
                'kind_id' => $kind->id,
                'institution' => trim((string) $data['institution']),
                'branch_name' => ($data['branch_name'] ?? '') ?: null,
                'reference_no' => ($data['reference_no'] ?? '') ?: null,
                'held_by' => $data['held_by'],
                'holder_name' => ($data['holder_name'] ?? '') ?: null,
                'principal' => $data['principal'],
                'profit_rate' => ($data['profit_rate'] ?? '') !== '' ? $data['profit_rate'] : null,
                'return_word' => $data['return_word'] ?? 'interest',
                'opened_on' => $data['opened_on'],
                'matures_on' => ($data['matures_on'] ?? '') ?: null,
                'instalment_amount' => $kind->takesInstalments() && ($data['instalment_amount'] ?? '') !== ''
                    ? $data['instalment_amount'] : null,
                'instalment_day' => $kind->takesInstalments() && ($data['instalment_day'] ?? '') !== ''
                    ? $data['instalment_day'] : null,
                'payout_account_id' => $kind->paysOut() ? ($data['payout_account_id'] ?? null) : null,
                'account_id' => $this->assetHead($data['held_by'])->id,
                'funded_from_account_id' => $from->id,
                'status' => Deposit::ACTIVE,
                'created_by' => auth()->id(),
            ]);

            $this->putMoneyIn($deposit, DepositMovement::OPENED, (string) $data['principal'], $from,
                (string) $data['opened_on']);

            return $deposit->fresh();
        });
    }

    /**
     * মাসের কিস্তি।
     *
     * ── কেন মূলধনটা সারিতে বাড়ে, চলাচল যোগ করে বের করা হয় না ────────
     * প্রতিবার যোগ করে বের করলে DPS-এর পাতা খুলতে ষাটটা সারি পড়তে হত,
     * আর তালিকার পর্দায় প্রতিটা DPS-এর জন্য একবার করে। সারিতে রাখা
     * সংখ্যাটা একই লেনদেনে বাড়ে, তাই দুইটা কোনোদিন আলাদা হয় না।
     *
     * @param  array<string, mixed>  $data
     */
    public function instalment(Deposit $deposit, array $data): DepositMovement
    {
        $this->assertOpen($deposit);

        if (! $deposit->kind->takesInstalments()) {
            throw ValidationException::withMessages([
                'amount' => __('finance::validation.deposit_takes_no_instalment'),
            ]);
        }

        return DB::transaction(function () use ($deposit, $data) {
            $from = $this->money($data['money_account_id']);

            $movement = $this->putMoneyIn($deposit, DepositMovement::INSTALMENT,
                (string) $data['amount'], $from, (string) $data['moved_on'], $data['note'] ?? null);

            $deposit->forceFill([
                'principal' => bcadd((string) $deposit->principal, (string) $data['amount'], 4),
            ])->save();

            return $movement;
        });
    }

    /**
     * মুনাফা তোলা — মূলধন বাড়ে না।
     *
     * @param  array<string, mixed>  $data
     */
    public function payout(Deposit $deposit, array $data): DepositMovement
    {
        $this->assertOpen($deposit);

        return DB::transaction(function () use ($deposit, $data) {
            $into = $this->money($data['money_account_id']);
            $amount = (string) $data['amount'];

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::RECEIPT,
                    'trx_date' => (string) $data['moved_on'],
                    'narration' => $data['note'] ?? __('finance::message.deposit_payout_narration', [
                        'no' => $deposit->document_no,
                        'where' => $deposit->institution,
                    ]),
                ],
                [
                    ['account_id' => $into->id, 'debit' => $amount, 'credit' => '0'],
                    ['account_id' => $this->returnHead($deposit)->id, 'debit' => '0', 'credit' => $amount],
                ],
            );

            $this->vouchers->post($voucher);

            return $this->write($deposit, DepositMovement::PAYOUT, $amount, $into,
                (string) $data['moved_on'], $voucher->id, $data['note'] ?? null);
        });
    }

    /**
     * মেয়াদ শেষ, বা ভেঙে ফেলা।
     *
     * ── কেন প্রাপ্ত টাকাটা জিজ্ঞেস করা হয়, হিসাব করা হয় না ───────────
     * মেয়াদান্তে কত আসবে সেটা সূত্রে বসানো যায়, কিন্তু ব্যাংক যা দেয়
     * তা প্রায়ই আলাদা — উৎসে কর কাটা, আবগারি শুল্ক, আগে ভাঙলে জরিমানা।
     * হিসাব করে বসালে খাতা ব্যাংকের কাগজের সাথে মিলত না, আর প্রতিবার
     * কেউ হাতে সংশোধনী দিত।
     *
     * বাড়তিটা মুনাফা, ঘাটতিটা খরচ — দুইটাই সেদিনের সত্যি।
     *
     * @param  array<string, mixed>  $data
     */
    public function close(Deposit $deposit, array $data): DepositMovement
    {
        $this->assertOpen($deposit);

        return DB::transaction(function () use ($deposit, $data) {
            $into = $this->money($data['money_account_id']);
            $received = (string) $data['amount'];
            $principal = (string) $deposit->principal;

            $lines = [['account_id' => $into->id, 'debit' => $received, 'credit' => '0']];

            if ($deposit->isBusinessAsset()) {
                /* মূলধনটা সম্পদ খাত থেকে বেরিয়ে যায়, যতটা বসেছিল ততটাই */
                $lines[] = ['account_id' => $this->assetHead(Deposit::BUSINESS)->id,
                    'debit' => '0', 'credit' => $principal];

                $extra = bcsub($received, $principal, 4);

                if (bccomp($extra, '0', 4) > 0) {
                    $lines[] = ['account_id' => $this->returnHead($deposit)->id,
                        'debit' => '0', 'credit' => $extra];
                }

                /*
                 * কম ফেরত এল — আগে ভাঙার জরিমানা, বা উৎসে কাটা কর।
                 * সুদ ব্যয়ে বসে, কারণ ওটাও টাকার দাম।
                 */
                if (bccomp($extra, '0', 4) < 0) {
                    $lines[] = ['account_id' => $this->head(StandardChart::INTEREST_EXPENSE)->id,
                        'debit' => bcmul($extra, '-1', 4), 'credit' => '0'];
                }
            } else {
                /*
                 * মালিকের কাগজ — মূলধনও মুনাফাও দুইটাই তাঁর। পুরোটা
                 * ব্যবসায় ঢুকলে তাঁর উত্তোলন ততটাই কমে।
                 */
                $lines[] = ['account_id' => $this->head(StandardChart::DRAWINGS)->id,
                    'debit' => '0', 'credit' => $received];
            }

            $voucher = $this->vouchers->create(
                [
                    'type' => Voucher::RECEIPT,
                    'trx_date' => (string) $data['moved_on'],
                    'narration' => $data['note'] ?? __('finance::message.deposit_closed_narration', [
                        'no' => $deposit->document_no,
                        'where' => $deposit->institution,
                    ]),
                ],
                $lines,
            );

            $this->vouchers->post($voucher);

            $movement = $this->write($deposit, DepositMovement::CLOSED, $received, $into,
                (string) $data['moved_on'], $voucher->id, $data['note'] ?? null);

            $deposit->forceFill([
                'status' => Deposit::CLOSED,
                'closed_on' => $data['moved_on'],
            ])->save();

            return $movement;
        });
    }

    /**
     * ভুল করে বসানো হয়েছিল — পুরোটা ফিরিয়ে নাও।
     *
     * ── কেন এটা "ভাঙা" নয় ───────────────────────────────────────────
     * ভাঙা একটা **ব্যবসায়িক ঘটনা**: ব্যাংক টাকা ফেরত দিয়েছে, আর সেটার
     * নিজের দাখিলা হয়। ভুল এন্ট্রিতে ব্যাংক কিছুই দেয়নি — জমাটা
     * কোনোদিন ছিলই না। ভাঙা দিয়ে সারালে খাতায় দুইটা মিথ্যা ঘটনা বসত:
     * একটা জমা যা হয়নি, আর একটা ফেরত যা আসেনি।
     *
     * ── কেন প্রতিটা ভাউচার আলাদা করে বাতিল ──────────────────────────
     * [[VoucherService::cancel()]] নিজেই উল্টো দাখিলা লেখে আর কাগজটা
     * বাতিল চিহ্নিত করে। এখানে হাতে একটা বিপরীত ভাউচার বানালে সেই
     * যুক্তিটা দুই জায়গায় থাকত, আর একদিন একটা বদলাত।
     *
     * ── কেন সারিটা থেকে যায় ─────────────────────────────────────────
     * নিয়ম ৫। মুছে ফেললে ছয় মাস পর "১১৬০ খাতে ২ লাখ ঢুকে বেরোল কেন"
     * প্রশ্নের উত্তরটা আর কোথাও থাকত না।
     */
    public function cancel(Deposit $deposit, string $reason): Deposit
    {
        if ($deposit->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.deposit_already_cancelled', [
                    'no' => $deposit->document_no,
                ]),
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'cancel_reason' => __('finance::validation.cancel_reason_needed'),
            ]);
        }

        return DB::transaction(function () use ($deposit, $reason) {
            foreach ($deposit->movements()->with('voucher')->get() as $movement) {
                $voucher = $movement->voucher;

                /*
                 * আগেই বাতিল হয়ে থাকতে পারে — কেউ হিসাবের পর্দা থেকে
                 * ভাউচারটা বাতিল করে থাকলে। তখন আবার বাতিল করলে সেবা
                 * ব্যতিক্রম ছুড়ত, আর গোটা কাজটা থেমে যেত অর্ধেক পথে।
                 */
                if ($voucher === null || $voucher->isCancelled()) {
                    continue;
                }

                $this->vouchers->cancel($voucher, __('finance::message.deposit_cancel_narration', [
                    'no' => $deposit->document_no,
                    'why' => $reason,
                ]));
            }

            $deposit->forceFill([
                'status' => Deposit::CANCELLED,
                'cancel_reason' => trim($reason),
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
            ])->save();

            return $deposit->fresh();
        });
    }

    /**
     * কত টাকা সরিয়ে রাখা আছে — ব্যবসার আর মালিকের, আলাদা।
     *
     * ── কেন দুইটা যোগ একসাথে করা যায় না ────────────────────────────
     * একটা স্থিতিপত্রে আছে, অন্যটা নেই। এক সংখ্যায় দেখালে সেটা কোনো
     * রিপোর্টের সাথেই মিলত না।
     *
     * @return array{business: string, owner: string, count: int, maturing: int}
     */
    public function standing(?string $issuer = null): array
    {
        $rows = Deposit::query()->open()
            ->when($issuer !== null, fn ($q) => $q->issuedBy($issuer))
            ->with('kind')->get();

        $business = '0.0000';
        $owner = '0.0000';
        $maturing = 0;

        foreach ($rows as $row) {
            if ($row->isBusinessAsset()) {
                $business = bcadd($business, (string) $row->principal, 4);
            } else {
                $owner = bcadd($owner, (string) $row->principal, 4);
            }

            $days = $row->daysToMaturity();

            if ($days !== null && $days <= 30) {
                $maturing++;
            }
        }

        return ['business' => $business, 'owner' => $owner,
            'count' => $rows->count(), 'maturing' => $maturing];
    }

    /**
     * টাকা ভেতরে গেল — খোলা আর কিস্তি দুইটারই একই দাখিলা।
     */
    private function putMoneyIn(Deposit $deposit, string $kind, string $amount,
        Account $from, string $on, ?string $note = null): DepositMovement
    {
        $voucher = $this->vouchers->create(
            [
                'type' => Voucher::PAYMENT,
                'trx_date' => $on,
                'narration' => $note ?? __('finance::message.deposit_opened_narration', [
                    'no' => $deposit->document_no,
                    'where' => $deposit->institution,
                ]),
            ],
            [
                ['account_id' => $this->assetHead($deposit->held_by)->id,
                    'debit' => $amount, 'credit' => '0'],
                ['account_id' => $from->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        $this->vouchers->post($voucher);

        return $this->write($deposit, $kind, $amount, $from, $on, $voucher->id, $note);
    }

    private function write(Deposit $deposit, string $kind, string $amount, Account $money,
        string $on, ?int $voucherId, ?string $note = null): DepositMovement
    {
        return DepositMovement::query()->create([
            'company_id' => CompanyContext::id(),
            'deposit_id' => $deposit->id,
            'kind' => $kind,
            'amount' => $amount,
            'moved_on' => $on,
            'money_account_id' => $money->id,
            'voucher_id' => $voucherId,
            'note' => $note,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * টাকাটা কোন খাতে বসে — আর এটাই মালিকের প্রশ্নের উত্তর।
     *
     * ব্যবসার নামে হলে সম্পদ (১১৬০); মালিকের নামে হলে উত্তোলন (৩২০০),
     * কারণ ফার্ম সঞ্চয়পত্র কিনতেই পারে না — টাকাটা ব্যবসা থেকে বেরিয়ে
     * গেছে, আর কাগজটা কেবল জানার জন্য এখানে থাকে।
     */
    private function assetHead(string $heldBy): Account
    {
        return $this->head($heldBy === Deposit::OWNER
            ? StandardChart::DRAWINGS
            : StandardChart::DEPOSITS_AND_INVESTMENTS);
    }

    /** মুনাফা কোথায় বসে — ব্যবসার হলে আয়, মালিকের হলে উত্তোলন কমা। */
    private function returnHead(Deposit $deposit): Account
    {
        return $this->head($deposit->isBusinessAsset()
            ? StandardChart::INTEREST_INCOME
            : StandardChart::DRAWINGS);
    }

    private function head(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::validation.chart_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
    }

    /**
     * টাকার খাত — মাথা নয়, আর সত্যিই নগদ বা ব্যাংক।
     */
    private function money(mixed $id): Account
    {
        $account = Account::query()->find($id);

        if ($account === null || $account->is_group) {
            throw ValidationException::withMessages([
                'money_account_id' => __('finance::validation.not_a_postable_account'),
            ]);
        }

        return $account;
    }

    /**
     * চালু না হলে আর কিছু করা যায় না।
     *
     * বাতিল আর চুকে যাওয়া — দুইটার বার্তা আলাদা, কারণ ব্যবহারকারীর
     * পরের পদক্ষেপও আলাদা: চুকে যাওয়াটায় করার কিছু নেই, বাতিলটায়
     * সম্ভবত নতুন করে ঠিক সারিটা বসাতে হবে।
     */
    private function assertOpen(Deposit $deposit): void
    {
        if ($deposit->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.deposit_already_cancelled', [
                    'no' => $deposit->document_no,
                ]),
            ]);
        }

        if ($deposit->status !== Deposit::ACTIVE) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.deposit_already_closed', ['no' => $deposit->document_no]),
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertSane(DepositKind $kind, array $data): void
    {
        if (bccomp((string) ($data['principal'] ?? '0'), '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'principal' => __('finance::validation.deposit_must_be_positive'),
            ]);
        }

        if (! in_array($data['held_by'] ?? '', [Deposit::BUSINESS, Deposit::OWNER], true)) {
            throw ValidationException::withMessages([
                'held_by' => __('finance::validation.unknown_holder'),
            ]);
        }

        /*
         * সঞ্চয়পত্র ব্যবসার নামে কেনা যায় না — আইনেই পারে না।
         *
         * ── কেন এটা পর্দার সতর্কবার্তা নয়, সেবার বাধা ────────────────
         * ভুলটা ধরা পড়ে অডিটে, এক বছর পরে, যখন স্থিতিপত্রে এমন একটা
         * সম্পদ বসে আছে যা ব্যবসার নয়। ততদিনে ওই সংখ্যাটা দিয়ে ঋণের
         * আবেদন হয়ে গেছে।
         */
        if ($kind->personal_only && ($data['held_by'] ?? '') === Deposit::BUSINESS) {
            throw ValidationException::withMessages([
                'held_by' => __('finance::validation.kind_is_personal_only', ['kind' => $kind->name()]),
            ]);
        }

        if ($kind->paysOut() && ($data['payout_account_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'payout_account_id' => __('finance::validation.payout_account_needed'),
            ]);
        }
    }
}
