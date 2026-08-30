<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\CommissionRule;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\Scheme;
use Illuminate\Support\Collection;

/**
 * কমিশন যেখানে **একবার** হিসাব হয়।
 *
 * ── কেন একটাই জায়গা ──────────────────────────────────────────────────
 * চারটা পর্দায় কমিশনের অঙ্ক দেখাতে হয়: বিলের পাতা, দাবির কাগজ, মাস
 * শেষের রিপোর্ট, আর ডিলারের বিবরণী। দুইটা পথে হিসাব করলে একদিন দুইটা
 * আলাদা উত্তর দেয়, আর পার্থক্যটা আবিষ্কার করেন সেই লোক **যিনি পর্দায়
 * দেখা অঙ্কের চেয়ে কম পেয়েছেন**।
 *
 * ── আগে দেখা আর বসানো একই অঙ্ক ───────────────────────────────────────
 * [[preview()]] হিসাবটা করে, আর দাবির সেবা সেটাই বসায়। "আগে দেখা"
 * আলাদা সরল কোডে করলে সেটা একসময় আসল অঙ্কের সাথে মেলা বন্ধ করে।
 *
 * ── কমিশন ছাড়ের উপর নয়, যা সত্যিই নেওয়া হলো তার উপর ─────────────────
 * ভিত্তি = মোট − ছাড়। মোটের উপর দিলে বিক্রয়কর্মী নিজের দেওয়া ছাড়ের
 * উপরেও কমিশন পেতেন — অর্থাৎ ছাড় দেওয়াটাই তাঁর জন্য লাভজনক হত।
 */
final class CommissionEngine
{
    /**
     * একটা বিলে কে কত পাবে — কিছুই সংরক্ষণ করে না।
     *
     * @return array{
     *   base: string,
     *   lines: list<array{scheme: Scheme, rule: CommissionRule, role: string, level: int, base: string, amount: string}>,
     *   total: string,
     *   reason: ?string
     * }
     */
    public function preview(SalesInvoice $invoice): array
    {
        /*
         * ভিত্তি = বিলের মোট, আর সেটাই ইতিমধ্যে নিট।
         *
         * ---- কেন ছাড় আবার বাদ দেওয়া হয় না, ৩০ আগস্ট ২০২৬ ----
         * প্রথমে লেখা ছিল `total - discount`, DMS-এর গড়ন দেখে। কিন্তু
         * ABOS-এ ছাড় বসে **সারিতে**, আর সারির `amount` ছাড় বাদ দিয়েই
         * হিসাব হয় ([[CalculatesSalesLines::lineFigures()]])। বিলের
         * `total` ওই সারিগুলোরই যোগফল।
         *
         * তাই আবার বাদ দিলে ছাড়টা **দুইবার** কাটত: ১০,০০০ টাকার বিলে
         * ২,০০০ ছাড় দিলে ভিত্তি দাঁড়াত ৬,০০০ — অর্থাৎ বিক্রয়কর্মী
         * প্রাপ্যের চেয়ে কম পেতেন, আর কেউ ধরতে পারত না কেন।
         *
         * টেস্ট ধরেছে প্রথমবারেই, কারণ অঙ্কটা ৮,০০০-এর বদলে ৬,০০০ এল।
         */
        $base = (string) $invoice->total;

        if (bccomp($base, '0', 4) <= 0) {
            return $this->nothing($base, __('sales::message.commission_nothing_charged'));
        }

        $on = $invoice->trx_date;

        $schemes = Scheme::query()
            ->liveOn($on)
            ->with(['rules' => fn ($q) => $q->orderBy('level_order')->orderBy('slab_from')])
            ->orderBy('code')
            ->get();

        if ($schemes->isEmpty()) {
            return $this->nothing($base, __('sales::message.commission_no_scheme', [
                'date' => DateFormat::format($on),
            ]));
        }

        $lines = [];

        foreach ($schemes as $scheme) {
            /*
             * এই স্কিমটা এই বিলের কতটুকুর উপর খাটে।
             *
             * ---- কেন প্রতিটা স্কিমের নিজের ভিত্তি ----
             * একটা স্কিম যদি একটা ব্র্যান্ডের দিকে তাক করা হয়, আর বিলে
             * তিনটা ব্র্যান্ড থাকে, তাহলে পুরো বিলের উপর টাকা দেওয়া
             * মানে বাকি দুইটা ব্র্যান্ডের জন্যও দেওয়া। DMS-এ ঠিক এই
             * ভুলটা ছিল, আর ধরা পড়েছিল ছয়টা লক্ষ্যের তিনটা কোনোদিন
             * কাজই করেনি বলে।
             *
             * শূন্য মানে এই বিলে এই স্কিমের কিছু নেই — টাকা দেওয়া হয় না।
             */
            $schemeBase = $this->baseFor($scheme, $invoice, $base);

            if (bccomp($schemeBase, '0', 4) <= 0) {
                continue;
            }

            foreach ($this->rulesFor($scheme, $schemeBase) as $rule) {
                $amount = $rule->amountOn($schemeBase);

                if (bccomp($amount, '0', 4) <= 0) {
                    continue;
                }

                $lines[] = [
                    'scheme' => $scheme,
                    'rule' => $rule,
                    'role' => (string) $rule->earner_role,
                    'level' => (int) $rule->level_order,
                    'base' => $schemeBase,
                    'amount' => $amount,
                ];
            }
        }

        if ($lines === []) {
            return $this->nothing($base, __('sales::message.commission_no_rule_matched'));
        }

        $total = array_reduce(
            $lines,
            fn (string $sum, array $l) => bcadd($sum, $l['amount'], 4),
            '0',
        );

        return ['base' => $base, 'lines' => $lines, 'total' => $total, 'reason' => null];
    }

    /**
     * এই স্কিমটা এই বিলের কোন অঙ্কের উপর খাটে।
     *
     * ── কেন পরিমাণ আর টাকা আলাদা ────────────────────────────────────
     * "প্রতি বস্তায় ২০ টাকা" আর "বিক্রয়ের ২%" — দুইটা আলাদা প্রশ্ন।
     * পরিমাণ-ভিত্তিক স্কিমে ধাপগুলোও বস্তায় গোনা হয়, টাকায় নয়; এক
     * করে ফেললে "পাঁচশো বস্তার উপরে" ধাপটা টাকার অঙ্কের সাথে মিলিয়ে
     * দেখা হত আর সবসময়ই সবচেয়ে উঁচু ধাপে পড়ত।
     */
    private function baseFor(Scheme $scheme, SalesInvoice $invoice, string $wholeBill): string
    {
        $lines = $this->linesOf($scheme, $invoice);

        if ($scheme->basis === Scheme::VOLUME) {
            return $lines->reduce(
                fn (string $sum, $l) => bcadd($sum, (string) $l->qty, 4),
                '0',
            );
        }

        /* গোটা বিলের স্কিমে সারি ধরে যোগ করার দরকার নেই — ছাড়সহ অঙ্কটাই ঠিক */
        if ($scheme->applies_to === Scheme::ALL) {
            return $wholeBill;
        }

        return $lines->reduce(
            fn (string $sum, $l) => bcadd($sum, (string) $l->amount, 4),
            '0',
        );
    }

    /**
     * বিলের যে সারিগুলো এই স্কিমের আওতায়।
     *
     * ── কেন এলাকা আর ডিলারের স্তর সারি ছাঁকে না ─────────────────────
     * ওই দুইটা **ক্রেতাকে** দেখে, পণ্যকে নয়। শর্তটা মিললে গোটা বিলই
     * আওতায়; না মিললে কিছুই নয়। পণ্য ধরে ছাঁকতে গেলে প্রতিটা সারির
     * সাথে এলাকার তুলনা করতে হত, যার কোনো মানে নেই।
     *
     * @return Collection<int, SalesInvoiceLine>
     */
    private function linesOf(Scheme $scheme, SalesInvoice $invoice): Collection
    {
        $lines = $invoice->relationLoaded('lines')
            ? $invoice->lines
            : $invoice->lines()->get();

        if ($scheme->applies_to === Scheme::ALL) {
            return $lines;
        }

        if (in_array($scheme->applies_to, [Scheme::TERRITORY, Scheme::DEALER_TIER], true)) {
            return $this->buyerMatches($scheme, $invoice) ? $lines : $lines->take(0);
        }

        $products = Product::query()
            ->whereIn('id', $lines->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $lines->filter(function ($line) use ($scheme, $products) {
            $product = $products->get($line->product_id);

            if ($product === null) {
                return false;
            }

            return match ($scheme->applies_to) {
                Scheme::PRODUCT => (int) $product->id === (int) $scheme->target_id,
                Scheme::CATEGORY => (int) $product->category_id === (int) $scheme->target_id,
                Scheme::BRAND => (int) $product->brand_id === (int) $scheme->target_id,
                default => false,
            };
        })->values();
    }

    /**
     * ক্রেতার দিক থেকে শর্তটা মেলে কি না।
     *
     * ── কেন না-জানা মানে "না" ───────────────────────────────────────
     * ক্রেতার এলাকা বসানো না থাকলে স্কিমটা খাটে কি না বলা যায় না।
     * সন্দেহে টাকা দিয়ে দিলে ভুল টাকাটা ফেরত আনতে হয়, আর সেটা
     * কারও কাছ থেকে টাকা ফেরত চাওয়া — না দিলে কেবল একটা প্রশ্ন ওঠে।
     */
    private function buyerMatches(Scheme $scheme, SalesInvoice $invoice): bool
    {
        $customer = $invoice->relationLoaded('customer')
            ? $invoice->customer
            : $invoice->customer()->first();

        if ($customer === null || $scheme->target_id === null) {
            return false;
        }

        return match ($scheme->applies_to) {
            Scheme::TERRITORY => (int) ($customer->location_id ?? 0) === (int) $scheme->target_id,
            Scheme::DEALER_TIER => (int) ($customer->party_type_id ?? 0) === (int) $scheme->target_id,
            default => false,
        };
    }

    /**
     * এই ভিত্তির জন্য প্রতিটা ভূমিকার যে একটা নিয়ম খাটে।
     *
     * ── কেন ভূমিকা ধরে একটাই ────────────────────────────────────────
     * সিঁড়ির ধাপগুলো পরস্পরকে বাদ দেয় — একটা অঙ্ক একটাই ধাপে পড়ে।
     * কিন্তু কেউ ভুল করে ঢাকাঢাকি ধাপ লিখলে (০–৫ লাখ আর ৩–৮ লাখ)
     * একই ভূমিকা দুইবার টাকা পেত। তাই ভূমিকা ধরে প্রথম মেলা নিয়মটাই
     * নেওয়া হয়, আর ক্রমটা নিচের ধাপ থেকে উপরে।
     *
     * @return list<CommissionRule>
     */
    private function rulesFor(Scheme $scheme, string $base): array
    {
        $picked = [];

        foreach ($scheme->rules as $rule) {
            $role = (string) $rule->earner_role;

            if (isset($picked[$role]) || ! $rule->covers($base)) {
                continue;
            }

            $picked[$role] = $rule;
        }

        return array_values($picked);
    }

    /** @return array{base: string, lines: list<never>, total: string, reason: string} */
    private function nothing(string $base, string $reason): array
    {
        return ['base' => $base, 'lines' => [], 'total' => '0', 'reason' => $reason];
    }

    /**
     * এই কোম্পানিতে ভূমিকার যে নামগুলো ব্যবহার হয়েছে।
     *
     * ── কেন হাতে লেখা তালিকা নয় ────────────────────────────────────
     * প্রতিটা পরিবেশক নিজের মতো নাম দেয় — কারও SR, কারও "বিক্রয়
     * প্রতিনিধি", কারও দালাল। কোরে একটা enum বসালে যাঁর নাম তালিকায়
     * নেই তিনি স্কিমই বানাতে পারতেন না ([[project_open_configurable_lists]])।
     *
     * @return list<string>
     */
    public function rolesUsed(): array
    {
        return CommissionRule::query()
            ->where('company_id', CompanyContext::id())
            ->distinct()
            ->orderBy('earner_role')
            ->pluck('earner_role')
            ->all();
    }
}
