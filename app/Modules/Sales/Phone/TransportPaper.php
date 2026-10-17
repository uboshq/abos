<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Http\Controllers\TransportAssignmentController;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * পরিবহনের খাতা — ওয়েবের `/sales/transport`: নিশ্চিত চালান, কোনটার পরিবহন ঠিক হয়নি, হয়েছে, গেট পাস হয়েছে, ভাড়া বাকি।
 *
 * <p>⛔ ট্যাবের সংজ্ঞা ওয়েবের নিজের ([[TransportAssignmentController::inTab()]]) — ফোনের "অবস্থা" চিপ ঠিক ঐ চারটা ট্যাব; না বাছলে
 * প্রথমটা, ওয়েবের মতো। সর্বমোট পরিবহন খরচের। ⓘ ভাড়া লেখা বা দেওয়া ওয়েবেই — এখানে কেবল দেখা।
 */
final class TransportPaper extends SalesPhonePaper
{
    public function key(): string
    {
        return 'sales_transport';
    }

    public function permission(): string
    {
        return 'sales.challan.view';
    }

    public function webRoute(): array
    {
        return ['sales.transport.index', []];
    }

    public function query(): Builder
    {
        return DeliveryChallan::query()->whereIn('sal_challans.status', DocumentStatus::POSTED);
    }

    public function statuses(): array
    {
        return collect(TransportAssignmentController::TABS)
            ->mapWithKeys(fn (string $tab) => [$tab => __('sales::transport_board.tab_'.$tab)])->all();
    }

    public function applyStatus(Builder $query, ?string $status): void
    {
        TransportAssignmentController::inTab($query, $status ?? TransportAssignmentController::TABS[0]);
    }

    /** ⓘ সারির অবস্থা চালানেরটাই (নিশ্চিত/সম্পন্ন) — ট্যাব ছাঁকনি, অবস্থা নয় */
    public function statusOf(Model $paper): string
    {
        return (string) $paper->getAttribute('status');
    }

    public function sumColumn(User $user): ?string
    {
        return 'sal_challans.transport_cost';
    }

    public function printType(): ?string
    {
        return 'DeliveryChallan';
    }

    public function with(): array
    {
        return ['customer', 'vehicle'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var DeliveryChallan $paper */
        return [
            'party' => self::nameOf($paper->customer),
            'note' => trim(implode(' · ', array_filter([
                $paper->vehicle?->getAttribute('registration_no') ?: $paper->vehicle_no,
                $paper->driver_name,
                $paper->carrier_name,
            ]))),
            'total' => (string) ($paper->transport_cost ?? '0'),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var DeliveryChallan $paper */
        $paper->loadMissing(['lines.product.unit', 'customer', 'vehicle', 'warehouse']);

        return PaperSheet::make(
            facts: [
                __('sales::field.customer') => self::nameOf($paper->customer),
                __('core.print.date') => self::day($paper->trx_date),
                __('sales::field.vehicle') => $paper->vehicle?->getAttribute('registration_no') ?: $paper->vehicle_no,
                __('sales::field.driver_name') => $paper->driver_name,
                __('sales::field.driver_phone') => $paper->driver_phone,
                __('sales::field.carrier') => $paper->carrier_name,
                __('sales::field.transport_cost') => self::moneyIfAny($paper->transport_cost),
                __('sales::field.ship_to') => $paper->ship_to,
                __('sales::field.warehouse') => self::nameOf($paper->warehouse),
            ],
            columns: [
                'product' => [__('sales::field.product')],
                'qty' => [__('sales::field.quantity'), true],
                'free' => [__('sales::field.free_qty'), true],
            ],
            rows: $paper->lines->sortBy('line_no')->values()->map(fn (DeliveryChallanLine $l) => [
                'product' => self::productOf($l),
                'qty' => self::qtyOf($l, 'delivered_qty'),
                'free' => self::freeOf($l),
            ])->all(),
        );
    }
}
