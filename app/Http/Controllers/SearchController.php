<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Search\SearchEngine;
use App\Core\Engines\Search\SearchHit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * উপরের খোঁজার ঘরটা — যা এতদিন কিছুই করত না।
 *
 * ── কী ভাঙা ছিল, ১৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * ⛔ টপবারের "যেকোনো কিছু খুঁজুন" বোতামটা `open-command-center` নামে
 * একটা ঘটনা পাঠাত, আর **পুরো রিপোতে তার কোনো শ্রোতা ছিল না**। বোতামটা
 * দেখতে জীবিত, চাপলে কিছুই হয় না।
 *
 * ⚠️ আর কথাটা টপবারের মন্তব্যে **লেখাও ছিল** — *"খোঁজার বোতামটা আজও
 * কিছুই করে না… ততক্ষণ বোতামটা নতুন জায়গায় বসল, কিন্তু প্রতিশ্রুতিটা
 * এখনো ফাঁকা।"* ⓘ অর্থাৎ ভুলটা কেউ ধরেনি এমন নয়; ধরা ছিল, লেখা ছিল,
 * আর তবু মাসখানেক পর্দায় একটা মৃত বোতাম বসে ছিল।
 *
 * ⓘ `SearchEngine`-ও লেখা হয়ে গিয়েছিল, কেবল তাকে কেউ ডাকত না। এই
 * কন্ট্রোলারটা সেই একটা অনুপস্থিত তার।
 *
 * ── কেন JSON, পুরো পাতা নয় ───────────────────────────────────────────
 * খোঁজা একটা **চলমান** কাজ: মানুষ টাইপ করেন, ফল সরু হয়, তারপর একটায়
 * চাপেন। প্রতিটা অক্ষরে পুরো পাতা বদলালে সেটা খোঁজা নয়, নেভিগেশন।
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchEngine $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        /*
         * ⓘ দুই অক্ষরের নিচে খোঁজা হয় না — একটা অক্ষরে প্রায় সব সারিই
         * মেলে, আর তাতে ফলের তালিকাটা কাজে আসে না, কেবল ডাটাবেজ ব্যস্ত
         * করে। ⚠️ খালি ফল ফেরানো হয়, ভুল নয়: মানুষ তখনো টাইপ করছেন।
         */
        if (mb_strlen($term) < 2) {
            return response()->json(['hits' => []]);
        }

        $hits = $this->search->search($term, $request->user());

        return response()->json([
            'hits' => array_map(fn (SearchHit $hit): array => [
                'type' => $hit->type,
                'no' => $hit->documentNo,
                'label' => $hit->label,
                'url' => $hit->url,
            ], $hits),
        ]);
    }
}
