<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDecision;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBudget;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Services\BudgetGuard;
use App\Modules\Promotion\Services\PromotionApprovalChain;
use App\Modules\Promotion\Services\BudgetKeeper;
use App\Modules\Promotion\Services\PromotionLifecycle;
use App\Modules\Promotion\Services\PromotionRules;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\ConditionKind;
use App\Modules\Promotion\Support\PromotionCombines;
use App\Modules\Promotion\Support\ScopeKind;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Validation\Rule;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * অফারের তালিকা — স্পেকের ৫ নম্বর ধারা।
 *
 * ── ⚠️ কেন এই পর্দাটা আগে, তৈরির পর্দাটা পরে ────────────────────────
 * ⓘ এখানে নিয়ম: যে রুট নেই তার মেনু সারি ঘোষণা করা যায় না। ⛔ তাই
 * মডিউলটা বুট করাতে হলে অন্তত একটা সত্যিকারের পর্দা লাগে।
 *
 * ⭐ আর তালিকাটাই প্রথম হওয়া উচিত: ⓘ তৈরির পর্দা আগে বানালে মানুষ অফার
 * বানাতে পারতেন কিন্তু **দেখতে পেতেন না** — আর তখন একই অফার দুইবার
 * বানানো হত, কেউ টের না পেয়ে।
 */
final class PromotionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly PromotionLifecycle $life,
        private readonly PromotionRules $rules,
        private readonly BudgetGuard $budgets,
        private readonly PromotionApprovalChain $chain,
    ) {}

    /*
     * ⭐ প্রতিটা দরজা নিজের চাবির পিছনে — স্পেক §১৯।
     *
     * ⓘ একজন সহকর্মী ধরেছিলেন: তেরোটা চাবি ঘোষিত, অথচ `view` ছাড়া
     * একটাও কোথাও যাচাই হত না। ⛔ ঘোষিত অথচ অব্যবহৃত চাবি আর ঘোষিত অথচ
     * অচল অফার-ধরন একই আকারের জিনিস — দেখতে সম্পূর্ণ, কাজে শূন্য।
     *
     * ⚠️ `approve` আর `activate` আলাদা চাবি, আর তফাতটা দামি: ⓘ যিনি সই
     * দেন আর যিনি চালু করেন তাঁরা এক মানুষ না হতেও পারেন।
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:promotion.view', only: ['index', 'show']),
            new Middleware('can:promotion.create', only: ['create', 'store']),

            /* ⓘ নিয়ম বসানো = অফার বদলানো — `update`, আর কেবল খসড়ায় (সেবা থামায়) */
            new Middleware('can:promotion.update', only: ['addStep', 'removeStep', 'addScope', 'removeScope']),
            new Middleware('can:promotion.submit', only: ['submit', 'withdraw']),
            new Middleware('can:promotion.approve', only: ['approve', 'sendBack']),
            new Middleware('can:promotion.activate', only: ['activate']),
            new Middleware('can:promotion.pause', only: ['pause']),
            new Middleware('can:promotion.cancel', only: ['cancel']),
            new Middleware('can:promotion.update', only: ['reschedule']),
        ];
    }

    /**
     * ⭐ একটা অফারের পাতা — নিয়ম, অবস্থা, আর যা করা যায়।
     *
     * ⓘ স্পেক §৫-এর *"View"*। ⚠️ এই পাতা না থাকলে অফার বানানো যেত,
     * কিন্তু শর্ত-সুবিধা বসানো যেত না — অর্থাৎ *"১০০ কিনলে ৫ ফ্রি"*
     * লেখার কোনো জায়গাই ছিল না।
     */
    public function show(Request $request, Promotion $promotion): View
    {
        /*
         * ⓘ ইনবক্স থেকে সই পড়লেও অফারের অবস্থা এখানে মিলিয়ে নেওয়া — ইঞ্জিন
         * শেষ সিদ্ধান্তে কাউকে খবর দেয় না, তাই পাতা খুললেই হিসাব মেলে।
         */
        $promotion = $this->chain->settle($promotion);
        $promotion->load(['conditions.benefits.giftProduct', 'scopes', 'creator', 'approver']);

        return view('promotion::show', [
            'menu' => $this->menu->forUser($request->user()),
            'offer' => $promotion,
            'conditionKinds' => [ConditionKind::QUANTITY, ConditionKind::VALUE],
            'benefitKinds' => [BenefitKind::PERCENT, BenefitKind::AMOUNT, BenefitKind::GOODS],
            'scopeKinds' => ScopeKind::cases(),

            /* ⓘ ছাদ আর খরচ — পাহারার একই হিসাব থেকে, আলাদা করে গোনা নয় */
            'budgets' => $this->budgets->usage($promotion),
            'budgetKinds' => BudgetKeeper::KINDS,
            'budgetWindows' => PromotionBudget::WINDOWS,
            'budgetOpen' => in_array($promotion->status, BudgetKeeper::OPEN, true),
            'reschedulable' => in_array($promotion->status, PromotionLifecycle::RESCHEDULABLE, true),

            /* ⓘ অনুমোদনের স্তরগুলো — কে সই দিলেন, কে বাকি, আর ফেরত পাঠালে কেন */
            'approvalPath' => $this->chain->progress($promotion),
            'canSign' => $this->chain->canSign($promotion, $request->user()),
            'sentBackBecause' => $promotion->status === PromotionStatus::DRAFT ? $this->chain->lastReason($promotion) : null,
        ]);
    }

    public function addStep(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'condition_kind' => ['required', 'in:quantity,value'],
            'value_from' => ['nullable', 'numeric', Decimal::RULE, 'gte:0'],
            'value_to' => ['nullable', 'numeric', Decimal::RULE, 'gte:0'],
            'benefit_kind' => ['required', 'in:percent,amount,goods'],

            /*
             * ⚠️ শতাংশ ১০০-র বেশি নয়।
             *
             * ⓘ ১৫০% ছাড় মানে বিলের চেয়ে বেশি ফেরত — ক্রেতাকে টাকা দিয়ে
             * মাল বিক্রি। ⛔ কেউ ইচ্ছা করে এটা চাইবেন না; এটা সবসময় একটা
             * আঙুলের ভুল, আর এক বিলেই গোটা দিনের লাভ যেত।
             */
            'amount' => ['required', 'numeric', Decimal::RULE, 'gt:0',
                Rule::when($request->input('benefit_kind') === 'percent', ['max:100'])],

            'gift_product_id' => ['nullable', 'integer'],
            'cap_per_bill' => ['nullable', 'numeric', Decimal::RULE, 'gt:0'],
        ]);

        $this->rules->addStep($promotion, $data);

        return back()->with('saved', __('promotion::message.step_added'));
    }

    public function removeStep(Promotion $promotion, PromotionCondition $step): RedirectResponse
    {
        $this->rules->removeStep($promotion, $step);

        return back()->with('saved', __('promotion::message.step_removed'));
    }

    public function addScope(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn ($k) => $k->value, ScopeKind::cases()))],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);

        $this->rules->addScope($promotion, ScopeKind::from($data['kind']), (int) $data['target_id']);

        return back()->with('saved', __('promotion::message.scope_added'));
    }

    public function removeScope(Promotion $promotion, PromotionScope $scope): RedirectResponse
    {
        $this->rules->removeScope($promotion, $scope);

        return back()->with('saved', __('promotion::message.scope_removed'));
    }

    public function create(Request $request): View
    {
        return view('promotion::create', [
            'menu' => $this->menu->forUser($request->user()),
            'types' => PromotionType::built(),
            'combines' => PromotionCombines::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],

            /*
             * ⓘ বাংলা নাম ঐচ্ছিক — এই বাড়ির নিয়ম, প্রতিটা মাস্টারে।
             *
             * ⚠️ প্রথম খসড়ায় এখানে `required` ছিল, আর টেবিলে `NOT NULL`।
             * ⛔ দুইটাই বাড়ির নিয়মের উল্টো: বাস্তবে বাংলা নাম প্রায়ই খালি
             * (*"bKash"*-এর বাংলা নামও *"bKash"*), আর
             * [[TheFormSaidOptionalTheColumnSaidRequiredTest]] প্রতিটা
             * `name_bn`-কে `nullable` চায়। ⓘ খালি থাকলে [[Promotion::name()]]
             * ইংরেজি নাম দেখায়।
             */
            'name_bn' => ['nullable', 'string', 'max:255'],

            'summary' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:32'],
            'combines' => ['nullable', 'string', 'in:best,adds,alone'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $offer = $this->life->draft($data);

        return redirect()->route('promotion.index')
            ->with('saved', __('promotion::message.drafted', ['code' => $offer->code]));
    }

    public function submit(Promotion $promotion): RedirectResponse
    {
        $this->chain->submit($promotion);

        return back()->with('saved', __('promotion::message.moved', ['code' => $promotion->code]));
    }

    /**
     * ⭐ সই — চলতি স্তরে। ছক না থাকলে আজকের একক অনুমোদন, হুবহু।
     */
    public function approve(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->chain->sign($promotion, $request->user(), $data['note'] ?? null);

        return back()->with('saved', __('promotion::approval.signed', ['code' => $promotion->code]));
    }

    /** ⛔ ফেরত পাঠানো — কারণ বাধ্যতামূলক; অফার খসড়ায় ফেরে */
    public function sendBack(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'reason_code' => ['nullable', Rule::in(ApprovalDecision::REASONS)],
        ]);

        $this->chain->sendBack($promotion, $request->user(), $data['reason'], $data['reason_code'] ?? null);

        return back()->with('saved', __('promotion::approval.sent_back', ['code' => $promotion->code]));
    }

    /** ⓘ নির্মাতা জমা ফিরিয়ে নেন — খোলা অনুরোধটাও বন্ধ হয় */
    public function withdraw(Request $request, Promotion $promotion): RedirectResponse
    {
        $this->chain->withdraw($promotion, $request->user());

        return back()->with('saved', __('promotion::approval.withdrawn_message', ['code' => $promotion->code]));
    }

    public function activate(Promotion $promotion): RedirectResponse
    {
        $promotion = $this->chain->settle($promotion);
        $this->life->activate($promotion);

        return back()->with('saved', __('promotion::message.moved', ['code' => $promotion->code]));
    }

    public function pause(Promotion $promotion): RedirectResponse
    {
        $this->life->pause($promotion);

        return back()->with('saved', __('promotion::message.moved', ['code' => $promotion->code]));
    }

    public function cancel(Promotion $promotion): RedirectResponse
    {
        $this->life->cancel($promotion);

        return back()->with('saved', __('promotion::message.moved', ['code' => $promotion->code]));
    }

    public function reschedule(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'ends_on' => ['required', 'date'],
            'ends_at' => ['nullable', 'date_format:H:i'],
        ]);

        $this->life->reschedule($promotion, $data['ends_on'], $data['ends_at'] ?? null);

        return back()->with('saved', __('promotion::message.rescheduled', ['code' => $promotion->code]));
    }

    public function index(Request $request): View
    {
        /*
         * ⚠️ পাতায় ভাগ করা **বাধ্যতামূলক**, আর সেটা মেপে শেখা।
         *
         * ⓘ `get()` লিখলে পর্দাটা গোটা টেবিলটা টেনে আনত। ⛔ প্রথম বছরে
         * ওটা দ্রুতই লাগে — পঞ্চাশটা অফার। ⚠️ তিন বছরে হাজারটা হলে
         * পাতাটা ধীরে ধীরে মরে, আর কোনোদিন কিছু ভাঙে না বলে কেউ খোঁজেও না।
         *
         * ⓘ পাহারাটা `EveryListScreenPaginates`-এ।
         */
        $rows = Promotion::query()
            ->with('creator')
            ->when($request->string('status')->toString() !== '',
                fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('type')->toString() !== '',
                fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->string('q')->toString() !== '', function ($q) use ($request) {
                $needle = '%'.$request->string('q')->toString().'%';

                /*
                 * ⓘ তিনটা ঘরেই খোঁজা: কোড, ইংরেজি নাম, বাংলা নাম।
                 * ⚠️ কেবল চলতি ভাষার নামে খুঁজলে বাংলায় দাঁড়িয়ে ইংরেজি
                 * নামে বানানো অফারটা খুঁজে পাওয়া যেত না।
                 */
                $q->where(fn ($w) => $w->where('code', 'like', $needle)
                    ->orWhere('name_en', 'like', $needle)
                    ->orWhere('name_bn', 'like', $needle));
            })
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('promotion::index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'statuses' => PromotionStatus::cases(),

            /*
             * ⭐ ছাঁকনিতে কেবল **যেগুলো সত্যিই চলে**।
             *
             * ⓘ বারোটা ধরন ঘোষিত, কিন্তু ইঞ্জিন আজ তিনটা চেনে। ⛔ বাকি
             * ন'টা ছাঁকনিতে দেখালে মানুষ বেছে দেখতেন তালিকা খালি, আর
             * ভাবতেন কিছু একটা ভেঙেছে — অথচ ভাঙেনি, ওটা এখনো তৈরিই হয়নি।
             */
            'types' => PromotionType::built(),
        ]);
    }
}
