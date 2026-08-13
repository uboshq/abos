<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Sales\Models\CounterShift;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * শিফট খোলা, বন্ধ করা, আর Z-রিপোর্টের সংখ্যাগুলো।
 *
 * ── Z-রিপোর্ট আসলে একটাই প্রশ্ন ──────────────────────────────────────
 * "ড্রয়ারে যা থাকার কথা, তা কি আছে?" বাকি সব সংখ্যা ওই প্রশ্নের
 * উপাদান: খোলার সময় যা ছিল, শিফটে যা ঢুকেছে, যা বেরিয়েছে।
 *
 * ── কেন সময় ধরে, তারিখ ধরে নয় ────────────────────────────────────────
 * খতিয়ানের সারিতে `trx_date` একটা তারিখ, ঘড়ি নয়। এক দিনে দুই শিফট
 * হলে তারিখ ধরে ভাগ করা যেত না — সকালের ঘাটতি বিকেলের লোকের ঘাড়ে
 * পড়ত। তাই সারিটা **কখন লেখা হয়েছে** (`created_at`) সেটাই ধরা হয়।
 *
 * এর একটা ফল আছে, আর সেটা ইচ্ছাকৃত: কেউ শিফট চলাকালীন একটা পুরনো
 * তারিখের নগদ ভাউচার লিখলে সেটা এই শিফটেই গোনা হবে। ঠিকই আছে —
 * টাকাটা ড্রয়ার থেকে তখনই নড়েছে, কাগজে যে তারিখই লেখা থাক।
 */
final class ShiftService
{
    /**
     * শিফট খোলা — কে, কোন ড্রয়ারে, হাতে কত নিয়ে।
     *
     * @param  string  $counted  খোলার সময় গুনে পাওয়া নগদ
     */
    public function open(CashTill $till, string $counted, ?string $narration = null): CounterShift
    {
        $counted = $this->money($counted);

        return DB::transaction(function () use ($till, $counted, $narration) {
            /*
             * এক ড্রয়ারে দুইজন নয়।
             *
             * ডাটাবেজের ইউনিক ইনডেক্সই আসল পাহারা (দুইজন একসাথে চাপলে
             * ওটাই দ্বিতীয়টাকে ফেরায়), কিন্তু বার্তাটা এখানে — নাহলে
             * ব্যবহারকারী একটা SQL ভুল দেখতেন।
             */
            if (CounterShift::query()->open()->where('cash_till_id', $till->id)->exists()) {
                throw ValidationException::withMessages([
                    'cash_till_id' => __('sales::validation.till_already_open', [
                        'till' => $till->name(),
                    ]),
                ]);
            }

            /*
             * একজন মানুষ একসাথে দুই ড্রয়ারে নয়।
             *
             * দুইটা খোলা থাকলে দিনশেষে ঘাটতিটা কোন ড্রয়ারের তা তিনি
             * নিজেও বলতে পারতেন না।
             */
            if (CounterShift::query()->open()->where('user_id', auth()->id())->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => __('sales::validation.you_already_have_a_shift'),
                ]);
            }

            return CounterShift::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $till->branch_id ?? CompanyContext::branchId(),
                'cash_till_id' => $till->id,
                'user_id' => auth()->id(),
                'opened_at' => now(),
                'opening_counted' => $counted,

                /*
                 * এই মুহূর্তে খতিয়ানের শেষ সারিটা কত — সীমানার শুরু।
                 *
                 * ঘড়ি নয়, সারি-নম্বর: খতিয়ানের সময় সেকেন্ড-নির্ভুল, আর
                 * শিফট বদল এক সেকেন্ডের ভেতরেই ঘটে।
                 */
                'opening_ledger_id' => LedgerEntry::query()->max('id') ?? 0,

                'status' => CounterShift::OPEN,
                'open_marker' => 1,
                'narration' => $narration,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * শিফট বন্ধ — গোনা নগদ বসিয়ে।
     *
     * পার্থক্যটা জমা রাখা হয় না, গোনা হয়। কেউ পরে একটা আদায় বাতিল
     * করলে খাতার সংখ্যা বদলায়, আর জমানো পার্থক্যটা তখন মিথ্যা হত।
     */
    public function close(CounterShift $shift, string $counted, ?string $narration = null): CounterShift
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => __('sales::validation.shift_already_closed'),
            ]);
        }

        $counted = $this->money($counted);

        return DB::transaction(function () use ($shift, $counted, $narration) {
            $shift->update([
                'closed_at' => now(),
                'closing_counted' => $counted,

                // সীমানার শেষ — এই সারিটা সহ, তার পরেরগুলো পরের শিফটের
                'closing_ledger_id' => LedgerEntry::query()->max('id') ?? 0,

                'status' => CounterShift::CLOSED,

                // NULL মানে "আর খোলা নয়" — ইউনিক ইনডেক্সটা তখন ছেড়ে দেয়
                'open_marker' => null,
                'narration' => $narration ?? $shift->narration,
            ]);

            /*
             * বন্ধ করার কাজটা অডিটে বসে, কারণ পার্থক্য থাকলে ছয় মাস
             * পরেও প্রশ্ন উঠতে পারে "সেদিন কে বন্ধ করেছিলেন"।
             */
            $shift->auditAction('shift_closed', $narration);

            return $shift->fresh();
        });
    }

    /**
     * Z-রিপোর্ট — ড্রয়ারে যা থাকার কথা, আর যা আছে।
     *
     * @return array{
     *     opening: string, cash_in: string, cash_out: string,
     *     expected: string, counted: ?string, difference: ?string,
     *     bills: int, from: Carbon, to: Carbon
     * }
     */
    public function figures(CounterShift $shift): array
    {
        $from = $shift->opened_at;
        $to = $shift->closed_at ?? now();

        /*
         * সীমানা সারি-নম্বরে: খোলার পরের সারি থেকে বন্ধের সারি পর্যন্ত।
         *
         * প্রতিটা সারি ঠিক একটা শিফটের — নম্বর ক্রমিক ও অনন্য, তাই
         * দুইটা শিফটে একই সারি গোনার কোনো পথ নেই। ঘড়ি দিয়ে করলে ওই
         * নিশ্চয়তাটা থাকত না: খতিয়ানের সময় সেকেন্ড-নির্ভুল, আর শিফট
         * বদল এক সেকেন্ডের ভেতরেই ঘটে।
         */
        $rows = LedgerEntry::query()
            ->where('account_id', $shift->till?->account_id)
            ->where('id', '>', $shift->opening_ledger_id ?? 0)
            ->when(
                $shift->closing_ledger_id !== null,
                fn ($q) => $q->where('id', '<=', $shift->closing_ledger_id),
            )
            ->get(['debit', 'credit']);

        // নগদ খাতে ডেবিট মানে টাকা ঢুকেছে, ক্রেডিট মানে বেরিয়েছে
        $in = $rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->debit, 4), '0');
        $out = $rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) $row->credit, 4), '0');

        $opening = (string) $shift->opening_counted;
        $expected = bcsub(bcadd($opening, $in, 4), $out, 4);
        $counted = $shift->closing_counted === null ? null : (string) $shift->closing_counted;

        return [
            'opening' => $opening,
            'cash_in' => $in,
            'cash_out' => $out,
            'expected' => $expected,

            // খোলা শিফটে এখনো গোনা হয়নি — শূন্য নয়, অজানা
            'counted' => $counted,
            'difference' => $counted === null ? null : bcsub($counted, $expected, 4),

            /*
             * এই শিফটে কয়টা বিল — কেবল এই ব্যবহারকারীর, এই সময়ে।
             *
             * খাতার সংখ্যার সাথে মেলানোর জন্য নয়, প্রসঙ্গের জন্য: তিনটা
             * বিলে দুইশো টাকার ঘাটতি আর তিনশো বিলে দুইশো — দুইটা এক
             * জিনিস নয়।
             */
            /*
             * বিলের সংখ্যাটাও একই সীমানায়, কিন্তু বিলের নিজের আইডি ধরে।
             *
             * বিল আর খতিয়ান আলাদা সারি, তাই একটার নম্বর দিয়ে অন্যটা
             * কাটা যায় না। সময় দিয়ে করা হয় — এখানে ভুল হলে ক্ষতি কম:
             * এটা প্রসঙ্গের সংখ্যা, টাকার নয়।
             */
            'bills' => SalesInvoice::query()
                ->where('created_by', $shift->user_id)
                ->where('created_at', '>=', $from)
                ->when($shift->closed_at !== null, fn ($q) => $q->where('created_at', '<=', $to))
                ->whereNotNull('idempotency_key')
                ->count(),

            'from' => $from,
            'to' => $to,
        ];
    }

    /** এই ব্যবহারকারীর খোলা শিফট, থাকলে। */
    public function openFor(int $userId): ?CounterShift
    {
        return CounterShift::query()->open()->where('user_id', $userId)->with('till')->first();
    }

    private function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'counted' => __('sales::validation.not_a_number'),
            ]);
        }

        if (bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'counted' => __('sales::validation.negative_amount'),
            ]);
        }

        return bcadd($value, '0', 4);
    }
}
