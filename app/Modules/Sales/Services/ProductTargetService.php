<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Http\Requests\PlainNumber;
use App\Modules\Sales\Models\ProductTarget;
use App\Modules\Sales\Reports\ProductInsightReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পণ্যের মাসিক বিক্রির লক্ষ্য — বসানো আর মেলানো (মালিক, ২৩ নভেম্বর ২০২৬, সমন্বয়কের মারফত: পণ্য ধরে লক্ষ্য বনাম বিক্রয়)।
 *
 * প্রতি পণ্য প্রতি মাসে দুই সংখ্যা: লক্ষ্যের **পরিমাণ** আর লক্ষ্যের **টাকা** — যেকোনো একটা বা দুটোই। দুটোই ফাঁকা বা ০ মানে ঐ মাসে ঐ পণ্যের
 * লক্ষ্য নেই (সারিটা মোছা হয়)।
 *
 * ── অর্জন ───────────────────────────────────────────────────────────────────
 * হাতে লেখা নয় — পণ্য ধরে বিক্রির একই সারি থেকে ([[ProductInsightReports::rows()]]): খাতায় বসা বিলের লাইন, ভ্যাট বাদ, বিলের ছাড়ের
 * ভাগসহ, বাদ ফেরত (ফেরতের দিনে)। তাই এই পাতার অর্জন আর "লক্ষ্য বনাম বিক্রয় — পণ্য" রিপোর্টের অঙ্ক কখনো আলাদা হয় না।
 * ⓘ লক্ষ্য গোটা কোম্পানির (শাখা ধরে ভাগ নেই), তাই অর্জনও গোটা কোম্পানির — হেডারে বাছা শাখা এখানে খাটে না।
 */
final class ProductTargetService
{
    public function readMonth(?string $raw): Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return Carbon::today()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen(trim($raw)) === 7 ? trim($raw).'-01' : $raw)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    /**
     * এক মাসের লক্ষ্যগুলো বসানো — দুই ঘরই ফাঁকা বা ০ মানে ঐ পণ্যের ঐ মাসের লক্ষ্য তুলে দেওয়া।
     *
     * @param  array<int|string, array{qty?: mixed, amount?: mixed}>  $rows  পণ্যের id → লক্ষ্যের পরিমাণ আর টাকা
     */
    public function setForMonth(Carbon $month, array $rows): void
    {
        $month = $month->copy()->startOfMonth();

        if ($month->greaterThan(Carbon::today()->startOfMonth()->addYears(2))) {
            throw ValidationException::withMessages(['month' => __('sales::product_target.month_too_far')]);
        }

        DB::transaction(function () use ($month, $rows): void {
            foreach ($rows as $productId => $row) {
                $this->setOne((int) $productId, $month, $row['qty'] ?? null, $row['amount'] ?? null);
            }
        });
    }

    /** একটা পণ্যের এক মাস — ইমপোর্ট আর পাতা দুইটাই এই পথে, একই যাচাই। */
    public function setOne(int $productId, Carbon $month, mixed $qty, mixed $amount): void
    {
        $month = $month->copy()->startOfMonth();
        $qty = $this->number($qty, 'qty');
        $amount = $this->number($amount, 'amount');

        if (bccomp($qty, '0', 4) === 0 && bccomp($amount, '0', 4) === 0) {
            ProductTarget::query()->where('product_id', $productId)->whereDate('month', $month->toDateString())->delete();

            return;
        }

        // ⛔ id-টা এই কোম্পানির না-মোছা পণ্যের — পাতার `target[...]` চাবি সরাসরি id, যাচাই ছাড়া অন্য কোম্পানির পণ্যে লক্ষ্য বসে যেত
        if (! Product::query()->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['target' => __('sales::product_target.unknown_product')]);
        }

        ProductTarget::query()->updateOrCreate(
            ['company_id' => CompanyContext::id(), 'product_id' => $productId, 'month' => $month->toDateString()],
            ['qty' => $qty, 'amount' => $amount, 'created_by' => Auth::id() !== null && Auth::user() instanceof \App\Models\User ? Auth::id() : null],
        );
    }

    /**
     * লক্ষ্যের পাতার সারি — প্রতি সচল পণ্য এক সারি, লক্ষ্য আর অর্জনসহ (লক্ষ্য আছে এমন অচল পণ্যও, যাতে বসানো লক্ষ্য হারিয়ে না যায়)।
     *
     * @return list<array{product: Product, qty: ?string, amount: ?string, sold_qty: string, sold_amount: string}>
     */
    public function board(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $to = Carbon::today()->lt($month->copy()->endOfMonth()) ? Carbon::today() : $month->copy()->endOfMonth();

        $targets = ProductTarget::query()->whereDate('month', $month->toDateString())->get()->keyBy('product_id');
        $products = Product::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $targets->keys()->all()))
            ->orderBy('code')->get(['id', 'code', 'name_en', 'name_bn']);
        $sold = $to->lt($month) ? collect() : $this->sold($month, $to);

        return $products->map(fn (Product $p) => [
            'product' => $p,
            'qty' => isset($targets[$p->id]) ? (string) $targets[$p->id]->qty : null,
            'amount' => isset($targets[$p->id]) ? (string) $targets[$p->id]->amount : null,
            'sold_qty' => bcadd((string) ($sold[$p->id]->qty ?? '0'), '0', 4),
            'sold_amount' => bcadd((string) ($sold[$p->id]->net_sales ?? '0'), '0', 4),
        ])->values()->all();
    }

    /**
     * পণ্য ধরে বিক্রি — রিপোর্টের একই সারি, গোটা কোম্পানির (লক্ষ্য কোম্পানির, তাই শাখা বা ডিলারের দেয়াল এখানে নয়)।
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function sold(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return ProductInsightReports::rows([
            'company_id' => (int) CompanyContext::id(), 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'branch_id' => null, 'branch_ids' => null, 'branch_nulls' => false, 'dealer_walled' => false,
        ])->get()->keyBy('product_id');
    }

    /** একটা ঘরের সংখ্যা — ফাঁকা মানে ০; ⛔ কেবল সাধারণ দশমিক (`1e5` bcmath-এ ValueError ছোড়ে) আর ঋণাত্মক নয় */
    private function number(mixed $value, string $field): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '0.0000';
        }

        if (! PlainNumber::is($value) || ! is_numeric($value) || bccomp($value, '0', 4) < 0) {
            throw ValidationException::withMessages([$field => __('sales::product_target.number_invalid')]);
        }

        return bcadd($value, '0', 4);
    }
}
