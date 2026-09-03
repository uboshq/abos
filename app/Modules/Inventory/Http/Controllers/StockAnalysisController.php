<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\StockFacts;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মরা · ধীর · দ্রুত চলা মাল — ড্যাশবোর্ডের সংখ্যার পেছনের তালিকা।
 *
 * ড্যাশবোর্ডে "ধীরগতি ৫" ছিল, কিন্তু ক্লিক করলে গোটা স্টক তালিকায় যেত —
 * সংখ্যাটা বিশ্বাস করতে হত, যাচাই করা যেত না। মালিকের স্থায়ী নিয়ম:
 * প্রতিটা সংখ্যা তার উৎসে নিয়ে যাবে। এই পর্দাটা সেই উৎস।
 *
 * ⚠️ গণনা এখানে নতুন করে লেখা হয় না — StockFacts-এর একই সংজ্ঞা
 * (slowMoving/nonMoving/fastMoving আর তাদের …List জোড়া, একই movementSql)।
 * তাই সংখ্যা বলে ৫, তালিকাও দেখায় ৫ — দুইটা কোনোদিন আলাদা বলে না।
 */
class StockAnalysisController extends Controller implements HasMiddleware
{
    /** কোন কোন ধরন দেখা যায় — জানালার বাইরের মান ঠিকানা থেকে, ব্যবহারকারীর হাত থেকে নয়।
     *  stagnant = ধীর + নিশ্চল একসাথে (out=0) — ড্যাশবোর্ডের "বেরোচ্ছে না" লিংকের জোড়া। */
    private const TYPES = ['fast', 'slow', 'non', 'stagnant'];

    /**
     * স্টকের বয়স-বাকেট — প্রাপ্তির তারিখ থেকে, আন্তর্জাতিক ধারার মতো।
     * key ব্যবহারকারীর পাঠানো মান মেলানোর জন্য; min/max দিন।
     */
    private const AGE_BUCKETS = [
        '0-30' => [0, 30],
        '30-60' => [30, 60],
        '60-90' => [60, 90],
        '90+' => [90, null],
    ];

    public function __construct(
        private readonly StockFacts $facts,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        // রিপোর্ট দেখার অনুমতি — বিদ্যমান inventory.report, নতুন চাবি নয়,
        // তাই পুরনো রোলগুলোয় আলাদা করে বিলি করতে হয় না
        return [new Middleware('can:inventory.report')];
    }

    public function movement(Request $request): View
    {
        $days = (int) $request->query('days', (string) StockFacts::WINDOWS[0]);
        if (! in_array($days, StockFacts::WINDOWS, true)) {
            $days = StockFacts::WINDOWS[0];
        }

        $type = (string) $request->query('type', 'slow');
        if (! in_array($type, self::TYPES, true)) {
            $type = 'slow';
        }

        // তিনটা সংখ্যাই দেখানো হয় (ট্যাব হিসেবে), আর বেছে নেওয়া ধরনের তালিকা।
        $counts = [
            'fast' => $this->facts->fastMoving($days),
            'slow' => $this->facts->slowMoving($days),
            'non' => $this->facts->nonMoving($days),
            'stagnant' => $this->facts->stagnantCount($days),
        ];

        $products = match ($type) {
            'fast' => $this->facts->fastMovingList($days),
            'non' => $this->facts->nonMovingList($days),
            // "বেরোচ্ছে না" — বিদ্যমান stagnant() তালিকা, শুধু বড় limit;
            // predicate (out=0) stagnantCount()-এর হুবহু সমান
            'stagnant' => $this->facts->stagnant($days, 100),
            default => $this->facts->slowMovingList($days),
        };

        return view('inventory::stock.movement', [
            'menu' => $this->menu->forUser($request->user()),
            'products' => $products,
            'counts' => $counts,
            'type' => $type,
            'days' => $days,
            'windows' => StockFacts::WINDOWS,
            'types' => self::TYPES,
        ]);
    }

    /**
     * স্টকের বয়স — কোন বাকেটে কত টাকা আটকে, আর তার স্তর-তালিকা।
     *
     * প্রতিটা বাকেটের সংখ্যাটা টাকা (qty_remaining × unit_cost), পণ্য-গণনা নয়:
     * আসল প্রশ্ন "কত টাকা আটকে", "কয়টা পণ্য পুরনো" নয়। সংখ্যা ও তালিকা এক
     * agingScope থেকে — বাকেটের অঙ্ক = তালিকার যোগফল।
     */
    public function age(Request $request): View
    {
        $bucket = (string) $request->query('bucket', '90+');
        if (! array_key_exists($bucket, self::AGE_BUCKETS)) {
            $bucket = '90+';
        }

        // প্রতিটা বাকেটে আটকে থাকা টাকা — ট্যাব-স্ট্রিপের সংখ্যা
        $totals = [];
        foreach (self::AGE_BUCKETS as $key => [$min, $max]) {
            $totals[$key] = $this->facts->agingValue($min, $max);
        }

        [$min, $max] = self::AGE_BUCKETS[$bucket];
        $layers = $this->facts->agingLayers($min, $max, 100);

        return view('inventory::stock.age', [
            'menu' => $this->menu->forUser($request->user()),
            'layers' => $layers,
            'totals' => $totals,
            'bucket' => $bucket,
            'buckets' => array_keys(self::AGE_BUCKETS),
        ]);
    }
}
