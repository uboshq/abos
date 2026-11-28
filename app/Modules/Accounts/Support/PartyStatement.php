<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\PartyLedger;
use App\Core\Support\ViewedBranch;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ হিসাব বিবরণী — একজন পক্ষের খাতা, যেকোনো সময়ের, সব সারি (মালিক, ১৫ নভেম্বর ২০২৬: "ব্যাংকের মতো … ৫ বছরের লেজার প্রিন্ট";
 * নমুনা অনুমোদিত, [[party-statement-owner-spec-15-nov]])।
 *
 * ── মালিকের কলাম, তাঁর ক্রমে ─────────────────────────────────────────────────────
 * ক্র. নং · ভাউচার নং · তারিখ · বিবরণ · ধরন · ডেবিট · ক্রেডিট · জের।
 *   - ধরন: সেলস / রিসিভ / পেমেন্ট / পারচেজ / রিটার্ন / জার্নাল — খতিয়ানের উৎস থেকে ([[KIND]])।
 *   - বিবরণ: কাগজের নাম, অথবা টাকা কোথায় জমা হলো বা কোথা থেকে গেল (নগদ / ব্যাংকের নাম), তার পাশে কাগজের ন্যারেশন — থাকলে।
 *     ⛔ পণ্যের নাম নয়, বিলের মেয়াদ নয় (মালিক: "বাড়তি কাজ না করলে করতে পারো না?")।
 *
 * ── হিসাব পর্দার খাতার হুবহু ─────────────────────────────────────────────────────────
 * ⓘ একই খতিয়ানের সারি, একই শাখা ([[ViewedBranch::narrow()]]), সম্পাদনার উল্টো জোড়া বাদ ([[PartyLedger::withoutUndoneEdits()]]) —
 *   তাই বিবরণীর শেষ জের দলের পাতার বকেয়ার সমান। শুরুর জের = "থেকে"-র আগের সব সারির যোগফল, একই ছাঁকনিতে।
 */
final class PartyStatement
{
    /** ⓘ এক বিবরণীর সর্বোচ্চ সারি — এর বেশি হলে তারিখ ছোট করতে বলা হয় (পাতা ধীর হয় না) */
    public const MAX_ROWS = 20000;

    /** খতিয়ানের উৎস → ধরন (মালিকের শব্দ); ⓘ চেক দিক ধরে — দলের ক্রেডিট মানে রিসিভ, ডেবিট মানে পেমেন্ট */
    public const KIND = [
        'sales_invoice' => 'sales',
        'sales_return' => 'return',
        'collection' => 'receive',
        'receipt_voucher' => 'receive',
        'payment_voucher' => 'payment',
        'purchase_payment' => 'payment',
        'expense_voucher' => 'payment',
        'purchase_bill' => 'purchase',
        'purchase_return' => 'return',
        'journal_voucher' => 'journal',
        'contra_voucher' => 'journal',
        'note' => 'journal',
        'opening' => 'opening',
    ];

    /** কাগজের নাম যেখানে বিবরণের শুরু (টাকার সারিতে নাম নয়, নগদ বা ব্যাংকের নাম) */
    private const DOCUMENT = [
        'sales_invoice' => 'accounts::statement.doc.sales_invoice',
        'sales_return' => 'accounts::statement.doc.sales_return',
        'purchase_bill' => 'accounts::statement.doc.purchase_bill',
        'purchase_return' => 'accounts::statement.doc.purchase_return',
        // ⓘ পক্ষের শুরুর বাকি — উৎস "customer:opening" / "supplier:opening" ([[OpeningBalanceService::SOURCE_SUFFIX]])
        'opening' => 'accounts::statement.doc.opening',
    ];

    public function __construct(private readonly PartyRegistry $parties, private readonly DrillResolver $drill) {}

    /**
     * @return array{opening: string, rows: list<array{sl: int, date: string, no: string, kind: string, particulars: string,
     *     debit: ?string, credit: ?string, balance: string}>, debit: string, credit: string, closing: string, normal: string, truncated: bool}
     */
    public function build(string $type, int $id, ?string $from, string $to): array
    {
        /*
         * ⓘ সরবরাহকারী: বিল-না-আসা মালের (GRNI) সারি দেনা নয় — পাতার দেনার হুবহু ভিত্তি ([[SupplierController::ledgerPart()]],
         *   [[BalanceConfirmation]])। নাহলে বিবরণীর শেষ জের পাতার দেনার সাথে মিলত না।
         */
        $grni = $type === 'supplier'
            ? ((StandardChart::find(StandardChart::GOODS_RECEIVED_NOT_INVOICED)?->selfAndDescendants() ?? collect())
                ->pluck('id')->map(fn ($v) => (int) $v)->values()->all() ?: [0])
            : null;

        $base = fn (): Builder => PartyLedger::withoutUndoneEdits(
            ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')->forParty($type, $id)
                ->when($grni !== null, fn ($q) => $q->whereNotIn('ledger_entries.account_id', $grni)));

        // ⓘ সরবরাহকারীর স্বাভাবিক জের ক্রেডিট (আমরা দেব), বাকিদের ডেবিট — শেষের মেলানোয় কোনটা যোগ কোনটা বাদ
        return $this->compose($base, $from, $to, $type === 'supplier' ? 'Cr' : 'Dr', null);
    }

    /**
     * ⭐ অ্যাকাউন্ট লেজার — যেকোনো হিসাবের খাত (সাধারণ খতিয়ান), একই ছাঁচে (মালিক, ১৫ নভেম্বর ২০২৬)।
     *
     * ⓘ দলের খাত বাছলে তার নিচের সব খাত মিলিয়ে। বিবরণ: সারিটা যার নামে (পক্ষ) — না থাকলে একই কাগজের অপর খাতের নাম — পাশে
     *   কাগজের ন্যারেশন। স্বাভাবিক জের খাতের প্রকৃতি ধরে (সম্পদ/খরচ ডেবিট, দায়/আয় ক্রেডিট)।
     *
     * @return array<string, mixed> [[build()]]-এর একই আকার
     */
    public function buildAccount(Account $account, ?string $from, string $to): array
    {
        $ids = $account->selfAndDescendants()->pluck('id')->map(fn ($v) => (int) $v)->values()->all() ?: [(int) $account->id];

        $base = fn (): Builder => PartyLedger::withoutUndoneEdits(
            ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')->whereIn('ledger_entries.account_id', $ids));

        return $this->compose($base, $from, $to, $account->nature === Account::DEBIT ? 'Dr' : 'Cr', $ids);
    }

    /**
     * @param  \Closure(): Builder<LedgerEntry>  $base
     * @param  list<int>|null  $accountIds  অ্যাকাউন্ট লেজার হলে নিজের খাতগুলো (অপর পাশের নাম খুঁজতে); পক্ষের খাতায় `null`
     * @return array<string, mixed>
     */
    private function compose(\Closure $base, ?string $from, string $to, string $normal, ?array $accountIds): array
    {
        $opening = '0';

        if ($from !== null) {
            $sum = $base()->where('ledger_entries.trx_date', '<', $from)
                ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) - COALESCE(SUM(ledger_entries.credit), 0) AS net')->value('net');
            $opening = bcadd((string) ($sum ?? '0'), '0', 2);
        }

        $entries = $base()
            ->when($from !== null, fn ($q) => $q->where('ledger_entries.trx_date', '>=', $from))
            ->where('ledger_entries.trx_date', '<=', $to)
            ->orderBy('ledger_entries.trx_date')->orderBy('ledger_entries.id')
            ->limit(self::MAX_ROWS + 1)
            ->get(['ledger_entries.id', 'ledger_entries.trx_date', 'ledger_entries.document_no', 'ledger_entries.debit',
                'ledger_entries.credit', 'ledger_entries.source_type', 'ledger_entries.source_id', 'ledger_entries.narration',
                'ledger_entries.party_type', 'ledger_entries.party_id']);

        $truncated = $entries->count() > self::MAX_ROWS;
        $entries = $entries->take(self::MAX_ROWS);

        $money = $accountIds === null ? $this->moneyAccounts($entries) : $this->counterparts($entries, $accountIds);
        $narration = $this->narrations($entries);

        $rows = [];
        $balance = $opening;
        $debit = '0';
        $credit = '0';

        foreach ($entries->values() as $i => $entry) {
            $dr = bcadd((string) $entry->debit, '0', 2);
            $cr = bcadd((string) $entry->credit, '0', 2);
            $balance = bcadd($balance, bcsub($dr, $cr, 2), 2);
            $debit = bcadd($debit, $dr, 2);
            $credit = bcadd($credit, $cr, 2);

            [$sourceBase, $undone] = $this->baseOf((string) $entry->source_type);
            $key = $accountIds === null ? $entry->source_type.':'.$entry->source_id : 'row:'.$entry->id;

            $rows[] = [
                'sl' => $i + 1,
                'date' => (string) $entry->trx_date?->toDateString(),
                'no' => (string) $entry->document_no,
                'kind' => $this->kind($sourceBase, $dr, $cr),
                'particulars' => $this->particulars($sourceBase, $undone, $money[$key] ?? '',
                    trim((string) ($narration[$sourceBase.':'.$entry->source_id] ?? '')), $accountIds !== null),
                'debit' => bccomp($dr, '0', 2) === 0 ? null : $dr,
                'credit' => bccomp($cr, '0', 2) === 0 ? null : $cr,
                'balance' => $balance,
            ];
        }

        return [
            'opening' => $opening,
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            'closing' => $balance,
            'normal' => $normal,
            'truncated' => $truncated,
        ];
    }

    /**
     * অ্যাকাউন্ট লেজারের প্রতিটা সারির অপর পাশ — সারিটা যে পক্ষের নামে, নয়তো একই কাগজের অন্য খাতগুলোর নাম।
     *
     * @param  iterable<LedgerEntry>  $entries
     * @param  list<int>  $ownIds
     * @return array<string, string> "row:আইডি" => লেখা
     */
    private function counterparts(iterable $entries, array $ownIds): array
    {
        $pairs = [];
        $bySource = [];

        foreach ($entries as $entry) {
            if ($entry->party_type !== null && (int) $entry->party_id > 0) {
                $pairs[] = [(string) $entry->party_type, (int) $entry->party_id];
            } else {
                $bySource[(string) $entry->source_type][] = (int) $entry->source_id;
            }
        }

        $bn = app()->getLocale() === 'bn';
        $others = [];
        $otherParties = [];

        foreach ($bySource as $sourceType => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                $found = DB::table('ledger_entries as le')
                    ->join('accounts as a', 'a.id', '=', 'le.account_id')
                    ->where('le.company_id', CompanyContext::id())
                    ->where('le.source_type', $sourceType)
                    ->whereIn('le.source_id', $chunk)
                    ->whereNotIn('le.account_id', $ownIds)
                    ->orderBy('a.id')
                    ->get(['le.source_id', 'le.party_type', 'le.party_id', 'a.name_en', 'a.name_bn']);

                foreach ($found as $row) {
                    $key = $sourceType.':'.$row->source_id;

                    // ⓘ অপর পাশে পক্ষ থাকলে তার নাম (Tally-র খতিয়ানের মতো: নগদের খাতায় "যার কাছ থেকে এল"), নয়তো খাতের নাম
                    if ($row->party_type !== null && (int) $row->party_id > 0) {
                        $otherParties[$key] ??= [(string) $row->party_type, (int) $row->party_id];
                        $pairs[] = [(string) $row->party_type, (int) $row->party_id];

                        continue;
                    }

                    $name = $bn && filled($row->name_bn) ? (string) $row->name_bn : (string) $row->name_en;
                    $others[$key] = isset($others[$key]) && ! str_contains($others[$key], $name) ? $others[$key].', '.$name : ($others[$key] ?? $name);
                }
            }
        }

        $partyNames = $pairs === [] ? [] : $this->parties->labelsOf($pairs);

        foreach ($otherParties as $key => [$ptype, $pid]) {
            $label = $partyNames[$ptype.':'.$pid] ?? '';

            if ($label !== '') {
                $others[$key] = $label;
            }
        }

        $out = [];

        foreach ($entries as $entry) {
            $party = $entry->party_type !== null ? ($partyNames[$entry->party_type.':'.$entry->party_id] ?? '') : '';
            $out['row:'.$entry->id] = $party !== '' ? $party : ($others[$entry->source_type.':'.$entry->source_id] ?? '');
        }

        return $out;
    }

    /** @return array{0: string, 1: bool} উৎসের মূল নাম, আর এটা বাতিল বা সম্পাদনার উল্টো সারি কি না */
    private function baseOf(string $sourceType): array
    {
        // ⓘ শুরুর বাকি — যে পক্ষেরই হোক, একটাই ধরন (লাইভে দেখা: "core.source.customer:opening" কাঁচা লেখা উঠেছিল)
        if (str_ends_with($sourceType, ':opening')) {
            return ['opening', false];
        }

        foreach ([':reversal', ':cancel'] as $suffix) {
            if (str_ends_with($sourceType, $suffix)) {
                return [substr($sourceType, 0, -strlen($suffix)), true];
            }
        }

        return [$sourceType, false];
    }

    private function kind(string $sourceBase, string $dr, string $cr): string
    {
        $kind = self::KIND[$sourceBase] ?? ($sourceBase === 'cheque' ? (bccomp($cr, '0', 2) > 0 ? 'receive' : 'payment') : null);

        if ($kind !== null) {
            return (string) __('accounts::statement.kind.'.$kind);
        }

        // ⛔ অজানা উৎসে কাঁচা চাবি নয় — নাম জানা থাকলে নাম, নাহলে জার্নাল
        return Lang::has('core.source.'.$sourceBase) ? (string) __('core.source.'.$sourceBase) : (string) __('accounts::statement.kind.journal');
    }

    private function particulars(string $sourceBase, bool $undone, string $money, string $narration, bool $accountLedger = false): string
    {
        $head = match (true) {
            // ⓘ অ্যাকাউন্ট লেজারে আগে অপর পাশ — পক্ষের নাম, নয়তো অপর খাত (আন্তর্জাতিক সাধারণ খতিয়ানের "Particulars")
            $accountLedger && $money !== '' => $money,
            isset(self::DOCUMENT[$sourceBase]) => (string) __(self::DOCUMENT[$sourceBase]),
            $money !== '' => $money,
            // ⓘ জার্নালে ন্যারেশনই বিবরণ; ন্যারেশন না থাকলে উৎসের নাম
            $narration !== '' && in_array(self::KIND[$sourceBase] ?? '', ['journal', 'receive', 'payment'], true) => '',
            default => Lang::has('core.source.'.$sourceBase) ? (string) __('core.source.'.$sourceBase) : '',
        };

        $text = trim($head.($head !== '' && $narration !== '' ? ' — ' : '').$narration);

        return $undone ? __('accounts::statement.undone').' — '.$text : $text;
    }

    /**
     * টাকার সারির অপর পাশ — নগদ বা কোন ব্যাংক (একই কাগজের খতিয়ানের সারি, যার খাত টাকার খাত)।
     *
     * @param  iterable<LedgerEntry>  $entries
     * @return array<string, string> "উৎস:আইডি" => খাতের নাম (একাধিক হলে কমা দিয়ে)
     */
    private function moneyAccounts(iterable $entries): array
    {
        $bySource = [];

        foreach ($entries as $entry) {
            [$sourceBase] = $this->baseOf((string) $entry->source_type);
            $kind = self::KIND[$sourceBase] ?? ($sourceBase === 'cheque' ? 'receive' : null);

            if (in_array($kind, ['receive', 'payment'], true)) {
                $bySource[(string) $entry->source_type][] = (int) $entry->source_id;
            }
        }

        $names = [];
        $bn = app()->getLocale() === 'bn';

        foreach ($bySource as $sourceType => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                $found = DB::table('ledger_entries as le')
                    ->join('accounts as a', 'a.id', '=', 'le.account_id')
                    ->where('le.company_id', CompanyContext::id())
                    ->where('le.source_type', $sourceType)
                    ->whereIn('le.source_id', $chunk)
                    ->whereIn('a.money_kind', Account::MONEY_KINDS)
                    ->orderBy('a.id')
                    ->get(['le.source_id', 'a.name_en', 'a.name_bn']);

                foreach ($found as $row) {
                    $name = $bn && filled($row->name_bn) ? (string) $row->name_bn : (string) $row->name_en;
                    $key = $sourceType.':'.$row->source_id;
                    $names[$key] = isset($names[$key]) && ! str_contains($names[$key], $name) ? $names[$key].', '.$name : $name;
                }
            }
        }

        return $names;
    }

    /**
     * কাগজের নিজের ন্যারেশন — উৎসের মডেলের টেবিলে `narration` ঘর থাকলে (বিল, আদায়, পরিশোধ, ভাউচার)।
     * ⓘ খতিয়ানের সারির নিজের ন্যারেশন নয়: আদায়ে সেখানে সিস্টেমের লেখা ("বকেয়ার বিপরীতে") থাকে, মানুষের লেখা নয়।
     *
     * @param  iterable<LedgerEntry>  $entries
     * @return array<string, string> "মূল-উৎস:আইডি" => ন্যারেশন
     */
    private function narrations(iterable $entries): array
    {
        $bySource = [];

        foreach ($entries as $entry) {
            [$sourceBase] = $this->baseOf((string) $entry->source_type);
            $bySource[$sourceBase][] = (int) $entry->source_id;
        }

        $map = $this->drill->map();
        $out = [];

        foreach ($bySource as $sourceBase => $ids) {
            $class = $map[$sourceBase] ?? null;

            if ($class === null) {
                continue;
            }

            $model = new $class;

            if (! Schema::hasColumn($model->getTable(), 'narration')) {
                continue;
            }

            foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
                // ⓘ মডেলের নিজের কোয়েরি — কোম্পানির গ্লোবাল স্কোপ নিজেই বসে, অন্য কোম্পানির লেখা আসে না
                foreach ($model->newQuery()->whereKey($chunk)->pluck('narration', $model->getKeyName()) as $sid => $text) {
                    if (filled($text)) {
                        $out[$sourceBase.':'.$sid] = (string) $text;
                    }
                }
            }
        }

        return $out;
    }
}
