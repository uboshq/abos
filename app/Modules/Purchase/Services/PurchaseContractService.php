<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Purchase\Models\PurchaseContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় চুক্তি — বছরের শুরুতে ঠিক করা দর, আর তার পাহারা।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────
 * *"Purchase Contract · Rate Contract · Framework Agreement · Annual
 * Contract · Blanket PO"*।
 *
 * ── ⚠️ এই সেবার আসল কাজ দ্বিতীয়টা ───────────────────────────────────
 * ⓘ চুক্তি লেখা সহজ, আর ওটা একাই কিছু করে না। ⛔ আসল কাজ হলো
 * [[rateFor()]] — আদেশের পর্দা যেন জিজ্ঞেস করতে পারে *"এই সরবরাহকারীর
 * সাথে এই পণ্যের চুক্তির দর কত"*, আর না মিললে বলতে পারে।
 *
 * ⚠️ ঐ জোড়াটা না থাকলে চুক্তিটা কেবল একটা সংরক্ষিত কাগজ — ঠিক যা আগে
 * ছিল, শুধু কাগজের বদলে পর্দায়।
 */
final class PurchaseContractService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): PurchaseContract
    {
        $clean = $this->cleanLines($lines);

        $starts = Carbon::parse($data['starts_on'] ?? now());
        $ends = Carbon::parse($data['ends_on'] ?? now());

        /*
         * ⛔ শেষ তারিখ শুরুর আগে নয়।
         *
         * ⚠️ হলে [[PurchaseContract::coversToday()]] কোনোদিন সত্যি
         * হত না, আর চুক্তিটা লেখা থাকত অথচ কোনোদিন খাটত না — ⓘ আর
         * সেই ব্যর্থতাটা সম্পূর্ণ নীরব।
         */
        if ($ends->lt($starts)) {
            throw ValidationException::withMessages([
                'ends_on' => __('purchase::validation.contract_ends_before_it_starts'),
            ]);
        }

        return DB::transaction(function () use ($data, $clean, $starts, $ends) {
            $contract = PurchaseContract::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('PC'),
                'supplier_id' => $data['supplier_id'],
                'supplier_ref' => $data['supplier_ref'] ?? null,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(),
                'terms' => $data['terms'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($clean as $i => $line) {
                $contract->lines()->create([
                    'company_id' => CompanyContext::id(),
                    'line_no' => $i + 1,
                    'product_id' => $line['product_id'],
                    'agreed_rate' => $line['agreed_rate'],
                    'qty_limit' => $line['qty_limit'] ?? null,
                    'value_limit' => $line['value_limit'] ?? null,
                    'narration' => $line['narration'] ?? null,
                ]);
            }

            return $contract->load('lines');
        });
    }

    /**
     * চুক্তিটা চালু করা — এরপর থেকে দর মেলানো এর বিরুদ্ধেই হয়।
     */
    public function activate(PurchaseContract $contract): PurchaseContract
    {
        if ($contract->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('purchase::validation.contract_not_draft'),
            ]);
        }

        $contract->loadMissing('lines');

        if ($contract->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.contract_needs_lines'),
            ]);
        }

        $contract->update(['status' => DocumentStatus::CONFIRMED]);

        return $contract->fresh('lines');
    }

    /**
     * ⭐ এই সরবরাহকারীর সাথে এই পণ্যের আজকের চুক্তির দর।
     *
     * ── ⚠️ এটাই গোটা মডিউলটার কারণ ──────────────────────────────────
     * ⓘ `null` মানে *"কোনো চলতি চুক্তি নেই"* — শূন্য নয়। ⛔ শূন্য
     * ফেরালে আদেশের পর্দা ভাবত চুক্তিতে জিনিসটা বিনামূল্যে, আর প্রতিটা
     * দরই "চুক্তির চেয়ে বেশি" দেখাত।
     *
     * ── ⓘ একাধিক চুক্তি থাকলে সবচেয়ে সাম্প্রতিকটা ───────────────────
     * ⚠️ একই সরবরাহকারীর সাথে দুইটা চলতি চুক্তি থাকা অস্বাভাবিক,
     * ⛔ কিন্তু অসম্ভব নয় — নবায়নের দিন দুইটা একসাথে চালু থাকতে পারে।
     * ⭐ তখন পরেরটাই সত্যি, কারণ ওটাই শেষ কথা।
     */
    public function rateFor(int $supplierId, int $productId, ?Carbon $on = null): ?string
    {
        $contract = PurchaseContract::query()
            ->liveOn($on)
            ->where('supplier_id', $supplierId)
            ->whereHas('lines', fn ($q) => $q->where('product_id', $productId))
            ->with('lines')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        return $contract?->rateFor($productId);
    }

    /**
     * ⭐ যে চুক্তিগুলোর মেয়াদ শেষ হয়ে আসছে।
     *
     * ⓘ স্পেকের *"Contract Expiry"*। ⚠️ তারিখটা অ্যাপের ঘড়ি ধরে
     * বাঁধা হয়, ডাটাবেজকে জিজ্ঞেস করা হয় না — ⛔ দুইটা এক হওয়ার
     * কোনো নিশ্চয়তা নেই, আর এক দিনের ভুলে একটা চুক্তি নীরবে পেরিয়ে
     * যেত।
     *
     * @return \Illuminate\Support\Collection<int, PurchaseContract>
     */
    public function expiringWithin(int $days)
    {
        $today = Carbon::today();

        return PurchaseContract::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->whereDate('ends_on', '<=', $today->copy()->addDays($days)->toDateString())
            ->with('supplier')
            ->orderBy('ends_on')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function cleanLines(array $lines): array
    {
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            $rate = (string) ($line['agreed_rate'] ?? '');

            if (blank($productId) || $rate === '') {
                continue;
            }

            /*
             * ⛔ চুক্তির দর শূন্যের কম নয়। ⓘ শূন্য চলে — কখনো একটা
             * পণ্য চুক্তিতে বিনামূল্যে দেওয়ার কথা থাকে।
             */
            if (! is_numeric($rate) || bccomp($rate, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.contract_rate_negative'),
                ]);
            }

            /*
             * ⛔ একই চুক্তিতে একই পণ্য দুইবার নয় — ⚠️ থাকলে *"চুক্তির
             * দর কত"* প্রশ্নের দুইটা উত্তর হত।
             */
            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'lines' => __('purchase::validation.contract_duplicate_product'),
                ]);
            }

            $seen[$productId] = true;
            $out[] = [
                'product_id' => $productId,
                'agreed_rate' => $rate,
                'qty_limit' => filled($line['qty_limit'] ?? null) ? (string) $line['qty_limit'] : null,
                'value_limit' => filled($line['value_limit'] ?? null) ? (string) $line['value_limit'] : null,
                'narration' => $line['narration'] ?? null,
            ];
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'lines' => __('purchase::validation.contract_needs_lines'),
            ]);
        }

        return $out;
    }
}
