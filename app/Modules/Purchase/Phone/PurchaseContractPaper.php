<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseContract;
use App\Modules\Purchase\Models\PurchaseContractLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * সরবরাহকারীর চুক্তি — ওয়েবের `/purchase/contracts`। ⓘ তারিখের ছাঁকনি নেই, ওয়েবের তালিকার মতো (চালু আগে, তারপর শেষের দিন)।
 */
final class PurchaseContractPaper extends PurchasePhonePaper
{
    public function key(): string
    {
        return 'purchase_contract';
    }

    /** ⓘ ওয়েবের তালিকা ([[PurchaseContractController::index()]]) বাতিলও দেখায় */
    protected function hidesCancelled(): bool
    {
        return false;
    }

    public function permission(): string
    {
        return 'purchase.contract.view';
    }

    public function webRoute(): array
    {
        return ['purchase.contract.index', []];
    }

    public function query(): Builder
    {
        return PurchaseContract::query();
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    public function sumColumn(User $user): ?string
    {
        return null;
    }

    /** ⓘ ওয়েবের তালিকায় খোঁজা নেই — ফোনে নম্বর বা সরবরাহকারীর সূত্র ধরে */
    public function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('pur_contracts.document_no', 'like', $like)->orWhere('pur_contracts.supplier_ref', 'like', $like));
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PurchaseContract $paper */
        return [
            'party' => self::nameOf($paper->supplier),
            'note' => trim(self::day($paper->starts_on).' – '.self::day($paper->ends_on), ' –'),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PurchaseContract $paper */
        $paper->loadMissing(['lines.product.unit', 'supplier', 'creator']);

        return PaperSheet::make(
            facts: [
                __('purchase::field.supplier') => self::nameOf($paper->supplier),
                __('purchase::field.supplier_ref') => $paper->supplier_ref,
                __('purchase::field.starts_on') => self::day($paper->starts_on),
                __('purchase::field.ends_on') => self::day($paper->ends_on),
                __('purchase::field.created_by') => $paper->creator?->name,
                __('purchase::field.narration') => $paper->narration,
            ],
            columns: [
                'product' => [__('purchase::field.product')],
                'qty' => [__('purchase::field.qty_limit'), true],
            ] + (self::showsPrice() ? [
                'rate' => [__('purchase::field.agreed_rate'), true],
                'value' => [__('purchase::field.value_limit'), true],
            ] : []),
            rows: $paper->lines->map(fn (PurchaseContractLine $l) => [
                'product' => self::productOf($l),
                'qty' => $l->qty_limit === null ? null : self::qtyOf($l, 'qty_limit'),
            ] + (self::showsPrice() ? [
                'rate' => self::money($l->agreed_rate),
                'value' => $l->value_limit === null ? null : self::money($l->value_limit),
            ] : []))->values()->all(),
        );
    }
}
