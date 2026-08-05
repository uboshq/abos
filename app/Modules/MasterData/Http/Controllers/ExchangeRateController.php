<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Services\ExchangeRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * একটা মুদ্রার হারের ইতিহাস।
 *
 * ── কেন এটার নিজের পর্দা ─────────────────────────────────────────────
 * বাকি মাস্টারগুলোয় এক সারি = এক রেকর্ড, তাই একটাই সাধারণ ফর্মে চলে।
 * মুদ্রা আলাদা: তার হার একটা নয়, তারিখে-তারিখে অনেকগুলো। সাধারণ
 * ফর্মে একটা "হার" ঘর বসালে নতুন হার বসানোর সাথে সাথে আগেরটা মুছে
 * যেত — আর গত মাসের বিলটা আজ অন্য টাকায় দেখাত।
 */
class ExchangeRateController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:master_data.view', only: ['index']),
            new Middleware('can:master_data.manage', only: ['store']),
        ];
    }

    public function index(Request $request, int $id): View
    {
        $currency = $this->currency($id);

        return view('master_data::currency.rates', [
            'menu' => $this->menu->forUser($request->user()),
            'currency' => $currency,
            'base' => $this->rates->baseCurrency(),
            'rates' => $currency->rates()->with('creator')->get(),
            'today' => $currency->rateOn(),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $currency = $this->currency($id);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $this->rates->record(
            $currency,
            $data['effective_from'],
            (string) $data['rate'],
            $data['source'] ?? null,
        );

        return back()->with('saved', __('master_data::message.rate_saved'));
    }

    /**
     * মুদ্রা বন্ধ থাকলে হারের পর্দাও নেই।
     *
     * তালিকার পর্দাটা বন্ধ অথচ হারের পর্দা খোলা থাকলে সুইচটা অর্ধেক
     * কাজ করত — আর ঠিক ওই অর্ধেকটা মনে রাখতে হত।
     */
    private function currency(int $id): Currency
    {
        abort_unless((bool) $this->settings->get('master_data.multi_currency_enabled'), 404);

        return Currency::query()->findOrFail($id);
    }
}
