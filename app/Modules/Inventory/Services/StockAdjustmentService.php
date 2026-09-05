<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\ReasonCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * গণনার পর সমন্বয় — তাক, দাম আর খাতা, তিনটাই একসাথে।
 *
 * ── কেন এটা StockService-এ নয় ───────────────────────────────────────
 * StockService চলাচলের আদিম স্তর: সে কেবল সারি লেখে আর "যা নেই তা বের
 * করা যায় না" জাতীয় নিয়ম পাহারা দেয়। টাকার হিসাব প্রতিটা নথি নিজের
 * সার্ভিসে বসায় — বিক্রয় বিল, ক্রয় ফেরত, বেতন, সবাই। সমন্বয়ও একটা
 * নথি, তাই তারও নিজের সার্ভিস।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * সমন্বয় এতদিন কেবল গুদামের হিসাব বদলাত, খতিয়ানে কিছুই বসাত না। গণনায়
 * পাঁচ বস্তা কম পাওয়া গেলে তাক থেকে কমত, অথচ ব্যালেন্স শিটে মজুদের টাকা
 * যেমন ছিল তেমনই থাকত। ঘাটতিটা কোনো খরচ হিসেবে বসত না, তাই মুনাফা ঠিক
 * ততটাই বেশি দেখাত — আর কেউ টের পেত না, কারণ দুইটা সংখ্যা কখনো পাশাপাশি
 * রাখা হত না।
 *
 * বিস্তারিত: docs/Finding — Inventory is valued two different ways.md
 */
final class StockAdjustmentService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly PostingEngine $posting,
    ) {}

    /**
     * তাকে যা পাওয়া গেল সেটাই সত্যি — আর সেই সত্যিটা খাতাতেও বসে।
     *
     * ── উদ্বৃত্তে দর কেন বাধ্যতামূলক ─────────────────────────────────
     * গণনায় বেশি পাওয়া মালের কোনো চালান নেই, তাই তার দামও কোথাও লেখা
     * নেই। একটা দর ধরে নিলে (যেমন পণ্যের ক্রয়মূল্য) ঠিক সেই ভুলটাই ফিরে
     * আসত যেটা সারাতে স্তরগুলো বসানো হয়েছে। তাই মানুষটাকেই বলতে হয়
     * মালটা কত টাকার — তিনিই একমাত্র জানেন কোন চালানের মাল ওটা।
     *
     * ঘাটতিতে দর লাগে না: যে মাল বেরিয়ে যাচ্ছে তার দাম স্তরেই লেখা আছে।
     */
    /**
     * বিক্রি ছাড়া মাল বের করে দেওয়া।
     *
     * ── সমন্বয় থেকে আলাদা কেন ───────────────────────────────────────
     * প্রশ্ন দুইটা আলাদা। সমন্বয়ে বলা হয় "গুনে কত পেলাম", আর সিস্টেম
     * পার্থক্যটা বের করে — অর্থাৎ একটা **ভুল ধরা পড়ছে**। এখানে বলা হয়
     * "কতটা দিয়ে দিলাম", আর কিছুই ভুল হয়নি: মালটা জেনেশুনে গেছে।
     *
     * এক পর্দায় রাখলে ব্যবহারকারীকে মাথায় বিয়োগ করতে হত ("তাকে ৫০ আছে,
     * ২ কার্টন দিলাম, তাহলে লিখব ৪৮") — আর ওই বিয়োগটাই ভুল হয়। তার
     * চেয়েও বড় কথা, "মজুদ ঘাটতি" রিপোর্টে আপ্যায়নের বিস্কুট ঘাটতি
     * হিসেবে দেখাত, আর মালিক ভাবতেন গুদামে চুরি হচ্ছে।
     *
     * টাকাটা কোন খাতে যাবে তা কারণ বলে দেয় (ReasonCode::account) —
     * আপ্যায়ন খরচে, উপহার উপহারের খাতে, আর মালিকের ব্যবহার উত্তোলনে,
     * যেটা খরচই নয়।
     */
    public function issue(
        Product $product,
        Warehouse $warehouse,
        string $qty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
        ?string $narration = null,
    ): ?StockMovement {
        if (bccomp($qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.issue_needs_qty'),
            ]);
        }

        $onHand = $this->stock->floorQty($product, $warehouse);

        /*
         * তাকে যা নেই তা দেওয়া যায় না।
         *
         * বিক্রয়ে ঋণাত্মক মজুদ একটা সেটিংসে খোলা যায় (মাল পথে আছে,
         * কাগজ পরে হবে)। এখানে সেটা অর্থহীন: বিস্কুটটা হয় তাকে ছিল,
         * নয় ছিল না।
         */
        if (bccomp($qty, $onHand, 4) > 0) {
            throw ValidationException::withMessages([
                'qty' => __('inventory::validation.issue_more_than_stock', [
                    'have' => rtrim(rtrim($onHand, '0'), '.'),
                ]),
            ]);
        }

        // গুনে-পাওয়া পরিমাণ = এখন যা আছে − যতটা যাচ্ছে। বাকিটা adjust()
        // যা করে তা-ই: স্তর থেকে দাম নেওয়া, খতিয়ানে বসানো।
        return $this->adjust(
            product: $product,
            warehouse: $warehouse,
            countedQty: bcsub($onHand, $qty, 4),
            reason: $reason,
            date: $date,
            narration: $narration,
        );
    }

    public function adjust(
        Product $product,
        Warehouse $warehouse,
        string $countedQty,
        ReasonCode $reason,
        Carbon|string|null $date = null,
        ?string $narration = null,
        ?string $unitCost = null,
    ): ?StockMovement {
        $current = $this->stock->floorQty($product, $warehouse);
        $difference = bcsub($countedQty, $current, 4);

        // মিলে গেলে কোনো সারি নয় — শূন্য সারি খতিয়ানে শুধু ভিড় বাড়ায়
        if (bccomp($difference, '0', 4) === 0) {
            return null;
        }

        $surplus = bccomp($difference, '0', 4) > 0;

        if ($surplus && ($unitCost === null || $unitCost === '' || bccomp($unitCost, '0', 4) < 0)) {
            throw ValidationException::withMessages([
                'unit_cost' => __('inventory::validation.surplus_needs_rate'),
            ]);
        }

        return DB::transaction(function () use (
            $product, $warehouse, $reason, $date, $narration, $difference, $surplus, $unitCost
        ) {
            $movement = $this->stock->move(
                product: $product,
                warehouse: $warehouse,
                sourceType: StockService::ADJUSTMENT,
                sourceId: $product->id,
                floor: $difference,
                reason: $reason,
                date: $date,
                narration: $narration,
            );

            /*
             * মালের দাম আগে, খতিয়ান পরে — কারণ খতিয়ানের অঙ্কটা দাম থেকেই আসে।
             *
             * ঘাটতিতে অঙ্কটা স্তর বলে দেয় (যে মাল যাচ্ছে তার নিজের দাম),
             * উদ্বৃত্তে মানুষটা বলে দেন।
             */
            if ($surplus) {
                $this->costs->receive(
                    product: $product,
                    qty: $difference,
                    unitCost: $unitCost,
                    sourceType: StockService::ADJUSTMENT,
                    sourceId: $movement->id,
                    documentNo: $movement->document_no,
                    date: $date,
                );

                $amount = bcmul($difference, $unitCost, 4);
            } else {
                $taken = $this->costs->issue(
                    product: $product,
                    qty: bcmul($difference, '-1', 4),
                    sourceType: StockService::ADJUSTMENT,
                    sourceId: $movement->id,
                    documentNo: $movement->document_no,
                    date: $date,
                );

                $amount = $taken['cost'];
            }

            if (bccomp($amount, '0', 4) !== 0) {
                $this->postToLedger($movement, $amount, $surplus, $reason, $narration);
            }

            return $movement;
        });
    }

    /**
     * দুইটা সারি, দুই দিকেই একই জোড়া খাত।
     *
     *     ঘাটতি:  Dr মজুদ ঘাটতি ও উদ্বৃত্ত (5160)   Cr মজুদ পণ্য (1120)
     *     উদ্বৃত্ত: Dr মজুদ পণ্য (1120)             Cr মজুদ ঘাটতি ও উদ্বৃত্ত
     *
     * বিবরণে কারণটা যায়, কারণ ছয় মাস পর "৫১৬০-এ এই ১২,০০০ টাকা কীসের"
     * প্রশ্নের উত্তর "গণনার পার্থক্য" যথেষ্ট নয় — নষ্ট হয়েছিল না চুরি
     * গিয়েছিল, সেটাই আসল খবর।
     */
    private function postToLedger(
        StockMovement $movement,
        string $amount,
        bool $surplus,
        ReasonCode $reason,
        ?string $narration,
    ): void {
        $inventory = $this->account(StandardChart::INVENTORY);

        /*
         * কারণটা নিজের খাত বললে সেটাই, নইলে মজুদ ঘাটতি ও উদ্বৃত্ত।
         *
         * ── কেন কারণভেদে খাত আলাদা ──────────────────────────────────
         * গণনার পার্থক্য বা নষ্ট মাল সত্যিই ঘাটতি — ৫১৬০ ঠিক জায়গা।
         * কিন্তু অফিসে খাওয়া বিস্কুট আপ্যায়ন খরচ, কাউকে দেওয়া উপহার
         * উপহারের খাত (কর হিসাবে আলাদা করে লাগে), আর মালিকের বাসায়
         * নেওয়া পণ্য **খরচই নয়** — ওটা উত্তোলন, মালিকের মূলধন কমে।
         *
         * সবগুলো ৫১৬০-এ ফেললে ব্যবসার মুনাফা ভুল হত, আর "মজুদ ঘাটতি"
         * রিপোর্ট দেখে মালিক ভাবতেন গুদামে চুরি হচ্ছে — অথচ ওটা তাঁরই
         * নেওয়া মাল।
         */
        $difference = $reason->account ?? $this->account(StandardChart::INVENTORY_SHORTAGE_SURPLUS);

        $note = $narration !== null && $narration !== ''
            ? $reason->name().' — '.$narration
            : $reason->name();

        $lines = $surplus
            ? [
                ['account_id' => $inventory->id, 'debit' => $amount, 'narration' => $note],
                ['account_id' => $difference->id, 'credit' => $amount, 'narration' => $note],
            ]
            : [
                ['account_id' => $difference->id, 'debit' => $amount, 'narration' => $note],
                ['account_id' => $inventory->id, 'credit' => $amount, 'narration' => $note],
            ];

        $this->posting->post(
            sourceType: StockService::ADJUSTMENT,
            sourceId: $movement->id,
            trxDate: $movement->trx_date,
            lines: $lines,
            documentNo: $movement->document_no,
            branchId: $movement->branch_id,
        );
    }

    private function account(string $code): Account
    {
        $account = Account::query()->postable()
            ->where('code', $code)
            ->where('company_id', CompanyContext::id())
            ->first();

        if ($account === null) {
            throw new RuntimeException("The standard chart is missing account {$code}.");
        }

        return $account;
    }
}
