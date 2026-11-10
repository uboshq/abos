<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * নগদের পূর্বাভাস — সামনের ৩০/৬০/৯০ দিনে হাতে কত থাকবে।
 * ফিন্যান্সের মানচিত্র §৮, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ধরা হয় ─────────────────────────────────────────────────────────
 * শুরু: আজকের নগদ + ব্যাংক + MFS ([[AccountsFacts::moneyPositions()]])।
 * আসবে: গ্রাহকের বাকি বিল (মেয়াদ ধরে), আর দেওয়া ঋণের কিস্তি।
 * যাবে: সরবরাহকারীর বাকি বিল (মেয়াদ ধরে), আর নেওয়া ঋণের কিস্তি।
 *
 * ⚠️ মেয়াদ পেরিয়ে যাওয়া বা মেয়াদ-না-লেখা বিল প্রথম ঝুড়িতে ("এখনই
 * পাওনা/দেয়")। ⓘ সেটা আশাবাদী — পুরনো বাকি সব আসবে না — কিন্তু লুকিয়ে
 * রাখলে পূর্বাভাসটা আরও মিথ্যা হত; আলাদা সারিতে থাকায় মানুষ নিজেই ছাড়
 * দিয়ে পড়তে পারেন।
 *
 * ⓘ বেতন, ভাড়ার মতো নিয়মিত খরচ এখানে নেই — খাতায় সেগুলোর কোনো "মেয়াদ"
 * লেখা থাকে না। পরে বাজেট থেকে আনা যায়; আজ যা নিশ্চিত জানা, কেবল তাই।
 */
final class CashForecast
{
    /** ঝুড়ি: এখনই (মেয়াদোত্তীর্ণ সহ), তারপর দিনের সীমা। */
    public const BUCKETS = ['now' => 0, 'd30' => 30, 'd60' => 60, 'd90' => 90];

    public function __construct(private readonly AccountsFacts $facts) {}

    /**
     * @return array{opening: string, rows: list<array{bucket: string, until: string, receivables: string,
     *     loans_in: string, payables: string, loans_out: string, net: string, closing: string}>}
     */
    public function build(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $money = $this->facts->moneyPositions();
        $opening = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);

        $sums = array_fill_keys(array_keys(self::BUCKETS), [
            'receivables' => '0', 'loans_in' => '0', 'payables' => '0', 'loans_out' => '0',
        ]);

        foreach ($this->dues($today, max(self::BUCKETS)) as $item) {
            $this->add($sums, $this->bucketOf($item['due'], $today), $item['side'], $item['amount']);
        }

        $rows = [];
        $running = $opening;

        foreach (self::BUCKETS as $bucket => $days) {
            $s = $sums[$bucket];
            $net = bcsub(bcadd($s['receivables'], $s['loans_in'], 4), bcadd($s['payables'], $s['loans_out'], 4), 4);
            $running = bcadd($running, $net, 4);

            $rows[] = [
                'bucket' => $bucket,
                'until' => $today->copy()->addDays($days)->toDateString(),
                ...$s,
                'net' => $net,
                'closing' => $running,
            ];
        }

        return ['opening' => $opening, 'rows' => $rows];
    }

    /**
     * ⭐ সামনের দিনগুলো দিন-ধরে — অর্থের ড্যাশবোর্ডের তহবিলের ঘর (মালিক, ৯ নভেম্বর ২০২৬, অনুমোদিত নমুনা): "৩০ দিনে আসবে − যাবে"
     * আর "সবচেয়ে টান পড়ার দিন"। ⓘ ঝুড়ির [[build()]]-এর একই কাগজ ([[dues()]]), একই নিয়ম: মেয়াদোত্তীর্ণ বা তারিখহীন আজকের দিনে।
     *
     * @return array{opening: string, in: string, out: string, lowest: array{date: string, closing: string, who: string}}
     */
    public function daily(int $days = 30, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $money = $this->facts->moneyPositions();
        $opening = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);

        $byDay = [];
        $biggestOut = [];
        $in = '0';
        $out = '0';

        foreach ($this->dues($today, $days) as $item) {
            if (bccomp($item['amount'], '0', 4) <= 0) {
                continue;
            }

            $at = $item['due'] === null || $item['due'] < $today->toDateString() ? $today->toDateString() : $item['due'];
            $coming = in_array($item['side'], ['receivables', 'loans_in'], true);
            $byDay[$at] = bcadd($byDay[$at] ?? '0', $coming ? $item['amount'] : bcmul($item['amount'], '-1', 4), 4);

            if ($coming) {
                $in = bcadd($in, $item['amount'], 4);
            } else {
                $out = bcadd($out, $item['amount'], 4);

                if (! isset($biggestOut[$at]) || bccomp($item['amount'], $biggestOut[$at]['amount'], 4) > 0) {
                    $biggestOut[$at] = $item;
                }
            }
        }

        ksort($byDay);
        $running = $opening;
        $lowest = ['date' => $today->toDateString(), 'closing' => $opening, 'who' => ''];

        foreach ($byDay as $date => $net) {
            $running = bcadd($running, $net, 4);

            if (bccomp($running, $lowest['closing'], 4) < 0) {
                $lowest = ['date' => $date, 'closing' => $running, 'who' => $biggestOut[$date]['who'] ?? ''];
            }
        }

        return ['opening' => $opening, 'in' => $in, 'out' => $out, 'lowest' => $lowest];
    }

    /**
     * ⭐ পরিশোধের ক্যালেন্ডার — এক দিন থেকে যত দিন, দিনে কত দিতে হবে (সরবরাহকারীর বাকি বিল, নেওয়া ঋণের কিস্তি)।
     * ⓘ [[dues()]]-এর একই কাগজ; মেয়াদোত্তীর্ণ বা তারিখহীন আজকের দিনে। কেবল যাওয়ার দিক।
     *
     * @return array<string, list<array{who: string, amount: string, side: string}>>
     */
    public function outgoingByDay(int $days, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $out = [];

        foreach ($this->dues($today, $days) as $item) {
            if (in_array($item['side'], ['receivables', 'loans_in'], true) || bccomp($item['amount'], '0', 4) <= 0) {
                continue;
            }

            $at = $item['due'] === null || $item['due'] < $today->toDateString() ? $today->toDateString() : $item['due'];
            $out[$at][] = ['who' => (string) $item['who'], 'amount' => $item['amount'], 'side' => $item['side']];
        }

        ksort($out);

        return $out;
    }

    /**
     * ⭐ 13 সপ্তাহের নগদের পূর্বাভাস — অর্থের ড্যাশবোর্ড, মালিকের চূড়ান্ত নমুনা 4h63xhNiy4m9KaJkZZQaEL (10 নভেম্বর 2026 রাত)।
     * ⓘ [[daily()]]-এর একই কাগজ আর নিয়ম ([[dues()]]): মেয়াদোত্তীর্ণ বা তারিখহীন প্রথম সপ্তাহে। সপ্তাহ আজ থেকে 7 দিনের টুকরো।
     * ⓘ প্রতিটা সপ্তাহে: নিট (আসবে − যাবে), সপ্তাহ-শেষের তহবিল, আর সবচেয়ে বড় দেনা (কার, কত) — "কেন টান" বলার জন্য।
     *
     * @return array{opening: string, weeks: list<array{from: string, to: string, net: string, closing: string, biggest: array{who: string, amount: string}|null}>}
     */
    public function weekly(int $weeks = 13, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $money = $this->facts->moneyPositions();
        $opening = bcadd(bcadd($money['cash'], $money['bank'], 4), $money['mfs'], 4);

        $rows = [];

        for ($i = 0; $i < $weeks; $i++) {
            $rows[$i] = ['from' => $today->copy()->addDays($i * 7)->toDateString(), 'to' => $today->copy()->addDays($i * 7 + 6)->toDateString(),
                'net' => '0', 'closing' => '0', 'biggest' => null];
        }

        foreach ($this->dues($today, $weeks * 7 - 1) as $item) {
            if (bccomp($item['amount'], '0', 4) <= 0) {
                continue;
            }

            $at = $item['due'] === null || $item['due'] < $today->toDateString() ? $today : Carbon::parse($item['due']);
            $i = intdiv((int) $today->diffInDays($at), 7);

            if (! isset($rows[$i])) {
                continue;
            }

            $coming = in_array($item['side'], ['receivables', 'loans_in'], true);
            $rows[$i]['net'] = bcadd($rows[$i]['net'], $coming ? $item['amount'] : bcmul($item['amount'], '-1', 4), 4);

            if (! $coming && ($rows[$i]['biggest'] === null || bccomp($item['amount'], $rows[$i]['biggest']['amount'], 4) > 0)) {
                $rows[$i]['biggest'] = ['who' => (string) $item['who'], 'amount' => $item['amount']];
            }
        }

        $running = $opening;

        foreach ($rows as $i => $row) {
            $running = bcadd($running, $row['net'], 4);
            $rows[$i]['closing'] = $running;
        }

        return ['opening' => $opening, 'weeks' => array_values($rows)];
    }

    /**
     * আজ থেকে `$days` দিনের মধ্যে যে কাগজে টাকা আসবে বা যাবে — গ্রাহকের বাকি চালান, সরবরাহকারীর বাকি বিল, ঋণের কিস্তি।
     *
     * @return list<array{due: ?string, side: string, amount: string, who: string}>
     */
    private function dues(Carbon $today, int $days): array
    {
        /*
         * ⛔ নব্বই দিনের বাইরের কাগজ আনা হয় না — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ এটা গতির কথা, আর গতির ভুল হঠাৎ থামে ────────────────
         * আগে কোম্পানির **প্রতিটা** পোস্ট করা চালান আর বিল একসাথে
         * মেমরিতে উঠত। ⓘ বছরে ৩০,০০০ চালানের ডিপোতে দ্বিতীয় বছরে
         * ৬০,০০০ সারি — আর তার পর একদিন পাতাটা সময় শেষ হয়ে থামত।
         *
         * ⭐ আর সারিগুলো কাজেও লাগত না: [[bucketOf()]] নব্বই দিনের
         * পরের সবকিছু `null` ফেরত দেয়, আর [[add()]] সেগুলো ফেলে দেয়।
         * ⓘ তাই এই ছাঁকনিতে পর্দার একটা সংখ্যাও বদলায় না।
         *
         * ⚠️ তারিখহীন কাগজগুলো তবু আসে — ওরা "এখন" ঘরে পড়ে,
         * আর বাদ দিলে বকেয়া পাওনা পর্দা থেকে মুছে যেত।
         */
        $horizon = $today->copy()->addDays($days)->toDateString();
        $items = [];

        $withinHorizon = fn ($q) => $q->where(
            fn ($w) => $w->whereNull('due_on')->orWhere('due_on', '<=', $horizon),
        );

        /*
         * ⛔ কেবল যে কাগজে এখনো টাকা বাকি — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ২ অক্টোবর ২০২৬
         * ([[TheForecastLoadedEveryPaidBillTest]])। ⓘ মেয়াদোত্তীর্ণ সব কাগজ "এখন" ঘরে আসে, তাই আগে
         * কোম্পানির **প্রতিটা** শোধ হয়ে যাওয়া পুরনো বিলও মেমরিতে উঠত — যোগফলে শূন্য দিত, কিন্তু বছর
         * গড়ালে পাতাটাই থামত। এখন বাকির হিসাব ([[SalesInvoice::dueAmount()]]-এর একই তিন ভাগ) SQL-এ।
         */
        $invoices = SalesInvoice::query()->posted()->withCollected()->tap($withinHorizon);

        foreach (SalesInvoice::query()->fromSub($invoices, 'sal_invoices')
            ->whereRaw(SalesInvoice::dueSql('sal_invoices').' > 0')
            ->get() as $invoice) {
            $items[] = ['due' => $this->day($invoice->due_on), 'side' => 'receivables', 'amount' => $invoice->dueAmount(), 'who' => ''];
        }

        $bills = PurchaseBill::query()->posted()->withPaid()->tap($withinHorizon);

        foreach (PurchaseBill::query()->fromSub($bills, 'pur_bills')->with('supplier')
            ->whereRaw('pur_bills.total - pur_bills.ait_amount - pur_bills.vds_amount - pur_bills.paid_total - pur_bills.voucher_paid_total - COALESCE(pur_bills.returned_total, 0) > 0')
            ->get() as $bill) {
            $items[] = ['due' => $this->day($bill->due_on), 'side' => 'payables', 'amount' => $bill->dueAmount(), 'who' => (string) $bill->supplier?->name()];
        }

        $instalments = LoanInstalment::query()
            ->with('loan')
            ->where('status', LoanInstalment::DUE)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED))

            /* ⓘ কিস্তিও একই জানালায় — পরেরগুলো পর্দায় আসে না */
            ->where('due_date', '<=', $horizon)
            ->get();

        foreach ($instalments as $row) {
            $left = bcsub(bcadd((string) $row->principal, (string) $row->interest, 4), (string) ($row->paid_amount ?? 0), 4);
            $side = $row->loan->direction === Loan::GIVEN ? 'loans_in' : 'loans_out';

            $items[] = ['due' => $this->day($row->due_date), 'side' => $side, 'amount' => $left, 'who' => (string) $row->loan->lender];
        }

        return $items;

    }

    private function day($due): ?string
    {
        return $due === null ? null : Carbon::parse($due)->toDateString();
    }

    /**
     * কোন ঝুড়িতে — মেয়াদোত্তীর্ণ বা মেয়াদ-না-লেখা হলে "এখনই"; ৯০ দিনের
     * পরের হলে কোনোটায় নয় (null)।
     */
    private function bucketOf($due, Carbon $today): ?string
    {
        if ($due === null) {
            return 'now';
        }

        $days = $today->diffInDays(Carbon::parse($due)->startOfDay(), false);

        foreach (self::BUCKETS as $bucket => $limit) {
            if ($days <= $limit) {
                return $bucket;
            }
        }

        return null;
    }

    /** @param  array<string, array<string, string>>  $sums */
    private function add(array &$sums, ?string $bucket, string $side, string $amount): void
    {
        if ($bucket === null || bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $sums[$bucket][$side] = bcadd($sums[$bucket][$side], $amount, 4);
    }
}
