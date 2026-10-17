<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Phone\PhonePaper;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * গুদাম থেকে গুদামে মাল সরানো — ওয়েবের `/inventory/transfers` ([[PhonePaper]])। ⓘ টাকা নেই; নিশ্চিত = পথে, সম্পন্ন = পৌঁছেছে।
 */
final class StockTransferPaper extends PhonePaper
{
    public function key(): string
    {
        return 'inventory_transfer';
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function permission(): string
    {
        return 'inventory.transfer.view';
    }

    public function webRoute(): array
    {
        return ['inventory.transfer.index', []];
    }

    public function query(): Builder
    {
        return StockTransfer::query();
    }

    public function statuses(): array
    {
        return [
            DocumentStatus::DRAFT => __('inventory::status.draft'),
            DocumentStatus::CONFIRMED => __('inventory::status.on_the_way'),
            DocumentStatus::CLOSED => __('inventory::status.arrived'),
            DocumentStatus::CANCELLED => __('inventory::status.cancelled'),
        ];
    }

    public function printType(): ?string
    {
        return 'StockTransfer';
    }

    public function with(): array
    {
        return ['fromWarehouse', 'toWarehouse'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var StockTransfer $paper */
        return ['party' => self::routeOf($paper)];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var StockTransfer $paper */
        $paper->loadMissing(['lines.product.unit', 'fromWarehouse', 'toWarehouse', 'creator']);

        return PaperSheet::make(
            facts: [
                __('inventory::field.from_warehouse') => self::nameOf($paper->fromWarehouse),
                __('inventory::field.to_warehouse') => self::nameOf($paper->toWarehouse),
                __('inventory::field.date') => $paper->trx_date === null ? null : DateFormat::format($paper->trx_date),
                __('inventory::field.dispatched_at') => $paper->dispatched_at === null ? null : DateFormat::formatWithTime($paper->dispatched_at),
                __('inventory::field.received_at') => $paper->received_at === null ? null : DateFormat::formatWithTime($paper->received_at),
                __('inventory::field.narration') => $paper->narration,
            ],
            columns: [
                'product' => [__('inventory::field.product')],
                'qty' => [__('inventory::field.quantity'), true],
            ],
            rows: $paper->lines->map(fn (StockTransferLine $l) => [
                'product' => trim(($l->product?->code ?? '').' - '.(self::nameOf($l->product) ?? ''), ' -'),
                'qty' => trim(Money::format((string) $l->qty).' '.(self::nameOf($l->product?->unit) ?? '')),
            ])->values()->all(),
        );
    }

    /** কোথা থেকে কোথায় */
    private static function routeOf(StockTransfer $paper): string
    {
        return trim((self::nameOf($paper->fromWarehouse) ?? '').' → '.(self::nameOf($paper->toWarehouse) ?? ''), ' →');
    }
}
