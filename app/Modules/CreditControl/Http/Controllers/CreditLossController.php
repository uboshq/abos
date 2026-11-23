<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\CreditControl\Services\ReceivableCreditLoss;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ পাওনার সম্ভাব্য ক্ষতি (IFRS 9) আর অনাদায়ী বাদ — এক পাতা: বয়সের ভাগ × হার = লক্ষ্য, ভাতায় যা আছে, তফাতের খসড়া; নিচে
 * একজন গ্রাহকের অনাদায়ী বাদের খসড়া (হিসাবের ফাঁক #১৮; [[ReceivableCreditLoss]])।
 *
 * <p>⛔ দুটো বোতামই কেবল খসড়া ভাউচার লেখে আর সেই ভাউচারের পাতায় নিয়ে যায় — পাকা হয় ভাউচারের নিজের পথে। চাবি: দেখা আর লেখা
 * `credit_control.credit_loss`, আর লেখায় ভাউচার লেখার চাবিও (`accounts.voucher.create`)।
 */
final class CreditLossController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu, private readonly ReceivableCreditLoss $loss) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:credit_control.credit_loss'),
            new Middleware('can:accounts.voucher.create', only: ['provision', 'writeOff']),
        ];
    }

    public function show(Request $request): View
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = $data['as_of'] ?? now()->toDateString();

        return view('credit_control::loss', [
            'menu' => $this->menu->forUser($request->user()),
            'position' => $this->loss->position($asOf),
            'customers' => Customer::query()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn']),
        ]);
    }

    public function provision(Request $request): RedirectResponse
    {
        $data = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']]);
        $voucher = $this->loss->draftProvision($data['as_of']);

        // ⭐ সংরক্ষণের জাবেদা সোজা অনুমোদনে (মালিক, ২১ অক্টোবর ২০২৬) — ছক না থাকলে খসড়া, অন্য হাতে পাকা
        $approval = $this->loss->sendForApproval($voucher);

        return redirect()->route('accounts.voucher.show', $voucher)->with('warning', $approval !== null
            ? __('credit_control::loss.sent_for_approval', ['no' => $voucher->document_no])
            : __('credit_control::loss.drafted'));
    }

    public function writeOff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
            'as_of' => ['required', 'date_format:Y-m-d'],
        ]);
        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $voucher = $this->loss->draftWriteOff($customer, (string) $data['amount'], (string) $data['reason'], $data['as_of']);

        // ⓘ 'warning' — ভাউচারের পাতা কেবল saved/warning দেখায়; খসড়া "হয়ে গেছে" নয়, তাই সবুজ নয়
        return redirect()->route('accounts.voucher.show', $voucher)->with('warning', __('credit_control::loss.drafted'));
    }
}
