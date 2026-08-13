<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Sales\Models\CounterShift;
use App\Modules\Sales\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * কাউন্টারের শিফট — খোলা, বন্ধ, আর Z-রিপোর্ট।
 *
 * ── কেন কাউন্টারের অনুমতিতেই ─────────────────────────────────────────
 * যিনি বেচেন তিনিই শিফট খোলেন ও বন্ধ করেন। আলাদা অনুমতির পেছনে রাখলে
 * প্রতিদিন সকালে ক্যাশিয়ারকে মালিকের জন্য অপেক্ষা করতে হত, আর তার
 * ফল হত শিফট না খুলেই বেচা শুরু করা — অর্থাৎ পুরো ব্যবস্থাটা বাদ।
 */
class ShiftController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShiftService $shifts) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.pos')];
    }

    public function index(Request $request): View
    {
        $mine = $this->shifts->openFor((int) $request->user()->id);

        return view('sales::shift.index', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'shift' => $mine,
            'figures' => $mine === null ? null : $this->shifts->figures($mine),

            /*
             * বাছার তালিকায় কেবল সেই ড্রয়ারগুলো যেগুলোয় এখন কেউ বসেনি।
             *
             * দখলে থাকা ড্রয়ার তালিকায় রাখলে ক্যাশিয়ার ওটা বেছে
             * "খুলুন" চাপতেন, আর একটা ভুলের বার্তা পেতেন — অথচ
             * তালিকাটাই আগে থেকে জানত।
             */
            'tills' => CashTill::query()
                ->active()
                ->whereNotIn('id', CounterShift::query()->open()->pluck('cash_till_id'))
                ->orderBy('code')
                ->get(),

            // আজকের বন্ধ হওয়া শিফটগুলো — মালিকের চোখে দিনের ছবি
            'closed' => CounterShift::query()
                ->whereDate('opened_at', now()->toDateString())
                ->where('status', CounterShift::CLOSED)
                ->with(['till', 'user'])
                ->orderByDesc('closed_at')
                ->get(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cash_till_id' => ['required', 'integer',
                Rule::exists((new CashTill)->getTable(), 'id')->where('company_id', CompanyContext::id())],
            'opening_counted' => ['required', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $till = CashTill::query()->findOrFail($data['cash_till_id']);

        $this->shifts->open($till, (string) $data['opening_counted'], $data['narration'] ?? null);

        return redirect()
            ->route('sales.shift.index')
            ->with('saved', __('sales::message.shift_opened', ['till' => $till->name()]));
    }

    public function close(Request $request, CounterShift $shift): RedirectResponse
    {
        /*
         * নিজের শিফটই বন্ধ করা যায়।
         *
         * অন্যের ড্রয়ার বন্ধ করতে পারলে গোনা সংখ্যাটা এমন কেউ বসাতেন
         * যিনি টাকাটা গোনেনইনি, আর দায়টা কার তা আবার ঘোলা হয়ে যেত।
         */
        abort_unless((int) $shift->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'closing_counted' => ['required', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $closed = $this->shifts->close($shift, (string) $data['closing_counted'], $data['narration'] ?? null);

        return redirect()
            ->route('sales.shift.show', ['shift' => $closed->id])
            ->with('saved', __('sales::message.shift_closed'));
    }

    /** Z-রিপোর্ট — বন্ধ হওয়া শিফটের হিসাব, ছাপার মতো করে। */
    public function show(Request $request, CounterShift $shift): View
    {
        /*
         * নিজের শিফট, নাহলে টিল দেখার অনুমতি।
         *
         * ক্যাশিয়ার নিজেরটা দেখবেন; মালিক বা হিসাবরক্ষক সবারটা। দুইটা
         * এক করলে হয় ক্যাশিয়ার নিজের হিসাবই দেখতে পেতেন না, নয়তো সবাই
         * সবার ড্রয়ারের ঘাটতি দেখত।
         */
        abort_unless(
            (int) $shift->user_id === (int) $request->user()->id
                || $request->user()->can('accounts.till.view'),
            403,
        );

        return view('sales::shift.show', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'shift' => $shift->load(['till', 'user']),
            'figures' => $this->shifts->figures($shift),
        ]);
    }
}
