<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\Peek;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ একই ঠিকানার দুইটা চেহারা আছে — ক্যাশকে সেটা বলে দেওয়া।
 *
 * ── ⛔ না বললে কী হত ─────────────────────────────────────────────────
 * পিক আর আসল পাতা এক URL ([[Peek]])। ⚠️ `Vary` না থাকলে প্রক্সি বা
 * ব্রাউজার প্রথমটাকে দ্বিতীয়টার উত্তর হিসেবে দিত — অর্থাৎ কেউ একটা
 * পপআপ খোলার পর ঐ ঠিকানায় গিয়ে **মেনু-বিহীন একটা পাতা** পেতেন, আর
 * সেটা দেখতে হুবহু ভেঙে যাওয়া সিস্টেমের মতো।
 *
 * ── ⓘ কেন প্রতিটা উত্তরে, কেবল পিকের উত্তরে নয় ──────────────────────
 * ⚠️ ক্যাশ ভুক্তিটা তৈরি হয় **প্রথম** উত্তরে। ⛔ তাই খোলসওয়ালা
 * উত্তরটাতেই `Vary` না থাকলে ওটা হেডার-নিরপেক্ষভাবে জমা হত, আর পরের
 * পিক অনুরোধ ঐ জমা খোলসটাই পেত।
 */
final class PeekVaries
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * ⛔ একটাই `Vary` লাইন, কমা দিয়ে মেলানো — আর এটা রুচির প্রশ্ন নয়।
         *
         * ── ⚠️ যা ধরা পড়েছিল ───────────────────────────────────────────
         * আগে এখানে `setVary(Peek::HEADER, false)` ছিল, অর্থাৎ "যা আছে তা
         * মুছো না"। ⓘ কিন্তু Symfony তখন **দ্বিতীয় একটা আলাদা `Vary`
         * লাইন** বসায়। HTTP-তে ওটা বৈধ (একই নামের দুইটা লাইন মানে কমা
         * দিয়ে জোড়া একটা তালিকা), কিন্তু —
         *
         * ⛔ `$response->headers->get('Vary')` কেবল **প্রথম** লাইনটা
         * ফেরায়, তাই কোড ও পরীক্ষা দুইটাই `X-Peek`-কে অদৃশ্য দেখত;
         * ⚠️ আর বাস্তবে সব প্রক্সি দুইটা লাইন ঠিকভাবে জোড়া দেয় না, আর
         * যেটা দেয় না সে খোলসহীন টুকরোটাকেই আসল পাতা হিসেবে পরিবেশন করত।
         *
         * ⓘ দাবিটা না লিখলে ব্যাপারটা ধরাই পড়ত না: আজ আর কেউ `Vary`
         * বসায় না, তাই পাতা দিয়ে মাপলে সবটা সবুজই থাকত।
         */
        $already = array_filter(array_map(
            'trim',
            explode(',', (string) $response->headers->get('Vary')),
        ));

        foreach ($already as $name) {
            if (strcasecmp($name, Peek::HEADER) === 0) {
                return $response;
            }
        }

        $already[] = Peek::HEADER;

        $response->headers->set('Vary', implode(', ', $already));

        return $response;
    }
}
