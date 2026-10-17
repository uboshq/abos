<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** ক্রয় ফেরত — ওয়েবের `/purchase/returns` */
final class PurchaseReturnPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_return';
    }

    public function permission(): string
    {
        return 'purchase.return.view';
    }

    public function webRoute(): array
    {
        return ['purchase.return.index', []];
    }

    public function query(): Builder
    {
        return PurchaseReturn::query();
    }

    public function printType(): ?string
    {
        return 'PurchaseReturn';
    }

    public function with(): array
    {
        return ['supplier', 'bill'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PurchaseReturn $paper */
        return parent::row($paper, $user) + [
            'note' => $paper->bill?->document_no === null ? null : __('purchase::field.bill').': '.$paper->bill->document_no,
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseReturn $paper */
        $paper->loadMissing(['lines.product.unit', 'supplier', 'warehouse', 'bill', 'reasonCode', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.supplier') => self::nameOf($paper->supplier),
                __('purchase::field.bill') => $paper->bill?->document_no,
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.warehouse') => self::nameOf($paper->warehouse),
                __('purchase::field.reason') => self::nameOf($paper->reasonCode),
                __('purchase::field.tax') => self::moneyIfAny($paper->tax),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: self::priced([
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.quantity'), true],
            ]),
            rows: $paper->lines->map(fn (PurchaseReturnLine $l) => self::pricedRow([
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'qty'),
            ], $l))->values()->all(),
            totals: ['amount' => self::money($paper->total)],
        );
    }
}
