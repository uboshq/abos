<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\HandLoanMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ⭐ পুরনো হাতধারের খতিয়ান-সারিতে মানুষটার নাম বসানো — সমন্বয়কের আদেশ, ৫ অক্টোবর ২০২৬ (মালিকের অভিযোগ: আভা ট্রেডে
 * হাতধারের টাকা ব্যক্তির খাতায় আসে না)।
 *
 * ── কী বদলায় ──────────────────────────────────────────────────────────────
 * 8c6b2195-এর আগে হাতধারের চলাচলের ভাউচার হাতধার খাতের (১১৭০ আর নিচের সব) সারিতে কারও নাম বসাত না, তাই ঐ টাকা
 * ব্যক্তির লেজারে আর "মোট পাওনা"-য় আসত না। এই কমান্ড এমন প্রতিটা ভাউচারের খোলা দাখিলা উল্টায়, আর একই তারিখে, একই
 * নম্বরে, একই অঙ্কে আবার বসায় — এবার হাতধার খাতের সারিতে মানুষটার নামসহ।
 *
 * ── ⛔ কেন UPDATE নয় ──────────────────────────────────────────────────────
 * খতিয়ানের সারি হ্যাশ-শিকলে বাঁধা ([[LedgerChain]]); পুরনো সারি বদলালে শিকল ভাঙে। তাই খাতায় লেখার একমাত্র পথ দিয়েই
 * ([[PostingEngine::reverse()]] তারপর [[PostingEngine::post()]]) — পুরনো সারি থাকে, উল্টো সারি আর নতুন সারি যোগ হয়।
 *
 * ── যা বদলায় না ───────────────────────────────────────────────────────────
 * হাতধারের বাকি (চলাচল থেকে গোনা; চলাচলের ভাউচার আর তার উল্টো সারি [[LoanLedgerReports::looseRows()]]-এ বাদ), আর
 * হাতধার খাতের মোট জের (উল্টো + নতুন = শূন্য বদল)। ⓘ বদলায় কেবল "মোট পাওনা" — এখন হাতধারও তাঁর নামে, তাই পুরনো
 * হাতধারে "অন্য খাতে" লেখা আর ওঠে না।
 *
 * ── নিরাপত্তা ────────────────────────────────────────────────────────────
 * `--apply` ছাড়া কিছুই লেখে না — কেবল কোম্পানি ধরে কয়টা ভাউচার, কত টাকা, কার নামে। একই ভাউচার দুবার ধরে না: নাম
 * বসে গেলে খোলা দাখিলায় আর নামহীন সারি থাকে না। বন্ধ মাস বা বন্ধ অর্থবছরের ভাউচার ছোঁয় না — আলাদা করে বলে।
 */
class HandLoanParty extends Command
{
    protected $signature = 'abos:hand-loan-party
        {--company= : কেবল এই কোম্পানি (কোড)}
        {--apply : সত্যিই উল্টে আবার বসায়; না দিলে কেবল গুনে দেখায়}';

    protected $description = 'পুরনো হাতধারের ভাউচারে হাতধার খাতের সারিতে মানুষটার নাম বসায় — উল্টো সারি আর নতুন সারি দিয়ে, শুকনো চালানো আগে';

    public function handle(PostingEngine $engine): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('company');
        $done = 0;
        $skipped = 0;

        $companies = Company::query()->when($only, fn ($q, $code) => $q->where('code', $code))->orderBy('id')->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $engine, $apply, &$done, &$skipped) {
                $found = $this->candidates();

                if ($found === []) {
                    return;
                }

                $total = '0';
                $byPerson = [];

                foreach ($found as $c) {
                    $total = bcadd($total, $c['amount'], 4);
                    $byPerson[$c['person']] = bcadd($byPerson[$c['person']] ?? '0', $c['amount'], 4);
                }

                $this->line(sprintf('%s: %dটা ভাউচার, মোট %s', $company->code, count($found), bcadd($total, '0', 2)));
                foreach ($byPerson as $who => $amount) {
                    $this->line(sprintf('    %s — %s', $who, bcadd($amount, '0', 2)));
                }

                if (! $apply) {
                    $done += count($found);

                    return;
                }

                foreach ($found as $c) {
                    try {
                        DB::transaction(fn () => $this->repost($engine, $c));
                        $done++;
                    } catch (Throwable $e) {
                        $skipped++;
                        $this->warn("    {$c['document_no']} বসানো যায়নি — {$e->getMessage()}");
                    }
                }
            });
        }

        if ($done === 0 && $skipped === 0) {
            $this->info('নাম বসানোর মতো কোনো পুরনো হাতধারের ভাউচার নেই।');
        } else {
            $this->info($apply ? "{$done}টা ভাউচারে নাম বসেছে।" : "{$done}টা ভাউচারে নাম বসানোর মতো আছে — বসাতে --apply দিন।");
        }

        if ($skipped > 0) {
            $this->warn("{$skipped}টা বসানো যায়নি — উপরের কারণগুলো দেখুন (বন্ধ মাস বা বন্ধ অর্থবছর হলে আগে খুলতে হয়)।");
        }

        return self::SUCCESS;
    }

    /**
     * যে হাতধারের ভাউচারের খোলা দাখিলায় হাতধার খাতের সারি নামহীন।
     *
     * @return list<array{type: string, voucher_id: int, document_no: ?string, person: string, person_id: int, amount: string, entries: Collection<int, LedgerEntry>}>
     */
    private function candidates(): array
    {
        $head = StandardChart::find(StandardChart::HAND_LOAN);
        $heads = $head === null ? [] : $head->selfAndDescendants()->modelKeys();

        if ($heads === []) {
            return [];
        }

        $out = [];
        $seen = [];

        $moves = HandLoanMovement::query()
            ->whereNotNull('voucher_id')
            ->with(['voucher', 'account.person'])
            ->orderBy('id')
            ->get();

        foreach ($moves as $move) {
            $voucher = $move->voucher;

            if ($voucher === null || $voucher->status !== DocumentStatus::CONFIRMED || isset($seen[$voucher->id])) {
                continue;
            }

            $seen[$voucher->id] = true;
            $type = Voucher::SOURCE_TYPES[$voucher->type] ?? null;

            if ($type === null || $move->account?->person_id === null) {
                continue;
            }

            $lastReversal = LedgerEntry::query()->where('source_type', $type.':reversal')->where('source_id', $voucher->id)->max('id');
            $entries = LedgerEntry::query()
                ->where('source_type', $type)
                ->where('source_id', $voucher->id)
                ->when($lastReversal !== null, fn ($q) => $q->where('id', '>', $lastReversal))
                ->orderBy('id')
                ->get();

            $nameless = $entries->filter(fn (LedgerEntry $e) => in_array((int) $e->account_id, $heads, true) && $e->party_type === null);

            if ($nameless->isEmpty()) {
                continue;
            }

            $out[] = [
                'type' => $type,
                'voucher_id' => (int) $voucher->id,
                'document_no' => $voucher->document_no,
                'person' => (string) ($move->account->person?->name() ?? '#'.$move->account->person_id),
                'person_id' => (int) $move->account->person_id,
                'amount' => (string) $nameless->sum(fn (LedgerEntry $e) => bcadd((string) $e->debit, (string) $e->credit, 4)),
                'entries' => $entries,
                'heads' => $heads,
            ];
        }

        return $out;
    }

    /** একটা ভাউচার: খোলা দাখিলা উল্টো, তারপর একই তারিখ-নম্বর-অঙ্কে আবার — হাতধার খাতের সারিতে নামসহ। */
    private function repost(PostingEngine $engine, array $c): void
    {
        $entries = $c['entries'];
        $date = $entries->first()->trx_date;

        $engine->reverse($c['type'], $c['voucher_id'], $date, __('finance::loan_ledger.party_backfill'), null, $c['document_no']);

        $engine->post($c['type'], $c['voucher_id'], $date, $entries->map(fn (LedgerEntry $e) => [
            'account_id' => (int) $e->account_id,
            'debit' => (string) $e->debit,
            'credit' => (string) $e->credit,
            'party_type' => $e->party_type ?? (in_array((int) $e->account_id, $c['heads'], true) ? 'person' : null),
            'party_id' => $e->party_id ?? (in_array((int) $e->account_id, $c['heads'], true) ? $c['person_id'] : null),
            'cost_center_id' => $e->cost_center_id,
            'narration' => $e->narration,
            'source_line_id' => $e->source_line_id,
            'branch_id' => $e->branch_id,
        ])->values()->all(), $c['document_no']);
    }
}
