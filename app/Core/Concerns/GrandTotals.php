<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * তালিকার সর্বমোট — গোটা ছাঁকা তালিকার, একটা প্রশ্নে (মালিক, ১ অক্টোবর ২০২৬: *"kono list er niche grand total nai keno"*)।
 *
 * ── কীভাবে ──────────────────────────────────────────────────────────────
 * তালিকার নিজের কোয়েরিটাই (খোঁজা, ছাঁকনি, শাখার দেয়াল সব বসানো) ক্লোন করে একটা ভেতরের প্রশ্ন বানানো হয়, আর তার
 * উপরে যোগ — তাই সর্বমোট ঠিক সেই সারিগুলোর, যা পর্দার সব পাতা মিলে দেখায়। ⓘ যোগের ঘর ভেতরের প্রশ্নের নাম ধরে
 * লেখা (`t.total`, `t.paid_total + t.voucher_paid_total`), তাই `withPaid()`-এর মতো সাব-কোয়েরির ঘরও যোগ হয়।
 *
 * ⓘ পর্দায় [[x-ui.table]]-এর `:grand` দিয়ে যায়; কোন কলাম যোগ দেখাবে তা কলাম নিজে বলে (`'total' => 'money'`)।
 */
trait GrandTotals
{
    /**
     * @param  array<string, string>  $sums  কলামের চাবি => যোগের SQL, ভেতরের প্রশ্নের নাম `t` ধরে
     * @return array<string, string>
     */
    protected function grandTotals(Builder $query, array $sums): array
    {
        if ($sums === []) {
            return [];
        }

        $inner = clone $query;
        $inner = $inner instanceof EloquentBuilder ? $inner->toBase() : $inner;

        // ⚠️ সীমা থাকলে (টপ/বটম ১০) ক্রমই ঠিক করে কোন সারি — তখন ক্রম রাখতে হয়, নইলে অন্য দশটার যোগ
        if ($inner->limit === null && $inner->offset === null) {
            $inner->reorder();
        }

        $select = collect($sums)
            ->map(fn (string $sql, string $key) => "COALESCE(SUM({$sql}), 0) as `{$key}`")
            ->implode(', ');

        $row = DB::query()->fromSub($inner, 't')->selectRaw($select)->first();

        return collect($sums)
            ->mapWithKeys(fn (string $sql, string $key) => [$key => (string) ($row->{$key} ?? '0')])
            ->all();
    }
}
