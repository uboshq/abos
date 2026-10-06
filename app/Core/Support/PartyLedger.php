<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\LedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * পার্টির পাতার "লেনদেন" — খোঁজা, ছাঁকনি, আর ছাঁকনিতেও সত্যি চলমান জের।
 *
 * মালিক, ৩ অক্টোবর ২০২৬: *"ফিল্টার অপশন দিতে হবে সার্চ অপশন দিতে হবে"* — গ্রাহক, সরবরাহকারী আর সেবাদাতার পাতায়।
 *
 * ── ছাঁকনি ─────────────────────────────────────────────────────────────
 * `q` (নম্বর, বিবরণ, বা ঠিক অঙ্কটা — ডেবিট বা ক্রেডিট) · `from`/`to` (তারিখ) · `kind` (কাগজের ধরন, [[KINDS]]) ·
 * `side` (কেবল ডেবিট বা কেবল ক্রেডিট)।
 *
 * ── ⛔ চলমান জের কখনো শূন্য থেকে শুরু নয় ────────────────────────────────
 * প্রতিটা দেখানো সারির জের = খাতার **সব** সারি (ছাঁকনি ছাড়া) ঐ সারি পর্যন্ত। অর্থাৎ ছাঁকনি কেবল ঠিক করে কোন সারি
 * দেখা যাবে, জের নয় — "কেবল ক্রেডিট" বাছলেও প্রতিটা সারির পাশে সেই মুহূর্তের আসল জের, যা খাতা আর মাথার অঙ্কের
 * সাথে মেলে। ⚠️ ছাঁকা সারিগুলো যোগ করে জের বানালে "কেবল আদায়" দেখালেই গ্রাহককে অগ্রিমে দেখাত।
 *
 * ⓘ জের রাখা হয় খাতার নিয়মে, ডেবিট − ক্রেডিট (`net`); পর্দা লেখে [[Money::drCr()]] দিয়ে — "(Dr) 250.79" /
 * "(Cr) 22,958.21"। ⓘ কোর কোনো মডিউল চেনে না: উৎসের নামগুলো কেবল খাতার `source_type`-এর লেখা।
 */
final class PartyLedger
{
    /**
     * কাগজের ধরন → খাতার `source_type`-এর শুরু (`:reversal` ইত্যাদি শেষাংশসহ মেলে)।
     * ⓘ "জার্নাল" বাকি সব — কোনো সারি কোনো ধরনের বাইরে পড়ে না।
     */
    public const KINDS = [
        'bill' => ['sales_invoice', 'delivery_challan', 'purchase_bill'],
        'money' => ['collection', 'purchase_payment', 'receipt_voucher', 'payment_voucher', 'cheque'],
        'return' => ['sales_return', 'purchase_return'],
        'note' => ['note'],
        'opening' => ['opening'],
        'journal' => [],
    ];

    public const SIDES = ['debit', 'credit'];

    public const PER_PAGE = 50;

    /**
     * ছাঁকা, পাতায় ভাগ করা সারি — প্রতিটার `net_balance` (ডেবিট − ক্রেডিট, খাতার সব সারি ঐ পর্যন্ত) বসানো।
     * সারিগুলো পুরনো থেকে নতুন; পর্দা চাইলে পরে উল্টায়।
     *
     * @param  Builder<LedgerEntry>  $base  এই পার্টির সব সারি (শাখা আর কোম্পানির দেয়াল আগেই বসানো)
     */
    public static function page(Builder $base, Request $request, bool $openAtEnd = false): LengthAwarePaginator
    {
        /* ⭐ সম্পাদিত কাগজের আগের সারি আর তার উল্টো সারি বাদ — জের, প্রারম্ভিক আর দেখানো সারি, তিনটা থেকেই ([[withoutUndoneEdits()]]) */
        $base = self::withoutUndoneEdits(clone $base);

        $order = fn (Builder $q) => $q->orderBy('ledger_entries.trx_date')->orderBy('ledger_entries.id');

        /*
         * ⭐ খাতা তারিখের ক্রমে, খুললে শেষ পাতা — মালিক, ৬ অক্টোবর ২০২৬ (আন্তর্জাতিক খাতার নিয়ম; নতুন লেনদেন চোখের সামনে)।
         * ⓘ `?page=` না থাকলে তবেই; পাতা বদলের তীর নিজের পাতা পাঠায়। ⛔ আগে প্রথম পাতায় সবচেয়ে পুরনো ৫০টা আসত, আর
         * আজকের বিল দ্বিতীয় পাতায় লুকিয়ে থাকত (বিক্রয় ধারার পরীক্ষা)।
         */
        $page = null;
        if ($openAtEnd && ! $request->has('page')) {
            $page = max(1, (int) ceil(self::filter(clone $base, $request)->reorder()->count() / self::PER_PAGE));
        }

        $rows = $order(self::filter(clone $base, $request))->paginate(self::PER_PAGE, ['*'], 'page', $page)->withQueryString();
        $items = $rows->getCollection();

        if ($items->isEmpty()) {
            return $rows;
        }

        $first = $items->first();
        $last = $items->last();

        /*
         * ⓘ প্রথম দেখানো সারির আগের সব কিছু — ছাঁকনির বাইরেরগুলোও।
         * ⛔ `reorder()` — ডাকনেওয়ালার ভিত্তিতে `ORDER BY` থাকে (গ্রাহকের পাতা), আর লাইভের ONLY_FULL_GROUP_BY
         * যোগফলের সাথে ক্রম মানে না: ৪ অক্টোবর ২০২৬ গ্রাহক ৪২৪-এর পাতা ৫০০ দিয়েছিল।
         */
        $opening = (string) ((clone $base)->reorder()->where(fn ($q) => $q
            ->where('ledger_entries.trx_date', '<', $first->trx_date)
            ->orWhere(fn ($w) => $w->where('ledger_entries.trx_date', $first->trx_date)->where('ledger_entries.id', '<', $first->id)))
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net') ?? '0');

        /* ⓘ প্রথম থেকে শেষ দেখানো সারি পর্যন্ত খাতার সব সারি — মাঝের লুকানো সারিও জেরে যোগ হয় */
        $span = $order((clone $base)
            ->where(fn ($q) => $q->where('ledger_entries.trx_date', '>', $first->trx_date)
                ->orWhere(fn ($w) => $w->where('ledger_entries.trx_date', $first->trx_date)->where('ledger_entries.id', '>=', $first->id)))
            ->where(fn ($q) => $q->where('ledger_entries.trx_date', '<', $last->trx_date)
                ->orWhere(fn ($w) => $w->where('ledger_entries.trx_date', $last->trx_date)->where('ledger_entries.id', '<=', $last->id))))
            ->get(['ledger_entries.id', 'ledger_entries.debit', 'ledger_entries.credit']);

        $running = new RunningBalance($opening);
        $after = [];

        foreach ($span as $entry) {
            $after[$entry->id] = $running->add($entry->debit, $entry->credit);
        }

        $items->each(fn (LedgerEntry $e) => $e->net_balance = $after[$e->id] ?? $running->current());

        return $rows;
    }

    /**
     * @param  Builder<LedgerEntry>  $query
     * @return Builder<LedgerEntry>
     */
    public static function filter(Builder $query, Request $request): Builder
    {
        $term = trim((string) $request->query('q'));
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));
        $kind = (string) $request->query('kind');
        $side = (string) $request->query('side');

        return $query
            ->when($term !== '', function ($q) use ($term) {
                $amount = str_replace(',', '', $term);

                $q->where(fn ($w) => $w
                    ->where('ledger_entries.document_no', 'like', '%'.$term.'%')
                    ->orWhere('ledger_entries.narration', 'like', '%'.$term.'%')
                    ->when(is_numeric($amount), fn ($a) => $a
                        ->orWhere('ledger_entries.debit', $amount)
                        ->orWhere('ledger_entries.credit', $amount)));
            })
            ->when($from !== null, fn ($q) => $q->where('ledger_entries.trx_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('ledger_entries.trx_date', '<=', $to))
            ->when(array_key_exists($kind, self::KINDS), fn ($q) => self::ofKind($q, $kind))
            ->when($side === 'debit', fn ($q) => $q->where('ledger_entries.debit', '>', 0))
            ->when($side === 'credit', fn ($q) => $q->where('ledger_entries.credit', '>', 0));
    }

    /** কোনো ছাঁকনি বসানো আছে কি না — খালি তালিকায় "কিছু মেলেনি" না "এখনো লেনদেন নেই" বলতে */
    public static function filtered(Request $request): bool
    {
        foreach (['q', 'from', 'to', 'kind', 'side'] as $key) {
            if (filled($request->query($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<LedgerEntry>  $query
     * @return Builder<LedgerEntry>
     */
    private static function ofKind(Builder $query, string $kind): Builder
    {
        $starts = fn ($q, array $prefixes) => $q->where(function ($w) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                $w->orWhere('ledger_entries.source_type', $prefix)
                    ->orWhere('ledger_entries.source_type', 'like', $prefix.':%');
            }
        });

        if ($kind !== 'journal') {
            return $starts($query, self::KINDS[$kind]);
        }

        $every = array_merge(...array_values(array_filter(self::KINDS)));

        return $query->where(fn ($q) => $q->whereNull('ledger_entries.source_type')
            ->orWhereNot(fn ($n) => $starts($n, $every)));
    }

    /** ⓘ ভুল লেখা তারিখ নীরবে বাদ — ঠিকানায় যা খুশি লিখে ৫০০ নয় */
    private static function date(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? $raw : null;
    }

    /**
     * ⭐ দলের খাতা "তাজা" — সম্পাদিত কাগজের কেবল শেষ রূপ (মালিক, ৪ অক্টোবর ২০২৬, INV-0002: "party ledger fresh hote hobe,
     * edite tai bosbe; history audit e thakbe")।
     *
     * ⓘ সম্পাদনায় খাতা আগের সারিগুলো উল্টায় (`<source>:reversal`, একই কাগজ) তারপর একই কাগজ আবার বসায়
     * ([[PostingEngine::reverse()]], [[SaleEditor]])। বাদ যায় দুই রকম সারি:
     *   ১ · উল্টো সারি, যার পরে একই কাগজ আবার বসেছে;
     *   ২ · আগের সারি, যাকে উল্টানো হয়েছে আর তারপর আবার বসেছে।
     * ⓘ জোড়াটা মিলে ঠিক শূন্য — তাই বকেয়া একটুও বদলায় না, কেবল দেখা পরিষ্কার। খাতায় সব সারি থাকে, অডিটেও।
     * ⛔ বাতিল কাগজ (উল্টানো, আর বসেনি — CXL) বাদ যায় না: তার উল্টো কাগজটাই মালিকের নিয়মে দেখানোর কথা।
     *
     * @param  Builder<LedgerEntry>  $query
     * @return Builder<LedgerEntry>
     */
    public static function withoutUndoneEdits(Builder $query): Builder
    {
        $t = $query->getModel()->getTable();

        return $query
            ->whereNot(fn ($q) => $q->where($t.'.source_type', 'like', '%:reversal')
                ->whereExists(fn ($again) => $again->selectRaw('1')->from($t.' as again')
                    ->whereColumn('again.company_id', $t.'.company_id')
                    ->whereColumn('again.source_id', $t.'.source_id')
                    ->whereRaw("CONCAT(again.source_type, ':reversal') = {$t}.source_type")
                    ->whereColumn('again.id', '>', $t.'.id')))
            ->whereNot(fn ($q) => $q->whereExists(fn ($undo) => $undo->selectRaw('1')->from($t.' as undo')
                ->whereColumn('undo.company_id', $t.'.company_id')
                ->whereColumn('undo.source_id', $t.'.source_id')
                ->whereRaw("undo.source_type = CONCAT({$t}.source_type, ':reversal')")
                ->whereColumn('undo.id', '>', $t.'.id')
                ->whereExists(fn ($again) => $again->selectRaw('1')->from($t.' as again')
                    ->whereColumn('again.company_id', $t.'.company_id')
                    ->whereColumn('again.source_id', $t.'.source_id')
                    ->whereColumn('again.source_type', $t.'.source_type')
                    ->whereColumn('again.id', '>', 'undo.id'))));
    }
}
