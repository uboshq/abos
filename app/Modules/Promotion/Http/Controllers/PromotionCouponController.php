<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionCoupon;
use App\Modules\Promotion\Services\CouponDesk;
use App\Modules\Promotion\Support\Decimal;
use App\Modules\Promotion\Support\PromotionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * কুপনের পর্দা — স্পেক §৭-ঞ: কোড তৈরি, কোডের তালিকা, আর কাউন্টারের খাটানো।
 *
 * ── ⚠️ কেন এতদিন ছিল না, আর কী ক্ষতি হচ্ছিল ────────────────────────
 * ⓘ [[CouponDesk]] কোড বানাত, গুনত, তালা দিত — কিন্তু কেবল পরীক্ষা তাকে
 * ডাকত। ⛔ অফারের ধরন *"কুপন"* বাছা যেত, অনুমোদন হত, চালুও হত — অথচ
 * একটাও কোড তৈরির পথ ছিল না, তাই অফারটা কোনোদিন কোনো বিলে বসত না।
 * ঠিক সেই "কাজটা আছে, জোড়াটা নেই" আকারের ভুল।
 *
 * ── ⭐ দুইটা চাবি, দুইটা কাজ ───────────────────────────────────────
 * ⓘ `promotion.coupon` — কোড তৈরি ও তালিকা দেখা। কোড মানে টাকা: একটা
 * ৫০০-কোডের ব্যাচ মানে ৫০০টা ছাড়ের প্রতিশ্রুতি।
 * ⓘ `promotion.apply` — কাউন্টারে কোড খাটানো, বিক্রয়কর্মীর রোজকার চাবি।
 * ⛔ এক চাবি হলে যিনি কোড খাটান তিনি নিজের জন্য কোড বানাতেও পারতেন।
 */
final class PromotionCouponController extends Controller implements HasMiddleware
{
    /**
     * ⛔ কোন কাগজে কুপন খাটে — হাতে লেখা তালিকা, খোলা লেখা নয়।
     *
     * ⚠️ এই মডিউল বিক্রয়কে চেনে না (`depends_on`-এ `sales` নেই — চক্র
     * হত), তাই মডেলের ক্লাস এখানে আনা যায় না, কেবল নামগুলো। ⓘ খোলা
     * রাখলে কেউ `source_type`-এ যা খুশি লিখে এমন "বিলে" কুপন খাটাতেন
     * যেটা কোনো তালিকায় কোনোদিন আসত না — আর ব্যবহারটা গোনা হয়ে যেত।
     */
    public const SOURCES = ['sales_invoice', 'sales_order'];

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CouponDesk $coupons,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:promotion.coupon', only: ['index', 'store']),

            /* ⓘ কাউন্টারের দরজা — `suggest`-এর মতোই `apply` চাবি */
            new Middleware('can:promotion.apply', only: ['redeem']),
        ];
    }

    /**
     * ⭐ কুপনের তালিকা — একটা অফারের, নাহলে সবগুলোর।
     *
     * ⓘ `?offer=` দিলে সেই অফারের কোড আর তৈরির ফর্ম; না দিলে সব অফারের
     * কোড একসাথে, যাতে মেনু থেকেও পাতাটা খোলা যায়।
     *
     * ⚠️ কোড খোঁজা (`q`) SQL-এ, পাতায় নয় — পাতায় ছাঁকলে পঞ্চাশের পাতায়
     * দুইটা সারি দেখাত আর বাকি পাতাগুলো খালি।
     */
    public function index(Request $request): View
    {
        /* ⓘ অন্য কোম্পানির অফার [[BelongsToCompany]]-এ অদৃশ্য — তাই ৪০৪, তালিকা নয় */
        $offer = $request->filled('offer')
            ? Promotion::query()->findOrFail((int) $request->query('offer'))
            : null;

        $search = PromotionCoupon::normalise((string) $request->query('q', ''));

        $rows = PromotionCoupon::query()
            ->with(['promotion', 'customer'])
            ->when($offer !== null, fn ($q) => $q->where('promotion_id', $offer->id))
            ->when($search !== '', fn ($q) => $q->where('code', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('promotion::coupons', [
            'menu' => $this->menu->forUser($request->user()),
            'offer' => $offer,
            'rows' => $rows,
            'search' => $search,

            /*
             * ⚠️ ফর্মটা কেবল তখন, যখন তৈরি সত্যিই সম্ভব।
             * ⓘ কুপন-ধরন নয় বা অফার শেষ — ফর্ম দেখিয়ে তারপর *"হবে না"*
             * বলার চেয়ে আগেই কারণটা দেখানো ভালো।
             */
            'canIssue' => $offer !== null
                && $offer->type === PromotionType::COUPON
                && ! $offer->status->isFinal(),
            'maxBatch' => CouponDesk::MAX_BATCH,
        ]);
    }

    /**
     * ⭐ কোড তৈরি — হয় একটা লেখা কোড, নয় সিরিজ থেকে কয়েকটা।
     *
     * ⓘ ফর্মের পোস্ট, তাই `validate()`-এর রিডাইরেক্টই ঠিক উত্তর। ⚠️ আসল
     * নিয়মগুলো (কোডের আকার, মেয়াদ অফারের ভিতরে, ক্রেতা-প্রতি সীমা)
     * [[CouponDesk::issue()]]-এ — এখানে আবার লেখা নয়, নাহলে দুই জায়গায়
     * দুই নিয়ম হত।
     */
    public function store(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'count' => ['nullable', 'integer', 'min:1', 'max:'.CouponDesk::MAX_BATCH],
            'max_uses' => ['required', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
        ]);

        /*
         * ⛔ শেষ বা বাতিল অফারে নতুন কোড নয়।
         *
         * ⓘ [[CouponDesk]] নিজে অবস্থা দেখে না (খসড়ায় কোড আগেভাগে বানানো
         * বৈধ)। ⚠️ কিন্তু বাতিল অফারের কোড ছাপা হয়ে ক্রেতার হাতে গেলে
         * কাউন্টারে শুনতেন *"খাটে না"* — ক্ষতিটা দোকানের সুনামে।
         */
        if ($promotion->status->isFinal()) {
            throw ValidationException::withMessages([
                'promotion' => __('promotion::coupon_screen.offer_final', ['code' => $promotion->code]),
            ]);
        }

        $code = filled($data['code'] ?? null) ? (string) $data['code'] : null;

        $made = $this->coupons->issue(
            $promotion,
            (int) ($data['count'] ?? 1),
            $code,
            [
                'max_uses' => (int) $data['max_uses'],
                'max_uses_per_customer' => $data['max_uses_per_customer'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
            ],
        );

        return redirect()
            ->route('promotion.coupon.index', ['offer' => $promotion->id])
            ->with('saved', $made->count() === 1
                ? __('promotion::coupon_screen.made_one', ['code' => $made->first()->code])
                : __('promotion::coupon_screen.made_many', ['count' => $made->count()]));
    }

    /**
     * ⭐ কাউন্টারে কোড খাটানো — JSON উত্তর।
     *
     * ── ⚠️ যাচাই নিজে হাতে, আর ডেস্কের প্রত্যাখ্যানও ধরে ৪২২ ──────────
     * ⓘ এই অ্যাপে JSON ভুল আপনাআপনি আসে কেবল `api/*` পথে। ⛔ বাকি সবখানে
     * `validate()` বা [[CouponDesk]]-এর `ValidationException` মানে ৩০২ —
     * fetch রিডাইরেক্ট মেনে একটা ২০০ HTML পায়, আর কাউন্টার নীরবে ধরে নেয়
     * *"কুপন বসেছে"*। ⭐ তাই দুইটাই এখানে ধরা হয়, পথ যেটাই হোক।
     */
    public function redeem(Request $request): JsonResponse
    {
        $check = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:60'],
            'source_type' => ['required', 'string', Rule::in(self::SOURCES)],
            'source_id' => ['required', 'integer', 'min:1'],
            'source_line_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', Decimal::RULE, 'gt:0'],
            'value' => ['required', 'numeric', Decimal::RULE, 'gte:0'],
            'customer_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($check->fails()) {
            return $this->refusal($check->errors()->toArray());
        }

        $data = $check->validated();

        /*
         * ⛔ অন্য কোম্পানির পণ্য বা ক্রেতা — Eloquent দিয়ে খোঁজা।
         * ⓘ কাঁচা `exists` নিয়ম কোম্পানির ছাঁকনি মানে না; [[BelongsToCompany]]
         * মানে, আর তখন `null` আসে।
         */
        $product = Product::query()->find($data['product_id']);

        if ($product === null) {
            return $this->refusal(['product_id' => [__('promotion::validation.product_not_yours')]]);
        }

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;

        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            return $this->refusal(['customer_id' => [__('promotion::coupon.customer_unknown')]]);
        }

        /* ⓘ সারিটা [[PromotionSuggestController]]-এর মতোই — শ্রেণি ও ব্র্যান্ডসহ, নাহলে সুযোগের ছাঁকনি মিলত না */
        $line = [
            'product_id' => $product->id,
            'category_id' => $product->category_id ?? null,
            'brand_id' => $product->brand_id ?? null,
            'customer_id' => $customerId,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'qty' => (string) $data['qty'],
            'value' => (string) $data['value'],
        ];

        try {
            $applied = $this->coupons->redeem(
                (string) $data['code'],
                $line,
                (string) $data['source_type'],
                (int) $data['source_id'],
                $customerId,
                isset($data['source_line_id']) ? (int) $data['source_line_id'] : null,
            );
        } catch (ValidationException $e) {
            /* ⚠️ ডেস্কের *"না"* একটা উত্তর — ৪২২ JSON, রিডাইরেক্ট নয় */
            return $this->refusal($e->errors());
        }

        $coupon = PromotionCoupon::query()
            ->where('code', PromotionCoupon::normalise((string) $data['code']))
            ->first();

        return response()->json([
            'data' => [
                'code' => $coupon?->code,
                'promotion' => $applied->promotion?->code,
                'name' => $applied->promotion?->name(),
                'benefit' => $applied->benefit_kind?->label(),
                'amount' => (string) $applied->benefit_amount,
                'worth' => (string) $applied->worth,
                'uses_left' => $coupon?->usesLeft(),
            ],
        ]);
    }

    /**
     * ⓘ প্রত্যাখ্যানের একটাই আকার — `message` (প্রথম কারণ) আর `errors`
     * (ঘর ধরে), Laravel-এর নিজের ৪২২-এর মতো, যাতে পর্দার এক কোডই দুইটা পড়ে।
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function refusal(array $errors): JsonResponse
    {
        $first = collect($errors)->flatten()->first();

        return response()->json([
            'message' => $first ?? __('promotion::coupon_screen.refused'),
            'errors' => $errors,
        ], 422);
    }
}
