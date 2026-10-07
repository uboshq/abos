<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\FiltersByDate;
use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * পরিবহন বরাদ্দ — Delivery Processing-এর প্রথম পাতা (মালিক, ৩ অক্টোবর ২০২৬: "Delivery Processing er kaj ke korteche?")।
 *
 * ── কী নয় ─────────────────────────────────────────────────────────────
 * নতুন কোনো ফর্ম নয়। মালিকের ২৮ সেপ্টেম্বরের সিদ্ধান্তে পরিবহন বসে চালানের "মাল কীভাবে যাবে" পপআপে
 * ([[ChallanTransportController]]); এই পাতা কেবল **কোন চালানে এখনো বসেনি** সেটা এক জায়গায় দেখায়, আর প্রতিটা
 * সারির বোতাম ঐ একই পপআপ খোলে। ⛔ দুই জায়গায় দুই রকম ফর্ম থাকলে একদিন দুইটা দুই নিয়ম মানত।
 *
 * ── তিন ট্যাব ──────────────────────────────────────────────────────────
 * নিশ্চিত চালান (খসড়া আর বাতিল নয়), তিন ভাগে — প্রতিটা চালান ঠিক একটায়:
 *   ⓵ ঠিক হয়নি  — নিজের গাড়ি নয়, গাড়ি/নম্বর/বাহক কিছুই বসেনি ([[ChallanTransportController::mode()]] null)
 *   ⓶ ঠিক হয়েছে — পথ বসেছে, গেট পাস এখনো হয়নি (তাই বদলানো যায়)
 *   ⓷ গেট পাস হয়েছে — চালু গেট পাস আছে; মাল বেরিয়ে গেছে ([[ChallanTransportController::locked()]]-এর একই প্রশ্ন)
 *
 * ⛔ শাখা: চালানের নিজের স্কোপ ([[ScopedToUserBranch]]) হেডারে বাছা শাখা মানে; গ্রাহকের ড্রপডাউন
 * `inViewedBranch()` — অন্য শাখার দোকানের নাম এখানে আসে না। কোম্পানির দেয়াল মডেলের স্কোপে।
 */
final class TransportAssignmentController extends Controller implements HasMiddleware
{
    use FiltersByDate;
    use GrandTotals;

    // ⭐ "ভাড়া বাকি" — পরে-দেব ভাড়া, এখনো দেওয়া হয়নি (মালিক, ৭ অক্টোবর ২০২৬; [[FarePayment::isDue()]])
    public const TABS = ['unassigned', 'assigned', 'passed', 'fare_due'];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.view')];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'unassigned';

        $base = DeliveryChallan::query()
            ->whereIn('sal_challans.status', DocumentStatus::POSTED)
            ->search($request->query('q'));

        $dates = $this->applyDateRange($base, $request);

        $vehicleId = (int) $request->query('vehicle');
        $customerId = (int) $request->query('customer');
        $driver = trim((string) $request->query('driver'));

        $base->when($vehicleId > 0, fn ($q) => $q->where('sal_challans.vehicle_id', $vehicleId))
            ->when($customerId > 0, fn ($q) => $q->where('sal_challans.customer_id', $customerId))
            ->when($driver !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('sal_challans.driver_name', 'like', '%'.$driver.'%')
                ->orWhere('sal_challans.driver_phone', 'like', '%'.$driver.'%')));

        $counts = [];

        foreach (self::TABS as $name) {
            $counts[$name] = self::inTab(clone $base, $name)->count();
        }

        $query = self::inTab(clone $base, $tab)
            ->with(['customer.location', 'vehicle'])
            ->orderByDesc('sal_challans.trx_date')
            ->orderByDesc('sal_challans.id');

        $grand = $this->grandTotals($query, ['transport_cost' => 't.transport_cost']);
        $challans = $query->paginate(50)->withQueryString();

        return view('sales::transport.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'counts' => $counts,
            'grand' => $grand,
            'challans' => $challans,
            // ⓘ চালু গেট পাসের নম্বর, পুরো পাতার এক প্রশ্নে — সারিপ্রতি নয়
            'passes' => GatePass::query()
                ->whereIn('delivery_challan_id', collect($challans->items())->pluck('id'))
                ->where('status', '<>', DocumentStatus::CANCELLED)
                ->pluck('document_no', 'delivery_challan_id'),
            'dates' => $dates,
            'q' => $request->query('q'),
            'vehicle' => $vehicleId ?: null,
            'customer' => $customerId ?: null,
            'driver' => $driver,
            'vehicles' => $this->settings->get('master_data.vehicle_enabled')
                ? Vehicle::query()->active()->orderBy('code')->get()
                : collect(),
            'customers' => Customer::query()->inViewedBranch()->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
            'canAssign' => (bool) $request->user()?->can('sales.challan.create'),
        ]);
    }

    /**
     * ⓘ তিন ট্যাবের সংজ্ঞা এক জায়গায় — গোনা আর তালিকা একই প্রশ্ন জিজ্ঞেস করে, তাই ট্যাবের সংখ্যা আর সারি কখনো দুই কথা বলে না।
     *
     * @param  Builder<DeliveryChallan>  $query
     * @return Builder<DeliveryChallan>
     */
    public static function inTab(Builder $query, string $tab): Builder
    {
        $passed = fn ($q) => $q->selectRaw('1')->from('sal_gate_passes as gp')
            ->whereColumn('gp.delivery_challan_id', 'sal_challans.id')
            ->where('gp.status', '<>', DocumentStatus::CANCELLED);

        $named = fn ($q) => $q->where('sal_challans.own_transport', true)
            ->orWhereNotNull('sal_challans.vehicle_id')
            ->orWhere(fn ($w) => $w->whereNotNull('sal_challans.vehicle_no')->where('sal_challans.vehicle_no', '<>', ''))
            ->orWhereNotNull('sal_challans.carrier_id')
            ->orWhere(fn ($w) => $w->whereNotNull('sal_challans.carrier_name')->where('sal_challans.carrier_name', '<>', ''));

        return match ($tab) {
            // ⓘ [[FarePayment::isDue()]]-এর একই শর্ত, কোয়েরিতে: পরে-দেব, বাহক আছে, ভাউচার নেই বা বাতিল
            'fare_due' => $query->where('sal_challans.fare_rule', \App\Modules\Sales\Services\FarePayment::RULE)
                ->where('sal_challans.fare_status', \App\Modules\Sales\Services\FarePayment::DUE)
                ->whereNotNull('sal_challans.carrier_id')
                ->where(fn ($w) => $w->whereNull('sal_challans.fare_voucher_id')
                    ->orWhereIn('sal_challans.fare_voucher_id', \App\Modules\Accounts\Models\Voucher::query()
                        ->where('status', DocumentStatus::CANCELLED)->select('id'))),
            'passed' => $query->whereExists($passed),
            'assigned' => $query->whereNotExists($passed)->where($named),
            default => $query->whereNotExists($passed)->whereNot($named),
        };
    }
}
