<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\MoneyTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * টাকা হস্তান্তরের স্ক্রিন।
 *
 * তালিকার উপরে "আপনার গ্রহণের অপেক্ষায়" আলাদা করে দেখানো হয়: একজন
 * ডেলিভারি ম্যান দিনে একবারই এই পর্দায় আসে, আর তার একটাই কাজ — যেটা
 * তার নামে পাঠানো হয়েছে সেটা গ্রহণ করা। সেটা তালিকার মাঝখানে খুঁজতে
 * হলে অর্ধেক দিন কেউ গ্রহণ করে না, আর টাকা কার হিসাবে তা অস্পষ্ট থাকে।
 */
class MoneyTransferController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly MoneyTransferService $transfers,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:accounts.transfer.create', only: ['index', 'show', 'create', 'store']),
            new Middleware('can:accounts.transfer.confirm', only: ['confirm']),
            new Middleware('can:accounts.transfer.create', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $query = MoneyTransfer::query()
            ->search($request->query('q'))
            ->with(['fromTill', 'toTill', 'toAccount', 'giver', 'receiver']);

        $sort = $this->applySort($query, $request, $this->sorts());

        $transfers = $query->paginate(50)->withQueryString();

        return view('accounts::transfer.index', [
            'menu' => $this->menu->forUser($request->user()),
            'transfers' => $transfers,
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
            // যেগুলো এই ব্যবহারকারীর গ্রহণের অপেক্ষায়
            'awaiting' => MoneyTransfer::query()
                ->awaiting((int) $request->user()->id)
                ->with(['fromTill', 'giver'])
                ->orderBy('trx_date')
                ->get(),
            'q' => $request->query('q'),
        ]);
    }

    /**
     * নতুন আগে; টাকার অঙ্ক দিয়েও সাজানো যায়।
     *
     * বড় অঙ্কের হস্তান্তরগুলোই সবচেয়ে বেশি দেখা হয় — কারণ ওখানেই
     * ভুল হলে সবচেয়ে বেশি টাকা আটকে যায়।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'latest' => fn ($q) => $q->orderByDesc('trx_date')->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('trx_date')->orderBy('id'),
            'amount' => fn ($q) => $q->orderByDesc('amount'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('accounts::sort.latest'),
            'oldest' => __('accounts::sort.oldest'),
            'amount' => __('accounts::sort.amount'),
        ];
    }

    public function create(Request $request): View
    {
        return view('accounts::transfer.form', [
            'menu' => $this->menu->forUser($request->user()),
            'transfer' => new MoneyTransfer(['trx_date' => now()]),
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'from_till_id' => ['required', 'integer'],
            // একটাই ঘর, দুই ধরনের গন্তব্য — "till:3" বা "account:12"
            'destination' => ['required', 'string', 'regex:/^(till|account):\d+$/'],
            'given_by' => ['nullable', 'integer', 'exists:users,id'],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $transfer = $this->transfers->initiate(
            $validated + $this->splitDestination($validated['destination']),
        );

        return redirect()
            ->route('accounts.transfer.show', $transfer)
            ->with('saved', __('accounts::message.transfer_started', ['no' => $transfer->document_no]));
    }

    public function show(Request $request, MoneyTransfer $transfer): View
    {
        $transfer->load(['fromTill', 'toTill', 'toAccount', 'giver', 'receiver', 'creator', 'confirmer', 'branch']);

        return view('accounts::transfer.show', [
            'menu' => $this->menu->forUser($request->user()),
            'transfer' => $transfer,
        ]);
    }

    /** গ্রহণ নিশ্চিত — এখনই টাকাটা হাত বদলায়। */
    public function confirm(Request $request, MoneyTransfer $transfer): RedirectResponse
    {
        $this->transfers->confirm($transfer, (int) $request->user()->id);

        return back()->with('saved', __('accounts::message.transfer_received', ['no' => $transfer->document_no]));
    }

    public function cancel(Request $request, MoneyTransfer $transfer): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->transfers->cancel($transfer, $validated['cancel_reason']);

        return back()->with('saved', __('accounts::message.transfer_cancelled', ['no' => $transfer->document_no]));
    }

    /**
     * ফর্মের একটা ঘর থেকে দুইটা কলাম।
     *
     * গন্তব্য হয় আরেকটা কাউন্টার, নয় একটা ব্যাংক খাত — কিন্তু ফর্মে
     * একটাই তালিকা, কারণ ব্যবহারকারীর কাছে দুইটাই "টাকাটা কোথায় গেল"।
     * আগে ধরন বাছতে বললে সেটা একটা অতিরিক্ত ধাপ হত, আর দিনশেষে
     * ব্যাংকে জমা দেওয়ার সময় সেই ধাপটা রোজ পড়ত।
     *
     * @return array<string, int|null>
     */
    private function splitDestination(string $destination): array
    {
        [$kind, $id] = explode(':', $destination, 2);

        return $kind === 'till'
            ? ['to_till_id' => (int) $id, 'to_account_id' => null]
            : ['to_till_id' => null, 'to_account_id' => (int) $id];
    }

    /** @return array<string, Collection<int, mixed>> */
    private function options(): array
    {
        return [
            'tills' => CashTill::query()->active()->with('account')->orderByDesc('is_primary')->orderBy('code')->get(),
            // ব্যাংকে জমা দেওয়াটাও হস্তান্তর, আর সেটাই দিনশেষে সবচেয়ে
            // বেশি হয় — তাই ব্যাংকের খাতগুলোও গন্তব্যের তালিকায়
            'bankAccounts' => Account::query()->where('is_bank', true)->postable()->active()->orderBy('code')->get(),
            'people' => User::query()
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ];
    }
}
