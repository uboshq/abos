<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    /**
     * ⭐ স্তরে যতটুকু আছে FIFO-তে, বাকিটুকু কেনা দামে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (বিক্রয় ⓘ১২; [[FreeGoodsTakeTheirCostFromTheLayersFirstTest]])।
     *
     * ⓘ ফ্রি মাল আর উপহার ([[DirectSaleService::giveFromStock()]], [[GiftIssuer::bookTheCost()]]) আগে স্তরে পুরোটা না কুলালে পুরো
     * পরিমাণ কেনা দামে ধরত, স্তর না ছুঁয়ে — যেটুকু স্তরে ছিল তাও FIFO-র বাইরে, আর সেই সস্তা বা দামি স্তর পরের বিক্রিতে পড়ে থাকত।
     * এখন যতটুকু স্তরে আছে ততটুকু [[issue()]]-এ (একই FIFO, একই লটের নিয়ম), কেবল ঘাটতিটুকু পণ্যের কেনা দামে — খোলা মজুদের মতো
     * স্তরহীন মাল দেওয়া আগের মতো চলে, থামে না। ঘাটতি না থাকলে আগের [[issue()]] হুবহু।
     *
     * @return string মোট খরচ
     */
    public function issueOrPrice(
        Product $product,
        string $qty,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
        ?Batch $batch = null,
    ): string {
        $have = $this->qtyOnHand($product);
        $fromLayers = bccomp($have, $qty, 4) >= 0 ? $qty : (bccomp($have, '0', 4) > 0 ? $have : '0');

        $cost = bccomp($fromLayers, '0', 4) > 0
            ? $this->issue($product, $fromLayers, $sourceType, $sourceId, $documentNo, $date, $batch)['cost']
            : '0';

        $short = bcsub($qty, $fromLayers, 4);

        return bccomp($short, '0', 4) > 0
            ? bcadd($cost, bcmul((string) ($product->purchase_price ?? '0'), $short, 4), 4)
            : $cost;
    }

    public function receive(
        Product $product,
        string $qty,
        string $unitCost,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,

        /*
         * ⭐ মালটা কোন লটের — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ দিলে স্তরটা লট চেনে, আর বিক্রেতা ঐ লট বাছলে খরচ এই স্তর থেকেই আসে ([[issue()]])। `null` মানে
         * লটহীন মাল — আচরণ আগের মতোই।
         */
        ?Batch $batch = null,

        // ⭐ মালটা কার (সরবরাহকারী/প্রিন্সিপাল) — ক্রয়ে উৎস থেকে, খোলা মজুদে মানুষের বাছাই; "আসল" কমিশন এটাই পড়ে
        ?int $supplierId = null,
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

            // ⓘ লট থাকলেই ঘরটা লেখা — লটহীন স্তরের সারি হুবহু আগের মতো
            ...($batch !== null ? ['batch_id' => $batch->id] : []),
            ...($supplierId !== null ? ['supplier_id' => $supplierId] : []),
        ]);
    }

    /**
     * ⭐ মাল ঢুকল, আর **মোট দামটাই** সত্য — ২১ সেপ্টেম্বর ২০২৬, অডিটে ধরা।
     *
     * ── ⛔ কী ঘটত ──────────────────────────────────────────────────────
     * ডাকার জায়গা একক দর বের করত `bcdiv($value, $qty, 4)` দিয়ে, আর সেটা
     * **কেটে ফেলে, রাউন্ড করে না**। ⓘ ৳১০০-এর ৩ বস্তা → একক ৩৩.৩৩৩৩ →
     * স্তরের মোট ৳৯৯.৯৯৯৯, অথচ খাতায় ৳১০০.০০০০। ⚠️ তিনটাই বেরিয়ে গেলে
     * ৳০.০০০১ মজুদ খাতায় পড়ে থাকে **যেখানে মজুদ শূন্য** — আর ওটা কেউ
     * ব্যাখ্যা করতে পারে না।
     *
     * ── ⭐ তাই টাকাটা স্থির, দরটা নয় ─────────────────────────────────────
     * বাকিটুকু আলাদা একটা স্তরে সরিয়ে রাখা হয়: কয়টা একক এক ধাপ (০.০০০১)
     * বেশি দরে বসবে, সেটাই গোনা হয়। ⓘ নিয়মটা নতুন নয় — মজুদ নতুন এককে
     * নামানোর সময় ([[PackRebase]]) ঠিক এভাবেই টাকা অক্ষত রাখা হয়েছিল।
     *
     * ⚠️ ফলে একই ক্রয়ে দুইটা স্তর হতে পারে, আর সেটা ঠিক: FIFO-র ক্রম
     * বদলায় না (একই দিন, একই কাগজ), কিন্তু যোগফল **হুবহু** মেলে।
     *
     * @return list<CostLayer>
     */
    public function receiveWorth(
        Product $product,
        string $qty,
        string $value,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
        ?Batch $batch = null,
        ?int $supplierId = null,
    ): array {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('A cost layer needs a positive quantity.');
        }

        $low = bcdiv($value, $qty, 4);
        $residue = bcsub($value, bcmul($qty, $low, 4), 4);

        // কয়টা একক এক ধাপ বেশি দরে বসবে — বাকিটুকু ঠিক ততটাই
        $higher = bcmul($residue, '10000', 0);

        /*
         * ⛔ ভগ্নাংশ পরিমাণে উপরের কৌশলটা পরিমাণের বেশি বসায় —
         * ২৭ সেপ্টেম্বর ২০২৬ব (নিরীক্ষা §২, abos-7c প্রমাণ দিয়েছেন)।
         *
         * ⓘ `$higher` প্রশ্নটার উত্তর *"কয়টা **একক** এক পয়সা বেশি
         * দরে বসবে"* — আর সেটা অর্থপূর্ণ কেবল পূর্ণসংখ্যক এককে।
         *
         * ⚠️ ০.৭ কেজি ও বিল ১০০-তে মাপা ফল হত: `low` ১৪২.৮৫৭১,
         * `residue` ০.০০০১, আর `$higher` = **১** — অর্থাত্ ০.৭ এল, স্তরে
         * বসত ১.০। ⛔ আর `$rest` তখন −০.৩, যা নিচের `> 0` শর্তে
         * **নীরবে** বাদ পড়ত। ⓘ ফল: ০.৩ একক মজুদ আর তার মূল্য শূন্য
         * থেকে তৈরি হত, আর মজুদ-খাত মজুদ রিপোর্টের সাথে মিলত না।
         *
         * ⭐ সীমাটাই যথেষ্ট, আর কিছু ছাড়তেও হয় না — মেপে দেখা:
         *     ০.৭ × ১৪২.৮৫৭১ = ৯৯.৯৯৯৯
         *     ০.৭ × ১৪২.৮৫৭২ = ১০০.০০০০   ← হুবহু
         * ⓘ অর্থাত্ পুরো পরিমাণটা উঁচু দরে বসলে **পরিমাণ ও মূল্য
         * দুইটাই** ঠিক থাকে।
         *
         * ⚠️ প্রথমে আমি ভেবেছিলাম ভগ্নাংশে একটা ছাড়তেই হবে — পরিমাণ
         * নয় মূল্য। ⓘ মাপাটা সেটা ভুল প্রমাণ করল, আর তাই এখানে কোনো
         * "অনিবার্য ফারাক"-এর টীকা নেই।
         */
        if (bccomp($higher, $qty, 4) > 0) {
            $higher = $qty;
        }

        $layers = [];

        /*
         * ⚠️ স্কেল **৪**, ০ নয় — সীমার পরে `$higher` ভগ্নাংশ হতে পারে।
         * ⛔ স্কেল ০-এ তুলনা করলে ০.৭ **শূন্যের সমান** গণ্য হত, তাই
         * স্তরটা বসতই না — আর মাল এল অথচ খরচের স্তর শূন্য।
         * ⓘ সীমা বসানোর পর এই লাইনটাও বদলাতে হয়, আর পরীক্ষাটা
         * ঠিক এই অবস্থাটাই ধরেছে।
         */
        if (bccomp($higher, '0', 4) > 0) {
            $layers[] = $this->receive(
                $product, $higher, bcadd($low, '0.0001', 4), $sourceType, $sourceId, $documentNo, $date, $batch, $supplierId
            );
        }

        $rest = bcsub($qty, $higher, 4);

        if (bccomp($rest, '0', 4) > 0) {
            $layers[] = $this->receive($product, $rest, $low, $sourceType, $sourceId, $documentNo, $date, $batch, $supplierId);
        }

        return $layers;
    }

    /**
     * মাল বেরোল — পুরনো স্তর থেকে, যতগুলো লাগে।
     *
     * ── কেন লক ─────────────────────────────────────────────────────
     * দুইজন একই মুহূর্তে একই পণ্য বেচলে দুইজনেই একই স্তরে সব মাল দেখত,
     * আর দুইজনেই সেখান থেকেই টানত — স্তরটা ঋণাত্মক হয়ে যেত। স্টকের
     * তাকেও একই সমস্যা, একই সমাধান (StockService::move)।
     *
     * ── ⭐ লট বাছা থাকলে, ৩০ সেপ্টেম্বর ২০২৬ (চূড়ান্ত অডিট) ─────────────
     * গুদাম থেকে যে লট বেরোল, খরচও তার **নিজের** স্তর থেকে — নাহলে খাতায় এক লটের দাম আর গুদামে আরেক
     * লটের মাল। ⛔ লটের স্তরে না কুলালে বাকিটা FIFO-তে, কিন্তু **কখনো নীরবে নয়**: টানের সারিতে
     * `fallback` চিহ্ন, আর লটের নামে নিরীক্ষার খাতায় একটা ঘটনা। ⓘ এমন হয় কেবল পুরনো স্তরে, যাদের
     * লট জানা নেই (লট আসার আগের মাল, আমদানি করা ইতিহাস)।
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
        ?Batch $batch = null,
    ): array {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('Issuing stock needs a positive quantity.');
        }

        return DB::transaction(function () use ($product, $qty, $sourceType, $sourceId, $documentNo, $date, $batch) {
            $cost = '0';
            $uses = [];
            $remaining = $qty;

            if ($batch !== null) {
                $own = CostLayer::query()
                    ->where('product_id', $product->id)
                    ->where('batch_id', $batch->id)
                    ->open()
                    ->lockForUpdate()
                    ->get();

                $drawn = $this->drawFrom($own, $remaining, $product, $sourceType, $sourceId, $documentNo, $date);

                $cost = $drawn['cost'];
                $uses = $drawn['uses'];
                $remaining = $drawn['left'];
            }

            if (bccomp($remaining, '0', 4) > 0) {
                /*
                 * ⓘ লট বাছা থাকলে FIFO-তে পড়ার সময় আগে **লটহীন** স্তর — অন্য লটের নিজের স্তর খেয়ে ফেললে সেই লট
                 * বিক্রির দিন আবার ঘাটতিতে পড়ত, আর ভুলটা এক লট থেকে আরেক লটে গড়াত। লটহীন স্তরে না কুলালে তবেই
                 * বাকি সব, পুরনো আগে। লট না থাকলে আগের FIFO হুবহু।
                 */
                $layers = CostLayer::query()
                    ->where('product_id', $product->id)
                    ->where('qty_remaining', '>', 0)
                    ->when($batch !== null, fn ($q) => $q->orderByRaw('CASE WHEN batch_id IS NULL THEN 0 ELSE 1 END'))
                    ->orderBy('trx_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $asked = $remaining;
                $drawn = $this->drawFrom($layers, $remaining, $product, $sourceType, $sourceId, $documentNo, $date, fallback: $batch !== null);

                $cost = bcadd($cost, $drawn['cost'], 4);
                $uses = [...$uses, ...$drawn['uses']];
                $remaining = $drawn['left'];

                if ($batch !== null && $drawn['uses'] !== []) {
                    app(AuditEngine::class)->recordAction($batch, 'lot_cost_fell_back', sprintf(
                        '%s: %s of %s drew FIFO cost; lot %s had no cost layer left for it',
                        $documentNo ?? $sourceType.'#'.$sourceId,
                        rtrim(rtrim(bcsub($asked, $remaining, 4), '0'), '.'),
                        $product->name(),
                        $batch->batch_no,
                    ));
                }
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

            $this->markLaterLayers($product, $uses, $date, $documentNo ?? $sourceType.'#'.$sourceId);

            return ['cost' => $cost, 'uses' => $uses];
        });
    }

    /**
     * ⭐ কাগজের তারিখের **পরে** আসা স্তর থেকে খরচ টানা হলে অডিটে চিহ্ন — পুরো-ERP অডিট, মজুদ ছ১০ (fe-র সিদ্ধান্ত (গ),
     * ১০ অক্টোবর ২০২৬; [[ALaterLayerLeavesAMarkTest]])।
     *
     * ⓘ স্তর তারিখের ক্রমে টানা হয়, তাই পেছনের তারিখের বিক্রি নিজের দিনের আগের স্তরই আগে পায়। কেবল সেই স্তর পরের তারিখের
     * বিক্রি আগেই খেয়ে ফেললে সে পরে আসা স্তর থেকে টানে — মোট খরচ ঠিক, দুই দিনের ভাগ উল্টো। ⚠️ পুরো সারাই মানে পরের
     * বিক্রিগুলোর খরচ নতুন করে গোনা (খাতার পুরনো সারি বদলায়) — মালিকের সিদ্ধান্তে, ফ্রিজের পরে। ততদিন চিহ্নটা থাকে:
     * কোন কাগজ, কত, কোন তারিখের স্তর — হিসাবরক্ষক দেখে বুঝতে পারেন। খাতা নিজে কিছুই বদলায় না।
     *
     * @param  list<CostLayerUse>  $uses
     */
    private function markLaterLayers(Product $product, array $uses, Carbon|string|null $date, string $paper): void
    {
        if ($uses === []) {
            return;
        }

        $day = $this->date($date);
        $later = CostLayer::query()
            ->whereIn('id', array_map(fn (CostLayerUse $use) => $use->cost_layer_id, $uses))
            ->whereDate('trx_date', '>', $day)
            ->pluck('trx_date', 'id');

        if ($later->isEmpty()) {
            return;
        }

        $qty = '0';

        foreach ($uses as $use) {
            if ($later->has($use->cost_layer_id)) {
                $qty = bcadd($qty, (string) $use->qty, 4);
            }
        }

        app(AuditEngine::class)->recordAction($product, 'cost_from_later_layer', sprintf(
            '%s (%s): %s of %s drew cost from layer(s) dated %s',
            $paper,
            $day,
            rtrim(rtrim($qty, '0'), '.'),
            $product->name(),
            $later->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->implode(', '),
        ));
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

        /*
         * ⭐ কোন ফেরতগুলো **এই মূল নথিরই** — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ আগে "আগে কতটা ফিরেছে" গোনা হত স্তর ধরে, **সব** ফেরত মিলিয়ে।
         * ফলে একই স্তর থেকে দুই বিল মাল নিলে, এক বিলের ফেরত অন্য বিলের
         * জায়গা খেয়ে ফেলত: S1 আর S2 দুটোই P1 থেকে ৪টা, S1-এর ৪টা ফেরত
         * এলে S2-এর ২টা ফেরত "বেরিয়েছিল তার বেশি" বলে আটকে যেত।
         *
         * ⓘ ডাকার পক্ষ বলে দেয় কোন ফেরত-নথিগুলো গোনা হবে (এই নথিটাও
         * সহ)। `null` মানে আগের আচরণ — ঐ ধরনের সব ফেরত।
         *
         * @var list<int>|null
         */
        ?array $returnedBy = null,

        /*
         * ⭐ কোন লটের মাল ফিরছে — Inventory অডিট ম৭, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে খরচ ফিরত টানের উল্টো ক্রমে, লট না দেখে: লট A-র মাল ফিরলেও খরচ বসত লট B-র স্তরে। ⓘ দিলে সেই লটের স্তর
         * আগে, বাকিটা আগের ক্রমে; `null` মানে আগের আচরণ হুবহু।
         */
        ?Batch $batch = null,
    ): string {
        if (bccomp($qty, '0', 4) <= 0) {
            throw new RuntimeException('Returning stock needs a positive quantity.');
        }

        return DB::transaction(function () use (
            $product, $qty, $issuedSourceType, $issuedSourceId, $sourceType, $sourceId, $documentNo, $date, $returnedBy, $batch
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

            /*
             * ⓘ একই স্তর থেকে মূল নথির একাধিক টান থাকলে (একই পণ্য দুই
             * সারিতে) স্তরটা একবারই দেখা হয় — মোট টানা থেকে মোট ফেরা বাদ।
             * ⚠️ টান ধরে ধরে দেখলে প্রতিটা টানের সাথে পুরো "আগে ফেরা" বাদ
             * যেত, আর জায়গাটা দুইবার কমত।
             */
            $issuedOnLayer = [];

            foreach ($uses as $use) {
                $issuedOnLayer[$use->cost_layer_id] = bcadd(
                    $issuedOnLayer[$use->cost_layer_id] ?? '0', (string) $use->qty, 4,
                );
            }

            /*
             * ⛔ মূল নথির নিজের উল্টানো টান বাদ — বিল সম্পাদনায় পুরনো টান `…:cancel`-এ ফেরে, তারপর নতুন টান (পুরো-ERP অডিট,
             * ৬ অক্টোবর ২০২৬, মজুদ M8; [[AReturnAfterABillEditFindsOnlyWhatTheBillHoldsTest]])। ⓘ আগে ধনাত্মক টানগুলোই গোনা হত,
             * তাই ফেরানো পুরনো টানের স্তরেও "এখনো ধরা" দেখাত, আর ফেরত সেখানে নামতে পারত যেখানে বিলটার আর কিছু নেই।
             * ⚠️ যখন ফেরতটাই সেই উল্টানো (বাতিল/সম্পাদনা নিজে এই পথে আসে), ঐ সারিগুলো নিচে "আগে ফিরেছে"-তেই বাদ পড়ে — দুবার নয়।
             */
            if ($sourceType !== $issuedSourceType.':cancel') {
                $reversed = CostLayerUse::query()
                    ->where('product_id', $product->id)
                    ->where('source_type', $issuedSourceType.':cancel')
                    ->where('source_id', $issuedSourceId)
                    ->whereRaw('qty < 0')
                    ->groupBy('cost_layer_id')
                    ->selectRaw('cost_layer_id, COALESCE(SUM(qty), 0) as q')
                    ->pluck('q', 'cost_layer_id');

                foreach ($reversed as $layerId => $q) {
                    if (isset($issuedOnLayer[$layerId])) {
                        $issuedOnLayer[$layerId] = bcadd($issuedOnLayer[$layerId], (string) $q, 4);
                    }
                }
            }

            $uses = $uses->unique('cost_layer_id')->values();

            // ⭐ ফেরা লটের স্তর আগে (ম৭) — বাকিগুলোর ক্রম অক্ষত (PHP-র সাজানো স্থির)
            if ($batch !== null) {
                $lotOf = CostLayer::query()->whereIn('id', $uses->pluck('cost_layer_id'))->pluck('batch_id', 'id');
                $uses = $uses->sortBy(fn ($use) => (int) ($lotOf[$use->cost_layer_id] ?? 0) === (int) $batch->id ? 0 : 1)->values();
            }

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
                    // ⓘ বাতিল ফেরতের উল্টো সারি (`…:cancel`, ধনাত্মক) কাটাকাটি করে — বাতিল ফেরত আর "আগে ফিরেছে" নয়, ডাকার পক্ষ
                    // তালিকায় তাকে রাখলেও (⚠️৫; [[OneBillsReturnAteAnotherBillsHeadroomTest]])
                    ->where(fn ($q) => $q->where(fn ($r) => $r->where('source_type', $sourceType)->whereRaw('qty < 0'))
                        ->orWhere('source_type', $sourceType.':cancel'))
                    ->when($returnedBy !== null, fn ($q) => $q->whereIn('source_id', $returnedBy))
                    ->sum('qty') ?: '0');

                $available = bcadd($issuedOnLayer[$use->cost_layer_id], $alreadyBack, 4);

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
     * একটা ফেরত বাতিল — স্তরে যা ফিরেছিল, তা আবার তুলে নেওয়া।
     *
     * ── ⛔ কী ঘটত, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
     * ফেরত বাতিলে মাল তাক থেকে নামত আর খাতার দাখিলা উল্টাত, কিন্তু
     * [[returnToLayers()]] স্তরে যা বসিয়েছিল তা থেকেই যেত। ⓘ হাতে গোনা:
     * ১০ বেচা, ৪ ফেরত, ফেরত বাতিল → তাকে ১০, খাতায় ৮০০, অথচ স্তরে ১৪
     * একক আর ১,০৪০ টাকা। ⚠️ মজুদ রিপোর্ট স্তর থেকে পড়ে, তাই ব্যালান্স
     * শিটের মজুদ আর রিপোর্টের মজুদ আলাদা হয়ে যেত — আর ঐ বাড়তি ৪টা
     * পরের বিক্রয়ে খরচ হয়ে বেরোত, যা কখনো গুদামে ছিল না।
     *
     * ⓘ ফেরত-সারিগুলো মুছে ফেলা হয়, উল্টো সারি লেখা নয় — ⚠️ উল্টো সারি
     * থাকলে "এই বিলের কতটা আগে ফিরেছে" গোনায় বাতিল ফেরতটাও ধরা পড়ত।
     * ইতিহাস হারায় না: মজুদের চলাচল আর খাতার উল্টো দাখিলা দুইটাই থাকে।
     *
     * ⛔ ফেরা মাল ইতিমধ্যে আবার বেরিয়ে গেলে (স্তরে ততটা নেই) থামে — তখন
     * বাতিল নয়, নতুন বিক্রয় বা সমন্বয়ই সৎ পথ।
     *
     * @return string যত টাকার মাল স্তর থেকে তোলা হলো
     */
    public function undoReturn(string $sourceType, int $sourceId, Carbon|string|null $date = null): string
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $date) {
            $rows = CostLayerUse::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereRaw('qty < 0')
                ->orderBy('id')
                ->get();

            /*
             * ⛔ ফেরতের সারি আর মোছা হয় না — আজকের (বাতিলের) তারিখে উল্টো সারি, `…:cancel` (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬,
             * মজুদ ⚠️৫; [[ACancelNeverRewritesAClosedMonthsStockTest]])। ⓘ মুছলে ফেরতের দিনের মজুদ-মূল্য পেছনে বদলাত, অথচ খাতা
             * উল্টো হয় বাতিলের দিনে — বন্ধ মাসের দুই সংখ্যা আলাদা। বিক্রির বাতিলের একই রীতি (`sales_invoice:cancel`)।
             * ⓘ আগে কতটা উল্টানো হয়েছে, তা বাদ — দুইবার ডাকলেও দ্বিতীয়বার কিছু লেখা হয় না।
             */
            $undone = CostLayerUse::query()
                ->where('source_type', $sourceType.':cancel')
                ->where('source_id', $sourceId)
                ->groupBy('cost_layer_id')
                ->selectRaw('cost_layer_id, COALESCE(SUM(qty), 0) as q')
                ->pluck('q', 'cost_layer_id')
                ->map(fn ($q) => (string) $q)
                ->all();

            $value = '0';

            foreach ($rows as $row) {
                $back = bcmul((string) $row->qty, '-1', 4);
                $already = $undone[$row->cost_layer_id] ?? '0';

                if (bccomp($already, '0', 4) > 0) {
                    $settled = bccomp($already, $back, 4) >= 0 ? $back : $already;
                    $undone[$row->cost_layer_id] = bcsub($already, $settled, 4);
                    $back = bcsub($back, $settled, 4);
                }

                if (bccomp($back, '0', 4) <= 0) {
                    continue;
                }

                $layer = CostLayer::query()->lockForUpdate()->find($row->cost_layer_id);

                if ($layer === null || bccomp((string) $layer->qty_remaining, $back, 4) < 0) {
                    throw ValidationException::withMessages([
                        'status' => __('inventory::validation.layer_already_used', [
                            'document' => $row->document_no ?? (string) $sourceId,
                        ]),
                    ]);
                }

                $layer->qty_remaining = bcsub((string) $layer->qty_remaining, $back, 4);
                $layer->save();

                $amount = bcmul($back, (string) $row->unit_cost, 4);

                CostLayerUse::create([
                    'company_id' => $this->companyId(),
                    'cost_layer_id' => $row->cost_layer_id,
                    'product_id' => $row->product_id,
                    'source_type' => $sourceType.':cancel',
                    'source_id' => $sourceId,
                    'document_no' => $row->document_no,
                    'trx_date' => $this->date($date),
                    'qty' => $back,
                    'unit_cost' => $row->unit_cost,
                    'amount' => $amount,
                    'created_by' => auth()->id(),
                ]);

                $value = bcadd($value, $amount, 4);
            }

            return $value;
        });
    }

    /**
     * নির্দিষ্ট একটা চালানের মাল বের করা — না কুলালে বাকিটা FIFO-তে।
     *
     * ── কেন ক্রয় ফেরতে এটা লাগে ─────────────────────────────────────
     * সরবরাহকারীকে যে দুই বস্তা ফেরত যাচ্ছে সেগুলো **ওই বিলেরই** মাল,
     * অন্য কোনো চালানের নয়। সাধারণ FIFO চালালে তাকের সবচেয়ে পুরনো
     * মালটা বেরোত — ৫০ টাকায় কেনা বস্তা ৩,৪০০ দরে বেরিয়ে যেত, আর
     * পার্থক্যটা মূল্য-পার্থক্য খাতে জমত। খাতা ভারসাম্যে থাকত, তবু
     * সংখ্যাটা মিথ্যা বলত।
     *
     * ── আর না কুলালে FIFO কেন ───────────────────────────────────────
     * ওই বিলের মাল ইতিমধ্যে বিক্রি হয়ে গিয়ে থাকতে পারে। তখন তাকে যা
     * পড়ে আছে সেটা অন্য চালানের, আর ফেরত যাচ্ছে সেটাই — তাই যা সত্যিই
     * যাচ্ছে তার দামই বেরোয়। পার্থক্যটা তখন সত্যিকারের পার্থক্য।
     *
     * @return array{cost: string, uses: list<CostLayerUse>}
     */
    public function issueFromSource(
        Product $product,
        string $qty,
        string $fromSourceType,
        int $fromSourceId,
        string $sourceType,
        int $sourceId,
        ?string $documentNo = null,
        Carbon|string|null $date = null,
    ): array {
        return DB::transaction(function () use (
            $product, $qty, $fromSourceType, $fromSourceId, $sourceType, $sourceId, $documentNo, $date
        ) {
            $layers = CostLayer::query()
                ->where('product_id', $product->id)
                ->where('source_type', $fromSourceType)
                ->where('source_id', $fromSourceId)
                ->where('qty_remaining', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $taken = $this->drawFrom($layers, $qty, $product, $sourceType, $sourceId, $documentNo, $date);

            if (bccomp($taken['left'], '0', 4) > 0) {
                $rest = $this->issue($product, $taken['left'], $sourceType, $sourceId, $documentNo, $date);

                return [
                    'cost' => bcadd($taken['cost'], $rest['cost'], 4),
                    'uses' => [...$taken['uses'], ...$rest['uses']],
                ];
            }

            return ['cost' => $taken['cost'], 'uses' => $taken['uses']];
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
     * ⚠️ স্তরটা **মোছে** — তাই কেবল সেই সংশোধনের জন্য যা মূল তারিখেই উল্টায় (খোলা মজুদের সংশোধন: মজুদ আর খাতা
     * দুটোই মূল দিনে ফেরে)। আজকের তারিখে বাতিল হলে [[cancelLayers()]] — মুছলে আগের মাসের মজুদ-মূল্য বদলাত (⚠️৫)।
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

    /**
     * ⭐ নথি বাতিল — বাতিলের তারিখে স্তরগুলো খালি করা, কিছু না মুছে (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, মজুদ ⚠️৫;
     * [[ACancelNeverRewritesAClosedMonthsStockTest]])।
     *
     * ⛔ [[withdraw()]] স্তরটা মুছত — কেনার তারিখসহ। খাতা উল্টায় বাতিলের দিনে, তাই কেনার মাসের মজুদ-মূল্য রিপোর্ট পেছনে
     * বদলাত, খাতা বদলাত না: বন্ধ মাসের দুই সংখ্যা আলাদা। ⓘ এখন স্তর থাকে (কেনার দিনে আগমন), আর বাতিলের দিনে `…:cancel`
     * ব্যবহার-সারি পুরো স্তরটা টেনে নেয় — বিক্রির বাতিলের একই রীতি। ⓘ ডাকার পক্ষ তারিখ দেয় — খাতা আর মজুদ যেদিন উল্টায়।
     *
     * ⓘ ছোঁয়া স্তরে থামে, [[withdraw()]]-এর একই কারণে। আগে বাতিল হওয়া স্তর (সম্পাদনায় উল্টে আবার বসানো বিল) বাদ।
     *
     * @return int কতগুলো স্তর খালি হলো
     */
    public function cancelLayers(string $sourceType, int $sourceId, Carbon|string|null $date = null): int
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $date) {
            $gone = CostLayerUse::query()
                ->where('source_type', $sourceType.':cancel')
                ->where('source_id', $sourceId)
                ->pluck('cost_layer_id');

            $layers = CostLayer::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->whereNotIn('id', $gone)
                ->lockForUpdate()
                ->get();

            // ⓘ নতুন দামে সরানো স্তর ([[revalue()]]): যতটা নতুন স্তরে গেছে, ততটা "ছোঁয়া" নয়; তার বাইরে বেরোলে ছোঁয়া
            $moved = CostLayerUse::query()
                ->where('source_type', $sourceType.':revalue')
                ->where('source_id', $sourceId)
                ->groupBy('cost_layer_id')
                ->selectRaw('cost_layer_id, COALESCE(SUM(qty), 0) as q')
                ->pluck('q', 'cost_layer_id');

            foreach ($layers as $layer) {
                $untouched = bcadd((string) $layer->qty_remaining, (string) ($moved[$layer->id] ?? '0'), 4);

                if (bccomp($untouched, (string) $layer->qty_in, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'status' => __('inventory::validation.layer_already_used', [
                            'document' => $layer->document_no ?? (string) $layer->id,
                        ]),
                    ]);
                }
            }

            foreach ($layers as $layer) {
                // ⓘ খালি স্তর (পুরোটা নতুন দামের স্তরে গেছে) — খালি করার কিছু নেই
                if (bccomp((string) $layer->qty_remaining, '0', 4) <= 0) {
                    continue;
                }

                CostLayerUse::create([
                    'company_id' => $this->companyId(),
                    'cost_layer_id' => $layer->id,
                    'product_id' => $layer->product_id,
                    'source_type' => $sourceType.':cancel',
                    'source_id' => $sourceId,
                    'document_no' => $layer->document_no,
                    'trx_date' => $this->date($date),
                    'qty' => $layer->qty_remaining,
                    'unit_cost' => $layer->unit_cost,
                    'amount' => bcmul((string) $layer->qty_remaining, (string) $layer->unit_cost, 4),
                    'created_by' => auth()->id(),
                ]);

                $layer->qty_remaining = '0';
                $layer->save();
            }

            return $layers->count();
        });
    }

    /**
     * ⭐ কেনার দাম বদলাল (সরবরাহকারী অন্য দরে বিল করলেন) — স্তর নতুন দামে, কিছু না মুছে, কিছু না বদলে (পুরো-ERP অডিট,
     * ৯ অক্টোবর ২০২৬, ক্রয় ⚠️৩, স্তরের দিক; খাতার দিক ec-র [[PurchaseBillService]]; [[ALayerTakesTheBillsPriceFromTheBillsDayTest]])।
     *
     * ⛔ স্তরের `unit_cost` সরাসরি বদলানো যায় না: মজুদ-মূল্য রিপোর্ট আগমন গোনে `qty_in × unit_cost` স্তরের নিজের দিনে, তাই
     * মাল-গ্রহণের মাস থেকে মূল্য পেছনে বদলাত — ⚠️৫-এর একই ভুল। ⓘ তাই প্রতিটা জীবিত স্তরের বাকি মাল `…:revalue` সারিতে
     * পুরনো দামে খালি হয়, আর একই উৎস, লট আর সরবরাহকারীর নামে নতুন দামে নতুন স্তর বসে — দুটোই `$date`-এ, খাতার দাখিলার দিনে।
     * ⓘ FIFO-তে নতুন স্তর `$date`-এর জায়গায় দাঁড়ায় (স্কিমা না বদলে আর উপায় নেই; ec রাজি, ১০ অক্টোবর ২০২৬)।
     *
     * ⓘ ফেরত: তাকে যতটা (`shelf_qty`, পার্থক্য `shelf_diff` → মজুদ খাত) আর আগেই যতটা বেরিয়েছে (`sold_qty`, পার্থক্য
     * `sold_diff` → খরচ খাত)। খাতা বসান ডাকার পক্ষ। ⓘ দাম না বদলালে, বা একই দামে দ্বিতীয়বার ডাকলে কিছুই লেখা হয় না —
     * আগে পুনর্মূল্যায়িত স্তরে চিহ্ন (`…:revalue` সারি, তাকে কিছু না থাকলে শূন্য পরিমাণের) থাকে, তাই তার বেরোনো অংশ দুবার গোনা হয় না।
     *
     * @return array{shelf_qty: string, shelf_diff: string, sold_qty: string, sold_diff: string}
     */
    public function revalue(string $sourceType, int $sourceId, int $productId, string $newUnitCost, Carbon|string $date): array
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $productId, $newUnitCost, $date) {
            $out = ['shelf_qty' => '0', 'shelf_diff' => '0', 'sold_qty' => '0', 'sold_diff' => '0'];

            $done = CostLayerUse::query()
                ->whereIn('source_type', [$sourceType.':revalue', $sourceType.':cancel'])
                ->where('source_id', $sourceId)
                ->pluck('cost_layer_id');

            $layers = CostLayer::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('product_id', $productId)
                ->whereNotIn('id', $done)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                $per = bcsub($newUnitCost, (string) $layer->unit_cost, 6);

                if (bccomp($per, '0', 6) === 0) {
                    continue;
                }

                $shelf = (string) $layer->qty_remaining;
                $sold = bcsub((string) $layer->qty_in, $shelf, 4);

                $out['shelf_qty'] = bcadd($out['shelf_qty'], $shelf, 4);
                $out['shelf_diff'] = bcadd($out['shelf_diff'], bcmul($shelf, $per, 6), 6);
                $out['sold_qty'] = bcadd($out['sold_qty'], $sold, 4);
                $out['sold_diff'] = bcadd($out['sold_diff'], bcmul($sold, $per, 6), 6);

                // ⓘ পুরনো দামে খালি — তাকে কিছু না থাকলেও চিহ্নটা থাকে (শূন্য পরিমাণ), যাতে বেরোনো অংশ দুবার গোনা না হয়
                CostLayerUse::create([
                    'company_id' => $this->companyId(),
                    'cost_layer_id' => $layer->id,
                    'product_id' => $layer->product_id,
                    'source_type' => $sourceType.':revalue',
                    'source_id' => $sourceId,
                    'document_no' => $layer->document_no,
                    'trx_date' => $this->date($date),
                    'qty' => $shelf,
                    'unit_cost' => $layer->unit_cost,
                    'amount' => bcmul($shelf, (string) $layer->unit_cost, 4),
                    'created_by' => auth()->id(),
                ]);

                if (bccomp($shelf, '0', 4) <= 0) {
                    continue;
                }

                $layer->qty_remaining = '0';
                $layer->save();

                CostLayer::create([
                    'company_id' => $this->companyId(),
                    'product_id' => $layer->product_id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_no' => $layer->document_no,
                    'trx_date' => $this->date($date),
                    'qty_in' => $shelf,
                    'qty_remaining' => $shelf,
                    'unit_cost' => $newUnitCost,
                    'batch_id' => $layer->batch_id,
                    'supplier_id' => $layer->supplier_id,
                    'created_by' => auth()->id(),
                ]);
            }

            return array_map(fn (string $v) => bcadd($v, '0', 4), $out);
        });
    }

    /**
     * ⭐ এতটা মাল এখন বেরোলে কত খরচ টানত — কিছু না টেনে, [[issue()]]-এর হুবহু ক্রমে (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬,
     * মজুদ ⚠️৮; [[ABranchTransferCarriesWhatTheGoodsCostTest]])।
     *
     * ⓘ লট দিলে আগে সেই লটের নিজের স্তর, তারপর লটহীন, তারপর বাকি সব — পুরনো আগে; লট না দিলে সোজা FIFO। পরের বিক্রি
     * ঠিক এই খরচই টানবে, তাই শাখা-পেরোনো বদলি এই দামেই মজুদের টাকা সরায়। ⓘ স্তরে না কুলালে বাকিটা কোম্পানির গড়ে
     * (আগের নিয়ম) — বদলি থামে না; স্তর একদম না থাকলে শূন্য।
     */
    public function costOf(Product $product, string $qty, ?Batch $batch = null): string
    {
        $cost = '0';
        $left = $qty;
        $used = [];

        $draw = function ($layers) use (&$cost, &$left, &$used): void {
            foreach ($layers as $layer) {
                if (bccomp($left, '0', 4) <= 0) {
                    return;
                }

                $free = bcsub((string) $layer->qty_remaining, $used[$layer->id] ?? '0', 4);

                if (bccomp($free, '0', 4) <= 0) {
                    continue;
                }

                $take = bccomp($free, $left, 4) >= 0 ? $left : $free;
                $used[$layer->id] = bcadd($used[$layer->id] ?? '0', $take, 4);
                $cost = bcadd($cost, bcmul($take, (string) $layer->unit_cost, 6), 6);
                $left = bcsub($left, $take, 4);
            }
        };

        if ($batch !== null) {
            $draw(CostLayer::query()->where('product_id', $product->id)->where('batch_id', $batch->id)->open()->get());
        }

        if (bccomp($left, '0', 4) > 0) {
            $draw(CostLayer::query()->where('product_id', $product->id)->where('qty_remaining', '>', 0)
                ->when($batch !== null, fn ($q) => $q->orderByRaw('CASE WHEN batch_id IS NULL THEN 0 ELSE 1 END'))
                ->orderBy('trx_date')->orderBy('id')->get());
        }

        if (bccomp($left, '0', 4) > 0) {
            $onHand = $this->qtyOnHand($product);

            if (bccomp($onHand, '0', 4) > 0) {
                $cost = bcadd($cost, bcmul($left, bcdiv($this->valueOnHand($product), $onHand, 6), 6), 6);
            }
        }

        return bcadd($cost, '0', 4);
    }

    /** এই পণ্যের যত মাল স্তরে পড়ে আছে, তার মোট মূল্য। */
    public function valueOnHand(Product $product): string
    {
        $rows = CostLayer::query()
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->get(['qty_remaining', 'unit_cost']);

        /*
         * ⓘ শুরুর মানটা স্কেল-৪, `'0'` নয় — ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ খালি তাকে একটাও সারি মেলে না (`qty_remaining > 0`), তাই
         * `reduce` শুরুর মানটাই ফেরত দিত: `'0'`। ⚠️ অথচ একটা সারি
         * থাকলেই bcadd স্কেল ৪ বানায়, আর এই ফাইলের বাকি সব পাঠই
         * স্কেল-৪ স্ট্রিং। ⓘ ফলে একই প্রশ্নের উত্তর দুই চেহারায় আসত,
         * আর কোনটা আসবে তা নির্ভর করত তাকে মাল আছে কি নেই তার উপর।
         *
         * ⓘ গণিতে কোনো ক্ষতি ছিল না — bcadd আর bccomp `'0'` ঠিকই পড়ে,
         * আর [[StockCountService::averageCost()]] ভাগের আগে
         * `bccomp($qty,'0',4) <= 0` দেখে নেয়। ⛔ ক্ষতিটা তুলনায়:
         * স্ট্রিং মিলিয়ে দেখা যেকোনো দাবি বা রিপোর্ট শূন্য তাকে
         * অপ্রত্যাশিত উত্তর পেত।
         */
        return $rows->reduce(
            fn (string $sum, CostLayer $l) => bcadd($sum, bcmul((string) $l->qty_remaining, (string) $l->unit_cost, 4), 4),
            '0.0000',
        );
    }

    /** স্তরে এখনো কতটা মাল আছে — টানার আগে দেখে নেওয়ার জন্য। */
    public function qtyOnHand(Product $product): string
    {
        return (string) (CostLayer::query()
            ->where('product_id', $product->id)
            ->sum('qty_remaining') ?: '0');
    }

    /**
     * দেওয়া স্তরগুলো থেকে যতটা কুলায় টেনে নেওয়া।
     *
     * দুই জায়গায় লাগে — সাধারণ FIFO টান, আর নির্দিষ্ট চালান থেকে টান।
     * নিয়মটা এক জায়গায় রাখা হয়েছে, কারণ দুই কপি হলে একদিন একটাতে
     * সারি লেখা হত আর অন্যটাতে হত না।
     *
     * "কত বাকি রইল" ফেরত দেয়, নিজে থামে না — কে থামবে সেটা ডাকা
     * পক্ষের সিদ্ধান্ত।
     *
     * @param  Collection<int, CostLayer>  $layers
     * @return array{cost: string, uses: list<CostLayerUse>, left: string}
     */
    private function drawFrom(
        $layers,
        string $qty,
        Product $product,
        string $sourceType,
        int $sourceId,
        ?string $documentNo,
        Carbon|string|null $date,

        // ⛔ লট চাওয়া হয়েছিল, তার স্তরে কুলায়নি — এই টানগুলো FIFO-র, আর সেটা সারিতে লেখা থাকে
        bool $fallback = false,
    ): array {
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
                ...($fallback ? ['fallback' => true] : []),
            ]);

            $layer->qty_remaining = bcsub((string) $layer->qty_remaining, $take, 4);
            $layer->save();

            $cost = bcadd($cost, $amount, 4);
            $remaining = bcsub($remaining, $take, 4);
        }

        return ['cost' => $cost, 'uses' => $uses, 'left' => $remaining];
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
