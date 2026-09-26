<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Services\BudgetKeeper;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * অফারের ছাদ বসানোর দরজা — স্পেক §১৫।
 *
 * ── ⚠️ কেন এতদিন ছিল না, আর কী ক্ষতি হচ্ছিল ────────────────────────
 * ⓘ [[BudgetGuard]] প্রতিটা বিলে ছাদ মাপত, কিন্তু ছাদ বসানোর কোনো
 * পর্দা ছিল না — কেবল পরীক্ষা সারি বসাত। ⛔ ফলে বাস্তবে প্রতিটা অফার
 * সীমাহীন চলত, অথচ কোডে *"বাজেট পাহারা"* লেখা ছিল।
 *
 * ⭐ নিজের চাবি — `promotion.budget`। ⓘ ছাদ বাড়ানো মানে আরও টাকা দেওয়ার
 * সিদ্ধান্ত; অফার বানানোর চাবি (`update`) তার সমান নয়।
 */
final class PromotionBudgetController extends Controller implements HasMiddleware
{
    public function __construct(private readonly BudgetKeeper $keeper) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.budget')];
    }

    public function __invoke(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(BudgetKeeper::KINDS)],
            'per' => ['nullable', Rule::in(PromotionBudget::WINDOWS)],
            'ceiling' => ['required', 'numeric', Decimal::RULE, 'gt:0', 'max:999999999999'],
            'warn_at_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $this->keeper->set(
            $promotion,
            $data['kind'],
            (string) $data['ceiling'],
            (int) ($data['warn_at_percent'] ?? 80),
            $data['per'] ?? PromotionBudget::PER_OFFER,
        );

        return back()->with('saved', __('promotion::message.budget_saved', ['code' => $promotion->code]));
    }
}
