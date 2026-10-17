<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\GatePass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * গেট পাস — ওয়েবের `/sales/gate-passes`। ⓘ তারিখ দেওয়ার মুহূর্ত (`issued_at`); অবস্থা দুইটা — দেওয়া আর বাতিল;
 * মাল চালানের সারি থেকে।
 */
final class GatePassPaper extends SalesPhonePaper
{
    public function key(): string
    {
        return 'sales_gate_pass';
    }

    public function permission(): string
    {
        return 'sales.gate_pass.view';
    }

    public function webRoute(): array
    {
        return ['sales.gate_pass.index', []];
    }

    public function query(): Builder
    {
        return GatePass::query();
    }

    public function dateColumn(): ?string
    {
        return 'sal_gate_passes.issued_at';
    }

    public function statuses(): array
    {
        return [
            GatePass::ISSUED => __('sales::gate_pass.status.issued'),
            GatePass::CANCELLED => __('sales::gate_pass.status.cancelled'),
        ];
    }

    public function printType(): ?string
    {
        return 'GatePass';
    }

    public function with(): array
    {
        return ['challan.customer'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var GatePass $paper */
        return [
            'party' => self::nameOf($paper->challan?->customer),
            'note' => trim(implode(' · ', array_filter([$paper->challan?->document_no, $paper->vehicle_no, $paper->driver_name]))),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var GatePass $paper */
        $paper->loadMissing(['challan.lines.product.unit', 'challan.customer', 'shipment', 'issuer', 'canceller']);

        return PaperSheet::make(
            facts: [
                __('sales::field.customer') => self::nameOf($paper->challan?->customer),
                __('sales::field.challan') => $paper->challan?->document_no,
                __('sales::gate_pass.column.trip') => $paper->shipment?->document_no,
                __('core.print.date') => self::moment($paper->issued_at),
                __('sales::field.vehicle_no') => $paper->vehicle_no,
                __('sales::field.driver_name') => $paper->driver_name,
                __('sales::field.driver_phone') => $paper->driver_phone,
                __('sales::gate_pass.column.issued_by') => $paper->issuer?->name,
                __('sales::field.reason') => $paper->cancel_reason,
            ],
            columns: [
                'product' => [__('sales::field.product')],
                'qty' => [__('sales::field.quantity'), true],
                'free' => [__('sales::field.free_qty'), true],
            ],
            rows: ($paper->challan?->lines ?? collect())->map(fn (DeliveryChallanLine $l) => [
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'delivered_qty'),
                'free' => self::freeOf($l),
            ])->values()->all(),
        );
    }
}
