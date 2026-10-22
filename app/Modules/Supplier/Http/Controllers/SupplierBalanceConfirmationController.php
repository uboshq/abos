<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Core\Services\PartyRegistry;
use App\Core\Support\BalanceConfirmation;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ সরবরাহকারীর পাওনা-দেনা নিশ্চিতকরণের চিঠি — ISA 505 (ERP ১০০%-এর হিসাবের ফাঁক #১৯, ২০ অক্টোবর ২০২৬; [[BalanceConfirmation]])।
 *
 * <p>⛔ জের সরবরাহকারীর পাতার দেনার খাতার একই ভিত্তিতে ([[SupplierController::ledgerPart()]]: হেডারে বাছা শাখা, এই সরবরাহকারী,
 * বিল-না-আসা মাল (GRNI) বাদ — ওটা এখনো দেনা নয়)। নাম "নাম · শাখা"। অনুমতি সরবরাহকারীর পাতারই — `can:view,supplier`।
 */
final class SupplierBalanceConfirmationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly BalanceConfirmation $letters, private readonly PartyRegistry $parties) {}

    public static function middleware(): array
    {
        return [new Middleware('can:view,supplier')];
    }

    public function __invoke(Request $request, Supplier $supplier): Response
    {
        $supplier->loadMissing('branch');
        $label = collect([$supplier->name(), $supplier->branch?->name()])->filter(fn ($part) => filled($part))->implode(' · ');

        return $this->letters->respond(
            $request,
            $supplier,
            $label,
            $this->parties->paperFacts(Supplier::drillSourceType(), (int) $supplier->id) ?? [],
            ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
                ->forParty(Supplier::drillSourceType(), $supplier->id)
                ->whereNotIn('ledger_entries.account_id', Supplier::goodsNotBilledAccountIds()),
        );
    }
}
