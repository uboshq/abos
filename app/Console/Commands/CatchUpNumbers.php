<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NumberSeries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * নম্বর সিরিজগুলোকে আসল কাগজের পেছন থেকে সামনে আনা।
 *
 * ── কী ভেঙেছিল, ২৯ আগস্ট ২০২৬ ────────────────────────────────────────
 * লাইভে মানুষের মতো একটা নতুন গ্রাহক বসাতে গিয়ে উত্তর এল **"এই কোডে
 * আরেকজন গ্রাহক আছে"**। কোডের ঘরটা খালি রাখা হয়েছিল, অর্থাৎ নম্বরটা
 * সিস্টেমের নিজের দেওয়া — তবু সংঘর্ষ।
 *
 * কারণটা মেপে পাওয়া গেল: Trade Depot-এ ৩১টা গ্রাহক আছে, `CUS-0001`
 * থেকে `CUS-0031`, অথচ CUS সিরিজের পরের নম্বর **১**। সিরিজগুলো
 * `NumberSeriesProvisioner` বানায় এক থেকে, আর ওই ৩১টা গ্রাহক
 * সিরিজের ভেতর দিয়ে আসেনি — ওরা আমদানি করা।
 *
 * ফলে নতুন গ্রাহক বসাতে গেলে ৩১ বার একই ভুল খেয়ে ৩২তম বারে গিয়ে
 * বসত। কোম্পানি ১-এর আটাশটা সিরিজের প্রায় সবগুলোই এই অবস্থায়।
 *
 * ── কেন হাতে লেখা তালিকা নয় ─────────────────────────────────────────
 * প্রথম ভাবনা ছিল একটা মানচিত্র: CUS → customers.code, INV →
 * sales_invoices.document_no… কিন্তু ওই তালিকা লেখার পরদিনই একটা নতুন
 * ডকুমেন্ট টাইপ আসবে, আর কেউ তালিকায় সারি যোগ করতে ভুলে যাবেন। তখন
 * এই কমান্ডটা চুপচাপ ওই টাইপটা বাদ দিয়ে সবুজ রিপোর্ট দিত — সবচেয়ে
 * খারাপ ধরনের ব্যর্থতা।
 *
 * তাই উৎসটা **স্কিমা**: যে টেবিলে `code` বা `document_no` কলাম আছে,
 * সেখানেই খোঁজা হয়, আর উপসর্গ দিয়ে মেলানো হয়। নতুন টেবিল এলে সে
 * নিজে থেকেই তালিকায় ঢোকে।
 *
 * ── কেন সংঘর্ষ হলে "পরেরটা নাও" করা হয়নি ────────────────────────────
 * ইঞ্জিনকে দিয়ে সংঘর্ষে পরের নম্বর নেওয়ানো যেত। কিন্তু প্রতিটা চেষ্টায়
 * `issued_numbers`-এ একটা সারি বসত, আর নিরীক্ষায় ৩১টা ইস্যু করা অথচ
 * কোথাও ব্যবহার না হওয়া নম্বর দেখা যেত। নম্বরের ফাঁক ব্যাখ্যা করতে
 * পারাটাই সিরিজের একমাত্র কাজ; ভুল সারানোর নামে ফাঁক বানানো যায় না।
 */
class CatchUpNumbers extends Command
{
    protected $signature = 'abos:catch-up-numbers
        {--company= : কেবল এই কোম্পানির সিরিজগুলো}
        {--pretend : কিছু লিখো না, কেবল দেখাও কী বদলাত}';

    protected $description = 'আমদানি করা কাগজের পেছনে পড়ে থাকা নম্বর সিরিজগুলোকে সামনে আনে';

    public function handle(): int
    {
        $columns = $this->numberColumns();

        if ($columns === []) {
            $this->error('কোনো টেবিলেই code বা document_no কলাম নেই — এটা হওয়ার কথা নয়।');

            return self::FAILURE;
        }

        $series = NumberSeries::query()
            ->withoutGlobalScopes()
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->orderBy('company_id')
            ->orderBy('doc_type')
            ->get();

        $moved = 0;

        foreach ($series as $one) {
            $highest = $this->highestUsed($one, $columns);

            if ($highest === null || $highest < $one->next_number) {
                continue;
            }

            $this->line(sprintf(
                '  কোম্পানি %d · %-5s  %d → %d   (%s-%s পর্যন্ত ব্যবহার হয়ে গেছে)',
                $one->company_id,
                $one->doc_type,
                $one->next_number,
                $highest + 1,
                $one->prefix,
                str_pad((string) $highest, (int) $one->padding, '0', STR_PAD_LEFT),
            ));

            $moved++;

            if (! $this->option('pretend')) {
                $one->forceFill(['next_number' => $highest + 1])->save();
            }
        }

        $this->newLine();

        $this->info($moved === 0
            ? 'সব সিরিজ আসল কাগজের সামনে আছে — কিছু বদলানোর নেই।'
            : ($this->option('pretend')
                ? "{$moved}টা সিরিজ পেছনে আছে (কিছু লেখা হয়নি)।"
                : "{$moved}টা সিরিজ সামনে আনা হলো।"));

        return self::SUCCESS;
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
