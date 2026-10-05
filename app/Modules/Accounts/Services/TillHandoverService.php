<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\TillHandover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ক্যাশবাক্সের দায়িত্ব হস্তান্তর — Accounts-Finance অডিট ম৮, ৪ অক্টোবর ২০২৬ (৬৩-এর সিদ্ধান্ত; আন্তর্জাতিক ধারা: শিফট
 * হ্যান্ডওভারে জের গুনে সই)।
 *
 * ⛔ আগে বাক্সের সম্পাদনায় হেফাজতকারী চুপচাপ বদলাত — কার হাত থেকে কার হাতে কত গেল তার কাগজ থাকত না, আর আগের জনের
 * ঘাটতি নতুন জনের ঘাড়ে পড়ত। ⓘ এখন:
 *  · খাতার জের সেই মুহূর্তে লেখা থাকে; নতুন জন গুনে কম-বেশি পেলে পার্থক্যটা নগদ গণনার কাগজে যায় ([[CashCountService]]) —
 *    এখানে কোনো হিসাব লেখা হয় না, টাকা একই বাক্সে থাকে।
 *  · টাকার দায় বদলায় বলে সই (`accounts.till_handover`), ছক চালু থাকলে; শেষ সইয়ে নিজেই শেষ হয়
 *    ([[FinishTheAccountsPaperOnTheLastSignature]])। ছক বন্ধে এখনই।
 */
final class TillHandoverService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly CashCountService $counts,
        private readonly AccountsSignature $signature,
    ) {}

    /**
     * @param  string|null  $counted  নতুন জন গুনে কত পেলেন — না দিলে খাতার জেরই মেনে নেওয়া
     */
    public function handOver(CashTill $till, int $toHolderId, ?string $counted = null, ?string $narration = null): TillHandover
    {
        $handover = DB::transaction(function () use ($till, $toHolderId, $counted, $narration) {
            $till = CashTill::query()->whereKey($till->id)->lockForUpdate()->firstOrFail();

            $this->assertMayHandOver($till, $toHolderId);

            $book = $till->balance();
            $countId = null;
            $difference = '0';

            if ($counted !== null && $counted !== '') {
                $counted = $this->wholeTaka($counted);
                $difference = bcsub($counted, $book, 4);

                // ⓘ কম-বেশি হলে নগদ গণনার কাগজ — সেখানেই খাতায় যায়, সেই কাগজের নিজের সইয়ে
                if (bccomp($difference, '0', 4) !== 0) {
                    $countId = $this->counts->record([
                        'cash_till_id' => $till->id,
                        'trx_date' => now()->toDateString(),
                        'narration' => __('accounts::custody.handover_count', ['to' => User::query()->find($toHolderId)?->name]),
                    ], $this->notesFor($counted))->id;
                }
            }

            return TillHandover::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $till->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('TH'),
                'trx_date' => now()->toDateString(),
                'cash_till_id' => $till->id,
                'from_holder_id' => $till->holder_id,
                'to_holder_id' => $toHolderId,
                'book_balance' => $book,
                'counted_amount' => $counted !== null && $counted !== '' ? $counted : null,
                'difference' => $difference,
                'cash_count_id' => $countId,
                'narration' => $narration,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);
        });

        // ⓘ লেনদেনের বাইরে — সইয়ের অনুরোধ কাগজের সাথে থাকে; থামলে কাগজটা সইয়ের অপেক্ষায়, বাক্স আগের জনেরই
        if ($this->signature->holds($handover, AccountsSignature::TILL_HANDOVER, (string) $handover->book_balance, $narration)) {
            $handover->forceFill(['status' => TillHandover::AWAITING])->save();

            return $handover->fresh();
        }

        return $this->finish($handover);
    }

    /**
     * শেষ সইয়ের পরে বা ছক বন্ধে — বাক্স নতুন জনের (সারি তালা দিয়ে; একই ঘটনা দুইবার এলে দ্বিতীয়বার কিছু হয় না)।
     */
    public function finish(TillHandover $handover): TillHandover
    {
        return DB::transaction(function () use ($handover) {
            $this->lockFresh($handover);

            if (! in_array($handover->status, [DocumentStatus::DRAFT, TillHandover::AWAITING], true)) {
                return $handover;
            }

            $till = CashTill::query()->whereKey($handover->cash_till_id)->lockForUpdate()->firstOrFail();

            // ⛔ মাঝে কেউ সাধারণ পথে বাক্স বদলে থাকলে এই কাগজ আর সত্য নয়
            if ((int) $till->holder_id !== (int) $handover->from_holder_id) {
                throw ValidationException::withMessages([
                    'to_holder_id' => __('accounts::validation.handover_holder_moved', ['no' => $handover->document_no]),
                ]);
            }

            $till->forceFill(['holder_id' => $handover->to_holder_id])->save();

            $handover->forceFill([
                'status' => DocumentStatus::CONFIRMED,
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ])->save();

            return $handover->fresh();
        });
    }

    /** সই ফেরত পেলে বা ভুল হলে — বাক্স আগের জনেরই থাকে; গণনার কাগজ থাকলে সেটা নিজের পথে চলে */
    public function cancel(TillHandover $handover): TillHandover
    {
        return DB::transaction(function () use ($handover) {
            $this->lockFresh($handover);

            if (! in_array($handover->status, [DocumentStatus::DRAFT, TillHandover::AWAITING], true)) {
                throw ValidationException::withMessages([
                    'status' => __('accounts::validation.handover_not_open', ['no' => $handover->document_no]),
                ]);
            }

            $handover->forceFill(['status' => DocumentStatus::CANCELLED])->save();

            return $handover->fresh();
        });
    }

    private function assertMayHandOver(CashTill $till, int $toHolderId): void
    {
        if (! $till->is_active) {
            throw ValidationException::withMessages(['cash_till_id' => __('accounts::validation.handover_closed_till')]);
        }

        if ((int) $till->holder_id === $toHolderId) {
            throw ValidationException::withMessages(['to_holder_id' => __('accounts::validation.handover_same_holder')]);
        }

        // ⛔ নতুন জন এই কোম্পানির চালু মানুষ — নইলে অন্য কোম্পানির কারো নামে বাক্স যেত
        $member = User::query()->whereKey($toHolderId)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', CompanyContext::id())->where('company_user.is_active', true))
            ->exists();

        if (! $member) {
            throw ValidationException::withMessages(['to_holder_id' => __('accounts::validation.handover_unknown_person')]);
        }

        // ⛔ একই বাক্সের আগের হস্তান্তর এখনো খোলা থাকলে আরেকটা নয় — দুই সই পড়লে কে মালিক তা ক্রমের উপর নির্ভর করত
        $open = TillHandover::query()
            ->where('cash_till_id', $till->id)
            ->whereIn('status', [DocumentStatus::DRAFT, TillHandover::AWAITING])
            ->value('document_no');

        if ($open !== null) {
            throw ValidationException::withMessages(['to_holder_id' => __('accounts::validation.handover_already_open', ['no' => $open])]);
        }
    }

    /** ⓘ গোনা টাকা পুরো টাকায় — নোট গুনে পয়সা আসে না, আর নগদ গণনার কাগজ নোট ধরে লেখে */
    private function wholeTaka(string $counted): string
    {
        if (! is_numeric($counted) || bccomp($counted, '0', 4) < 0 || bccomp(bcmod($counted, '1', 4), '0', 4) !== 0) {
            throw ValidationException::withMessages(['counted_amount' => __('accounts::validation.handover_counted_whole')]);
        }

        return bcadd($counted, '0', 4);
    }

    /**
     * @return array<int, int> নোট → সংখ্যা, বড় নোট আগে — অঙ্কটাই নগদ গণনার কাগজের মোট
     */
    private function notesFor(string $amount): array
    {
        $left = (int) bcadd($amount, '0', 0);
        $notes = [];

        foreach (CashCount::DENOMINATIONS as $note) {
            if ($left >= $note) {
                $notes[$note] = intdiv($left, $note);
                $left %= $note;
            }
        }

        return $notes;
    }
}
