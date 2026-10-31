<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Contracts\ClassifiesDrafts;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * ⭐ তালিকার মোট — শুধু পাকা কাগজ গোনে; অপেক্ষা, খসড়া, ফেরানো আর বাতিল নিজের নিজের লাইনে (fe-র নিয়ম; তালিকার মোটের
 * চুক্তি v6, a4-list-summary-contract-24-oct.md — ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ফেরে ─────────────────────────────────────────────────────────────
 *   period    — যে তারিখ সত্যিই খাটানো হলো, অথবা null (সব তারিখ);
 *   counted   — {count, total}: পাকা কাগজ (DocumentStatus::POSTED-এর মতো);
 *   open      — counted-এর ভিতরের অংশ, যোগফলে আলাদা নয় (যার আছে, তারই);
 *   pending, draft, rejected, cancelled — {count, total}; যে দল কাগজে হয়ই না, তার চাবি নেই।
 * ⓘ টাকা চার দশমিকের লেখা, গোনা int। পুরো ছাঁকা ফলের উপর — তালিকার নিজের কোয়েরি, পাতা ভাগ ছাড়া ([[GrandTotals]]-এর মতো)।
 *
 * ── দুই ভাগে ─────────────────────────────────────────────────────────────
 * ⓵ status দিয়েই যে দল ঠিক হয়: SQL-এ GROUP BY status, SUM(DECIMAL) — DECIMAL-এর যোগ নির্ভুল, তাই ফল লেখা হিসেবে
 *   পড়া হয়, কখনো (float) নয়, তারপর bcmath।
 * ⓶ যে status-এর সারি কাগজের সই-সেবা ভাগ করে ([[ClassifiesDrafts]]): ঐ সারিগুলো তুলে সেবাকে একসাথে দেওয়া হয়, যোগ bcmath-এ।
 *   ⛔ কখনো কাটা নয় — অনেক খসড়া হলে কেবল লগ।
 *
 * ── ⛔ পাহারা ────────────────────────────────────────────────────────────
 *   · টাকার কলাম DECIMAL না হলে থামে (DOUBLE/FLOAT-এর যোগ গোল হয়) — প্রক্রিয়ায় একবার মাপা, টেবিল.কলাম ধরে;
 *   · অচেনা status এলে থামে — কোনো সারি নীরবে সব দলের বাইরে পড়তে পারে না;
 *   · সই-সেবা কোনো খসড়ার উত্তর না দিলে, বা নিজের ঘোষণার বাইরের দল দিলে, থামে।
 * ⓘ [[EveryListSummaryAddsDecimalsTest]] প্রতিটা [[SummarisesItsList]]-এর আকার আগেই যাচাই করে, তাই এই থামাগুলো পরীক্ষায় ধরা পড়ে।
 */
final class ListSummary
{
    public const COUNTED = 'counted';

    public const PENDING = 'pending';

    public const DRAFT = 'draft';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** এই status-এর সারি কাগজের সই-সেবা ভাগ করবে */
    public const CLASSIFY = 'classify';

    /** চুক্তির ক্রম — summary-র চাবিও এই ক্রমে */
    public const GROUPS = [self::COUNTED, self::PENDING, self::DRAFT, self::REJECTED, self::CANCELLED];

    /** এর বেশি খসড়া হলে লগ — তবু সব গোনা হয় */
    public const MANY_DRAFTS = 500;

    /** খসড়া এতগুলো করে তোলা হয় */
    public const CHUNK = 500;

    /** @var array<string, string>  "টেবিল.কলাম" => ধরন — প্রক্রিয়ায় একবার */
    private static array $columnTypes = [];

    /**
     * @param  array{from: ?string, to: ?string}|null  $period
     * @return array<string, mixed>
     */
    public static function of(Builder $query, SummaryShape $shape, ?array $period = null): array
    {
        self::assertShape($shape);

        $inner = clone $query;
        $inner = $inner instanceof EloquentBuilder ? $inner->toBase() : $inner;

        // ⚠️ GrandTotals-এর একই নিয়ম: সীমা থাকলে ক্রমই ঠিক করে কোন সারি — তখন ক্রম রাখতে হয়
        if ($inner->limit === null && $inner->offset === null) {
            $inner->reorder();
        }

        $out = array_fill_keys($shape->keys(), ['count' => 0, 'total' => '0']);
        $open = ['count' => 0, 'total' => '0'];
        $classify = [];

        $rows = DB::query()->fromSub($inner, 't')
            ->selectRaw("t.`{$shape->status}` as status, COUNT(*) as n, COALESCE(SUM(t.`{$shape->amount}`), 0) as total")
            ->groupBy("t.{$shape->status}")
            ->get();

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $group = $shape->groups[$status] ?? throw new LogicException(
                "⛔ তালিকার মোট: {$shape->model}-এর status '{$status}' কোনো দলে নেই — সারিটা নীরবে সব দলের বাইরে পড়ত।"
            );

            if ($group === self::CLASSIFY) {
                $classify[] = $status;

                continue;
            }

            // ⓘ SUM(DECIMAL) লেখা হিসেবেই আসে; (string)-এ কেবল int-এর শূন্যটা লেখা হয়
            $out[$group] = self::add($out[$group], (int) $row->n, (string) $row->total);

            if (in_array($status, $shape->open, true)) {
                $open = self::add($open, (int) $row->n, (string) $row->total);
            }
        }

        if ($classify !== []) {
            foreach (self::classified($inner, $shape, $classify) as $group => $sum) {
                $out[$group] = self::add($out[$group], $sum['count'], $sum['total']);
            }
        }

        // ⓘ এক দিকের সীমা হলে অন্য দিক null — "থেকে ১ অক্টোবর", শেষ খোলা
        $summary = ['period' => $period === null ? null : ['from' => $period['from'] ?? null, 'to' => $period['to'] ?? null]];

        foreach ($out as $group => $sum) {
            $summary[$group] = ['count' => $sum['count'], 'total' => bcadd($sum['total'], '0', 4)];

            if ($group === self::COUNTED && $shape->open !== []) {
                $summary['open'] = ['count' => $open['count'], 'total' => bcadd($open['total'], '0', 4)];
            }
        }

        return $summary;
    }

    /**
     * আকারটা চলবে কি — টাকার কলাম DECIMAL, দলগুলো চেনা, open কেবল counted-এর ভিতরে, ভাগ থাকলে সেবাও।
     *
     * @throws LogicException
     */
    public static function assertShape(SummaryShape $shape): void
    {
        foreach ($shape->groups as $status => $group) {
            if ($group !== self::CLASSIFY && ! in_array($group, self::GROUPS, true)) {
                throw new LogicException("⛔ তালিকার মোট: {$shape->model}-এর status '{$status}'-এর দল '{$group}' চুক্তিতে নেই।");
            }
        }

        foreach ($shape->open as $status) {
            if (($shape->groups[$status] ?? null) !== self::COUNTED) {
                throw new LogicException("⛔ তালিকার মোট: {$shape->model}-এর open '{$status}' counted-এর ভিতরে নয় — open কেবল counted-এর অংশ।");
            }
        }

        if (in_array(self::CLASSIFY, $shape->groups, true)
            && ($shape->classifier === null || ! is_subclass_of($shape->classifier, ClassifiesDrafts::class))) {
            throw new LogicException("⛔ তালিকার মোট: {$shape->model}-এর খসড়া ভাগ করবে কে — ClassifiesDrafts মানা সেবা নেই।");
        }

        $table = $shape->table();
        $key = $table.'.'.$shape->amount;
        $type = self::$columnTypes[$key] ??= strtolower((string) Schema::getColumnType($table, $shape->amount));

        if ($type !== 'decimal') {
            throw new LogicException("⛔ তালিকার মোট: {$key} DECIMAL নয় ({$type}) — DOUBLE বা FLOAT-এর যোগ গোল হয়, টাকা মিলবে না।");
        }
    }

    /**
     * সই-সেবার ভাগ — খসড়াগুলো CHUNK করে তুলে, প্রত্যেকটার দল আর টাকা; ⛔ কাটা নয়।
     *
     * @param  list<string>  $statuses
     * @return array<string, array{count: int, total: string}>
     */
    private static function classified(\Illuminate\Database\Query\Builder $inner, SummaryShape $shape, array $statuses): array
    {
        /** @var ClassifiesDrafts $classifier */
        $classifier = app((string) $shape->classifier);
        $allowed = $classifier->groups();
        $model = new $shape->model;
        $keyName = $model->getKeyName();

        $amounts = DB::query()->fromSub($inner, 't')
            ->whereIn("t.{$shape->status}", $statuses)
            ->orderBy("t.{$keyName}")
            ->pluck("t.{$shape->amount}", "t.{$keyName}");

        if ($amounts->count() > self::MANY_DRAFTS) {
            Log::warning('⛔ তালিকার মোট: অনেক খসড়া — সব গোনা হলো, তবু দেখুন কেন এত পড়ে আছে', [
                'model' => $shape->model, 'drafts' => $amounts->count(),
            ]);
        }

        $sums = [];

        foreach ($amounts->keys()->chunk(self::CHUNK) as $ids) {
            // ⓘ আইডিগুলো তালিকার নিজের ছাঁকা কোয়েরি থেকে — দেয়াল সেখানে বসানো; এখানে কেবল সারিগুলো তোলা
            $drafts = $model->newQueryWithoutScopes()->whereKey($ids->all())->get();
            $standing = $classifier->standing($drafts);

            foreach ($ids as $id) {
                $group = $standing[$id] ?? null;

                if ($group === null || ! in_array($group, $allowed, true)) {
                    throw new LogicException("⛔ তালিকার মোট: {$shape->model} #{$id}-এর দল সই-সেবা দিল না, বা ঘোষণার বাইরের দল দিল ('".($group ?? 'কিছু না')."')।");
                }

                $sums[$group] = self::add($sums[$group] ?? ['count' => 0, 'total' => '0'], 1, (string) $amounts[$id]);
            }
        }

        return $sums;
    }

    /**
     * @param  array{count: int, total: string}  $sum
     * @return array{count: int, total: string}
     */
    private static function add(array $sum, int $count, string $total): array
    {
        return ['count' => $sum['count'] + $count, 'total' => bcadd($sum['total'], $total, 4)];
    }
}
