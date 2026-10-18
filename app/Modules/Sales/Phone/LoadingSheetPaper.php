<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Sales\Http\Controllers\LoadingSheetController;
use App\Modules\Sales\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * লোডিং শিট — ওয়েবের `/sales/loading-sheets`: যে ট্রিপ এখনো গাড়িতে তোলা হচ্ছে (খসড়া)। ⓘ পাতায় পণ্যের মোট, ওয়েবের শিটের
 * একই হিসাব ([[LoadingSheetController::productTotals()]]); দর নেই, শিটেও নেই।
 */
final class LoadingSheetPaper extends SalesPhonePaper
{
    public function key(): string
    {
        return 'sales_loading_sheet';
    }

    public function permission(): string
    {
        return 'sales.shipment.view';
    }

    public function webRoute(): array
    {
        return ['sales.loading_sheet.index', []];
    }

    /** ⓘ কেবল খসড়া ট্রিপ — তালিকাতেও, পাতাতেও (ওয়েবের শিটের মতো) */
    public function query(): Builder
    {
        return Shipment::query()->where('sal_shipments.status', DocumentStatus::DRAFT);
    }

    /** ⓘ একটাই অবস্থা — খসড়া; বাছাইয়ের কিছু নেই */
    public function statuses(): array
    {
        return [];
    }

    public function applyStatus(Builder $query, ?string $status): void
    {
        // ⓘ অবস্থা কোয়েরিতেই বাঁধা ([[query()]])
    }

    public function statusOf(Model $paper): string
    {
        return DocumentStatus::DRAFT;
    }

    public function with(): array
    {
        return ['vehicle', 'route', 'lines'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var Shipment $paper */
        return [
            'party' => trim(implode(' · ', array_filter([ShipmentPaper::vehicleOf($paper), $paper->driver_name]))),
            'note' => trim(implode(' · ', array_filter([
                self::nameOf($paper->route),
                __('sales::shipment.lines_count', ['count' => $paper->lines->count()]),
            ]))),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var Shipment $paper */
        $paper->loadMissing(['lines.challan.lines.product.unit', 'lines.challan.customer', 'vehicle', 'route', 'warehouse']);

        return PaperSheet::make(
            facts: [
                __('sales::field.vehicle') => ShipmentPaper::vehicleOf($paper),
                __('sales::field.driver_name') => $paper->driver_name,
                __('core.print.date') => self::day($paper->trx_date),
                __('sales::field.warehouse') => self::nameOf($paper->warehouse),
                __('sales::shipment.loaded') => $paper->lines->map(fn ($l) => $l->challan?->document_no)->filter()->implode(', '),
            ],
            columns: [
                'product' => [__('sales::field.product')],
                'qty' => [__('sales::field.quantity'), true],
                'free' => [__('sales::field.free_qty'), true],
            ],
            rows: array_values(array_map(fn (array $t) => [
                'product' => $t['product'],
                'qty' => trim(Money::format($t['qty']).' '.$t['unit']),
                'free' => bccomp($t['free'], '0', 4) > 0 ? Money::format($t['free']) : null,
            ], LoadingSheetController::productTotals($paper))),
        );
    }
}
