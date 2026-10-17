<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\Rfq;
use App\Modules\Purchase\Models\RfqLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** দর চাওয়া (RFQ) — ওয়েবের `/purchase/rfqs`; পক্ষ যাদের কাছে চাওয়া, কতজন উত্তর দিলেন */
final class RfqPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_rfq';
    }

    /** ⓘ ওয়েবের তালিকা ([[RfqController::index()]]) বাতিলও দেখায় */
    protected function hidesCancelled(): bool
    {
        return false;
    }

    public function permission(): string
    {
        return 'purchase.rfq.view';
    }

    public function webRoute(): array
    {
        return ['purchase.rfq.index', []];
    }

    public function query(): Builder
    {
        return Rfq::query();
    }

    public function with(): array
    {
        return ['suppliers', 'quotations'];
    }

    public function sumColumn(User $user): ?string
    {
        return null;
    }

    /** ⓘ ওয়েবের তালিকায় খোঁজা নেই — ফোনে কেবল নম্বর ধরে */
    public function search(Builder $query, string $term): void
    {
        $query->where('pur_rfqs.document_no', 'like', '%'.addcslashes($term, '%_\\').'%');
    }

    public function row(Model $paper, User $user): array
    {
        /** @var Rfq $paper */
        return [
            'party' => $paper->suppliers->map(fn ($s) => self::nameOf($s))->filter()->implode(', '),
            'note' => __('purchase::field.answers').': '.$paper->quotations->count().'/'.$paper->suppliers->count(),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var Rfq $paper */
        $paper->loadMissing(['lines.product.unit', 'suppliers', 'quotations.supplier', 'warehouse', 'requisition', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.respond_by') => self::day($paper->respond_by),
                __('purchase::field.warehouse') => self::nameOf($paper->warehouse),
                __('purchase::field.ask_these') => $paper->suppliers->map(fn ($s) => self::nameOf($s))->filter()->implode(', '),
                __('purchase::field.answers') => $paper->quotations->map(fn ($q) => self::nameOf($q->supplier))->filter()->implode(', '),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: [
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.quantity'), true],
                'spec' => [__('purchase::field.specification')],
            ],
            rows: $paper->lines->map(fn (RfqLine $l) => [
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'qty'),
                'spec' => $l->specification,
            ])->values()->all(),
        );
    }
}
