<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\NumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * কোন নম্বর সিরিজ আসল কাগজের পেছনে পড়ে আছে, আর সেগুলোকে সামনে আনা।
 *
 * ── কেন কমান্ড থেকে আলাদা, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────
 * `abos:catch-up-numbers` চালাতে ssh লাগত, আর ফিন্যান্স মানচিত্রের
 * "নম্বর সিরিজ মেলানো" লাইনে লেখা ছিল *"কমান্ড আছে, পর্দা নেই"*। পর্দা আর
 * কমান্ড এখন একই হিসাব পড়ে, তাই দুইটা কখনো দুই রকম উত্তর দেয় না।
 * কেন তালিকাটা স্কিমা থেকে আসে, সেটা [[CatchUpNumbers]]-এ লেখা।
 */
final class NumberSeriesCatchUp
{
    /**
     * পেছনে পড়ে থাকা সিরিজগুলো, প্রতিটার সাথে সবচেয়ে বড় ব্যবহৃত নম্বর।
     *
     * @return list<array{series: NumberSeries, highest: int}>
     */
    public function behind(?int $companyId = null): array
    {
        $columns = $this->numberColumns();

        if ($columns === []) {
            return [];
        }

        $series = NumberSeries::query()
            ->withoutGlobalScopes()
            ->when($companyId, fn ($q, $id) => $q->where('company_id', (int) $id))
            ->orderBy('company_id')
            ->orderBy('doc_type')
            ->get();

        $out = [];

        foreach ($series as $one) {
            $highest = $this->highestUsed($one, $columns);

            if ($highest !== null && $highest >= $one->next_number) {
                $out[] = ['series' => $one, 'highest' => $highest];
            }
        }

        return $out;
    }

    /**
     * সিরিজগুলোকে সবচেয়ে বড় ব্যবহৃত নম্বরের পরেরটায় আনা।
     *
     * @param  list<array{series: NumberSeries, highest: int}>  $behind
     */
    public function apply(array $behind): int
    {
        foreach ($behind as $row) {
            $row['series']->forceFill(['next_number' => $row['highest'] + 1])->save();
        }

        return count($behind);
    }

    /**
     * কোন টেবিলের কোন কলামে নম্বর বসে — স্কিমা থেকে।
     *
     * `company_id` আছে কি না সেটাও দেখা হয়, কারণ থাকলে খোঁজাটা
     * কোম্পানিতে সীমাবদ্ধ রাখতে হয়। না রাখলে এক কোম্পানির কাগজ দেখে
     * অন্য কোম্পানির সিরিজ লাফ দিত, আর তাদের নম্বরে ফাঁক পড়ত।
     *
     * @return list<array{table: string, column: string, scoped: bool}>
     */
    private function numberColumns(): array
    {
        $database = DB::connection()->getDatabaseName();

        $rows = DB::select(
            'select table_name as t, column_name as c
             from information_schema.columns
             where table_schema = ? and column_name in (?, ?)',
            [$database, 'code', 'document_no'],
        );

        $scoped = collect(DB::select(
            'select table_name as t from information_schema.columns
             where table_schema = ? and column_name = ?',
            [$database, 'company_id'],
        ))->pluck('t')->map(fn ($t) => (string) $t)->all();

        $out = [];

        foreach ($rows as $row) {
            $table = (string) $row->t;

            $out[] = [
                'table' => $table,
                'column' => (string) $row->c,
                'scoped' => in_array($table, $scoped, true),
            ];
        }

        return $out;
    }

    /**
     * এই সিরিজের উপসর্গ ধরে সবচেয়ে বড় যে ক্রমটা ইতিমধ্যেই ব্যবহার হয়েছে।
     *
     * ── কেন শেষ টুকরাটাই ক্রম ────────────────────────────────────────
     * ছক দুইরকম: মাস্টারের `{PREFIX}-{SEQ}` (CUS-0031), আর কাগজের
     * `{PREFIX}-{FY}-{SEQ}` (INV-2026-2027-0004)। দুইটাতেই ক্রমটা
     * শেষ ড্যাশের পরে, তাই ওটাই নেওয়া হয় — ছকের নাম পড়তে হয় না।
     *
     * ── উপসর্গের সাথে ড্যাশ কেন ─────────────────────────────────────
     * `PR` আর `PRD` দুইটাই আছে। ড্যাশ ছাড়া মেলালে `PRD-0001` দেখে
     * PR সিরিজ লাফ দিত, আর ক্রয় ফেরতের নম্বরে একটা ফাঁক পড়ত যার
     * কোনো ব্যাখ্যা কোথাও থাকত না।
     *
     * @param  list<array{table: string, column: string, scoped: bool}>  $columns
     */
    private function highestUsed(NumberSeries $series, array $columns): ?int
    {
        $like = $series->prefix.'-%';
        $highest = null;

        foreach ($columns as $where) {
            $query = DB::table($where['table'])->where($where['column'], 'like', $like);

            if ($where['scoped']) {
                $query->where('company_id', $series->company_id);
            }

            /*
             * ক্রমটা সংখ্যা হিসেবে, লেখা হিসেবে নয়। `MAX('0009')` আর
             * `MAX('0031')` লেখা হিসেবে তুলনা করলেও ঠিক আসে, কিন্তু
             * ৯৯৯৯ পেরোলে `'10000' < '9999'` হয়ে যেত — আর ঠিক তখনই
             * সিরিজটা পিছিয়ে গিয়ে পুরনো নম্বর আবার দিত।
             */
            $max = $query->max(DB::raw(
                "CAST(SUBSTRING_INDEX({$where['column']}, '-', -1) AS UNSIGNED)"
            ));

            if ($max !== null) {
                $highest = max($highest ?? 0, (int) $max);
            }
        }

        return $highest;
    }
}
