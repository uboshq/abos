<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\Company;
use Illuminate\Support\Facades\Lang;

/**
 * বিলের প্রতিটা নকশা যা জিজ্ঞেস করে — একবার গুনে, এক জায়গায়।
 *
 * ── ⭐ কেন লাগল, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * মালিক বিলের বিশটা নকশা চাইলেন (ছাপার নিয়ন্ত্রণে বাছাই)। ⛔ প্রতিটা ছাঁচ নিজে সুইচ পড়লে, নিজে
 * DUPLICATE খুঁজলে, নিজে QR-এর শর্ত লিখলে বিশ জায়গায় একই নিয়ম হাতে লেখা থাকত — আর একদিন একটা
 * নকশায় "আগের বকেয়া" বন্ধ করার সুইচ কাজ করত না, অন্যগুলোয় করত।
 *
 * ⭐ তাই ছাঁচ কেবল আঁকে; কী আঁকবে তা এই ক্লাস বলে, আর সব সুইচ [[InvoicePrintLook]] থেকে।
 * ⓘ অঙ্ক এখানেও গোনা হয় না — সব `$facts` থেকে ([[SalesPrintController::classicFacts()]])।
 */
final class InvoicePaperView
{
    /** @var array{name: string, address: string, phone: string, email: string, website: string} */
    public readonly array $head;

    /** @var list<string> */
    public readonly array $signatures;

    public readonly string $footnote;

    /** সরু রোলের ছোট নির্দেশনা — মালিক, ৩ অক্টোবর ২০২৬ */
    public readonly string $footnoteThermal;

    /** DUPLICATE ছোট ছাপ — কেবল দ্বিতীয় ছাপা থেকে, আর সুইচ চালু থাকলে */
    public readonly bool $duplicate;

    /** কততম ছাপা — DUPLICATE ছাপে দেখাতে; প্রথম ছাপায় '' */
    public readonly string $copyNo;

    /** বাকি সতর্কবার্তা (যেমন বাতিল) — এগুলো সবসময় বড় করে */
    /** @var list<string> */
    public readonly array $notices;

    public readonly bool $showVat;

    public readonly bool $free;

    public readonly bool $totalQty;

    /** BIN/TIN-এর লাইন — সুইচ বন্ধ বা ফাঁকা হলে '' */
    public readonly string $taxIds;

    /** স্ক্যানের ঠিকানা — সুইচ বন্ধ বা ঠিকানা নেই (কাউন্টারের বিল) হলে '' */
    public readonly string $qr;

    public readonly ?string $logo;

    /** @var array<string, mixed> */
    public readonly array $sums;

    private readonly InvoicePrintLook $look;

    /**
     * @param  array<string, mixed>  $facts
     */
    public function __construct(PrintableDocument $doc, public readonly array $facts, Company $company, PrintProfile $profile)
    {
        $this->look = app(InvoicePrintLook::class);

        $this->head = $this->look->header($company);
        $this->signatures = $this->look->signatures();
        $this->footnote = $this->look->footnote();
        $this->footnoteThermal = $this->look->footnote(true);

        $mark = $doc->duplicateNotice();
        $all = $doc->notices();
        $this->duplicate = $mark !== null && $this->shows('duplicate');
        // ⓘ কততম ছাপা — লেখার শেষের সংখ্যা; ঘর যে ভাষায় লেখে সেই ভাষায় আবার বানাতে ([[en()]])
        $this->copyNo = $mark !== null && preg_match('/(\d+)\s*$/', $mark, $m) === 1 ? $m[1] : '';
        $this->notices = array_values(array_filter($all, fn (string $n) => ! PrintableDocument::isDuplicateNotice($n)));

        $this->sums = $facts['sums'];
        $this->showVat = bccomp(str_replace(',', '', (string) $this->sums['vat']), '0', 4) !== 0;
        $this->free = $this->shows('free');
        $this->totalQty = $this->shows('total_qty');

        $this->taxIds = $this->shows('bin') ? trim(implode('   ', array_filter([
            filled($company->bin) ? $this->en('bin').' '.$company->bin : null,
            filled($company->tin) ? $this->en('tin').' '.$company->tin : null,
        ]))) : '';

        /*
         * ⚠️ 'qr' সুইচ `SHOWS`-এ না আসা পর্যন্ত জিজ্ঞাসাই নয় — অঘোষিত চাবি পড়লে SettingsService
         * ব্যতিক্রম ছোড়ে, আর তখন গোটা বিলের ছাপা ভাঙত।
         */
        $this->qr = in_array('qr', InvoicePrintLook::SHOWS, true) && $this->shows('qr')
            ? trim((string) ($facts['scan_url'] ?? ''))
            : '';

        $this->logo = $profile->shows('logo') ? $company->logoData() : null;
    }

    /**
     * ⭐ "DUPLICATE — Print No. 3" — নকশাগুলো `en('duplicate')` ডাকে, তাই নম্বর এখানে একবার, ৪১টা ছাঁচে নয়।
     */
    private function duplicateIn(string $key, string $locale): ?string
    {
        if ($key !== 'duplicate' || $this->copyNo === '') {
            return null;
        }

        return (string) __('core.print.duplicate_notice', ['n' => $this->copyNo], $locale);
    }

    /** একটা দেখানো/লুকানোর সুইচ — [[InvoicePrintLook::shows()]] */
    public function shows(string $what): bool
    {
        return $this->look->shows($what);
    }

    /** ঘরের নাম, ইংরেজিতে — ক্লাসিকের সেই একই লেখা */
    public function en(string $key, array $replace = []): string
    {
        return $this->duplicateIn($key, 'en') ?? (string) __('sales::print.classic.'.$key, $replace, 'en');
    }

    /** ঘরের নাম, বাংলায় */
    public function bn(string $key, array $replace = []): string
    {
        return $this->duplicateIn($key, 'bn') ?? (string) __('sales::print.classic.'.$key, $replace, 'bn');
    }

    /** ঘরের নাম, যেকোনো ভাষায় — `en` বা `bn` */
    public function t(string $key, string $locale = 'en', array $replace = []): string
    {
        return $locale === 'bn' ? $this->bn($key, $replace) : $this->en($key, $replace);
    }

    /** শেষের ':' বা ',' ছাড়া — শিরোনাম-ধাঁচের ঘরে */
    public function label(string $key, string $locale = 'en'): string
    {
        return rtrim($locale === 'bn' ? $this->bn($key) : $this->en($key), ':,');
    }

    /**
     * বিলের নিজের ঘরগুলো — [label, value, data-চিহ্ন বা null]।
     *
     * ⓘ ফাঁকা ঘর বাদ ("শেষ তারিখ" নগদে নেই, বিক্রয়কর্মী সবসময় নয়); অর্ডার আর ধরন নিজের সুইচে।
     *
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    public function billFacts(): array
    {
        $bill = $this->facts['bill'];

        $list = [
            [$this->label('bill_no'), (string) ($bill['bill_no'] ?? ''), null],
            [$this->label('bill_date'), (string) ($bill['bill_date'] ?? ''), null],
            [(string) __('sales::invoice_design.due_date', [], 'en'), (string) ($bill['due_date'] ?? ''), null],
            $this->shows('order_no') ? [$this->label('order_no'), (string) ($bill['order_no'] ?? ''), 'data-order-no'] : null,
            $this->shows('invoice_type') ? [$this->label('type'), (string) ($bill['type'] ?? ''), 'data-invoice-type'] : null,
            [(string) __('sales::invoice_design.sales_officer', [], 'en'), (string) ($bill['sales_officer'] ?? ''), null],
            [$this->label('created_by'), (string) ($bill['created_by'] ?? ''), null],
        ];

        /* ⓘ সুইচের ঘর ফাঁকা হলেও থাকে (চিহ্নটা দাবির জন্য); বাকি ফাঁকা ঘর বাদ */
        return array_values(array_filter($list, fn ($row) => $row !== null && ($row[1] !== '' || $row[2] !== null)));
    }

    /**
     * হিসাবের চলাচল — আগের জের, এই বিল, প্রতিটা জমা, আর প্রতিটার পরে চলমান জের।
     *
     * ⭐ "হিসাবের খতিয়ান-সহ বিল" নকশার জন্য (মালিক, ৩০ সেপ্টেম্বর ২০২৬)। ⓘ অঙ্ক সব `$facts['sums']`
     * আর জমার সারি থেকে; এখানে কেবল যোগ-বিয়োগ, bcmath-এ — ছাঁচে কোনো অঙ্ক গোনা হয় না।
     * ⚠️ শেষ জের = মোট বকেয়া হতেই হবে; আগের বকেয়ার সংজ্ঞা বদলালে এই মিলটাই প্রথমে ভাঙবে।
     * ⓘ ফেরত দেওয়া অঙ্ক ছাপার রূপে (`Money::format`, `12,34,567.00`) — বাকি টাকার ঘরের মতোই।
     *
     * @param  list<array<string, mixed>>  $payments  `$doc->payments`
     * @return list<array{date: string, text: string, debit: string, credit: string, balance: string}>
     */
    public function movement(array $payments): array
    {
        $plain = fn ($v) => str_replace(',', '', (string) $v);

        /*
         * ⭐ বিলের মাসের আসল খাতা, বিবরণসহ — মালিক, ৩ অক্টোবর ২০২৬ ([[SalesPrintController::monthMovement()]])।
         * ⓘ প্রথম সারির নাম এখানে, কাগজের ভাষায়। ⓘ জের "250.79 Due" / "22,958.21 Advance" — চিহ্ন নয়, নাম
         * (মালিক, একই দিন: "+- dile bujte kosto hobe")।
         */
        if (is_array($this->facts['movement'] ?? null)) {
            $money = fn (string $x) => $x === '' ? '' : Money::format($x);
            /* ⓘ ডিলারের কাগজে Dr/Cr নয় — মালিক, ৩ অক্টোবর ২০২৬ (নকশা দেখার সময়): "… Due" / "… Advance" */
            $side = fn (string $x) => match (bccomp($x, '0', 4)) {
                1 => Money::format($x).' Due',
                -1 => Money::format(bcmul($x, '-1', 4)).' Advance',
                default => Money::format('0'),
            };

            $rows = array_values($this->facts['movement']);

            return array_map(fn (array $r, int $i) => [
                ...$r,
                'text' => $i === 0 ? $this->label('month_opening') : $r['text'],
                'debit' => $money($r['debit']),
                'credit' => $money($r['credit']),
                'balance' => $side($r['balance']),
            ], $rows, array_keys($rows));
        }

        $balance = $plain($this->sums['previous_due']);
        $rows = [['date' => '', 'text' => $this->label('previous_due'), 'debit' => '', 'credit' => '', 'balance' => $balance]];

        $balance = bcadd($balance, $plain($this->sums['net_payable']), 4);
        $rows[] = [
            'date' => (string) ($this->facts['bill']['bill_date'] ?? ''),
            'text' => $this->label('heading').' '.($this->facts['bill']['bill_no'] ?? ''),
            'debit' => $plain($this->sums['net_payable']),
            'credit' => '',
            'balance' => $balance,
        ];

        foreach ($payments as $row) {
            $amount = $plain($row['amount'] ?? '0');
            $balance = bcsub($balance, $amount, 4);
            $rows[] = [
                'date' => (string) ($row['date'] ?? ''),
                'text' => trim(($row['ref'] ?? '').' · '.($row['method'] ?? ''), ' ·'),
                'debit' => '',
                'credit' => $amount,
                'balance' => $balance,
            ];
        }

        $money = fn (string $x) => $x === '' ? '' : Money::format($x);

        return array_map(fn (array $r) => [
            ...$r,
            'debit' => $money($r['debit']),
            'credit' => $money($r['credit']),
            'balance' => $money($r['balance']),
        ], $rows);
    }

    /** QR-এর নিচের লেখা — অনুবাদ না থাকলে '' (তখন কেবল কোডটা) */
    public function scanHint(): string
    {
        $key = 'sales::print.classic.scan_hint';

        return Lang::has($key, 'bn') ? (string) __($key, [], 'bn') : '';
    }

    /** নিচের "ছাপার সময়" লাইন */
    public function printedAt(): string
    {
        $who = auth()->check() ? ' · '.auth()->user()->name : '';

        return $this->en('printed_at').' '.DateFormat::formatWithTime(now()).$who;
    }

    /*
     * ── ⭐ ডিলারের ভাষায় শেষ লাইন — মালিক, ৩ অক্টোবর ২০২৬ ("Special for DB" নকশা দেখার সময়) ─────────────────
     * "Outstanding" বা Dr/Cr নয়: বাকি থাকলে **Due**, বেশি দেওয়া থাকলে **Advance**, শূন্যে **No Due**; আর এই বিলে বিলের
     * চেয়ে বেশি দিলে "Invoice Due"-র জায়গায় **Extra Paid**। ⓘ অঙ্ক `sums`-এরই — আলাদা করে গোনা নয়; অন্য নকশাও নিতে পারে।
     */

    /** শেষ লাইনের নাম — `outstanding`-এর চিহ্ন দেখে। */
    public function balanceWord(): string
    {
        return match ($this->sign($this->sums['outstanding'] ?? '0')) {
            1 => 'Due',
            -1 => 'Advance',
            default => 'No Due',
        };
    }

    /** শেষ লাইনের অঙ্ক — চিহ্ন ছাড়া, কারণ নামটাই দিক বলে। */
    public function balanceAmount(): string
    {
        return Money::format($this->absolute($this->sums['outstanding'] ?? '0'));
    }

    /**
     * এই বিলের আগের জের — বিল বসার আর এই টাকা আসার আগে। মালিকের ছবি, ৩ অক্টোবর ২০২৬ (সরকার এন্টারপ্রাইজ, S-0001)।
     *
     * ⛔ আগে "আগের বকেয়া" ঘরে বাড়তি টাকা কাটার **পরের** অঙ্ক বসত (২৯,৭৪৮.২৭ — শেষ লাইনের সমান), অথচ আগের বকেয়া ছিল
     * ৩০,৬৪২.১৫; যোগটা মিলত না। ⓘ এখানে নিজেই গোনা: মোট জের − প্রদেয় + পরিশোধ — তাই "আগের + বিল − পরিশোধ = শেষ জের"
     * কাগজে সবসময় মেলে। `sums['previous_due']`-ও এখন একই অঙ্ক দেয় (abos-63, 69be60b6); দুইটা আলাদা পথে এক উত্তর।
     */
    public function previousBeforeBill(): string
    {
        $before = bcadd(bcsub($this->plain($this->sums['outstanding'] ?? '0'), $this->plain($this->sums['net_payable'] ?? '0'), 4),
            $this->plain($this->sums['paid'] ?? '0'), 4);

        return Money::format($before);
    }

    /** এই বিলের ঘরের নাম — বেশি দিলে Extra Paid। */
    public function billLeftWord(): string
    {
        return $this->sign($this->extraPaid()) > 0 ? 'Extra Paid' : 'Invoice Due';
    }

    /** এই বিলের ঘরের অঙ্ক — বাকি, বা যতটা বেশি দেওয়া হয়েছে। */
    public function billLeftAmount(): string
    {
        $extra = $this->extraPaid();

        return $this->sign($extra) > 0 ? Money::format($extra) : (string) ($this->sums['invoice_due'] ?? Money::format('0'));
    }

    /**
     * লক্ষ্যের বাক্স — ডিলারের লক্ষ্য না থাকলে `null`, আর তখন বাক্সটাই আঁকা হয় না ([[CustomerTargetService::reminderFor()]])।
     *
     * @return array{month: string, target: string, achieved: string, remaining: string, closes_on: string, bank_days: string}|null
     */
    public function target(): ?array
    {
        $t = $this->facts['target'] ?? null;

        return is_array($t) ? $t : null;
    }

    private function extraPaid(): string
    {
        return bcsub($this->plain($this->sums['paid'] ?? '0'), $this->plain($this->sums['net_payable'] ?? '0'), 4);
    }

    private function sign(string $formatted): int
    {
        $v = $this->plain($formatted);

        return bccomp($v, '0.005', 4) > 0 ? 1 : (bccomp($v, '-0.005', 4) < 0 ? -1 : 0);
    }

    private function absolute(string $formatted): string
    {
        $v = $this->plain($formatted);

        return bccomp($v, '0', 4) < 0 ? bcmul($v, '-1', 4) : $v;
    }

    /** ছাপার অঙ্ক থেকে কমা আর ফাঁকা বাদ — `sums` আগেই ছাপার রূপে আসে */
    private function plain(string $formatted): string
    {
        $v = str_replace([',', ' '], '', trim($formatted));

        return is_numeric($v) ? bcadd($v, '0', 4) : '0.0000';
    }
}
