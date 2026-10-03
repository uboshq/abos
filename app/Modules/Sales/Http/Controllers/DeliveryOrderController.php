<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\DeliveryOrderTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * পুরনো `/sales/do` — চালানের ধাপের তালিকা, এখন "ডেলিভারি চালান তালিকা"-র ট্যাব (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ আসল ডেলিভারি অর্ডারের নিজের ডেস্ক আছে ([[DeliveryOrderDeskController]]); একই চালানের দুই তালিকা আর নয়।
 * পুরনো ঠিকানা আর বুকমার্ক হারায় না — একই ট্যাবে পৌঁছায়, খোঁজ আর তারিখসহ।
 */
class DeliveryOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.view')];
    }

    public function index(Request $request): RedirectResponse
    {
        $tab = in_array($request->query('tab'), DeliveryOrderTabs::LISTED, true) ? (string) $request->query('tab') : 'awaiting';

        return redirect()->route('sales.challan.index', ['tab' => $tab, ...$request->except(['tab', 'page'])], 301);
    }
}
