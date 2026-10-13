<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Promotion\Models\PromotionApplication;
use App\Modules\Promotion\Services\GiftIssuer;
use App\Modules\Promotion\Support\BenefitKind;
use App\Modules\Promotion\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * উপহার দেওয়ার পর্দা — স্পেক §৮ *"Gift Issue"*।
 *
 * ── ⚠️ কেন এতদিন ছিল না, আর কী ক্ষতি হচ্ছিল ────────────────────────
 * ⓘ [[GiftIssuer]] মজুদ কমাত, লট চাইত, খরচ জমাত — কিন্তু কেউ তাকে
 * ডাকতে পারতেন না, কেবল পরীক্ষা ডাকত। ⛔ ফলে বিলে *"৫ কার্টন ফ্রি"*
 * লেখা হত, আর গুদাম থেকে মাল বেরোনোর কোনো পথ ছিল না — ঠিক সেই
 * "কাজটা আছে, জোড়াটা নেই" আকারের ভুল।
 *
 * ⭐ নিজের চাবি — `promotion.gift`। ⓘ উপহার বের করা গুদামের কাজ; যিনি
 * অফার বসান (`apply`) তিনি মাল বের করেন না।
 */
final class PromotionGiftController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly GiftIssuer $issuer,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.gift')];
    }

    /**
     * ⭐ যে উপহারগুলো এখনো পুরো দেওয়া হয়নি।
     *
     * ⚠️ বাতিল বিলের উপহার এখানে নেই। ⓘ থাকলে গুদামের লোক দেখতেন
     * *"দিতে হবে"*, দিয়েও দিতেন — আর মালটা এমন বিলে যেত যেটা আর নেই।
     */
    public function index(Request $request): View
    {
        /*
         * ⛔ শাখার দেয়াল — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (প্রমোশন ২১; [[AGiftIsIssuedOnlyInsideMyBranchTest]])। ⓘ আগে সব শাখার বাকি
         * উপহার এখানে আসত। কাগজের শাখা ধরে, বাকি কাগজের একই নিয়মে ([[DataScope::inView()]] — "সব শাখা"-তে শাখাহীন সারিও)।
         */
        $rows = app(DataScope::class)->inView(PromotionApplication::query(), 'promotion_applications.branch_id')
            ->with(['promotion', 'benefit.giftProduct'])
            ->withSum('gifts as issued_qty', 'qty')
            ->where('benefit_kind', BenefitKind::GOODS->value)
            ->whereNull('reversed_at')

            /*
             * ⓘ পুরো দেওয়া হয়ে গেলে তালিকা থেকে সরে যায়। ⚠️ ছাঁকনিটা
             * SQL-এ, পাতায় নয় — পাতায় ছাঁকলে পঞ্চাশের পাতায় তিনটা সারি
             * দেখাত আর বাকি পাতাগুলো খালি।
             *
             * ⓘ সাব-কোয়েরিটা এই সারির `id` ধরে বাঁধা, আর বাইরের প্রশ্নটা
             * কোম্পানি দিয়ে ছাঁকা — তাই অন্য কোম্পানির উপহার এখানে আসে না।
             */
            ->whereRaw('benefit_amount > coalesce((select sum(g.qty) from promotion_gift_issues g '
                .'where g.promotion_application_id = promotion_applications.id), 0)')
            ->orderBy('id')
            ->paginate(50);

        /*
         * ⓘ একটা পাতার সব উপহার-পণ্যের লট একবারে — সারি ধরে ধরে নয়।
         * ⚠️ সারি ধরে টানলে পঞ্চাশটা সারিতে পঞ্চাশটা প্রশ্ন।
         */
        $productIds = $rows->getCollection()
            ->map(fn (PromotionApplication $a) => $a->benefit?->gift_product_id)
            ->filter()
            ->unique()
            ->values();

        return view('promotion::gifts', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'lots' => Batch::query()->whereIn('product_id', $productIds)->orderBy('expiry_date')->get()->groupBy('product_id'),
        ]);
    }

    public function store(Request $request, PromotionApplication $application): RedirectResponse
    {
        // ⛔ নাগালের বাইরের শাখার কাগজের উপহার নয় — ঠিকানা বসিয়েও না (প্রমোশন ২১)
        abort_unless(app(DataScope::class)->allows($request->user(), UserDataScope::BRANCH,
            $application->branch_id === null ? null : (int) $application->branch_id), 403);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'qty' => ['required', 'numeric', Decimal::RULE, 'gt:0'],
            'serials_text' => ['nullable', 'string', 'max:40000'],
        ]);

        /*
         * ⚠️ গুদাম আর লট **এই কোম্পানির** মধ্যেই খোঁজা — `BelongsToCompany`
         * আপনাআপনি ছাঁকে, তাই অন্য কোম্পানির আইডি পাঠালে ৪০৪, মাল নয়।
         */
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $batch = isset($data['batch_id']) ? Batch::query()->findOrFail($data['batch_id']) : null;

        /*
         * ⓘ কোন পণ্য — অফারের ধাপ যা বলে, ফর্ম যা পাঠায় তা নয়।
         *
         * ⛔ `product_id`-তে ফিরে যাওয়া নয়: ওটা **কেনা** পণ্য। ⚠️ ধাপে
         * উপহার-পণ্য না থাকলে ওটা ধরে নিলে ক্রেতা যা কিনেছেন সেটাই আবার
         * গুদাম থেকে কমত, আর কেউ টের পেতেন না।
         */
        $product = $application->benefit?->giftProduct;

        if ($product === null) {
            throw ValidationException::withMessages([
                'qty' => __('promotion::validation.gift_needs_product'),
            ]);
        }

        $issue = $this->issuer->issue($application, $product, $warehouse, (string) $data['qty'], $batch, serials: preg_split('/\s+/', trim((string) ($data['serials_text'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return back()->with('saved', __('promotion::message.gift_issued', ['code' => $issue->code]));
    }
}
