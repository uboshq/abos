<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ReadsPackedQuantities;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Supplier\Models\Supplier;

/**
 * ⭐ সরাসরি ক্রয়ের সারাংশ — "নিশ্চিত করুন" চাপলে আগে এটা (মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*; [[ConfirmOverview]])।
 *
 * ⓘ একই ঘর যা [[DirectPurchaseService::complete()]] পায় — কিন্তু কিছুই লেখে না। অঙ্ক বিলের অঙ্কের হুবহু, কারণ পথও
 * একই: সারি বাছাই `complete()`-এর মতো, বিলের ছাড়ের ভাগ [[DirectPurchaseService::spreadBillDiscount()]] নিজেই, প্যাক
 * থেকে পণ্যের এককে [[ReadsPackedQuantities::packed()]], আর সারির হিসাব [[CalculatesLineTotals::lineFigures()]] —
 * [[PurchaseBillService]]-এর `replaceLines()` যা ডাকে।
 * ⓘ সরবরাহকারীর দেনা = সারির মোট; ভাড়া আলাদা — সেটা মালের খরচে বসে, বাহকের খাতায় যায়, সরবরাহকারীর নয়।
 * ⛔ কেবল দেখায়: সই লাগবে কিনা বলে, কিন্তু আসল পাহারা নিশ্চিতের দরজাতেই।
 */
final class DirectPurchaseOverview
{
    use CalculatesLineTotals;
    use ReadsPackedQuantities;

    public function __construct(
        private readonly DirectPurchaseService $purchases,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data  পর্দার ঘর ([[DirectPurchaseRules::store()]]-এ যাচাই করা)
     */
    public function build(array $data): ConfirmOverview
    {
        $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
        $warehouse = filled($data['warehouse_id'] ?? null)
            ? Warehouse::query()->find((int) $data['warehouse_id'])
            : Warehouse::query()->where('is_default', true)->first();

        $o = ConfirmOverview::titled(__('purchase::overview_confirm.title'))
            ->head(__('purchase::field.supplier'), $supplier->name())
            ->head(__('purchase::field.date'), DateFormat::format($data['trx_date'] ?? now()->toDateString()))
            ->head(__('purchase::field.supplier_bill_no'), filled($data['supplier_bill_no'] ?? null) ? (string) $data['supplier_bill_no'] : null)
            ->head(__('purchase::overview_confirm.term'), $this->termLabel($data))
            ->head(__('purchase::field.warehouse'), $warehouse?->name());

        // ⓘ `complete()`-এর বাছাই — পণ্য আর পরিমাণ ছাড়া সারি বিলে যায় না, তাই সারাংশেও না
        $lines = array_values(array_filter(
            (array) ($data['lines'] ?? []),
            fn ($line) => is_array($line) && filled($line['product_id'] ?? null) && $this->moreThanZero($line['qty'] ?? null),
        ));
        $lines = $this->purchases->spreadBillDiscount($lines, $data);

        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];

        foreach ($lines as $line) {
            $product = Product::query()->find((int) $line['product_id']);
            if ($product === null) {
                continue;
            }

            $pack = $this->packed($product, (string) $line['qty'], $line['unit_id'] ?? null, $this->money($line['rate'] ?? null));
            $figures = $this->lineFigures($pack['qty'], $pack['rate'], $line['discount'] ?? '0', $line['tax'] ?? null, $product->tax);
            $totals = $this->addToTotals($totals, $figures);
            $free = (string) ($line['free_qty'] ?? '0');

            $o->line($product->name(), [
                filled($line['batch_no'] ?? null) ? __('purchase::overview_confirm.lot', ['lot' => trim((string) $line['batch_no'])]) : null,
                filled($line['expiry_date'] ?? null) ? __('purchase::overview_confirm.expiry', ['date' => DateFormat::format((string) $line['expiry_date'])]) : null,
                Money::quantity((string) $line['qty']).' × '.Money::format((string) $line['rate']),
                is_numeric($free) && bccomp($free, '0', 4) > 0 ? __('purchase::overview_confirm.free', ['qty' => Money::quantity($free)]) : null,
                bccomp($figures['discount'], '0', 4) > 0 ? __('purchase::overview_confirm.discount', ['amount' => Money::format($figures['discount'])]) : null,
                bccomp($figures['tax'], '0', 4) > 0 ? __('purchase::overview_confirm.vat', ['amount' => Money::format($figures['tax'])]) : null,
                filled($line['sales_price'] ?? null) ? __('purchase::overview_confirm.sales_price', ['amount' => Money::format((string) $line['sales_price'])]) : null,
            ], Money::format($figures['amount']));
        }

        foreach ((array) ($data['gifts'] ?? []) as $gift) {
            $product = is_array($gift) ? Product::query()->find((int) ($gift['product_id'] ?? 0)) : null;
            if ($product !== null && $this->moreThanZero($gift['qty'] ?? null)) {
                $o->line($product->name(), [__('purchase::overview_confirm.gift', ['qty' => Money::quantity((string) $gift['qty'])])]);
            }
        }

        $o->total(__('purchase::overview_confirm.subtotal'), Money::format($totals['subtotal']));
        if (bccomp($totals['discount'], '0', 4) > 0) {
            $o->total(__('purchase::overview_confirm.discounts'), '− '.Money::format($totals['discount']));
        }
        if (bccomp($totals['tax'], '0', 4) > 0) {
            $o->total(__('purchase::overview_confirm.vat_total'), Money::format($totals['tax']));
        }
        $o->total(__('purchase::overview_confirm.net'), Money::format($totals['total']), strong: true);

        // ── টাকা ──────────────────────────────────────────────────────
        $paying = $this->paying($data);
        $o->money(__('purchase::overview_confirm.paying_now'), Money::format($paying));
        $left = bcsub($totals['total'], $paying, 4);
        $o->money(__('purchase::overview_confirm.owed'), Money::format(bccomp($left, '0', 4) > 0 ? $left : '0'));

        $transport = $this->money($data['transport_cost'] ?? null);
        if (bccomp($transport, '0', 4) > 0) {
            $carrier = filled($data['carrier_id'] ?? null)
                ? Supplier::query()->find((int) $data['carrier_id'])?->name()
                : (filled($data['carrier_name'] ?? null) ? (string) $data['carrier_name'] : null);
            $o->money(__('purchase::overview_confirm.transport', ['who' => $carrier ?? '—']), Money::format($transport));
        }

        // ── কার সই লাগবে ─────────────────────────────────────────────
        if ($this->approvals->requires('purchase', 'bill', $totals['total'], class_basename(PurchaseBill::class))) {
            $o->note(__('purchase::overview_confirm.signature'), 'warn');
        }

        return $o;
    }

    /** শূন্যের বেশি কি না — bcmath-এ ([[MoneyIsNeverAFloatTest]]); খালি বা অসংখ্যা মানে "না" */
    private function moreThanZero(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));

        return is_numeric($value) && bccomp($value, '0', 4) > 0;
    }

    /** @param  array<string, mixed>  $data */
    private function paying(array $data): string
    {
        $rows = (array) ($data['deposits'] ?? []);
        if ($rows !== []) {
            return array_reduce($rows, fn (string $sum, $row) => bcadd($sum, $this->money(is_array($row) ? ($row['amount'] ?? '0') : '0'), 4), '0');
        }

        return $this->money($data['paid_now'] ?? null);
    }

    /** @param  array<string, mixed>  $data */
    private function termLabel(array $data): ?string
    {
        $due = filled($data['due_on'] ?? null) ? DateFormat::format((string) $data['due_on']) : null;

        $label = match ((string) ($data['payment_term'] ?? '')) {
            'cash' => __('purchase::field.term_cash'),
            'credit' => __('purchase::overview_confirm.term_credit'),
            'month_end' => __('purchase::field.term_month_end'),
            'fixed' => __('purchase::field.term_fixed'),
            default => null,
        };

        if ($label !== null && $due !== null && ($data['payment_term'] ?? '') !== 'cash') {
            return __('purchase::overview_confirm.term_due', ['term' => $label, 'date' => $due]);
        }

        return $label;
    }
}
