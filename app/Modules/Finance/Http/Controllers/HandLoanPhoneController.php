<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ হাতধারের টাকা দেওয়া-নেওয়া, ফোনের নিজের ফর্মে (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
 *
 *   GET  /api/v1/finance/hand-loans/money-accounts      কোন খাত থেকে/খাতে — নগদ, ব্যাংক, MFS
 *   POST /api/v1/finance/hand-loans/{id}/move           `direction` out|in, `amount`, `moved_on`, `money_account`, …
 *
 * ⛔ ওয়েবের একই সেবা ([[HandLoanService::move()]]): দেওয়া = প্রদান ভাউচার Dr ১১৭০ হাতধার (মানুষের নামে) / Cr টাকার খাত, নেওয়া =
 * গ্রহণ ভাউচার Dr টাকার খাত / Cr ১১৭০; তারপর অর্থের সই ([[FinanceSignature::postOrHold()]]) — ছক থাকলে ভাউচার খসড়া, শেষ সইয়ে
 * নিজেই বসে, ফেরালে বাতিল। চাবি ওয়েবের (`finance.hand_loan.move`); নিরীক্ষা মডেলের নিজের। ⭐ একবারই — [[RemembersAPhoneWrite]]।
 */
final class HandLoanPhoneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:finance.hand_loan.move')];
    }

    public function moneyAccounts(): JsonResponse
    {
        return response()->json(['rows' => Account::query()->whereIn('money_kind', Account::MONEY_KINDS)->active()
            ->where('is_group', false)->orderBy('code')->get()
            ->map(fn (Account $a) => ['id' => (string) $a->public_id, 'name' => $a->label(), 'kind' => (string) $a->money_kind])->values()]);
    }

    public function move(Request $request, HandLoanService $loans, string $id): JsonResponse
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $loan = HandLoanAccount::query()->where('public_id', $id)->firstOrFail();

        $data = $request->validate([
            'direction' => ['required', 'string', 'in:'.implode(',', HandLoanMovement::DIRECTIONS)],
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/', 'gt:0'],
            'moved_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'return_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:moved_on'],
            'money_account' => ['required', 'string', 'max:64'],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $account = Account::query()->where('public_id', $data['money_account'])->where('company_id', CompanyContext::id())->value('id');

        if ($account === null) {
            throw ValidationException::withMessages(['money_account' => __('finance::validation.phone_unknown_reference')]);
        }

        $movement = $loans->move($loan, [
            'direction' => $data['direction'],
            'amount' => (string) $data['amount'],
            'moved_on' => $data['moved_on'],
            'return_on' => $data['return_on'] ?? null,
            'money_account_id' => (int) $account,
            'instrument_no' => $data['instrument_no'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        // ⓘ সইয়ের অপেক্ষায় থাকলে সেটাই বলা — ওয়েবের একই কথা
        $held = (bool) $movement->voucher?->isDraft();

        return response()->json([
            'status' => $held ? 'held' : 'done',
            'id' => (string) $movement->public_id,
            'voucher' => $movement->voucher?->document_no,
            'notice' => $held ? __('finance::message.awaiting_signature') : __('finance::message.hand_loan_moved'),
        ], $held ? 202 : 201);
    }
}
