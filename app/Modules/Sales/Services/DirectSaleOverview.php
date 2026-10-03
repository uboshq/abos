<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * ⭐ সরাসরি বিক্রয়ের সারাংশ — "নিশ্চিত করুন" চাপলে আগে এটা (মালিক, ৪ অক্টোবর ২০২৬; [[ConfirmOverview]])।
 *
 * ⓘ একই ঘর যা [[DirectSaleService::complete()]] পায় — কিন্তু কিছুই লেখে না। সারির হিসাব বিলের হিসাবের হুবহু
 * ([[CalculatesSalesLines::lineFigures()]]), শেষ মোট = সারির মোট + রাউন্ডিং − বিলের ছাড় ([[SalesInvoiceService]])।
 * বকেয়া, আটকে থাকা আর সীমা — ফোনের অর্ডারের একই হিসাব ([[OrderStanding::for()]], ভেতরে [[CreditExposure]]),
 * আর সীমার সুইচ বন্ধ থাকলে সীমার কথা ওঠেই না ([[CreditExposure::isOn()]])।
 * ⛔ কেবল দেখায়: সীমা পার হলে "নিশ্চিত হবে না" বলে (`stop`), কিন্তু আসল দেয়াল নিশ্চিতের দরজাতেই; খসড়ায় দেয়াল নেই।
 */
final class DirectSaleOverview
{
    use CalculatesSalesLines;

    public function __construct(
        private readonly OrderStanding $standing,
        private readonly CreditExposure $credit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  কাউন্টারের ঘর ([[DirectSaleRules::store()]]-এ যাচাই করা)
     * @param  list<array<string, mixed>>  $lines
     */
    public function build(array $data, array $lines): ConfirmOverview
    {
        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $warehouse = filled($data['warehouse_id'] ?? null)
            ? Warehouse::query()->find((int) $data['warehouse_id'])
            : Warehouse::query()->where('is_default', true)->first();

        $o = ConfirmOverview::titled(__('sales::overview_confirm.title'))
            ->head(__('sales::field.customer'), $customer->name())
            ->head(__('sales::field.date'), DateFormat::format($data['trx_date'] ?? now()->toDateString()))
            ->head(__('sales::overview_confirm.term'), $this->termLabel($data))
            ->head(__('sales::field.warehouse'), $warehouse?->name());

        $sum = '0';
        $lineDiscount = '0';
        $hasDiscount = false;

        foreach ($lines as $line) {
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));
            if ($product === null) {
                continue;
            }
            $qty = (string) $line['qty'];
            $rate = (string) $line['rate'];
            $percent = (string) ($line['discount_percent'] ?? '0');
            $discount = bccomp($percent, '0', 4) > 0 ? bcdiv(bcmul(bcmul($qty, $rate, 4), $percent, 4), '100', 4) : '0';
            $figures = $this->lineFigures($qty, $rate, $discount, null, $product->tax);
            $lot = filled($line['batch_id'] ?? null) ? Batch::query()->find((int) $line['batch_id'])?->batch_no : null;
            $free = (string) ($line['free_qty'] ?? '0');

            $o->line($product->name(), [
                $lot !== null ? __('sales::overview_confirm.lot', ['lot' => $lot]) : null,
                Money::quantity($qty).' × '.Money::format($rate),
                bccomp($free, '0', 4) > 0 ? __('sales::overview_confirm.free', ['qty' => Money::quantity($free)]) : null,
                bccomp($discount, '0', 4) > 0 ? __('sales::overview_confirm.line_discount', ['percent' => Money::quantity($percent), 'amount' => Money::format($discount)]) : null,
                bccomp($figures['tax'], '0', 4) > 0 ? __('sales::overview_confirm.vat', ['amount' => Money::format($figures['tax'])]) : null,
            ], Money::format($figures['amount']));

            $sum = bcadd($sum, $figures['amount'], 4);
            $lineDiscount = bcadd($lineDiscount, $figures['discount'], 4);
            $hasDiscount = $hasDiscount || bccomp($figures['discount'], '0', 4) > 0;
        }

        $billDiscount = $this->money($data['discount_amount'] ?? '0');
        $rounding = (string) ($data['rounding_amount'] ?? '0');
        $rounding = is_numeric($rounding) ? $rounding : '0';
        $net = bcsub(bcadd($sum, $rounding, 4), $billDiscount, 4);
        $hasDiscount = $hasDiscount || bccomp($billDiscount, '0', 4) > 0;

        $o->total(__('sales::overview_confirm.lines_total'), Money::format($sum));
        if (bccomp($lineDiscount, '0', 4) > 0) {
            $o->total(__('sales::overview_confirm.line_discounts'), Money::format($lineDiscount));
        }
        if (bccomp($billDiscount, '0', 4) > 0) {
            $o->total(__('sales::overview_confirm.bill_discount'), '− '.Money::format($billDiscount));
        }
        if (bccomp($rounding, '0', 4) !== 0) {
            $o->total(__('sales::overview_confirm.rounding'), Money::format($rounding));
        }
        $o->total(__('sales::overview_confirm.net'), Money::format($net), strong: true);

        // ── টাকা আর সীমা ─────────────────────────────────────────────
        $paying = $this->paying($data);
        $o->money(__('sales::overview_confirm.paying_now'), Money::format($paying));
        $left = bcsub($net, $paying, 4);
        $o->money(__('sales::overview_confirm.left_on_bill'), Money::format(bccomp($left, '0', 4) > 0 ? $left : '0'));

        $s = $this->standing->for($customer, bccomp($left, '0', 4) > 0 ? $left : '0');
        $o->money(__('sales::overview_confirm.old_due'), Money::format($s['due']));
        if (bccomp($s['advance'], '0', 4) > 0) {
            $o->money(__('sales::overview_confirm.advance'), Money::format($s['advance']), 'good');
        }
        if (bccomp($s['held'], '0', 4) > 0) {
            $o->money(__('sales::overview_confirm.held'), Money::format($s['held']));
        }

        if ($this->credit->isOn()) {
            $o->money(__('sales::overview_confirm.limit'), Money::format($s['limit']));
            if ($s['over_limit']) {
                $o->money(__('sales::overview_confirm.over_limit'), Money::format($s['to_pay']), 'bad');
                $o->note(__('sales::overview_confirm.over_limit_note', ['amount' => Money::format($s['to_pay'])]), 'stop');
            }
        }

        // ── কার সই লাগবে ─────────────────────────────────────────────
        if ($hasDiscount && app(ApprovalEngine::class)->requires('sales', 'discount', $net, class_basename(SalesInvoice::class))) {
            $o->note(__('sales::overview_confirm.discount_signature'), 'warn');
        }

        return $o;
    }

    private function paying(array $data): string
    {
        $rows = (array) ($data['deposits'] ?? []);
        if ($rows !== []) {
            return array_reduce($rows, fn (string $sum, $row) => bcadd($sum, $this->money(is_array($row) ? ($row['amount'] ?? '0') : '0'), 4), '0');
        }

        return $this->money($data['deposit'] ?? '0');
    }

    private function termLabel(array $data): ?string
    {
        $term = (string) ($data['payment_term'] ?? '');

        return match ($term) {
            'cash' => __('sales::field.term_cash'),
            'cod' => __('sales::field.term_cod'),
            'credit' => __('sales::field.term_credit', ['count' => (int) ($data['credit_period_days'] ?? 0)]),
            'month_end' => __('sales::field.term_month_end'),
            'fixed' => __('sales::field.term_fixed'),
            default => null,
        };
    }
}
