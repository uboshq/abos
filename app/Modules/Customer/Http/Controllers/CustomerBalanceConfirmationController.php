<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Services\PartyRegistry;
use App\Core\Support\BalanceConfirmation;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ গ্রাহকের পাওনা-দেনা নিশ্চিতকরণের চিঠি — ISA 505 (ERP ১০০%-এর হিসাবের ফাঁক #১৯, ২০ অক্টোবর ২০২৬; [[BalanceConfirmation]])।
 *
 * <p>⛔ জের গ্রাহকের পাতার খাতার একই ভিত্তিতে ([[CustomerController::show()]]: হেডারে বাছা শাখা, এই গ্রাহক)। নাম "নাম · পয়েন্ট
 * · শাখা" (মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)। অনুমতি গ্রাহকের পাতারই — `can:view,customer`।
 */
final class CustomerBalanceConfirmationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly BalanceConfirmation $letters, private readonly PartyRegistry $parties) {}

    public static function middleware(): array
    {
        return [new Middleware('can:view,customer')];
    }

    public function __invoke(Request $request, Customer $customer): Response
    {
        $customer->loadMissing(['location.parent', 'branch']);
        $label = collect([$customer->nameWithPoint(), $customer->branch?->name()])->filter(fn ($part) => filled($part))->implode(' · ');

        return $this->letters->respond(
            $request,
            $customer,
            $label,
            $this->parties->paperFacts(Customer::drillSourceType(), (int) $customer->id) ?? [],
            ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')->forParty(Customer::drillSourceType(), $customer->id),
        );
    }
}
