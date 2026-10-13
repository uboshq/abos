<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Models\PromotionBenefit;
use App\Modules\Promotion\Models\PromotionCondition;
use App\Modules\Promotion\Models\PromotionScope;
use App\Modules\Promotion\Services\PromotionSalesOffers;
use App\Modules\Promotion\Support\PromotionType;
use App\Modules\Promotion\Support\ScopeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * ⭐ ফোনে চলতি অফার — SR দোকানে দাঁড়িয়ে দেখেন কোন পণ্যে কী ছাড়, কী ফ্রি, কবে পর্যন্ত (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
 *
 * `GET /api/v1/promotion/offers`
 *
 * ⓘ "চলতি" মানে বিক্রির ইঞ্জিনের একই প্রশ্ন — [[Promotion::scopeLiveOn()]] (অবস্থা চালু, আজ মেয়াদের ভিতরে, প্রথম আর শেষ দিনে
 * ঘড়িও); কোম্পানি বা এই শাখায় প্রচার বন্ধ থাকলে কিছুই নয় ([[PromotionSalesOffers::enabled()]], চালানের একই সুইচ)।
 * ⛔ কুপনের অফার আসে না — ওটা কোড ছাড়া কারও নয় (ইঞ্জিনও কোড ছাড়া ছোঁয় না, [[PromotionEngine::offersFor()]])।
 *
 * ⛔ দেয়াল, ইঞ্জিনের [[PromotionEngine::scopeFits()]]-এর মতো "এক দিকে সারি থাকলে মিলতে হবে":
 *   · শাখায় বাঁধা অফার কেবল সেই শাখা দেখার সময় (সব শাখা দেখলে সবগুলো);
 *   · নির্দিষ্ট দোকানের অফার কেবল তখন, যখন তার অন্তত একটা দোকান এই মানুষের চোখে পড়ে — দেখার শাখা আর বিক্রয়কর্মীর বাঁধা
 *     ডিলার ([[ScopedToUserDealers]]); আর নামও কেবল সেই দোকানগুলোর। অন্যের দোকানের নাম ফোনে আসে না।
 *
 * ⓘ নিয়মটা কাঠামো হয়ে যায় (শর্ত, সুবিধা, পণ্যের নাম) — বাংলা বাক্য ফোন বানায়, তাই ব্যবহারকারীর ভাষা ইংরেজি হলেও
 * অফারের কথা বাংলায় পড়া যায়।
 */
final class PromotionApiController extends Controller implements HasMiddleware
{
    /**
     * একসাথে চলা অফার কয়টা পর্যন্ত — চলতি অফার মেয়াদে বাঁধা, জমে না (পুরনো সব ওয়েবের তালিকায়)।
     * ⓘ পাতা ভাগ নয়: শাখা আর দোকানের দেয়াল PHP-তে বসে, তাই পাতা ভাগ করলে পাতার সারি অসমান হত।
     */
    private const LIMIT = 200;

    public function __construct(private readonly PromotionSalesOffers $sales) {}

    public static function middleware(): array
    {
        // ⓘ ওয়েবের তালিকার একই চাবি ([[PromotionController::middleware()]]) — মাঠের SR-এর ছাঁচে আছে
        return [new Middleware('can:promotion.view')];
    }

    public function offers(): JsonResponse
    {
        if (! $this->sales->enabled()) {
            return response()->json(['enabled' => false, 'rows' => []]);
        }

        $offers = Promotion::query()->liveOn(now())
            ->where('type', '!=', PromotionType::COUPON->value)
            ->with(['scopes', 'conditions.benefits.giftProduct', 'benefits.giftProduct'])
            ->orderBy('ends_on')
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        $names = $this->namesFor($offers);
        $branch = CompanyContext::branchId();

        $rows = $offers
            ->filter(fn (Promotion $p) => $this->reaches($p, $branch, $names))
            ->map(fn (Promotion $p) => $this->facts($p, $names))
            ->values();

        return response()->json(['enabled' => true, 'rows' => $rows]);
    }

    /**
     * এই মানুষটার কাছে অফারটা পৌঁছায় কি না — শাখা আর দোকানের দেয়াল।
     *
     * @param  array<string, array<int, string>>  $names
     */
    private function reaches(Promotion $promotion, ?int $branch, array $names): bool
    {
        $byKind = $promotion->scopes->groupBy(fn (PromotionScope $s) => $s->kind->value);

        $branches = $byKind->get(ScopeKind::BRANCH->value);
        if ($branches !== null && $branch !== null
            && ! $branches->contains(fn (PromotionScope $s) => (int) $s->target_id === $branch)) {
            return false;
        }

        $shops = $byKind->get(ScopeKind::CUSTOMER->value);
        if ($shops !== null
            && ! $shops->contains(fn (PromotionScope $s) => isset($names[ScopeKind::CUSTOMER->value][(int) $s->target_id]))) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, array<int, string>>  $names
     * @return array<string, mixed>
     */
    private function facts(Promotion $promotion, array $names): array
    {
        $targets = function (ScopeKind $kind) use ($promotion, $names): array {
            return $promotion->scopes
                ->filter(fn (PromotionScope $s) => $s->kind === $kind)
                ->map(fn (PromotionScope $s) => $names[$kind->value][(int) $s->target_id] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();
        };

        // ⓘ শর্তের নিচে বসা সুবিধা শর্তের সাথে; শর্ত ছাড়া সুবিধা আলাদা সারিতে (শর্তহীন — সবসময়)
        $steps = $promotion->conditions->map(fn (PromotionCondition $c) => [
            'kind' => $c->kind->value,
            'from' => $this->plain($c->value_from),
            'to' => $this->plain($c->value_to),
            'product' => $c->target_id === null ? null : ($names[ScopeKind::PRODUCT->value][(int) $c->target_id] ?? null),
            'from_time' => $c->from_time === null ? null : substr((string) $c->from_time, 0, 5),
            'to_time' => $c->to_time === null ? null : substr((string) $c->to_time, 0, 5),
            'benefits' => $c->benefits->map(fn (PromotionBenefit $b) => $this->benefit($b))->values(),
        ]);
        $loose = $promotion->benefits->whereNull('promotion_condition_id')->map(fn (PromotionBenefit $b) => $this->benefit($b))->values();

        return [
            'id' => (string) $promotion->public_id,
            'code' => (string) $promotion->code,
            'name' => (string) (filled($promotion->name_bn) ? $promotion->name_bn : $promotion->name_en),
            'summary' => (string) ($promotion->summary ?? ''),
            'type' => $promotion->type->value,
            'starts_on' => $promotion->starts_on?->toDateString(),
            'ends_on' => $promotion->ends_on?->toDateString(),
            'ends_at' => $promotion->ends_at === null ? null : substr((string) $promotion->ends_at, 0, 5),
            'products' => $targets(ScopeKind::PRODUCT),
            'categories' => $targets(ScopeKind::CATEGORY),
            'brands' => $targets(ScopeKind::BRAND),
            'shops' => $targets(ScopeKind::CUSTOMER),
            'party_types' => $targets(ScopeKind::PARTY_TYPE),
            'areas' => $targets(ScopeKind::LOCATION),
            'steps' => $steps->values(),
            'benefits' => $loose,
        ];
    }

    /** @return array<string, mixed> */
    private function benefit(PromotionBenefit $b): array
    {
        return [
            'kind' => $b->kind->value,
            'amount' => $this->plain($b->amount),
            'gift' => $b->giftProduct === null ? null : $this->productName($b->giftProduct),
            'cap' => $this->plain($b->cap_per_bill),
        ];
    }

    /**
     * এক ডাকে প্রতিটা ধরনের নাম — অফারের সংখ্যায় কোয়েরি বাড়ে না।
     *
     * ⛔ দোকানের নাম কেবল এই মানুষের চোখে পড়া দোকানের — দেখার শাখা আর বাঁধা ডিলারের দেয়াল ([[Customer::scopeInViewedBranch()]]
     * আর মডেলের নিজের স্কোপ)।
     *
     * @param  Collection<int, Promotion>  $offers
     * @return array<string, array<int, string>>
     */
    private function namesFor(Collection $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            foreach ($offer->scopes as $scope) {
                $ids[$scope->kind->value][] = (int) $scope->target_id;
            }
            foreach ($offer->conditions as $condition) {
                if ($condition->target_id !== null) {
                    $ids[ScopeKind::PRODUCT->value][] = (int) $condition->target_id;
                }
            }
        }

        $read = fn (string $kind, callable $query): array => empty($ids[$kind]) ? [] : $query(array_values(array_unique($ids[$kind])));
        $label = fn (Model $m): string => method_exists($m, 'name') ? (string) $m->name('bn') : '';

        return [
            ScopeKind::PRODUCT->value => $read(ScopeKind::PRODUCT->value, fn (array $in) => Product::query()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (Product $p) => [(int) $p->id => $this->productName($p)])->all()),
            ScopeKind::CATEGORY->value => $read(ScopeKind::CATEGORY->value, fn (array $in) => ProductCategory::query()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (ProductCategory $c) => [(int) $c->id => $label($c)])->all()),
            ScopeKind::BRAND->value => $read(ScopeKind::BRAND->value, fn (array $in) => Brand::query()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (Brand $b) => [(int) $b->id => $label($b)])->all()),
            ScopeKind::PARTY_TYPE->value => $read(ScopeKind::PARTY_TYPE->value, fn (array $in) => PartyType::query()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (PartyType $t) => [(int) $t->id => $label($t)])->all()),
            ScopeKind::LOCATION->value => $read(ScopeKind::LOCATION->value, fn (array $in) => Location::query()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (Location $l) => [(int) $l->id => $label($l)])->all()),
            ScopeKind::CUSTOMER->value => $read(ScopeKind::CUSTOMER->value, fn (array $in) => Customer::query()->inViewedBranch()->whereIn('id', $in)->get()
                ->mapWithKeys(fn (Customer $c) => [(int) $c->id => (string) $c->name()])->all()),
        ];
    }

    private function productName(Product $product): string
    {
        return $product->name('bn');
    }

    /** ১০.০০০০ → "১০" নয়, "10" — সংখ্যা ফোন সাজায়; কেবল শেষের শূন্য কাটা */
    private function plain(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
