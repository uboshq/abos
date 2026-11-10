<?php

declare(strict_types=1);

namespace App\Modules\Sales\Forecasts;

use App\Core\Engines\Forecast\ForecastSource;
use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\SalesInvoiceLine;

/**
 * ⭐ একটা পণ্যের দিনে বিক্রি (পরিমাণ) — "আগামী সপ্তাহে চিনি কত লাগবে" (পূর্ণ পরিকল্পনা অংশ ২)।
 *
 * ⓘ পাকা বিলের লাইন, বিলের তারিখ ধরে; বিলের দেয়াল (শাখা, SR-এর নিজের ডিলার) বিলের মডেলেই খাটে।
 * ⓘ মান দরকার: `product_id`।
 */
final class ProductSalesHistory implements ForecastSource
{
    public static function key(): string
    {
        return 'sales.product_qty';
    }

    public static function permission(): string
    {
        return 'sales.invoice.view';
    }

    public function history(string $from, string $to, array $args = []): array
    {
        $product = (int) ($args['product_id'] ?? 0);

        if ($product <= 0) {
            return [];
        }

        return SalesInvoiceLine::query()
            ->where('product_id', $product)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', DocumentStatus::POSTED)->whereBetween('trx_date', [$from, $to]))
            ->join('sal_invoices', 'sal_invoices.id', '=', 'sal_invoice_lines.sales_invoice_id')
            ->groupBy('sal_invoices.trx_date')
            ->selectRaw('sal_invoices.trx_date as d, SUM(sal_invoice_lines.qty) as q')
            ->toBase()->get()
            ->mapWithKeys(fn ($r): array => [substr((string) $r->d, 0, 10) => (string) $r->q])
            ->all();
    }
}
