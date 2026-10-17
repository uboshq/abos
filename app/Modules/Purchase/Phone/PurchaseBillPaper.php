<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ক্রয় বিল — ওয়েবের `/purchase/bills`। ⓘ সরাসরি ক্রয়ও এখানে: ওয়েবের `/purchase/direct` কেবল লেখার ফর্ম, তার ফল বিলই।
 */
final class PurchaseBillPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_bill';
    }

    public function permission(): string
    {
        return 'purchase.bill.view';
    }

    public function webRoute(): array
    {
        return ['purchase.bill.index', []];
    }

    public function query(): Builder
    {
        return PurchaseBill::query();
    }

    public function printType(): ?string
    {
        return 'PurchaseBill';
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PurchaseBill $paper */
        return parent::row($paper, $user) + [
            'note' => filled($paper->supplier_bill_no) ? __('purchase::field.supplier_bill_no').': '.$paper->supplier_bill_no : null,
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseBill $paper */
        $paper->loadMissing(['lines.product.unit', 'supplier', 'warehouse', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.supplier') => self::nameOf($paper->supplier),
                __('purchase::field.supplier_bill_no') => $paper->supplier_bill_no,
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.received_on') => self::day($paper->received_on),
                __('purchase::field.due_on') => self::day($paper->due_on),
                __('purchase::field.warehouse') => self::nameOf($paper->warehouse),
                __('purchase::field.subtotal') => self::money($paper->subtotal),
                __('purchase::field.discount') => self::moneyIfAny($paper->discount),
                __('purchase::field.tax') => self::moneyIfAny($paper->tax),
                __('purchase::field.transport_cost') => self::moneyIfAny($paper->transport_cost),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: self::priced([
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.quantity'), true],
                'free' => [__('purchase::field.free_qty'), true],
            ]),
            rows: $paper->lines->map(fn (PurchaseBillLine $l) => self::pricedRow([
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'qty'),
                'free' => self::freeOf($l),
            ], $l))->values()->all(),
            totals: ['amount' => self::money($paper->total)],
        );
    }
}
