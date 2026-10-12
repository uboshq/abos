<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\Money;
use App\Core\Support\PartyLedger;
use App\Core\Support\PhoneInput;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ ফোনে গ্রাহকের খাতা — মালিক, ১২ অক্টোবর ২০২৬: *"app e customer list e Customar ledger zate dekha zay sohoze bujarmoto
 * mobile vew sohoz kore koro"*।
 *
 * `GET /api/v1/sales/customers/{public_id}/ledger?page=&from=&to=`
 *
 * ⓘ ওয়েবের গ্রাহক-পাতার খাতার হুবহু ([[CustomerController::show()]]): হেডারে বাছা শাখার সারি, তারিখের ক্রমে, খুললে শেষ পাতা,
 * প্রতিটা সারির জের খাতার সব সারি থেকে ([[PartyLedger::page()]])। প্রিন্সিপালের ফোন-খাতার একই আকার ([[PurchaseApiController::principal()]])।
 * ⛔ কেবল public_id; দেখার শাখার গ্রাহক; বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলার ([[ScopedToUserDealers]]) — অন্যের দোকান ৪০৪।
 */
final class CustomerLedgerApiController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:customer.view')];
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $customer = Customer::query()->inViewedBranch()->where('public_id', $id)->firstOrFail();

        $ledger = ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
            ->forParty(Customer::drillSourceType(), $customer->id)
            ->orderBy('trx_date')
            ->orderBy('id');

        // ⓘ মাথার বকেয়া — খাতার সারির ঠিক সেই ছাঁকনিতে, যাতে শেষ সারির জের আর উপরের অঙ্ক মেলে
        $due = (string) ((clone $ledger)->reorder()->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net') ?? '0');

        $asked = max(1, (int) PhoneInput::text($request, 'page', '1'));
        $query = array_diff_key($request->query(), ['page' => true]);

        $entries = PartyLedger::page(clone $ledger, $request->duplicate($query), openAtEnd: true);
        $last = $entries->lastPage();
        if ($asked > 1) {
            $entries = $asked > $last ? null
                : PartyLedger::page(clone $ledger, $request->duplicate($query), page: $last - $asked + 1);
        }

        return response()->json([
            'id' => (string) $customer->public_id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name(),
            // ⓘ ধনাত্মক = গ্রাহকের কাছে পাওনা (Dr), ঋণাত্মক = গ্রাহকের জমা (Cr) — খাতার নিয়ম ([[Money::drCr()]])
            'due' => Money::round($due, 4),
            'entries' => collect($entries?->items() ?? [])->reverse()->values()->map(fn (LedgerEntry $e) => [
                'date' => $e->trx_date?->toDateString(),
                'no' => (string) ($e->document_no ?? ''),
                'narration' => (string) ($e->narration ?? ''),
                'debit' => Money::round((string) $e->debit, 4),
                'credit' => Money::round((string) $e->credit, 4),
                'balance' => Money::round((string) $e->net_balance, 4),
            ]),
            // ⓘ আরও পুরনো থাকলে তবেই — খাতার আগের পাতা
            'next_page' => $entries !== null && $entries->currentPage() > 1 ? $asked + 1 : null,
        ]);
    }
}
