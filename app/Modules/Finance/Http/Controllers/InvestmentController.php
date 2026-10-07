<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\InvestmentReturns;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * বিনিয়োগের রিটার্ন — মানচিত্র §১২। কার টাকা কত আয় করল।
 *
 * ⓘ কেবল পড়ার পর্দা; কোনো দাখিলা বসায় না। হিসাব [[InvestmentReturns]]-এ।
 */
class InvestmentController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly InvestmentReturns $returns,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.capital.view', only: ['returns'])];
    }

    public function returns(Request $request): View
    {
        /*
         * ⓘ ডিফল্টে চলতি অর্থবছর নয়, চলতি বছরের শুরু থেকে আজ — মালিক
         * "এ বছর আমার টাকা কত আনল" প্রশ্নটাই আগে করেন, আর অর্থবছরের
         * হিসাব রিপোর্টে আছে।
         */
        $from = $this->date($request->query('from'), now()->startOfYear());
        $to = $this->date($request->query('to'), now());

        return view('finance::investment.returns', [
            'menu' => $this->menu->forUser($request->user()),
            'report' => $this->returns->forPeriod($from, $to),
        ]);
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // ⓘ ঠিকানায় আবোলতাবোল তারিখ এলে পাতা ভাঙে না, ডিফল্টে ফেরে
            return $fallback;
        }
    }
}
