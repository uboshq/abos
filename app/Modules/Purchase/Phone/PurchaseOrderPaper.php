<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** ক্রয়াদেশ — ওয়েবের `/purchase/orders` */
final class PurchaseOrderPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_order';
    }

    public function permission(): string
    {
        return 'purchase.order.view';
    }

    public function webRoute(): array
    {
        return ['purchase.order.index', []];
    }

    public function query(): Builder
    {
        return PurchaseOrder::query();
    }

    public function printType(): ?string
    {
        return 'PurchaseOrder';
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseOrder $paper */
        $paper->loadMissing(['lines.product.unit', 'supplier', 'warehouse', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.supplier') => self::nameOf($paper->supplier),
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.expected_on') => self::day($paper->expected_on),
                __('purchase::field.warehouse') => self::nameOf($paper->warehouse),
                __('purchase::field.subtotal') => self::money($paper->subtotal),
                __('purchase::field.discount') => self::moneyIfAny($paper->discount),
                __('purchase::field.tax') => self::moneyIfAny($paper->tax),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: self::priced([
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.quantity'), true],
            ]),
            rows: $paper->lines->map(fn (PurchaseOrderLine $l) => self::pricedRow([
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'ordered_qty'),
            ], $l))->values()->all(),
            totals: ['amount' => self::money($paper->total)],
        );
    }
}
