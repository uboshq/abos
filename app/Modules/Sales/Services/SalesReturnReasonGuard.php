<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Validation\ValidationException;

/**
 * ফেরতের কারণ — একটা দরজা, সব পথের জন্য। NEXUS §২৪।
 *
 * ── ⭐ নিয়ম চারটা ──────────────────────────────────────────────────────
 *   ১. প্রতিটা ফেরতে একটা কারণ — এই কোম্পানির, ফেরতের প্রসঙ্গের, চালু
 *   ২. লাইনে নিজের কারণ ঐচ্ছিক; খালি মানে হেডারেরটাই
 *   ৩. কারণটা নোট চাইলে (`needs_note`, যেমন "অন্যান্য") নোট লাগবে
 *   ৪. কারণটা লট চাইলে (`needs_lot`, যেমন "মেয়াদোত্তীর্ণ") আর পণ্যে লট
 *      ধরা থাকলে — লাইনে লট লাগবে, আর লটটা ঐ পণ্যেরই হতে হবে
 *
 * ── ⛔ কেন সেবায়, কেবল ফর্মের যাচাইয়ে নয় ──────────────────────────────
 * ফেরত ঢোকে তিন পথে: অফিসের পর্দা, কাউন্টার ([[PosService::takeBack()]]),
 * আর সরাসরি সেবা। ⚠️ কাউন্টারের পর্দায় কারণের ঘরই ছিল না — ফর্মে নিয়ম
 * বসালে ঐ পথটা খোলাই থাকত, আর রিপোর্টের বড় সারিটা হত "কারণ নেই"।
 *
 * ── ⓘ কোম্পানির সীমা ────────────────────────────────────────────────
 * [[ReasonCode]] ও [[Batch]] দুইটাই `BelongsToCompany` — অন্য কোম্পানির
 * সারি কোয়েরিতে আসেই না, তাই সেটা "অজানা কারণ" / "এই পণ্যের লট নয়"
 * হয়ে থামে।
 */
final class SalesReturnReasonGuard
{
    /** নোট কমপক্ষে এত অক্ষর — "." বা "x" লিখে পার হওয়া যাবে না। */
    public const NOTE_MIN = 3;

    /**
     * যাচাই করে, আর নোট/লট ছেঁটে ফেরত দেয়।
     *
     * ⓘ শূন্য পরিমাণের লাইন সেবার `replaceLines()` ফেলে দেয়, তাই এখানেও
     * গোনা হয় না — ⛔ নাহলে একটা ফাঁকা সারির জন্য লট চেয়ে বসত। লাইনের
     * নম্বরও তাই কেবল গোনা লাইন ধরে, যেমন কাগজে ছাপা হয়।
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public function check(array $data, array $lines): array
    {
        $header = $this->reason($data['reason_code_id'] ?? null);

        if ($header === null) {
            throw ValidationException::withMessages([
                'reason_code_id' => blank($data['reason_code_id'] ?? null)
                    ? __('sales::return_reason.reason_required')
                    : __('sales::return_reason.unknown_reason'),
            ]);
        }

        $data['reason_code_id'] = $header->id;
        $data['reason_note'] = $this->note($data['reason_note'] ?? null);

        if ($header->needs_note && $data['reason_note'] === null) {
            throw ValidationException::withMessages([
                'reason_note' => __('sales::return_reason.note_required', [
                    'reason' => $header->name(),
                    'min' => self::NOTE_MIN,
                ]),
            ]);
        }

        $number = 0;

        foreach ($lines as $index => $line) {
            if (! $this->counts($line)) {
                continue;
            }

            $number++;
            $lines[$index] = $this->checkLine($line, $header, $number);
        }

        return [$data, array_values($lines)];
    }

    /**
     * রাখা কাগজটা আবার যাচাই — নিশ্চিত করার ঠিক আগে।
     *
     * ⛔ নিয়মটা বসার আগের খসড়াগুলো কারণ ছাড়াই পড়ে আছে। ⚠️ কেবল
     * create/update-এ দেখলে ওগুলো সরাসরি নিশ্চিত হয়ে খাতায় বসত — অর্থাৎ
     * নিয়ম চালুর পরেও "কারণ নেই" সারি জন্মাত।
     */
    public function checkDocument(SalesReturn $return): void
    {
        $return->loadMissing('lines');

        $this->check(
            [
                'reason_code_id' => $return->reason_code_id,
                'reason_note' => $return->reason_note,
            ],
            $return->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'qty' => (string) $line->qty,
                'reason_code_id' => $line->reason_code_id,
                'reason_note' => $line->reason_note,
                'batch_id' => $line->batch_id,
            ])->values()->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function checkLine(array $line, ReasonCode $header, int $number): array
    {
        $own = null;

        if (filled($line['reason_code_id'] ?? null)) {
            $own = $this->reason($line['reason_code_id']);

            if ($own === null) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::return_reason.line_unknown_reason', ['no' => $number]),
                ]);
            }
        }

        $line['reason_code_id'] = $own?->id;
        $line['reason_note'] = $this->note($line['reason_note'] ?? null);

        /*
         * ⓘ লাইনের নিজের কারণ নোট চাইলে নোটটা লাইনেই। ⚠️ হেডারের নোট
         * এখানে চলে না — হেডারের কারণ হয়তো "ক্ষতিগ্রস্ত", আর ওর নোট এই
         * লাইনের "অন্যান্য"-র কিছুই বলে না।
         */
        if ($own !== null && $own->needs_note && $line['reason_note'] === null) {
            throw ValidationException::withMessages([
                'lines' => __('sales::return_reason.line_note_required', [
                    'no' => $number,
                    'reason' => $own->name(),
                    'min' => self::NOTE_MIN,
                ]),
            ]);
        }

        $line['batch_id'] = $this->lot($line, $own ?? $header, $number)?->id;

        return $line;
    }

    /**
     * লাইনের লট — লাগলে বাধ্যতামূলক, দিলে যাচাই করা।
     *
     * @param  array<string, mixed>  $line
     */
    private function lot(array $line, ReasonCode $reason, int $number): ?Batch
    {
        $product = Product::query()->whereKey((int) ($line['product_id'] ?? 0))->first();

        // অজানা পণ্য সেবা নিজেই থামায় — এখানে দ্বিতীয় বার্তা দরকার নেই
        if ($product === null) {
            return null;
        }

        $batchId = $line['batch_id'] ?? null;

        if (blank($batchId)) {
            /*
             * ⭐ লট চায় কেবল তখন, যখন কারণটা চায় **আর** পণ্যে লট ধরা।
             *
             * ⓘ ডিপোর চাল-ডাল-সাবানে লট নেই — ⛔ ওখানে "মেয়াদোত্তীর্ণ"
             * বাছলে লট চাইলে ফেরতটা নেওয়াই যেত না।
             */
            if ($reason->needs_lot && $product->track_batch) {
                throw ValidationException::withMessages([
                    'lines' => __('sales::return_reason.lot_required', [
                        'no' => $number,
                        'reason' => $reason->name(),
                        'product' => $product->name(),
                    ]),
                ]);
            }

            return null;
        }

        /*
         * ⛔ অন্য পণ্যের লট চলে না — নাহলে রিকলের দিন "এই লট কাদের কাছে
         * গেছে" প্রশ্নে একটা ভুল পণ্যের ফেরত উঠে আসত।
         */
        $batch = is_numeric($batchId)
            ? Batch::query()->whereKey((int) $batchId)->where('product_id', $product->id)->first()
            : null;

        if ($batch === null) {
            throw ValidationException::withMessages([
                'lines' => __('sales::return_reason.lot_not_this_product', [
                    'no' => $number,
                    'product' => $product->name(),
                ]),
            ]);
        }

        return $batch;
    }

    /**
     * এই কোম্পানির, ফেরতের প্রসঙ্গের, চালু কারণ — নাহলে null।
     *
     * ⚠️ প্রসঙ্গ দেখা জরুরি: "হারানো বা চুরি" (মজুদ সমন্বয়) ফেরতের কারণ
     * হিসেবে বসলে রিপোর্টে একটা অর্থহীন সারি জন্মাত।
     */
    private function reason(mixed $id): ?ReasonCode
    {
        if (blank($id) || ! is_numeric($id)) {
            return null;
        }

        return ReasonCode::query()
            ->inContext(ReasonCode::SALES_RETURN)
            ->active()
            ->whereKey((int) $id)
            ->first();
    }

    private function note(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));

        return mb_strlen($note) >= self::NOTE_MIN ? $note : null;
    }

    /** @param array<string, mixed> $line */
    private function counts(array $line): bool
    {
        $qty = trim((string) ($line['qty'] ?? ''));

        return filled($line['product_id'] ?? null)
            && is_numeric($qty)
            && bccomp($qty, '0', 4) > 0;
    }
}
