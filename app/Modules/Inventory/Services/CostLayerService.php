<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * মালের দাম রাখা ও টানা — FIFO, মালিকের সিদ্ধান্ত অনুযায়ী।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * খরচের দর আসত পণ্য-মাস্টার থেকে, আর মাল খতিয়ানে ঢুকত চালানের দরে।
 * চালিয়ে দেখা গেছে ১,০০০ টাকার মালের ৪০% বেচে মজুদ খাত থেকে ১৩,৬০০
 * বেরিয়ে গেছে। বিস্তারিত: docs/Finding — Inventory is valued two
 * different ways.md
 *
 * ── এখানে কী নিশ্চিত করা হয় ─────────────────────────────────────────
 * যে টাকায় মাল ঢুকেছে, ঠিক সেই টাকাই বেরোয় — এক পয়সা বেশিও নয়, কমও
 * নয়। তাই মজুদ খাত আর গুদামের মাল কখনো আলাদা হয় না।
 */
final class CostLayerService
{
    /**
     * মাল ঢুকল — একটা নতুন স্তর।
     *
     * দর অবশ্যই দিতে হবে, আর সেটা ইচ্ছাকৃত: দর ছাড়া মাল ঢোকানোর মানে
     * হত কোনো একটা দর ধরে নেওয়া, আর ধরে নেওয়া দরই তো এতদিনের সমস্যা।
     * বিনামূল্যের মাল হলে দর শূন্য — কিন্তু সেটা তখন লেখা থাকে, অনুমান
     * করা হয় না।
     */
    public function receive(
        Product $product,
        string $qty,
        string $unitCost,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
    ): CostLayer {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('A cost layer needs a positive quantity.');
        }

        if (bccomp($unitCost, '0', 4) < 0) {
            throw new RuntimeException('A cost layer cannot carry a negative unit cost.');
        }

        return CostLayer::create([
            'company_id' => $this->companyId(),
            'product_id' => $product->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'document_no' => $documentNo,
            'trx_date' => $this->date($date),
            'qty_in' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $unitCost,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * মাল বেরোল — পুরনো স্তর থেকে, যতগুলো লাগে।
     *
     * ── কেন লক ─────────────────────────────────────────────────────
     * দুইজন একই মুহূর্তে একই পণ্য বেচলে দুইজনেই একই স্তরে সব মাল দেখত,
     * আর দুইজনেই সেখান থেকেই টানত — স্তরটা ঋণাত্মক হয়ে যেত। স্টকের
     * তাকেও একই সমস্যা, একই সমাধান (StockService::move)।
     *
     * @return array{cost: string, uses: list<CostLayerUse>}
     */
    public function issue(
        Product $product,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
    ): array {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('Issuing stock needs a positive quantity.');
        }

        return DB::transaction(function () use ($product, $qty, $sourceType, $sourceId, $documentNo, $date) {
            $layers = CostLayer::query()
                ->where('product_id', $product->id)
                ->open()
                ->lockForUpdate()
                ->get();

            $remaining = $qty;
            $cost = '0';
            $uses = [];

            foreach ($layers as $layer) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                $take = bccomp((string) $layer->qty_remaining, $remaining, 4) >= 0
                    ? $remaining
                    : (string) $layer->qty_remaining;

                $amount = bcmul($take, (string) $layer->unit_cost, 4);

                $uses[] = CostLayerUse::create([
                    'company_id' => $this->companyId(),
                    'cost_layer_id' => $layer->id,
                    'product_id' => $product->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_no' => $documentNo,
                    'trx_date' => $this->date($date),
                    'qty' => $take,
                    'unit_cost' => $layer->unit_cost,
                    'amount' => $amount,
                    'created_by' => auth()->id(),
                ]);

                $layer->qty_remaining = bcsub((string) $layer->qty_remaining, $take, 4);
                $layer->save();

                $cost = bcadd($cost, $amount, 4);
                $remaining = bcsub($remaining, $take, 4);
            }

            /*
             * স্তরে যত মাল আছে তার বেশি বেরোতে পারে না।
             *
             * তাকে মাল আছে অথচ স্তরে নেই — এমন হয় কেবল যদি কোনো পথ
             * স্তর না বানিয়ে স্টক বাড়িয়ে থাকে। তখন থামা ছাড়া উপায়
             * নেই: একটা দর ধরে নিয়ে এগোলে ঠিক সেই ভুলটাই ফিরে আসত
             * যেটা সারাতে এই ক্লাসটা লেখা।
             */
            if (bccomp($remaining, '0', 4) > 0) {
                throw ValidationException::withMessages([
                    'product_id' => __('inventory::validation.no_cost_layer', [
                        'product' => $product->name(),
                        'qty' => rtrim(rtrim($remaining, '0'), '.'),
                    ]),
                ]);
            }

            return ['cost' => $cost, 'uses' => $uses];
        });
    }

    /**
     * ফেরত এল — যে দামে বেরিয়েছিল সেই দামেই ফেরে।
     *
     * ── কেন আজকের দর নয় ────────────────────────────────────────────
     * গ্রাহক গত মাসের মাল ফেরত দিলে সেটা গত মাসের দামের মাল। আজকের
     * দরে ফিরিয়ে নিলে দাম বাড়লে মুনাফা তৈরি হত শুধু ফেরত নেওয়ার
     * কারণে — কেউ কিছু বেচেনি, তবু খাতায় লাভ বসত।
     *
     * তাই মূল টানগুলো ধরে ধরে ফেরানো হয়: যে স্তর থেকে যতটা গিয়েছিল,
     * সেখানেই ততটা ফেরে।
     *
     * ── আর ফেরার ক্রম উল্টো, ইচ্ছাকৃতভাবে ──────────────────────────
     * একটা বিক্রয়ে যদি ১০ বস্তা পুরনো চালান থেকে আর ৫ বস্তা নতুন চালান
     * থেকে গিয়ে থাকে, আর ৩ বস্তা ফেরত আসে, তবে সেগুলো নতুন চালানেই
     * ফেরে — শেষে যেটা বেরিয়েছে, আগে সেটাই।
     *
     * পুরনোটায় ফেরালে নিঃশেষ হয়ে যাওয়া সস্তা স্তরটা আবার জ্যান্ত হয়ে
     * উঠত, আর FIFO নিয়ম মেনে পরের বিক্রয় ওখান থেকেই টানত — তাকে
     * থাকত নতুন দামের মাল, খাতায় বসত পুরনো দাম। ধরা পড়েছে ইঞ্জিনটা
     * চালিয়ে: ১২০ টাকার ৩ বস্তা ফেরত এসে ৩০০ টাকা হয়ে গিয়েছিল।
     *
     * @return string ফেরত আসা মালের মোট মূল্য
     */
    public function returnToLayers(
        Product $product,
        string $qty,
        string $issuedSourceType,
        int $issuedSourceId,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
    ): string {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('Returning stock needs a positive quantity.');
        }

        return DB::transaction(function () use (
            $product, $qty, $issuedSourceType, $issuedSourceId, $sourceType, $sourceId, $documentNo, $date
        ) {
            // মূল নথিটা যে স্তরগুলো থেকে টেনেছিল — টানার উল্টো ক্রমে
            $uses = CostLayerUse::query()
                ->where('product_id', $product->id)
                ->where('source_type', $issuedSourceType)
                ->where('source_id', $issuedSourceId)
                ->where('qty', '>', 0)
                ->orderByDesc('id')
                ->get();

            $remaining = $qty;
            $value = '0';

            foreach ($uses as $use) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                /*
                 * এই টান থেকে আগে কতটা ফেরত এসেছে তা বাদ দিতে হয় —
                 * নইলে একই চালান দুইবার ফেরত দিলে দুইবারই পুরো মাল
                 * ফিরত, আর গুদামে না থাকা মাল খাতায় জমা হত।
                 */
                $alreadyBack = (string) (CostLayerUse::query()
                    ->where('cost_layer_id', $use->cost_layer_id)
                    ->where('product_id', $product->id)
                    ->where('source_type', $sourceType)
                    ->whereRaw('qty < 0')
                    ->sum('qty') ?: '0');

                $available = bcadd((string) $use->qty, $alreadyBack, 4);

                if (bccomp($available, '0', 4) <= 0) {
                    continue;
                }

                $take = bccomp($available, $remaining, 4) >= 0 ? $remaining : $available;
                $amount = bcmul($take, (string) $use->unit_cost, 4);

                CostLayerUse::create([
                    'company_id' => $this->companyId(),
                    'cost_layer_id' => $use->cost_layer_id,
                    'product_id' => $product->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_no' => $documentNo,
                    'trx_date' => $this->date($date),

                    // ঋণাত্মক টান — অর্থাৎ ফেরত। উল্টো সারি, মোছা নয়।
                    'qty' => bcmul($take, '-1', 4),
                    'unit_cost' => $use->unit_cost,
                    'amount' => bcmul($amount, '-1', 4),
                    'created_by' => auth()->id(),
                ]);

                $layer = CostLayer::query()->lockForUpdate()->find($use->cost_layer_id);
                $layer->qty_remaining = bcadd((string) $layer->qty_remaining, $take, 4);
                $layer->save();

                $value = bcadd($value, $amount, 4);
                $remaining = bcsub($remaining, $take, 4);
            }

            /*
             * মূল নথির চেয়ে বেশি ফেরত — এটা ঠেকানোর জায়গা এখানে নয়,
             * ফেরতের সার্ভিসে (সেখানে লাইন ধরে ধরে পরিমাণ মেলানো হয়)।
             * এখানে পৌঁছে গেলে থামতেই হবে, কারণ ফেরত আসা মালের কোনো
             * দাম নেই — আর দাম ধরে নেওয়াই এই পুরো ফাইলটার শত্রু।
             */
            if (bccomp($remaining, '0', 4) > 0) {
                throw ValidationException::withMessages([
                    'product_id' => __('inventory::validation.return_exceeds_issue', [
                        'product' => $product->name(),
                    ]),
                ]);
            }

            return $value;
        });
    }

    /**
     * নথি বাতিল — তার আনা স্তরগুলো তুলে নেওয়া।
     *
     * ── কেন ছোঁয়া হয়ে গেলে আর তোলা যায় না ──────────────────────────
     * ওই স্তরের মাল যদি ইতিমধ্যে বিক্রি হয়ে থাকে, তবে সেই বিক্রয়ের
     * খরচ ওই দামেই বসে গেছে। এখন স্তরটা তুলে নিলে খরচটা এমন মালের
     * থাকত যা কখনো আসেইনি, আর গত মাসের মুনাফা আজ বদলে যেত।
     *
     * FIFO-তে পুরনো স্তর আগে খরচ হয়, তাই সচরাচর সদ্য আসা চালানের
     * স্তরে কেউ হাত দেয়নি — বাতিল করা যায়। ছোঁয়া হয়ে গেলে সৎ পথ
     * বাতিল নয়, ক্রয় ফেরত।
     *
     * @return int কতগুলো স্তর তোলা হলো
     */
    public function withdraw(string $sourceType, int $sourceId): int
    {
        return DB::transaction(function () use ($sourceType, $sourceId) {
            $layers = CostLayer::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if (bccomp((string) $layer->qty_remaining, (string) $layer->qty_in, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'status' => __('inventory::validation.layer_already_used', [
                            'document' => $layer->document_no ?? (string) $layer->id,
                        ]),
                    ]);
                }
            }

            foreach ($layers as $layer) {
                $layer->delete();
            }

            return $layers->count();
        });
    }

    /** এই পণ্যের যত মাল স্তরে পড়ে আছে, তার মোট মূল্য। */
    public function valueOnHand(Product $product): string
    {
        $rows = CostLayer::query()
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->get(['qty_remaining', 'unit_cost']);

        return $rows->reduce(
            fn (string $sum, CostLayer $l) => bcadd($sum, bcmul((string) $l->qty_remaining, (string) $l->unit_cost, 4), 4),
            '0',
        );
    }

    /** স্তরে এখনো কতটা মাল আছে — টানার আগে দেখে নেওয়ার জন্য। */
    public function qtyOnHand(Product $product): string
    {
        return (string) (CostLayer::query()
            ->where('product_id', $product->id)
            ->sum('qty_remaining') ?: '0');
    }

    private function companyId(): int
    {
        $id = CompanyContext::id();

        if ($id === null) {
            throw new RuntimeException('Cannot touch cost layers without a company in context.');
        }

        return $id;
    }

    private function date(Carbon|string|null $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse($date ?? now()))->toDateString();
    }
}
