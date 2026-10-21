<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Services\HeldWages;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ দেওয়ার অপেক্ষায় বকেয়া মজুরি — যেকোনো দিন, পরের মাসের রানের অপেক্ষা নয় (fe, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩; [[HeldWages]])।
 *
 * ⓘ তালিকা (`hr.payroll.view`); দেওয়া আর কাগজ আবার নামানো (`hr.payroll.manage` + `hr.identity.view` — কাগজে হিসাব নম্বর, ব্যাংক
 * ফাইলের একই দুই চাবি)।
 */
class HeldWageController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly HeldWages $held,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:hr.payroll.view', only: ['index']),
            new Middleware(['can:hr.payroll.manage', 'can:hr.identity.view'], only: ['pay', 'paper']),
        ];
    }

    public function index(Request $request): View
    {
        return view('hr::payroll.held', [
            'menu' => $this->menu->forUser($request->user()),
            'ready' => $this->held->ready($request->user()),
            'accounts' => [
                'bank' => Account::query()->money()->where('money_kind', Account::BANK)->where('is_group', false)->orderBy('code')->get(),
                'mfs' => Account::query()->money()->where('money_kind', Account::MFS)->where('is_group', false)->orderBy('code')->get(),
            ],
            'papers' => HeldWagePayment::query()->whereNotNull('voucher_id')->with('voucher')->latest('id')->get()
                ->unique('voucher_id')->take(20)->values(),
        ]);
    }

    /** ⭐ দেওয়া — খসড়া পরিশোধ ভাউচার আর দেওয়ার কাগজ, এক চাপে; একই স্লিপ দ্বিতীয়বার নয় */
    public function pay(Request $request, string $channel): Response
    {
        $data = $request->validate([
            'payslips' => ['required', 'array', 'min:1'],
            'payslips.*' => ['integer'],
            'account_id' => ['required', 'integer'],
        ]);

        // ⓘ পথ আর খাত মেলে আগে — ব্যাংকের তালিকা থেকে MFS খাতে নয় (দেওয়ার আগে, কিছু লেখার আগে)
        $kind = Account::query()->whereKey((int) $data['account_id'])->value('money_kind');
        abort_unless($kind === ($channel === 'mfs' ? Account::MFS : Account::BANK), 422);

        return $this->download($this->held->pay(array_map('intval', $data['payslips']), (int) $data['account_id'], $request->user()));
    }

    /** ⓘ একই ভাউচারের কাগজ আবার — নতুন কিছু দেওয়া হয় না */
    public function paper(Voucher $voucher): Response
    {
        abort_unless(HeldWagePayment::query()->where('voucher_id', $voucher->id)->exists(), 404);

        return $this->download($this->held->paper($voucher));
    }

    /** @param array{name: string, content: string} $paper */
    private function download(array $paper): Response
    {
        return response("\u{FEFF}".$paper['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$paper['name'].'"',
        ]);
    }
}
