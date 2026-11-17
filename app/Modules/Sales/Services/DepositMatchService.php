<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\Actor;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankStatementLine;
use App\Modules\Accounts\Services\BankStatementFile;
use App\Modules\Accounts\Services\BankStatementService;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DepositClaim;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ জমার খবর ↔ ব্যাংক স্টেটমেন্ট — শেষ কথা ব্যাংকের (মালিক, ১৬ নভেম্বর ২০২৬: "ok suro koro"; ধাপ গ)।
 *
 * ⓘ ব্যাংকের সারি থাকে মিলকরণের একই টেবিলে ([[BankStatementLine]]) — দ্বিতীয় কোনো টেবিল নয়; একই ফাইল আবার তুললে
 * [[BankStatementService::add()]]-এর ছাপ সারি দ্বিগুণ হতে দেয় না।
 *
 * ── মেলানোর নিয়ম ────────────────────────────────────────────────────────
 *   · প্রস্তাব ([[suggestions()]]) কেবল তিন শর্তে একসাথে: হুবহু একই অঙ্ক, একই ব্যাংক খাত, আর তারিখ খবরের ±N দিনের মধ্যে
 *     (N = সেটিং [[DepositClaim::MATCH_DAYS]])। রেফারেন্স দুই দিকেই থাকলে আগে রেফারেন্স। ⛔ একাধিক প্রার্থী — দুই দিকের
 *     যেকোনো দিকে — হলে কোনো প্রস্তাব নয়; ভুল জোড়া কেউ কোনোদিন খুঁজে পেত না, তার চেয়ে মানুষটাকে দুইটাই দেখানো।
 *   · প্রস্তাব নিজে কিছু লেখে না — অফিস এক চাপে মেলান ([[match()]]); হাতেও মেলানো যায় (অঙ্ক আলাদা হলে — ব্যাংক চার্জ)।
 *   · ⛔ একটা ব্যাংক-সারি একটাই খবরে, আর একটা খবর একটাই সারিতে — সেবা তালা দিয়ে দেখে, আর টেবিলের unique শেষ পাহারা।
 *   · ⛔ যে ব্যাংক-সারি মিলকরণে আগেই খাতার কোনো সারির সাথে মিলেছে (টাকা খাতায় আগেই উঠেছে), সেটা খবরের জন্য নয় — নইলে একই
 *     টাকা দুইবার খাতায় উঠত।
 *   · মিল খোলা ([[unmatch()]]) কারণসহ; অনুমোদনের অপেক্ষার খসড়া আদায় থাকলে সেটা বাতিল, অপেক্ষার অনুরোধ তুলে নেওয়া।
 */
final class DepositMatchService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly BankStatementFile $files,
    ) {}

    /** খবরের তারিখ থেকে কত দিন — সেটিং, ০–৩০ */
    public function windowDays(): int
    {
        return max(0, min(30, (int) $this->settings->get(DepositClaim::MATCH_DAYS, 3)));
    }

    /**
     * ব্যাংকের স্টেটমেন্ট ফাইল তোলা — একটা খাতের জন্য। একই সারি দ্বিতীয়বার বসে না।
     *
     * @return array{added: int, known: int, bad: list<string>}
     */
    public function load(Account $account, UploadedFile $file): array
    {
        $this->assertBankAccount($account);

        $rows = $this->files->rows($file);
        // ⓘ নতুন সেবা — "এক ফাইল, এক গোনা" ([[BankStatementService]]-এর ছাপের গোনা এই অবজেক্টে থাকে)
        $lines = new BankStatementService;

        $added = 0;
        $known = 0;
        $bad = [];

        DB::transaction(function () use ($rows, $lines, $account, &$added, &$known, &$bad): void {
            foreach ($rows as $n => $row) {
                $date = $this->files->dateOf($lines, $row['trx_date']);
                $debit = $lines->amountOf($row['debit']);
                $credit = $lines->amountOf($row['credit']);

                if ($date === null) {
                    // ⓘ "Opening balance" বা পাতার শেষের যোগফল — তারিখহীন সারি টাকা হলে তবেই ভুল বলে জানানো
                    if (bccomp($debit, '0', 4) > 0 || bccomp($credit, '0', 4) > 0) {
                        $bad[] = (string) __('sales::deposit.bad_line', ['line' => $n, 'value' => $row['trx_date']]);
                    }

                    continue;
                }

                if (bccomp($debit, '0', 4) === 0 && bccomp($credit, '0', 4) === 0) {
                    continue;
                }

                $new = $lines->add(
                    account: $account,
                    date: $date,
                    description: $row['description'] !== '' ? mb_substr($row['description'], 0, 255) : null,
                    reference: $row['reference'] !== '' ? mb_substr($row['reference'], 0, 60) : null,
                    debit: $debit,
                    credit: $credit,
                    balance: $row['balance'] === '' ? null : $lines->amountOf($row['balance']),
                );

                $new ? $added++ : $known++;
            }
        });

        return ['added' => $added, 'known' => $known, 'bad' => $bad];
    }

    /**
     * হাতে একটা জমার সারি — ব্যাংকের অ্যাপে দেখা লেনদেন। ⓘ একই সারি দুইবার লিখলে দ্বিতীয়টা বসে না (একই ছাপ)।
     *
     * @param  array{trx_date: string, amount: string, reference?: ?string, description?: ?string}  $data
     */
    public function addByHand(Account $account, array $data): bool
    {
        $this->assertBankAccount($account);

        $amount = Money::of((string) $data['amount']);
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('sales::portal.amount_must_be_positive')]);
        }

        return (new BankStatementService)->add(
            account: $account,
            date: Carbon::parse($data['trx_date'])->toDateString(),
            description: filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            reference: filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            debit: '0',
            credit: bcadd($amount, '0', 4),
        );
    }

    /**
     * এখনো কোনো খবরে না-বসা জমার সারি — টাকা এসেছে (ক্রেডিট), মিলকরণে খাতার সারির সাথে মেলেনি, কোনো খবরে বসেনি।
     *
     * @return Builder<BankStatementLine>
     */
    public function freeLines(): Builder
    {
        return BankStatementLine::query()
            ->where('credit', '>', 0)
            ->where('debit', 0)
            ->whereNull('matched_line_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sal_deposit_claims as dc')
                ->whereColumn('dc.statement_line_id', 'acc_bank_statement_lines.id')
                ->whereNull('dc.deleted_at'));
    }

    /**
     * ব্যাংকে এখনো না-মেলা খোলা খবর — দেখা শাখার।
     *
     * @return Builder<DepositClaim>
     */
    public function waitingClaims(): Builder
    {
        return DepositClaim::query()->inViewedBranch()->open()->whereNull('statement_line_id');
    }

    /**
     * ⭐ নিজে থেকে মেলানোর প্রস্তাব — হুবহু অঙ্ক, একই খাত, ±N দিন; দুই দিকেই একটাই প্রার্থী হলে তবেই।
     *
     * @return list<array{claim: DepositClaim, line: BankStatementLine}>
     */
    public function suggestions(): array
    {
        $days = $this->windowDays();
        $claims = $this->waitingClaims()->whereNotNull('bank_account_id')
            ->with(['customer.location.parent', 'customer.branch', 'bankAccount'])->orderBy('claimed_on')->orderBy('id')->get();

        if ($claims->isEmpty()) {
            return [];
        }

        $lines = $this->freeLines()->whereIn('bank_account_id', $claims->pluck('bank_account_id')->unique()->all())->get();

        $picked = [];
        foreach ($claims as $claim) {
            $candidates = $lines->filter(fn (BankStatementLine $line) => self::fits($claim, $line, $days));

            $reference = self::plain($claim->reference);
            if ($reference !== '' && $candidates->count() > 1) {
                $byReference = $candidates->filter(fn (BankStatementLine $line) => self::mentions($line, $reference));
                if ($byReference->count() === 1) {
                    $candidates = $byReference;
                }
            }

            if ($candidates->count() === 1) {
                $picked[] = ['claim' => $claim, 'line' => $candidates->first()];
            }
        }

        // ⛔ একই ব্যাংক-সারি দুই খবরের একমাত্র প্রার্থী — যন্ত্র বলতে পারে না কোনটা কার, তাই দুইটাই মানুষের হাতে
        $count = array_count_values(array_map(fn (array $p) => (int) $p['line']->id, $picked));

        return array_values(array_filter($picked, fn (array $p) => $count[(int) $p['line']->id] === 1));
    }

    /**
     * ⓘ প্রস্তাবের তিন শর্ত — হুবহু অঙ্ক, একই খাত, তারিখ ±N দিন। হাতে মেলানো এগুলো চায় না।
     */
    public static function fits(DepositClaim $claim, BankStatementLine $line, int $days): bool
    {
        return $claim->bank_account_id !== null
            && (int) $line->bank_account_id === (int) $claim->bank_account_id
            && bccomp((string) $line->credit, (string) $claim->amount, 4) === 0
            && bccomp((string) $line->debit, '0', 4) === 0
            && abs(Carbon::parse($line->trx_date)->startOfDay()->diffInDays(Carbon::parse($claim->claimed_on)->startOfDay(), false)) <= $days;
    }

    /**
     * ⭐ মেলানো — খবরটা এই ব্যাংক-সারির সাথে। এক সারি এক খবর, এক খবর এক সারি।
     */
    public function match(DepositClaim $claim, BankStatementLine $line): DepositClaim
    {
        return DB::transaction(function () use ($claim, $line) {
            $fresh = DepositClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw ValidationException::withMessages(['claim' => __('sales::portal.already_decided')]);
            }
            if ($fresh->statement_line_id !== null) {
                throw ValidationException::withMessages(['claim' => __('sales::deposit.claim_already_matched')]);
            }

            $row = BankStatementLine::query()->whereKey($line->getKey())->lockForUpdate()->first();
            if ($row === null || bccomp((string) $row->credit, '0', 4) <= 0 || bccomp((string) $row->debit, '0', 4) !== 0) {
                throw ValidationException::withMessages(['line' => __('sales::deposit.line_not_a_deposit')]);
            }
            if ($row->matched_line_id !== null) {
                throw ValidationException::withMessages(['line' => __('sales::deposit.line_in_the_books')]);
            }

            $taken = DepositClaim::query()->withoutGlobalScopes()->where('statement_line_id', $row->id)->whereNull('deleted_at')->exists();
            if ($taken) {
                throw ValidationException::withMessages(['line' => __('sales::deposit.line_already_used')]);
            }

            if ($fresh->bank_account_id !== null && (int) $fresh->bank_account_id !== (int) $row->bank_account_id) {
                throw ValidationException::withMessages(['line' => __('sales::deposit.other_bank_account')]);
            }

            $fresh->update([
                'statement_line_id' => $row->id,
                // ⓘ খবরে খাত না থাকলে (বিকাশ, নগদ) ব্যাংক যে খাতে টাকা পেল সেটাই
                'bank_account_id' => $row->bank_account_id,
                'matched_by' => Actor::userId(),
                'matched_at' => now(),
                // ⓘ মেলানোই যাচাই — দোকানি আর SR দেখেন কেউ ধরেছেন
                'status' => $fresh->status === DepositClaim::PENDING ? DepositClaim::VERIFYING : $fresh->status,
            ]);

            return $claim->setRawAttributes($fresh->refresh()->getAttributes(), true);
        });
    }

    /**
     * ⭐ মিল খোলা — কারণসহ। অনুমোদনের অপেক্ষার খসড়া আদায় থাকলে সেটা বাতিল, অপেক্ষার অনুরোধ তুলে নেওয়া।
     */
    public function unmatch(DepositClaim $claim, string $reason): DepositClaim
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('sales::portal.reason_required')]);
        }

        return DB::transaction(function () use ($claim, $reason) {
            $fresh = DepositClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw ValidationException::withMessages(['claim' => __('sales::portal.already_decided')]);
            }
            if ($fresh->statement_line_id === null) {
                throw ValidationException::withMessages(['claim' => __('sales::deposit.not_matched')]);
            }

            $this->dropTheWaitingDraft($fresh, $reason);

            $fresh->update([
                'statement_line_id' => null,
                'matched_by' => null,
                'matched_at' => null,
                'unmatch_reason' => trim($reason),
            ]);

            return $claim->setRawAttributes($fresh->refresh()->getAttributes(), true);
        });
    }

    /**
     * ⓘ সইয়ের অপেক্ষার খসড়া আদায় — বাতিল আর অনুরোধ তুলে নেওয়া; খবর আবার খোলা হয়, আদায়হীন। পাকা আদায়ে হাত দেয় না।
     */
    public function dropTheWaitingDraft(DepositClaim $claim, string $reason): void
    {
        if ($claim->collection_id === null) {
            return;
        }

        $collection = Collection::query()->withoutGlobalScope('user-branch')->find($claim->collection_id);

        if ($collection !== null && $collection->status === \App\Core\Support\DocumentStatus::DRAFT) {
            $approvals = app(\App\Core\Engines\Approval\ApprovalEngine::class);
            $waiting = $approvals->latestFor($collection, 'collection');
            if ($waiting !== null && $waiting->status === \App\Models\Approval::PENDING) {
                $approvals->withdrawStale($waiting);
            }

            app(CollectionService::class)->cancel($collection, $reason);
        }

        $claim->forceFill(['collection_id' => null])->save();
    }

    private function assertBankAccount(Account $account): void
    {
        if (! ($account->isBank() || $account->isMfs()) || $account->is_group) {
            throw ValidationException::withMessages(['account_id' => __('sales::deposit.not_a_bank_account')]);
        }
    }

    private static function plain(?string $reference): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim((string) $reference)));
    }

    private static function mentions(BankStatementLine $line, string $reference): bool
    {
        $haystack = self::plain($line->reference).'|'.self::plain($line->description);

        return str_contains($haystack, $reference);
    }
}
