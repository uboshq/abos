<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Models\ShipmentLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * রওনার খাতা (ট্রিপ) — ওয়েবের `/sales/shipments`। ⓘ খসড়া = গাড়িতে তোলা হচ্ছে, নিশ্চিত = পথে, সম্পন্ন = ফিরেছে;
 * সর্বমোট পরিবহন খরচের। পাতায় প্রতিটা চালান আর তার কী হলো।
 */
final class ShipmentPaper extends SalesPhonePaper
{
    public function key(): string
    {
        return 'sales_shipment';
    }

    public function permission(): string
    {
        return 'sales.shipment.view';
    }

    public function webRoute(): array
    {
        return ['sales.shipment.index', []];
    }

    public function query(): Builder
    {
        return Shipment::query();
    }

    public function statuses(): array
    {
        return [
            DocumentStatus::DRAFT => __('sales::shipment.loaded'),
            DocumentStatus::CONFIRMED => __('sales::shipment.on_the_road'),
            DocumentStatus::CLOSED => __('core.status.closed'),
            DocumentStatus::CANCELLED => __('core.status.cancelled'),
        ];
    }

    public function sumColumn(User $user): ?string
    {
        return 'sal_shipments.transport_cost';
    }

    public function with(): array
    {
        return ['vehicle', 'route', 'lines'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var Shipment $paper */
        return [
            'party' => trim(implode(' · ', array_filter([self::vehicleOf($paper), $paper->driver_name]))),
            'note' => trim(implode(' · ', array_filter([
                self::nameOf($paper->route),
                __('sales::shipment.lines_count', ['count' => $paper->lines->count()]),
            ]))),
            'total' => (string) ($paper->transport_cost ?? '0'),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var Shipment $paper */
        $paper->loadMissing(['lines.challan.customer', 'vehicle', 'route', 'warehouse', 'carrier', 'creator']);

        return PaperSheet::make(
            facts: [
                __('sales::field.vehicle') => self::vehicleOf($paper),
                __('sales::field.driver_name') => $paper->driver_name,
                __('sales::field.driver_phone') => $paper->driver_phone,
                __('sales::field.carrier') => self::nameOf($paper->carrier) ?? $paper->carrier_name,
                __('core.print.date') => self::day($paper->trx_date),
                __('sales::shipment.dispatch') => self::moment($paper->dispatched_at),
                __('sales::shipment.close') => self::moment($paper->returned_at),
                __('sales::field.warehouse') => self::nameOf($paper->warehouse),
                __('sales::field.transport_cost') => self::moneyIfAny($paper->transport_cost),
                __('core.table.narration') => $paper->narration,
            ],
            columns: [
                'challan' => [__('sales::field.challan')],
                'customer' => [__('sales::field.customer')],
                'outcome' => [__('sales::shipment.outcome')],
            ],
            rows: $paper->lines->sortBy('line_no')->values()->map(fn (ShipmentLine $l) => [
                'challan' => $l->challan?->document_no,
                'customer' => self::nameOf($l->challan?->customer),
                'outcome' => trim(__('sales::shipment.'.($l->outcome ?: 'pending')).($l->outcome_note ? ' — '.$l->outcome_note : '')),
            ])->all(),
            totals: [],
        );
    }

    /** গাড়ি — বহরের হলে তার নম্বর, নইলে লেখা নম্বর */
    public static function vehicleOf(Shipment $paper): ?string
    {
        return $paper->vehicle?->getAttribute('registration_no') ?: ($paper->vehicle_no ?: null);
    }
}
