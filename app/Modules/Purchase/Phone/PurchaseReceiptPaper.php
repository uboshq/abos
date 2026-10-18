<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReceiptLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** মাল গ্রহণ (GRN) — ওয়েবের `/purchase/receipts` */
final class PurchaseReceiptPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_receipt';
    }

    public function permission(): string
    {
        return 'purchase.receipt.view';
    }

    public function webRoute(): array
    {
        return ['purchase.receipt.index', []];
    }

    public function query(): Builder
    {
        return PurchaseReceipt::query();
    }

    public function printType(): ?string
    {
        return 'PurchaseReceipt';
    }

    public function with(): array
    {
        return ['supplier', 'order'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PurchaseReceipt $paper */
        return parent::row($paper, $user) + [
            'note' => $paper->order?->document_no === null ? null : __('purchase::field.order').': '.$paper->order->document_no,
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseReceipt $paper */
        $paper->loadMissing(['lines.product.unit', 'supplier', 'warehouse', 'order', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.supplier') => self::nameOf($paper->supplier),
                __('purchase::field.supplier_challan_no') => $paper->supplier_challan_no,
                __('purchase::field.order') => $paper->order?->document_no,
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.warehouse') => self::nameOf($paper->warehouse),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: self::priced([
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.received'), true],
                'free' => [__('purchase::field.free_qty'), true],
                'batch' => [__('inventory::field.batch_no')],
            ]),
            rows: $paper->lines->map(fn (PurchaseReceiptLine $l) => self::pricedRow([
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'received_qty'),
                'free' => self::freeOf($l),
                'batch' => $l->batch_no,
            ], $l))->values()->all(),
            totals: ['amount' => self::money($paper->total)],
        );
    }
}
