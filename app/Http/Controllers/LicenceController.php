<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Licence\LicenceReader;
use App\Core\Services\MenuBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * কাগজটার অবস্থা — আর কী করলে ঠিক হবে।
 *
 * ── ⭐ কেন এই পর্দাটা তালার বাইরে ───────────────────────────────────
 * [[RefuseWorkWithoutALicence]] সব পথ বন্ধ করে **এই পর্দায় পাঠায়**।
 * ⛔ এটাও বন্ধ থাকলে মানুষ একটা অন্তহীন চক্রে পড়তেন: তালাবদ্ধ পর্দা
 * থেকে তালাবদ্ধ পর্দায়, আর কোথাও লেখা থাকত না কেন।
 *
 * ── ⓘ কেন অনুমতি লাগে না ────────────────────────────────────────────
 * কাগজের মেয়াদ ফুরালে **যে কেউ** এটা দেখতে পারেন। ⚠️ অনুমতি বসালে
 * একটা দুষ্টচক্র হত: অনুমতি পড়তে হলে ডাটাবেজে যেতে হয়, আর সেই পথটাই
 * তালাবদ্ধ।
 *
 * ⓘ পর্দাটা কোনো ব্যবসায়িক তথ্য দেখায় না — কেবল কাগজের নিজের কথা।
 */
final class LicenceController extends Controller
{
    public function __construct(
        private readonly LicenceReader $licences,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request): View
    {
        $verdict = $this->licences->read();

        return view('licence.show', [
            /*
             * ⚠️ মেনুটা আসে, কিন্তু তালাবদ্ধ অবস্থায় ওর সারিগুলো কাজ
             * করবে না — আর সেটাই ঠিক: মানুষ দেখতে পান ব্যবস্থাটা আছে,
             * কেবল থেমে আছে। ⛔ মেনু ছাড়া পর্দাটা দেখাত যেন সব মুছে
             * গেছে, আর সেটা অনেক বেশি ভীতিকর।
             */
            'menu' => $request->user() !== null ? $this->menu->forUser($request->user()) : [],
            'verdict' => $verdict,
            'licence' => $verdict->licence,
        ]);
    }
}
