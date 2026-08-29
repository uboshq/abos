<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মূলধন ও বিনিয়োগ — কে ব্যবসায় টাকা দিলেন, আর কে কোথায় দাঁড়িয়ে।
 *
 * ── কেন এই পর্দাটা ───────────────────────────────────────────────────
 * মালিক ব্যবসার পথটা ক্রমে বললেন, আর প্রথম ধাপেই ABOS-এ কিছু ছিল না।
 * খাত ছিল, ভাউচার ছিল — পর্দা ছিল না, তাই ব্যবসার প্রথম কাজটা হত একটা
 * হাতে লেখা জাবেদা।
 */
class CapitalController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CapitalService $capital,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.capital.view', only: ['index']),
            new Middleware('can:finance.capital.create', only: ['store']),
            new Middleware('can:finance.capital.post', only: ['post']),
        ];
    }

    public function index(Request $request): View
    {
        return view('finance::capital.index', [
            'menu' => $this->menu->forUser($request->user()),
            'entries' => CapitalEntry::query()->with('account')
                ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50),
            'positions' => $this->capital->positions(),

            /*
             * টাকা যেখানে আসতে পারে — নগদ, ব্যাংক, টিল।
             *
             * গোটা ছক দিলে কেউ "বিক্রয়" খাতে মূলধন বসিয়ে দিতে পারতেন,
             * আর সেটা সারানোর একমাত্র উপায় হত একটা বিপরীত এন্ট্রি।
             */
            /*
             * নগদ ও ব্যাংকের নিচের খাতগুলো — আদায়ের পর্দা যেভাবে বাছে।
             *
             * ── কেন `is_cash` পতাকা দিয়ে নয় ─────────────────────────
             * প্রথমে ওটাই লেখা হয়েছিল, আর তালিকা খালি এল: বসানো ছকে
             * পতাকাটা কেউ তোলে না। মাথার নিচে খোঁজাটা ছকের গড়ন ধরে
             * চলে, আর ওই গড়নটা `StandardChart` নিজেই বসায়।
             */
            'accounts' => Account::query()
                ->where('is_group', false)
                ->whereIn('parent_id', Account::query()
                    ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
                ->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contributor_name' => ['required', 'string', 'max:191'],
            'contributor_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::WHO)],
            'entry_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::KINDS)],
            'trx_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $this->capital->record($data);

        return redirect()->route('finance.capital.index')
            ->with('saved', __('finance::message.capital_recorded', ['no' => $entry->document_no]));
    }

    /**
     * টাকাটা এসেছে — খাতায় বসাও।
     *
     * কোন খাতে এসেছে সেটা এখানেই জিজ্ঞেস করা হয়, লেখার সময় নয়:
     * তখনো জানা ছিল না।
     */
    public function post(Request $request, CapitalEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'received_into_account_id' => ['required', 'integer',
                'exists:accounts,id'],
        ]);

        $this->capital->post($entry, Account::query()->findOrFail($data['received_into_account_id']));

        return back()->with('saved', __('finance::message.capital_posted', ['no' => $entry->document_no]));
    }
}
