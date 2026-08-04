<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\FinancialYear;
use App\Modules\Accounts\Services\YearEndService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * বছর সমাপনী।
 *
 * বছরে একবার খোলা হয়, আর কাজটা ফেরানো যায় না — তাই পর্দাটা অন্য
 * স্ক্রিনের চেয়ে বেশি কথা বলে: কী ঘটবে তা আগে দেখায়, তারপর একবার
 * জিজ্ঞেস করে।
 *
 * চূড়ান্ত হিসাবের অনুমতি লাগে (accounts.report.final), শুধু ভাউচার
 * লেখার অনুমতি নয়: বছর বন্ধ করা মানে প্রতিষ্ঠানের বছরের ফল চূড়ান্ত
 * করে দেওয়া, আর সেটা হিসাবরক্ষকের রোজকার কাজ নয়।
 */
class YearEndController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly YearEndService $yearEnd,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report.final')];
    }

    public function index(Request $request): View
    {
        $current = FinancialYear::query()->where('is_current', true)->first();

        return view('accounts::year-end.index', [
            'menu' => $this->menu->forUser($request->user()),
            'year' => $current,
            // চলতি বছর না থাকলে দেখানোর কিছুই নেই, আর preview() ডাকলে
            // ব্যতিক্রম পড়ত
            'preview' => $current !== null ? $this->yearEnd->preview($current) : null,
            'years' => FinancialYear::query()->orderByDesc('starts_on')->get(),
        ]);
    }

    public function close(Request $request, FinancialYear $year): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:32'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],

            /*
             * নামটা হাতে লিখে নিশ্চিত করা।
             *
             * সাধারণ confirm() যথেষ্ট নয়: মানুষ নিশ্চিতকরণের বাক্স না
             * পড়েই "হ্যাঁ" চাপে। বছরের নামটা লিখতে বললে অন্তত একবার
             * চোখ বুলাতে হয় — আর এই কাজটা ফেরানো যায় না।
             */
            'confirm' => ['required', 'string'],
        ]);

        if (trim($data['confirm']) !== $year->name) {
            return back()
                ->withInput()
                ->withErrors(['confirm' => __('accounts::validation.year_confirm_name', ['name' => $year->name])]);
        }

        $newYear = $this->yearEnd->close($year, [
            'name' => $data['name'] ?? null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ]);

        return redirect()
            ->route('accounts.year_end.index')
            ->with('saved', __('accounts::message.year_closed', [
                'closed' => $year->name,
                'opened' => $newYear->name,
            ]));
    }
}
