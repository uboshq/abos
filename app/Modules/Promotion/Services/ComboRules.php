<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Promotion\Models\ComboItem;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Support\PromotionStatus;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Validation\ValidationException;

/**
 * কম্বো ও বান্ডলের উপাদান বসানো — *"কোন পণ্য, অন্তত কতটা"*।
 *
 * ── ⛔ [[PromotionRules]]-এর নিয়মই: বদলানো যায় কেবল খসড়ায় ─────────
 * ⓘ অনুমোদনকারী সই করেছিলেন *"ক + খ + গ"*-এ। ⚠️ পরে একটা উপাদান সরানো
 * গেলে অফারটা *"ক + খ"* হয়ে যেত — সহজে খোলে, একই ছাড় — আর কাগজে সইটা
 * থেকেই যেত।
 *
 * ── ⚠️ কেন আলাদা ফাইল, [[PromotionRules]]-এর ভিতরে নয় ────────────────
 * ⓘ আজ ঐ ফাইলে অন্য কাজ চলছে, আর একই গাছে কয়েকটা সেশন। ⭐ জোড়ার সময়
 * `PromotionController` এই সেবাটা ডাকবে; নিয়মগুলো এক জায়গায়ই থাকবে।
 */
final class ComboRules
{
    /**
     * ⭐ একটা উপাদান — একই পণ্য আবার দিলে পরিমাণটা বদলায়, দ্বিতীয় সারি হয় না।
     */
    public function addItem(Promotion $offer, int $productId, string $minQty): ComboItem
    {
        $this->assertComboDraft($offer);

        /*
         * ⛔ শূন্য বা ঋণাত্মক ন্যূনতম।
         *
         * ⚠️ শূন্য মানে *"এই পণ্য না কিনলেও চলে"* — উপাদানটা তালিকায় থাকত
         * অথচ কিছুই চাইত না, আর ইঞ্জিনে শূন্য দিয়ে ভাগ হত।
         */
        if (! is_numeric($minQty) || bccomp($minQty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'min_qty' => __('promotion::combo.min_qty_positive'),
            ]);
        }

        /* ⓘ কোম্পানির ছাঁকনি মডেলেই — অন্য কোম্পানির পণ্য এখানে `null` */
        $product = Product::query()->find($productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => __('promotion::combo.product_missing'),
            ]);
        }

        return ComboItem::query()->updateOrCreate(
            ['promotion_id' => $offer->id, 'product_id' => $product->id],
            ['min_qty' => $minQty],
        );
    }

    public function removeItem(Promotion $offer, ComboItem $item): void
    {
        $this->assertComboDraft($offer);

        /* ⛔ অন্য অফারের উপাদান — ঠিকানা ধরে ধরে পরের কম্বো ভাঙা যেত */
        if ((int) $item->promotion_id !== (int) $offer->id) {
            abort(404);
        }

        $item->delete();
    }

    /**
     * ⭐ জমা দেওয়ার আগে: কম্বোটা আদৌ কম্বো কি না।
     *
     * ── ⛔ অন্তত **দুইটা** উপাদান ──────────────────────────────────
     * ⓘ একটা উপাদানের কম্বো আসলে এক পণ্যের অফার — সারির ইঞ্জিনের কাজ। ⚠️
     * শূন্য উপাদানের কম্বো কখনো খোলে না, অথচ তালিকায় *"সক্রিয়"* দেখাত —
     * ঠিক সেই নীরব আকার যা [[PromotionType::isBuilt()]] আটকাতে চায়।
     *
     * ── ⛔ পণ্যের দিকের সুযোগ-সারি নয় ──────────────────────────────
     * ⓘ কম্বোর পণ্য বলে উপাদান; *"শ্রেণি: সাবান"* সারি থাকলে
     * [[BillPromotionEngine]] অফারটা খোলে না। ⚠️ এখানে না থামালে অফারটা
     * অনুমোদন পেত আর কোনোদিন কাজ করত না।
     *
     * ⓘ ডাকবে [[PromotionLifecycle::submit()]] — জোড়ার প্রস্তাব দেখুন।
     */
    public function assertReadyToSubmit(Promotion $offer): void
    {
        if (! BillPromotionEngine::isBillType($offer->type)) {
            return;
        }

        if (ComboItem::query()->where('promotion_id', $offer->id)->count() < 2) {
            throw ValidationException::withMessages([
                'combo' => __('promotion::combo.needs_two_items'),
            ]);
        }

        $goodsScope = $offer->scopes()->get()
            ->contains(fn ($s) => $s->kind instanceof ScopeKind && $s->kind->isAboutTheGoods());

        if ($goodsScope) {
            throw ValidationException::withMessages([
                'scope' => __('promotion::combo.no_goods_scope'),
            ]);
        }
    }

    private function assertComboDraft(Promotion $offer): void
    {
        if (! BillPromotionEngine::isBillType($offer->type)) {
            throw ValidationException::withMessages([
                'type' => __('promotion::combo.not_a_combo'),
            ]);
        }

        if ($offer->status !== PromotionStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('promotion::validation.rules_frozen', ['status' => $offer->status->label()]),
            ]);
        }
    }
}
