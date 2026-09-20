<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\CarrierAndLabourLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * পরিবহন ও শ্রমিকের খতিয়ান — মানচিত্র §৬। শিরোনাম · বর্ণনা → পরিবহন /
 * শ্রমিক ট্যাব → পক্ষ ধরে বাকি → একটা পক্ষ বাছলে তার সারি, চলমান বাকিসহ।
 *
 * ⓘ কেবল দেখা: ভাড়া জমে চালানে, টাকা যায় পরিশোধ ভাউচারে। অনুমতি খরচের
 * পাতার (`finance.expense.view`) — ভাড়া আর মজুরি দুইটাই খরচ।
 */
class CarrierAndLabourController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.expense.view')];
    }

    public function index(Request $request, CarrierAndLabourLedger $ledger): View
    {
        $tab = array_key_exists((string) $request->query('tab'), CarrierAndLabourLedger::HEADS)
            ? (string) $request->query('tab') : 'transport';

        $today = Carbon::today();
        $from = $this->date($request->query('from')) ?? $today->copy()->startOfMonth()->toDateString();
        $to = $this->date($request->query('to')) ?? $today->toDateString();

        $head = $ledger->head($tab);
        $party = $request->has('party') ? (string) $request->query('party') : null;

        return view('finance::carrier-labour.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'from' => $from,
            'to' => $to,
            'head' => $head,
            'parties' => $head ? $ledger->parties($head, $from, $to) : collect(),
            'party' => $party,
            'statement' => $head && $party !== null ? $ledger->statement($head, $party, $from, $to) : null,
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
