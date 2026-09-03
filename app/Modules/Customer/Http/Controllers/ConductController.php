<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerConduct;
use App\Modules\Customer\Services\ConductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * পার্টির আচরণ লেখা ও নামানো — গ্রাহকের পাতা থেকে।
 *
 * এক অনুমতি দুই কাজে: পতাকা তোলা আর নামানো একই দায়িত্বের দুই দিক।
 */
final class ConductController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ConductService $conduct) {}

    public static function middleware(): array
    {
        return [new Middleware('can:customer.conduct.manage')];
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // ধরন চেনা কি না আর OTHER-এ নোট আছে কি না — সার্ভিস দেখে
        $this->conduct->record($customer, $data['type'], $data['note'] ?? null);

        return back()->with('status', __('customer::conduct.recorded'));
    }

    public function retire(CustomerConduct $conduct): RedirectResponse
    {
        $this->conduct->retire($conduct);

        return back()->with('status', __('customer::conduct.was_retired'));
    }
}
