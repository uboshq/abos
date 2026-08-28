<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\FinancialYear;
use App\Modules\Inventory\Models\Production;
use App\Modules\Inventory\Models\ProductionLine;
use App\Modules\Inventory\Models\Recipe;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * হাঁড়ির রান্না — উপকরণ বেরোয়, তৈরি খাবার ঢোকে।
 *
 * ── দুইটা কাজ, আর দুইটাই বিদ্যমান পথে ────────────────────────────────
 * বেরোনোটা `RecipeService::consume()` দিয়ে — সে-ই জানে অপচয় ধরে কতটা
 * লাগে, আর সে নিজেও `StockService`-এর উপরেই বসে।
 *
 * ঢোকানোটা `StockService::move()` + `CostLayerService::receive()` দিয়ে —
 * ঠিক যেভাবে কেনা মাল ঢোকে।
 *
 * এখানে নতুন কোনো স্টকের অঙ্ক নেই, আর সেটাই মূল কথা: রান্না করা খাবার
 * গুদামে ঢোকার পর সে আর দশটা পণ্যের মতোই — একই FIFO, একই ব্যাচ, একই
 * মুভমেন্ট। বিক্রির সময় তার জন্য আলাদা কোনো নিয়ম লাগে না।
 *
 * ── তৈরি খাবারের দর কোথা থেকে ───────────────────────────────────────
 * উপকরণে যত টাকা গেল, ÷ যত প্লেট হলো। নতুন করে কিছু হিসাব করা হয় না —
 * উপকরণগুলো বেরোনোর সময় FIFO স্তর যা বলেছে, সেটাই।
 *
 * রেসিপিতে দর বসালে ৭ আগস্ট ২০২৬-এর ভুলটাই ফিরত: মাল ঢুকত এক দামে,
 * বেরোত আরেক দামে।
 */
final class ProductionService
{
    public function __construct(
        private readonly NumberSeriesEngine $numbers,
        private readonly RecipeService $recipes,
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
    ) {}

    /**
     * খসড়া রান্নার কাগজ — এখনো কিছুই নড়ে না।
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Production
    {
        return DB::transaction(function () use ($data) {
            $recipe = Recipe::query()->with('lines.product')->findOrFail($data['recipe_id']);

            $this->assertCookable($recipe);

            $date = Carbon::parse($data['trx_date'] ?? now());

            return Production::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'financial_year_id' => $this->yearFor($date)?->id,
                'document_no' => $this->numbers->next(Production::NUMBER_SERIES),
                'recipe_id' => $recipe->id,
                'product_id' => $recipe->product_id,
                'warehouse_id' => $data['warehouse_id'] ?? $this->defaultWarehouse()?->id,
                'trx_date' => $date->toDateString(),
                'qty' => $data['qty'],
                'status' => DocumentStatus::DRAFT,
                'narration' => $data['narration'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * নিশ্চিত — এখানেই উপকরণ কমে আর খাবার ঢোকে।
     *
     * ── কেন গোটাটা একটা লেনদেনে ──────────────────────────────────────
     * উপকরণ বেরিয়ে গেছে অথচ তৈরি খাবার ঢোকেনি — এই অবস্থাটা কোনো
     * বাস্তব ঘটনার সাথে মেলে না। গুদামে তখন চাল নেই, বিরিয়ানিও নেই,
     * আর কেউ বলতে পারবে না মালটা কোথায় গেল।
     */
    public function confirm(Production $production): Production
    {
        if ($production->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('inventory::validation.production_not_draft'),
            ]);
        }

        return DB::transaction(function () use ($production) {
            $recipe = $production->recipe()->with('lines.product')->firstOrFail();

            $this->assertCookable($recipe);

            $warehouse = $production->warehouse ?? $this->defaultWarehouse();

            if ($warehouse === null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => __('inventory::validation.production_needs_warehouse'),
                ]);
            }

            $this->assertEnoughIngredients($recipe, $production, $warehouse);

            /*
             * উপকরণ বেরোয় — পরিমাণ `RecipeService` ঠিক করে (অপচয় ধরে),
             * আর দর FIFO স্তর থেকে টানা হয়।
             *
             * দুইটা আলাদা ডাক, কারণ পরিমাণ নাড়ানো আর দর টানা এই
             * প্রকল্পে দুইটা আলাদা খাতা — বিক্রির পথেও তাই।
             */
            $taken = $this->recipes->consume(
                recipe: $recipe,
                servings: (string) $production->qty,
                warehouse: $warehouse,
                sourceType: Production::STOCK_SOURCE,
                sourceId: $production->id,
                date: $production->trx_date,
                documentNo: $production->document_no,
            );

            $total = '0';
            $sort = 0;

            foreach ($taken as $need) {
                $drawn = $this->costs->issue(
                    product: $need['product'],
                    qty: $need['qty'],
                    sourceType: Production::STOCK_SOURCE,
                    sourceId: $production->id,
                    documentNo: $production->document_no,
                    date: $production->trx_date,
                );

                ProductionLine::query()->create([
                    'production_id' => $production->id,
                    'product_id' => $need['product']->id,
                    'qty' => $need['qty'],
                    'cost' => $drawn['cost'],
                    'sort' => $sort++,
                ]);

                $total = bcadd($total, $drawn['cost'], 4);
            }

            $production->forceFill([
                'cost_total' => $total,
                'status' => DocumentStatus::CONFIRMED,
            ])->save();

            /*
             * তৈরি খাবার গুদামে ঢোকে — ঠিক কেনা মালের মতো।
             *
             * পরিমাণ `move()`-এ, দর `receive()`-এ। এর পর ওই খাবার আর
             * দশটা পণ্যের মতোই: বিক্রিতে তার নিজের FIFO স্তরই টানা
             * হবে, আর রেসিপির কোনো ভূমিকা থাকবে না।
             */
            $this->stock->move(
                product: $production->product,
                warehouse: $warehouse,
                sourceType: Production::STOCK_SOURCE,
                sourceId: $production->id,
                floor: (string) $production->qty,
                date: $production->trx_date,
                documentNo: $production->document_no,
            );

            $this->costs->receive(
                product: $production->product,
                qty: (string) $production->qty,
                unitCost: $production->unitCost(),
                sourceType: Production::STOCK_SOURCE,
                sourceId: $production->id,
                documentNo: $production->document_no,
                date: $production->trx_date,
            );

            return $production->fresh(['lines.product', 'product', 'recipe']);
        });
    }

    /**
     * রেসিপিটা সত্যিই রান্না করা যায় কি না।
     *
     * উপকরণহীন রেসিপি দিয়ে রান্না করলে কাগজে বিরিয়ানি ঢুকত আর গুদাম
     * থেকে কিছুই বেরোত না — অর্থাৎ শূন্য থেকে মাল তৈরি হত।
     */
    private function assertCookable(Recipe $recipe): void
    {
        if ($recipe->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'recipe_id' => __('inventory::validation.production_recipe_empty', [
                    'product' => $recipe->product?->name() ?? '',
                ]),
            ]);
        }

        if (bccomp((string) $recipe->yield_qty, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'recipe_id' => __('inventory::validation.production_recipe_no_yield'),
            ]);
        }
    }

    /**
     * গুদামে সব উপকরণ আছে কি না — কমানোর আগেই।
     *
     * ── কেন বার্তাটা খাবারের ভাষায় ──────────────────────────────────
     * `StockService` নিজেও বাধা দিত, কিন্তু বলত "চাল কম"। রাঁধুনি চাল
     * বেচছেন না, বিরিয়ানি রাঁধছেন — তাঁর কাজের প্রশ্ন "আজ কয় প্লেট
     * করা যাবে", আর উত্তরটা সেই ভাষাতেই আসা উচিত।
     */
    private function assertEnoughIngredients(
        Recipe $recipe,
        Production $production,
        Warehouse $warehouse,
    ): void {
        foreach ($this->recipes->needsFor($recipe, (string) $production->qty) as $need) {
            $available = $this->stock->availableQty($need['product'], $warehouse);

            if (bccomp($available, $need['qty'], 4) < 0) {
                throw ValidationException::withMessages([
                    'qty' => __('inventory::validation.production_not_enough', [
                        'product' => $production->product?->name() ?? '',
                        'ingredient' => $need['product']->name(),
                        'available' => rtrim(rtrim($available, '0'), '.'),
                    ]),
                ]);
            }
        }
    }

    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()->where('is_default', true)->active()->first();
    }

    private function yearFor(Carbon $date): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();
    }
}
