<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\PaperTrail;
use App\Models\DocumentDelivery;
use App\Models\DocumentShare;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * এই কাগজটা কে কখন বের করেছে — গোনার পিছনের তালিকা।
 *
 * ── ⭐ কেন সংখ্যাটা ক্লিকযোগ্য হতেই হবে ──────────────────────────────
 * মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*।
 * ⓘ আর এই সংখ্যাটার ক্ষেত্রে কারণটা আরও সোজা: তিনি গোনাটা চেয়েছিলেন
 * **কারণ** প্রশ্নটা "কে পাঠাল, কখন" — "চারবার" নিজে থেকে সেই উত্তর দেয় না।
 *
 * ── ⚠️ অনুমতি: যে কাগজটা ছাপতে পারে, সে-ই ইতিহাসটা দেখে ──────────────
 * ⛔ প্রথমে ভেবেছিলাম লগইনই যথেষ্ট, যুক্তিটা ছিল "এখানে কাগজের বিষয়বস্তু
 * নেই"। ⚠️ ওটা ভুল ছিল: কে কোন ভাউচার ছেপেছে, কখন, আর গ্রাহক সেটা
 * খুলেছেন কি না — সেটাও আমাদের ভিতরের কথা, আর ঠিকানা টাইপ করলেই যে কেউ
 * দেখে ফেলতেন (abos-d1 ধরেছে, abos-8b জানিয়েছে, ২০ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ তাই পাহারাটা ঐ কাগজের নিজের ছাপার রুটের `can:` শর্ত
 * ([[PaperTrail::abilitiesFor()]]) — নতুন কোনো ক্ষমতা বানানো হয়নি, কারণ
 * "ছাপতে পারি কিন্তু কে ছেপেছে দেখতে পারি না" কোনো সত্যিকারের সীমা নয়।
 *
 * ⚠️ অচেনা ধরনে ৪০৪ — অনুমতি জানা না থাকলে দেখানোও নয়।
 */
class PaperHistoryController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public function show(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $id = (int) $request->query('id', 0);

        abort_if($type === '' || $id <= 0, 404);

        // ⛔ চেনা কাগজ না হলে এখানেই শেষ — অনুমতি না জানলে দেখানো নয়
        abort_unless(array_key_exists($type, PaperTrail::DOCUMENT_ROUTES), 404);

        $abilities = PaperTrail::abilitiesFor($type);

        /*
         * ⛔ খালি তালিকা = দরজা বন্ধ, খোলা নয়।
         *
         * ⚠️ কেবল `foreach` থাকলে খালি তালিকায় শরীরটা একবারও চলত না আর
         * পাতাটা **খুলে যেত** — fail-open (abos-8b ধরেছে, ২০ সেপ্টেম্বর
         * ২০২৬)। ⓘ আজ প্রতিটা ছাপার রুটেই `can:` আছে বলে বিপদটা ঘুমিয়ে
         * ছিল; কেউ একটার পাহারা মেথডে সরালেই জেগে উঠত।
         */
        abort_if($abilities === [], 403);

        foreach ($abilities as $ability) {
            $this->authorize($ability);
        }

        /*
         * ⭐ এখনো বেঁচে থাকা গোপন লিংকগুলো — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ `revoked_at` ঘরটা প্রথম দিন থেকেই ছিল আর [[DocumentShare::isAlive()]]
         * ওটা পড়তও, কিন্তু **কেউ কোনোদিন লিখত না**। ⛔ অর্থাৎ ভুল লোককে
         * লিংক পাঠিয়ে ফেললে ৩০ দিন অপেক্ষা ছাড়া কিছুই করার ছিল না।
         * ⓘ পাতাটা এখানেই, কারণ প্রশ্নটা একই: "এই কাগজটা কোথায় কোথায় গেছে"।
         */
        $shares = DocumentShare::query()
            ->alive()
            ->where('document_type', $type)
            ->where('document_id', $id)
            ->latest('id')
            ->get();

        $rows = DocumentDelivery::query()
            ->where('document_type', $type)
            ->where('document_id', $id)
            ->with('user')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        return view('paper.history', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'id' => $id,
            'documentNo' => $rows->first()?->document_no,
            'shares' => $shares,
            'rows' => $rows,
        ]);
    }
}
