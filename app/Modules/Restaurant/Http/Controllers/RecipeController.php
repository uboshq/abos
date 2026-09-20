<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers;

use App\Core\Concerns\AuthorizesResource;
use App\Core\Concerns\SortsLists;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Restaurant\Http\Requests\RecipeRequest;
use App\Modules\Restaurant\Models\Production;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RecipeLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * রেসিপি — কোন খাবার কী দিয়ে তৈরি।
 *
 * ── কেন এটা ইনভেন্টরিতে, বিক্রয়ে নয় ─────────────────────────────────
 * রেসিপি বিক্রির কথা নয়, **স্টকের** কথা। একই রেসিপি বিক্রিতে লাগে,
 * উৎপাদনে লাগে, খরচের রিপোর্টে লাগে। বিক্রয়ে রাখলে উৎপাদনকে বিক্রয়ের
 * উপর নির্ভর করতে হত — অথচ হাঁড়ি চড়ানোর সাথে বিক্রির সম্পর্ক নেই।
 */
class RecipeController extends Controller implements HasMiddleware
{
    use AuthorizesResource;
    use SortsLists;

    public function __construct(
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            ...static::resourcePermissions(Recipe::class, 'recipe'),

            /*
             * আবার সচল করাও নিষ্ক্রিয় করার মতোই ক্ষমতা।
             *
             * একটা রেসিপি চালু করা মানে **আজ থেকে ওটাই স্টক কমাবে** —
             * অর্থাৎ ফলটা নিষ্ক্রিয় করার সমানই বড়। গুদাম ও পণ্যেও
             * একই নিয়ম।
             *
             * `resourcePermissions()` কেবল সাতটা চেনা কাজ চেনে
             * (index/create/store/edit/update/destroy/show); `activate`
             * তার বাইরে, তাই আলাদা করে বলতে হয় — নাহলে ঠিকানা টাইপ
             * করলেই যে কেউ রেসিপি চালু করে দিতে পারতেন।
             */
            new Middleware('can:delete,recipe', only: ['activate']),
        ];
    }

    public function index(Request $request): View
    {
        $query = Recipe::query()
            ->with(['product', 'lines'])
            ->when(! $request->boolean('inactive'), fn ($q) => $q->where('is_active', true))
            ->when((string) $request->query('kind') !== '' && in_array($request->query('kind'), Recipe::KINDS, true),
                fn ($q) => $q->where('kind', $request->query('kind')))
            /*
             * খোঁজাটা খাবারের নামে — কারণ মানুষ ওটাই মনে রাখেন।
             *
             * উপকরণ ধরে খোঁজা আরও কাজের হত ("চাল কোন কোন রেসিপিতে"),
             * কিন্তু ওটা আলাদা একটা প্রশ্ন, আর তার উত্তর দেবে খাদ্য-খরচের
             * রিপোর্ট। এক ঘরে দুই প্রশ্ন মেশালে কোনোটারই উত্তর পরিষ্কার হত না।
             */
            ->when((string) $request->query('q') !== '', function ($q) use ($request) {
                $term = '%'.$request->query('q').'%';

                $q->whereHas('product', fn ($p) => $p
                    ->where('name_en', 'like', $term)
                    ->orWhere('name_bn', 'like', $term)
                    ->orWhere('code', 'like', $term));
            });

        $sort = $this->applySort($query, $request, [
            'recent' => fn ($q) => $q->orderByDesc('id'),
            'dish' => fn ($q) => $q->orderBy(
                Product::query()->select('name_en')->whereColumn('inv_products.id', 'inv_recipes.product_id')
            ),
        ]);

        return view('restaurant::recipe.index', [
            'menu' => $this->menu->forUser($request->user()),
            'recipes' => $query->paginate(50)->withQueryString(),
            'q' => $request->query('q'),
            'kind' => $request->query('kind'),
            'showInactive' => $request->boolean('inactive'),
            'sort' => $sort,
            'sortOptions' => [
                'recent' => __('inventory::sort.recent'),
                'dish' => __('inventory::sort.dish'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('restaurant::recipe.form', [
            'menu' => $this->menu->forUser($request->user()),
            'recipe' => new Recipe(['kind' => Recipe::TO_ORDER, 'yield_qty' => '1', 'is_active' => true]),
            ...$this->options(),
        ]);
    }

    public function store(RecipeRequest $request): RedirectResponse
    {
        $recipe = $this->save(new Recipe, $request);

        return redirect()
            ->route('restaurant.recipe.edit', $recipe)
            ->with('saved', __('inventory::message.recipe_saved'));
    }

    public function edit(Request $request, Recipe $recipe): View
    {
        return view('restaurant::recipe.form', [
            'menu' => $this->menu->forUser($request->user()),
            'recipe' => $recipe->load('lines.product'),
            ...$this->options(),
        ]);
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        $this->save($recipe, $request);

        return redirect()
            ->route('restaurant.recipe.edit', $recipe)
            ->with('saved', __('inventory::message.recipe_saved'));
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয়।
     *
     * পুরনো বিক্রির ইতিহাস এই রেসিপির দিকে দেখায়। মুছে ফেললে "ওই দিন
     * কী দিয়ে বানানো হয়েছিল" প্রশ্নের উত্তর হারাত (নিয়ম ৫)।
     */
    public function destroy(Recipe $recipe): RedirectResponse
    {
        $recipe->forceFill(['is_active' => false])->save();

        return redirect()
            ->route('restaurant.recipe.index')
            ->with('saved', __('inventory::message.recipe_deactivated'));
    }

    public function activate(Recipe $recipe): RedirectResponse
    {
        $recipe->forceFill(['is_active' => true])->save();

        return redirect()
            ->route('restaurant.recipe.index')
            ->with('saved', __('inventory::message.recipe_activated'));
    }

    /**
     * রেসিপি ও তার লাইনগুলো — একসাথে, একটা লেনদেনে।
     *
     * ── কেন লাইনগুলো মুছে আবার লেখা হয় ──────────────────────────────
     * ফর্মটা গোটা রেসিপি পাঠায়, আংশিক বদল নয়। কোন লাইন বদলেছে, কোনটা
     * মুছেছে, কোনটা নতুন — সেটা মিলিয়ে দেখতে গেলে কোডটা জটিল হত আর
     * একটা ভুলে একটা লাইন থেকে যেত।
     *
     * মুছে-আবার-লেখায় অডিটে কিছু হারায় না: `IsAudited` সৃষ্টি ও
     * মোছাও লেখে, তাই "কে কখন উপকরণটা বাদ দিল" প্রশ্নের উত্তর থাকে।
     *
     * ── কেন লেনদেন ──────────────────────────────────────────────────
     * পুরনো লাইনগুলো মুছে ফেলার পর নতুনগুলো লিখতে গিয়ে ব্যর্থ হলে
     * রেসিপিটা **উপকরণহীন** হয়ে বসত — আর তখন ওই খাবার বেচলে কিছুই
     * কমত না।
     */
    private function save(Recipe $recipe, RecipeRequest $request): Recipe
    {
        $data = $request->validated();

        /*
         * ⛔ রান্না হয়ে গেলে ধরন আর পদ বদলানো যায় না — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ কী ঘটত ────────────────────────────────────────────────
         * হাঁড়ির রেসিপি (`batch`) রান্নার মুহূর্তেই উপকরণ কাটে। সেটা
         * পরে `to_order` করলে [[SalesInvoiceService]] **প্রতিটা বিক্রয়েও**
         * আবার কাটত — চাল সকালে একবার গেছে, বিক্রয়ে আবার যায়। ⛔ মজুদ
         * তখন কমতেই থাকত, আর কেউ কারণ খুঁজে পেত না।
         *
         * ⓘ উপকরণের সারি বদলানো যায় — রাঁধুনী পরিমাণ শোধরান, আর সেটা
         * পুরনো কাগজ বদলায় না। ⛔ কেবল ধরন আর কোন পদ, এই দুইটাই আটকানো।
         */
        if ($recipe->exists) {
            $this->assertStillFree($recipe, $data);
        }

        return DB::transaction(function () use ($recipe, $data) {
            $recipe->fill([
                'product_id' => $data['product_id'],
                'kind' => $data['kind'],
                'yield_qty' => $data['yield_qty'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'note' => $data['note'] ?? null,
            ])->save();

            $recipe->lines()->delete();

            foreach (array_values($data['lines']) as $i => $line) {
                RecipeLine::query()->create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $line['product_id'],
                    'qty' => $line['qty'],
                    'waste_pct' => $line['waste_pct'] ?? '0',
                    'sort' => $i,
                ]);
            }

            return $recipe->fresh(['lines.product', 'product']);
        });
    }

    /**
     * এই রেসিপি কি এখনও অব্যবহৃত — ধরন ও পদ বদলানোর আগে।
     *
     * ⓘ রান্না হয়ে গেলে (উৎপাদনের সারি আছে) দুইটাই আটকে যায়।
     * ⚠️ বাতিল উৎপাদন গোনা হয় না: ওগুলোর উপকরণ ফেরত দেওয়া হয়েছে,
     * তাই ওরা মজুদের উপর আর কোনো দাবি রাখে না।
     *
     * @param  array<string, mixed>  $data
     */
    private function assertStillFree(Recipe $recipe, array $data): void
    {
        $changesKind = (string) $data['kind'] !== (string) $recipe->kind;
        $changesDish = (int) $data['product_id'] !== (int) $recipe->product_id;

        if (! $changesKind && ! $changesDish) {
            return;
        }

        $cooked = Production::query()
            ->where('recipe_id', $recipe->id)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();

        if (! $cooked) {
            return;
        }

        throw ValidationException::withMessages([
            $changesKind ? 'kind' : 'product_id' => __('restaurant::validation.recipe_in_use'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'products' => Product::query()->active()->with('unit')->orderBy('name_en')->get(),
            'kinds' => [
                Recipe::TO_ORDER => __('inventory::field.recipe_to_order'),
                Recipe::BATCH => __('inventory::field.recipe_batch'),
            ],
        ];
    }
}
