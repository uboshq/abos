<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseRequisition;
use App\Modules\Purchase\Models\PurchaseRequisitionLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ক্রয়ের চাহিদা — ওয়েবের `/purchase/requisitions`। ⓘ টাকা নেই (আনুমানিক দর সারিতে, ক্রয়মূল্যের চাবিতে); পক্ষ চাহিদাকারী।
 */
final class PurchaseRequisitionPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_requisition';
    }

    /** ⓘ ওয়েবের তালিকা ([[PurchaseRequisitionController::index()]]) বাতিলও দেখায় */
    protected function hidesCancelled(): bool
    {
        return false;
    }

    public function permission(): string
    {
        return 'purchase.requisition.view';
    }

    public function webRoute(): array
    {
        return ['purchase.requisition.index', []];
    }

    public function query(): Builder
    {
        return PurchaseRequisition::query();
    }

    public function with(): array
    {
        return ['requester', 'order'];
    }

    public function sumColumn(User $user): ?string
    {
        return null;
    }

    /** ⓘ ওয়েবের তালিকায় খোঁজা নেই — ফোনে কেবল নম্বর ধরে */
    public function search(Builder $query, string $term): void
    {
        $query->where('pur_requisitions.document_no', 'like', '%'.addcslashes($term, '%_\\').'%');
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PurchaseRequisition $paper */
        return [
            'party' => $paper->requester?->name,
            'note' => $paper->order?->document_no === null ? $paper->department : __('purchase::field.became_order').': '.$paper->order->document_no,
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseRequisition $paper */
        $paper->loadMissing(['lines.product.unit', 'requester', 'order', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.requested_by') => $paper->requester?->name,
                __('purchase::field.department') => $paper->department,
                __('purchase::field.date') => self::day($paper->trx_date),
                __('purchase::field.needed_by') => self::day($paper->needed_by),
                __('purchase::field.became_order') => $paper->order?->document_no,
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.purpose') => $paper->purpose,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: [
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.quantity'), true],
            ] + (self::showsPrice() ? ['rate' => [__('purchase::field.estimated_rate'), true]] : []),
            rows: $paper->lines->map(fn (PurchaseRequisitionLine $l) => [
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'qty'),
            ] + (self::showsPrice() ? ['rate' => self::money($l->estimated_rate)] : []))->values()->all(),
        );
    }
}
