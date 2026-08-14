<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ভাউচার লেখা, পোস্ট করা ও বাতিল করা।
 *
 * পাঁচ ধরনের ফর্ম আলাদা, কিন্তু নিয়ম এক জায়গায়। DMS-এ প্রতিটা ভাউচারের
 * নিজের কন্ট্রোলারে নিজের পোস্টিং লেখা ছিল, আর সেই কারণেই "প্রতিটা কন্ট্রা
 * উল্টো দিকে বসত" — এক জায়গার ভুল, যা ধরা পড়েছিল অনেক পরে। এখানে
 * দিকটা একবারই ঠিক করা হয়, আর পাঁচটা ধরনই সেই একই পথে যায়।
 */
final class VoucherService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * খসড়া হিসেবে তৈরি — লেজারে কিছুই বসে না।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Voucher
    {
        return DB::transaction(function () use ($data, $lines) {
            $type = $this->assertType($data['type'] ?? null);

            $trxDate = Carbon::parse($data['trx_date']);
            $year = $this->resolveFinancialYear($trxDate);

            // নম্বর ট্রানজেকশনের ভেতরে — সেভ ব্যর্থ হলে নম্বরটাও ফিরে যায়,
            // নাহলে প্রতিটা ব্যর্থ চেষ্টায় সিরিজে একটা ফাঁক থাকত
            $documentNo = $this->numbers->next(Voucher::DOC_TYPES[$type]);

            $voucher = Voucher::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $year->id,
                'type' => $type,
                'document_no' => $documentNo,
                'trx_date' => $trxDate->toDateString(),
                'party_type' => $data['party_type'] ?? null,
                'party_id' => $data['party_id'] ?? null,
                'narration' => $data['narration'] ?? null,
                'instrument' => $data['instrument'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'instrument_date' => $data['instrument_date'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            $this->replaceLines($voucher, $lines);

            // ইস্যু করা নম্বরটা কোন ভাউচারে বসল — "RV-০০০৭ কার" প্রশ্নের উত্তর
            IssuedNumber::query()
                ->where('document_no', $documentNo)
                ->whereNull('source_id')
                ->update([
                    'source_type' => Voucher::SOURCE_TYPES[$type],
                    'source_id' => $voucher->id,
                ]);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function update(Voucher $voucher, array $data, array $lines): Voucher
    {
        $this->assertEditable($voucher);

        return DB::transaction(function () use ($voucher, $data, $lines) {
            $trxDate = Carbon::parse($data['trx_date'] ?? $voucher->trx_date);

            $voucher->update([
                ...$data,
                // ধরন ও নম্বর কখনো বদলায় না: নম্বরটা সিরিজ থেকে এসেছে আর
                // ধরন বদলালে ওই সিরিজটাই ভুল হয়ে যেত
                'type' => $voucher->type,
                'document_no' => $voucher->document_no,
                'trx_date' => $trxDate->toDateString(),
                'financial_year_id' => $this->resolveFinancialYear($trxDate)->id,
            ]);

            $this->replaceLines($voucher, $lines);

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * লেজারে বসানো।
     *
     * এখান থেকেই ভাউচারটা আসল হয়ে ওঠে — তার আগ পর্যন্ত সেটা শুধু একটা
     * খসড়া, কোনো হিসাবে নেই। PostingEngine নিজেই দেখে ডেবিট-ক্রেডিট
     * মিলছে কি না, বছর খোলা আছে কি না, আর আগে বসানো হয়েছে কি না।
     */
    public function post(Voucher $voucher): Voucher
    {
        if ($voucher->isPosted()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_posted', ['no' => $voucher->document_no]),
            ]);
        }

        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.cancelled_cannot_post'),
            ]);
        }

        $voucher->load('lines.account');

        $this->assertLinesArePostable($voucher);
        $this->assertBankReferenceIsFree($voucher);

        return DB::transaction(function () use ($voucher) {
            $this->posting->post(
                Voucher::SOURCE_TYPES[$voucher->type],
                $voucher->id,
                $voucher->trx_date,
                $voucher->lines->map(fn (VoucherLine $line) => [
                    'account_id' => $line->account_id,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                    'party_type' => $line->party_type ?? $voucher->party_type,
                    'party_id' => $line->party_id ?? $voucher->party_id,
                    'narration' => $line->narration ?? $voucher->narration,
                    'source_line_id' => $line->id,
                ])->all(),
                documentNo: $voucher->document_no,
                branchId: $voucher->branch_id,
            );

            $voucher->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'amount' => $voucher->totals()['debit'],
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * বাতিল করা — বিপরীত এন্ট্রি দিয়ে, মুছে নয় (নিয়ম ৫)।
     *
     * মূল এন্ট্রিগুলো লেজারে থেকে যায়, আর তার পাশে সমান-উল্টো এন্ট্রি বসে।
     * মুছে দিলে ছাপা কাগজের নম্বরটা আর কোনো রেকর্ডের সাথে মিলত না, আর
     * অডিটে দেখা যেত একটা নম্বর ইস্যু হয়েছে কিন্তু কিছুই ঘটেনি।
     */
    public function cancel(Voucher $voucher, string $reason, ?string $onDate = null): Voucher
    {
        if ($voucher->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.already_cancelled'),
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'cancel_reason' => __('accounts::validation.cancel_reason_required'),
            ]);
        }

        return DB::transaction(function () use ($voucher, $reason, $onDate) {
            // খসড়া কখনো লেজারে বসেনি, তাই ফেরানোরও কিছু নেই
            if ($voucher->isPosted()) {
                $this->posting->reverse(
                    Voucher::SOURCE_TYPES[$voucher->type],
                    $voucher->id,
                    $onDate ?? now()->toDateString(),
                    $reason,
                );
            }

            $voucher->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                // ব্যাংক লেনদেনের নম্বরটা আবার খালি হয় — ভুল ভাউচার
                // বাতিল করে একই TrxID নিয়ে সঠিকটা তোলা রোজকার কাজ, আর
                // ধরে রাখলে ওই টাকাটা আর কখনো খাতায় উঠত না। নম্বরটা
                // `instrument_no`-তে থেকেই যায়, শুধু জোড়াটা ছাড়া পায়
                'money_account_id' => null,
            ])->save();

            return $voucher->fresh(['lines']);
        });
    }

    /**
     * সহজ ফর্ম থেকে দুই লাইনের ভাউচার।
     *
     * আদায়, পরিশোধ, খরচ ও কন্ট্রা — চারটাই আসলে "এখান থেকে ওখানে"।
     * ব্যবহারকারী দুইটা খাত ও একটা অঙ্ক দেয়; কে ডেবিট আর কে ক্রেডিট
     * সেটা এখানে ঠিক হয়, একবারের জন্য।
     *
     * এই একটা জায়গাই DMS-এর কন্ট্রা-বাগটার উত্তর: ওখানে প্রতিটা পর্দায়
     * আলাদা করে দিক ঠিক করা হত, আর একটায় উল্টো লেখা ছিল।
     *
     * @return list<array<string, mixed>>
     */
    public function twoLineEntry(string $type, int $fromAccountId, int $toAccountId, string $amount, ?string $narration = null): array
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('accounts::validation.amount_must_be_positive'),
            ]);
        }

        if ($fromAccountId === $toAccountId) {
            throw ValidationException::withMessages([
                'to_account_id' => __('accounts::validation.same_account_both_sides'),
            ]);
        }

        /*
         * "to" সবসময় ডেবিট, "from" সবসময় ক্রেডিট।
         *
         * টাকা যেখানে গেল সেটা বাড়ল (ডেবিট), যেখান থেকে এল সেটা কমল
         * (ক্রেডিট)। চারটা ধরনেই একই কথা:
         *
         *   আদায়   — টাকা এল গ্রাহক থেকে, গেল ক্যাশে
         *   পরিশোধ — টাকা এল ক্যাশ থেকে, গেল সরবরাহকারীর হিসাবে
         *   খরচ    — টাকা এল ক্যাশ থেকে, গেল খরচের খাতে
         *   কন্ট্রা  — টাকা এল ক্যাশ থেকে, গেল ব্যাংকে
         *
         * পর্দার লেবেল আলাদা হতে পারে, কিন্তু হিসাবটা এক।
         */
        return [
            ['account_id' => $toAccountId, 'debit' => $amount, 'credit' => '0', 'narration' => $narration],
            ['account_id' => $fromAccountId, 'debit' => '0', 'credit' => $amount, 'narration' => $narration],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Voucher $voucher, array $lines): void
    {
        $clean = [];

        foreach ($lines as $index => $line) {
            $accountId = (int) ($line['account_id'] ?? 0);

            if ($accountId === 0) {
                continue;
            }

            $debit = $this->money($line['debit'] ?? 0);
            $credit = $this->money($line['credit'] ?? 0);

            // দুই দিকেই শূন্য এমন সারি বাদ — ফর্মে খালি সারি রাখা
            // স্বাভাবিক, আর সেগুলো সেভ করলে ভাউচারে অর্থহীন লাইন জমত
            if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
                continue;
            }

            $clean[] = [
                'account_id' => $accountId,
                'party_type' => $line['party_type'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $line['narration'] ?? null,
                'sort_order' => $index,
            ];
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'lines' => __('accounts::validation.no_lines'),
            ]);
        }

        $voucher->lines()->delete();
        $voucher->lines()->createMany($clean);
        $voucher->load('lines');

        $voucher->forceFill(['amount' => $voucher->totals()['debit']])->save();
    }

    /**
     * ব্যাংক বা MFS-এ গেলে লেনদেনের নম্বর লাগে, আর সেটা একবারই।
     *
     * ── কেন নিশ্চিত করার সময়, এন্ট্রির সময় নয় ───────────────────────
     * এন্ট্রির মুহূর্তে হিসাবরক্ষক লিখছেন "করিম স্টোরকে ৫০,০০০ দেব" —
     * বিকাশের TrxID তখনো **জন্মায়ইনি**। তখন বাধ্যতামূলক করলে মানুষ
     * `0` বা `-` বসিয়ে এগিয়ে যেতেন, আর **ভুল নম্বর কোনো নম্বর না
     * থাকার চেয়ে খারাপ**: ব্যাংক মেলানোর সময় ওটা দেখে সবাই ভাবে
     * মিলে গেছে।
     *
     * নিশ্চিতকরণ সেই মুহূর্ত যখন টাকা সত্যিই নড়ে। অনুমোদনের ধাপ থাকলে
     * সেটা এর আগেই ঘটে, তাই অনুমোদনকারীও নম্বরটা বসাতে পারেন — আর
     * থ্রেশহোল্ডের নিচে অনুমোদন না লাগলেও পাহারাটা থেকে যায়।
     *
     * ── নগদে চাওয়া হয় না ───────────────────────────────────────────
     * নগদের কোনো TrxID নেই। চাইলে প্রতিটা নগদ ভাউচারে একটা বানানো
     * নম্বর বসত। তাই শর্তটা টাকার খাতের উপর: ব্যাংক হলে লাগে।
     *
     * ── কী আটকায়, আর কী আটকায় না ───────────────────────────────────
     * আটকায়: **একই ব্যাংক লেনদেন দুইবার খাতায় ওঠা** — হিসাবরক্ষক
     * তুললেন, ম্যানেজারও তুললেন। আটকায় না: একই বিলের বিপরীতে দুইটা
     * আলাদা পাঠানো — ওটা বিলের বরাদ্দের কাজ, আলাদা পাহারা।
     */
    private function assertBankReferenceIsFree(Voucher $voucher): void
    {
        $account = $voucher->lines
            ->map(fn (VoucherLine $line) => $line->account)
            ->first(fn (?Account $a) => $a?->is_bank);

        // নগদ, বা টাকার কোনো ব্যাংক-খাত নেই (জাবেদা) — প্রশ্নই ওঠে না
        if ($account === null) {
            return;
        }

        $reference = trim((string) $voucher->instrument_no);

        if ($reference === '') {
            throw ValidationException::withMessages([
                'instrument_no' => __('accounts::validation.bank_reference_required', [
                    'account' => $account->label(),
                ]),
            ]);
        }

        $twin = Voucher::query()
            ->where('money_account_id', $account->id)
            ->where('instrument_no', $reference)
            ->whereKeyNot($voucher->id)
            ->first();

        if ($twin !== null) {
            throw ValidationException::withMessages([
                'instrument_no' => __('accounts::validation.bank_reference_used', [
                    'reference' => $reference,
                    'no' => $twin->document_no,
                ]),
            ]);
        }

        // খাতটা মাথায় বসে, কারণ অনন্যতার ইনডেক্সও মাথার উপর — লাইনে
        // বসালে দুই লাইনের ভাউচারে নিয়মটা কোন লাইনের তা বলা যেত না
        $voucher->forceFill(['money_account_id' => $account->id])->save();
    }

    /**
     * পোস্ট করার আগে শেষ যাচাই।
     *
     * PostingEngine ভারসাম্য দেখে, কিন্তু "গ্রুপ খাতে এন্ট্রি বসে না" ও
     * "নিষ্ক্রিয় খাতে নতুন এন্ট্রি নয়" — এগুলো হিসাবের ছকের নিয়ম, আর
     * সেগুলো এই মডিউলের দায়িত্ব।
     */
    private function assertLinesArePostable(Voucher $voucher): void
    {
        foreach ($voucher->lines as $line) {
            $account = $line->account;

            if ($account === null) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.account_missing'),
                ]);
            }

            if ($account->is_group) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.group_cannot_take_entries'),
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'lines' => __('accounts::validation.inactive_account', ['name' => $account->label()]),
                ]);
            }
        }

        if (! $voucher->isBalanced()) {
            $t = $voucher->totals();

            throw ValidationException::withMessages([
                'lines' => __('accounts::validation.not_balanced', [
                    'debit' => Money::format($t['debit']),
                    'credit' => Money::format($t['credit']),
                ]),
            ]);
        }
    }

    private function assertEditable(Voucher $voucher): void
    {
        if (! $voucher->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('accounts::validation.posted_cannot_edit', ['no' => $voucher->document_no]),
            ]);
        }
    }

    private function assertType(mixed $type): string
    {
        if (! in_array($type, Voucher::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => __('accounts::validation.unknown_voucher_type'),
            ]);
        }

        return $type;
    }

    /**
     * তারিখটা কোন অর্থবছরে পড়ে।
     *
     * PostingEngine নিজেও এটা করে, আর ওখানেই শেষ কথা। এখানে আগে করা
     * হয় শুধু একটা কারণে: খসড়া সংরক্ষণের সময়ও বছরটা জানা দরকার, আর
     * তখনো পোস্টিং হয়নি। ভুলের বার্তাটাও এখানে ব্যবহারকারীর ভাষায় আসে —
     * engine-এর বার্তা ডেভেলপারের জন্য লেখা।
     */
    private function resolveFinancialYear(Carbon $date): FinancialYear
    {
        $year = FinancialYear::forDate($date);

        if ($year === null) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.no_financial_year', ['date' => DateFormat::format($date)]),
            ]);
        }

        if ($year->is_closed) {
            throw ValidationException::withMessages([
                'trx_date' => __('accounts::validation.year_closed', ['year' => $year->name]),
            ]);
        }

        return $year;
    }

    /**
     * খতিয়ানে ঢোকার আগে টাকার রূপ ঠিক করা।
     *
     * ── কেন এটা দেখানোর ফরম্যাটিং নয় ────────────────────────────────
     * এই মানটা পর্দায় যায় না, **খাতায় যায়**। আগে এখানে
     * `number_format((float) $value, 4)` ছিল — অর্থাৎ ভাউচারের প্রতিটা
     * অঙ্ক ডেবিট-ক্রেডিট মেলানোর আগেই একবার float হয়ে আসত। যে জায়গাটা
     * সবচেয়ে বেশি নির্ভুলতা দাবি করে, ঠিক সেখানেই।
     */
    private function money(mixed $value): string
    {
        return Money::round($value, 4);
    }

    /**
     * টাকার খাতগুলো — আদায়, পরিশোধ ও কন্ট্রার ড্রপডাউনে যা দেখাবে।
     *
     * @return Collection<int, Account>
     */
    public function moneyAccounts(): Collection
    {
        return Account::query()->money()->postable()->active()->orderBy('code')->get();
    }
}
