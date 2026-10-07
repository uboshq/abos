<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesQuotationLine;
use Illuminate\Support\Collection;

/**
 * দুই বা ততোধিক উদ্ধৃতি পাশাপাশি — সারি ধরে, বদলগুলো আলাদা করে (মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬)।
 *
 * ── ⓘ কোনটার সাথে মেলানো হয় ──────────────────────────────────────────────
 * প্রথম কলাম "ভিত্তি" — পর্দা পুরনো থেকে নতুন সাজায়, তাই সংস্করণের তুলনায় ভিত্তি মূল উদ্ধৃতি। প্রতিটা ঘর
 * ভিত্তির একই ঘরের সাথে মেলে; আলাদা হলে `changed`। ⓘ "আগের কলামের সাথে" নয়: তিন সংস্করণে দর ৯০ → ৯৫ → ৯০
 * হলে শেষটা আগেরটা থেকে বদলেছে, অথচ মূলের সাথে মেলে — ডিলারের সাথে কথা হয় মূল দর ধরে।
 *
 * ── ⓘ সারি মেলানো ──────────────────────────────────────────────────────
 * পণ্য ধরে, একই পণ্য একাধিকবার থাকলে তার ক্রম ধরে (`পণ্য#০`, `পণ্য#১`)। ⚠️ সারির নম্বর ধরে নয়: মাঝখানে একটা
 * সারি বাদ পড়লে নিচের সব সারি এক ঘর সরে যেত, আর পর্দা বলত "সব বদলেছে"।
 * ভিত্তিতে নেই অথচ অন্যটায় আছে → `added`; ভিত্তিতে আছে অথচ এটায় নেই → `missing`।
 */
final class QuotationComparison
{
    /** ⓘ এর বেশি কলাম ১৯২০ চওড়ায় আর এক নজরে পড়া যায় না */
    public const MAX = 6;

    /** @var list<string> সারির ঘর — ক্রমটাই পর্দার ক্রম */
    public const LINE_FIELDS = ['qty', 'rate', 'discount', 'tax', 'amount'];

    /**
     * @param  Collection<int, SalesQuotation>  $quotations  পুরনো থেকে নতুন; প্রথমটা ভিত্তি
     * @return array{
     *     head: list<array{label: string, values: list<string>, changed: list<bool>}>,
     *     lines: list<array{label: string, cells: list<array{fields: array<string, string>, unit: string, changed: array<string, bool>, added: bool}|null>, missing: list<bool>}>
     * }
     */
    public static function of(Collection $quotations): array
    {
        $quotations = $quotations->values();

        return [
            'head' => self::head($quotations),
            'lines' => self::lines($quotations),
        ];
    }

    /**
     * @param  Collection<int, SalesQuotation>  $quotations
     * @return list<array{label: string, values: list<string>, changed: list<bool>}>
     */
    private static function head(Collection $quotations): array
    {
        $fields = [
            'sales::field.date' => fn (SalesQuotation $q) => DateFormat::format($q->trx_date),
            'sales::quotation.field.valid_until' => fn (SalesQuotation $q) => DateFormat::format($q->valid_until),
            'sales::field.state' => fn (SalesQuotation $q) => __('sales::quotation.status.'.$q->effectiveStatus()),
            'sales::quotation.field.payment_term' => fn (SalesQuotation $q) => (string) ($q->paymentTerm?->name() ?? '—'),
            'sales::quotation.field.delivery_terms' => fn (SalesQuotation $q) => (string) ($q->delivery_terms ?: '—'),
            'sales::field.subtotal' => fn (SalesQuotation $q) => Money::format($q->subtotal),
            'sales::quotation.field.header_discount' => fn (SalesQuotation $q) => Money::format($q->header_discount),
            'sales::field.discount' => fn (SalesQuotation $q) => Money::format($q->discount),
            'sales::field.tax' => fn (SalesQuotation $q) => Money::format($q->tax),
            'sales::field.total' => fn (SalesQuotation $q) => Money::format($q->total),
        ];

        $rows = [];

        foreach ($fields as $label => $read) {
            $values = $quotations->map(fn (SalesQuotation $q) => $read($q))->all();

            $rows[] = [
                'label' => __($label),
                'values' => $values,
                'changed' => array_map(fn (string $v) => $v !== $values[0], $values),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, SalesQuotation>  $quotations
     * @return list<array{label: string, cells: list<array{fields: array<string, string>, unit: string, changed: array<string, bool>, added: bool}|null>, missing: list<bool>}>
     */
    private static function lines(Collection $quotations): array
    {
        /** @var list<array<string, SalesQuotationLine>> $keyed  প্রতিটা উদ্ধৃতির সারি, "পণ্য#ক্রম" ধরে */
        $keyed = [];
        $order = [];

        foreach ($quotations as $i => $quotation) {
            $seen = [];
            $keyed[$i] = [];

            foreach ($quotation->lines as $line) {
                $n = $seen[$line->product_id] = ($seen[$line->product_id] ?? -1) + 1;
                $key = $line->product_id.'#'.$n;

                $keyed[$i][$key] = $line;
                $order[$key] ??= $line;
            }
        }

        $rows = [];

        foreach ($order as $key => $first) {
            $base = $keyed[0][$key] ?? null;
            $baseFigures = $base === null ? null : self::figures($base);

            $cells = [];
            $missing = [];

            foreach (array_keys($keyed) as $i) {
                $line = $keyed[$i][$key] ?? null;

                if ($line === null) {
                    $cells[] = null;
                    $missing[] = true;

                    continue;
                }

                $figures = self::figures($line);

                $cells[] = [
                    'fields' => $figures,
                    'unit' => $line->packedUnitName(),
                    'changed' => array_map(
                        fn (string $field) => $baseFigures !== null && $figures[$field] !== $baseFigures[$field],
                        array_combine(self::LINE_FIELDS, self::LINE_FIELDS),
                    ),
                    'added' => $baseFigures === null,
                ];
                $missing[] = false;
            }

            $rows[] = [
                'label' => trim(($first->product?->code ?? '').' - '.($first->product?->name() ?? ''), ' -'),
                'cells' => $cells,
                'missing' => $missing,
            ];
        }

        return $rows;
    }

    /** @return array<string, string> পর্দায় যেমন দেখায় — প্যাকের এককে, তাই তুলনাও সেই লেখায় */
    private static function figures(SalesQuotationLine $line): array
    {
        return [
            'qty' => self::qty($line->packedQty('qty')),
            'rate' => Money::format($line->packedRate('rate', 'qty')),
            'discount' => Money::format($line->fullDiscount()),
            'tax' => Money::format($line->tax),
            'amount' => Money::format($line->amount),
        ];
    }

    /** পরিমাণে পিছনের শূন্য বাদ — "১০.০০০০ বস্তা" কেউ লেখে না ([[SalesQuotationController::qty()]]-এর একই নিয়ম)। */
    private static function qty(mixed $value): string
    {
        $formatted = rtrim(rtrim(Money::format($value, 4), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
