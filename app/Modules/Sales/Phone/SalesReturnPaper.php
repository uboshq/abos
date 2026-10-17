<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** বিক্রয় ফেরত — ওয়েবের `/sales/returns`। ⓘ মালের খরচ (`cost_of_goods`) ফোনে নেই — ওয়েবের তালিকাতেও নয় */
final class SalesReturnPaper extends SalesPhonePaper
{
    public function key(): string
    {
        return 'sales_return';
    }

    public function permission(): string
    {
        return 'sales.return.view';
    }

    public function webRoute(): array
    {
        return ['sales.return.index', []];
    }

    public function query(): Builder
    {
        return SalesReturn::query();
    }

    public function with(): array
    {
        return ['customer', 'invoice'];
    }

    public function sumColumn(User $user): ?string
    {
        return 'sal_returns.total';
    }

    public function row(Model $paper, User $user): array
    {
        /** @var SalesReturn $paper */
        return [
            'party' => self::nameOf($paper->customer),
            'note' => $paper->invoice?->document_no === null ? null : __('sales::field.invoice').': '.$paper->invoice->document_no,
            'total' => (string) ($paper->total ?? '0'),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var SalesReturn $paper */
        $paper->loadMissing(['lines.product.unit', 'customer', 'invoice', 'warehouse', 'reasonCode', 'creator']);

        return PaperSheet::make(
            facts: [
                __('sales::field.customer') => self::nameOf($paper->customer),
                __('sales::field.invoice') => $paper->invoice?->document_no,
                __('core.print.date') => self::day($paper->trx_date),
                __('sales::field.warehouse') => self::nameOf($paper->warehouse),
                __('sales::field.reason') => trim((self::nameOf($paper->reasonCode) ?? '').' '.($paper->reason_note ?? '')),
                __('sales::field.subtotal') => self::money($paper->subtotal),
                __('sales::field.tax') => self::moneyIfAny($paper->tax),
                __('core.table.narration') => $paper->narration,
            ],
            columns: [
                'product' => [__('sales::field.product')],
                'qty' => [__('sales::field.quantity'), true],
                'free' => [__('sales::field.free_qty'), true],
                'rate' => [__('sales::field.rate'), true],
                'amount' => [__('sales::field.amount'), true],
            ],
            rows: $paper->lines->map(fn (SalesReturnLine $l) => [
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'qty'),
                'free' => self::freeOf($l),
                'rate' => self::money($l->rate),
                'amount' => self::money($l->amount),
            ])->values()->all(),
            totals: ['amount' => self::money($paper->total)],
        );
    }
}
