<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ "বাকি বন্ধ" বসানো ও তোলা — গ্রাহকের পাতা থেকে, বাকি ও আদায় (৫ অক্টোবর ২০২৬)।
 *
 * ⛔ বাকির সীমা যে চাবিতে বদলায় সেই চাবিতেই (`update` নীতি → `customer.update`) — দুইটাই একই প্রশ্নের উত্তর:
 * এই গ্রাহক কত বাকি পাবেন। ⓘ দুই দিকেই কারণ বাধ্যতামূলক, আর কাজটা নিরীক্ষার খাতায় ওঠে ([[CustomerService::blockCredit()]])।
 */
final class CreditBlockController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CustomerService $customers) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:update,customer', only: ['store']),
            // ⛔ তোলা সীমা বাড়ানোর সইকারীর কাজ ([[CustomerPolicy::liftCreditBlock()]], পুনঃঅডিট ৯ অক্টোবর ২০২৬, গ্রাহক ১৬)
            new Middleware('can:liftCreditBlock,customer', only: ['destroy']),
        ];
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->customers->blockCredit($customer, $data['reason']);

        return back()->with('saved', __('customer::credit_block.blocked'));
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->customers->clearCreditBlock($customer, $data['reason']);

        return back()->with('saved', __('customer::credit_block.cleared'));
    }
}
