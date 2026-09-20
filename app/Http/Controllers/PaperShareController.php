<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PaperTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * "গ্রাহককে পাঠান" — কাগজটার একটা গোপন লিংক বানায়।
 *
 * ── ⚠️ অনুমতি: যে কাগজটা ছাপতে পারে, সে-ই পাঠাতে পারে ───────────────
 * আলাদা কোনো "শেয়ার করার ক্ষমতা" বানানো হয়নি, কারণ সেটা মিথ্যা পাহারা
 * হত: যিনি বিলটা ছেপে হাতে দিতে পারেন, তিনি ছবি তুলেও পাঠাতে পারেন।
 * ⓘ তাই পাহারাটা **ঐ রুটের নিজের অনুমতি** — যে রুট থেকে কাগজটা আঁকা হয়,
 * তার `can:` শর্তটাই এখানে মেপে দেখা হয়।
 *
 * ⛔ রুটের নাম বাইরে থেকে আসে, তাই সেটা যাচাই না করে নেওয়া যায় না:
 * নাহলে যে কেউ যেকোনো রুটের নাম পাঠিয়ে সেটার একটা খোলা লিংক বানিয়ে
 * ফেলতেন — আর সেটাই হত পুরো ব্যবস্থার সবচেয়ে বড় ফাঁক।
 */
class PaperShareController extends Controller
{
    public function __construct(private readonly PaperTrail $trail) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'route' => ['required', 'string', 'max:120'],
            'params' => ['array'],

            /*
             * ⛔ ধরনটা মুক্ত লেখা ছিল (`string, max:40`), আর সেটাই ছিল ফাঁক:
             * যেকোনো নামে লিংক ফাইল করা যেত, আর গোনা-দেখা-ইতিহাস সব ঐ
             * বানানো নামের নিচে বসত (abos-8b ধরেছে, ২০ সেপ্টেম্বর ২০২৬)।
             */
            'document_type' => ['required', Rule::in(array_keys(PaperTrail::DOCUMENT_ROUTES))],
            'document_id' => ['required', 'integer', 'min:1'],
            'document_no' => ['nullable', 'string', 'max:60'],
            'paper' => ['required', Rule::in(PaperSize::all())],
        ]);

        /*
         * ⛔⛔ রুটটা **তালিকা থেকে**, নামের ভিতরে "print" খুঁজে নয়।
         *
         * ── ⚠️ আগের নিয়মটা কী ভাঙত ──────────────────────────────────
         * আগে শর্ত ছিল `str_contains($route, 'print')`। নাম ধরে "print"
         * আছে এমন রুট আঠারোটা, আর তার তিনটা কাগজ **নয়**:
         *   · `sales.print_queue.index` — গোটা কোম্পানির ছাপার সারির তালিকা
         *   · `sales.print_queue.settle` — **POST**, অবস্থা বদলায়
         *   · `inventory.label.print` — কোন রেকর্ড, সেটা ঠিকানা থেকে নেয়
         *
         * ⛔ অর্থাৎ একটা তালিকার পর্দার গোপন লিংক বানানো যেত, আর POST-টার
         * লিংক বানালে সেটা লগইন ছাড়া, CSRF ছাড়া, ৩০ দিন ধরে বারবার চলত —
         * যার হাতে লিংকটা, অর্থাৎ যে গ্রাহককে বিলটা পাঠানো হয়েছে।
         *
         * ⓘ এখন রুটটা আসে নথির ধরন থেকে ([[PaperTrail::DOCUMENT_ROUTES]]),
         * আর পাঠানো নামটার সাথে **হুবহু** মিলতে হয়। দুইটা ফাঁক একসাথে বন্ধ:
         * রুটটা তালিকাভুক্ত, আর ধরনটা ঐ রুটেরই।
         */
        abort_unless($data['route'] === PaperTrail::DOCUMENT_ROUTES[$data['document_type']], 404);

        $route = Route::getRoutes()->getByName($data['route']);

        abort_if($route === null, 404);

        $abilities = $this->abilitiesOf($route);

        /*
         * ⛔ খালি তালিকা মানে "সবার জন্য খোলা" নয়, "জানি না" — আর অজানা
         * পাহারায় দরজা বন্ধ থাকে।
         *
         * ⚠️ আগে এখানে কেবল `foreach` ছিল, তাই তালিকা খালি হলে শরীরটা
         * একবারও চলত না আর অনুরোধটা **পাশ করে যেত** — fail-open। আজ
         * আঠারোটা রুটেই `can:` আছে বলে ঘুমিয়ে ছিল, কিন্তু কেউ একটা রুটের
         * পাহারা মেথডের ভিতরে সরালেই দরজাটা নীরবে খুলে যেত।
         */
        abort_if($abilities === [], 403);

        // ⓘ ঐ রুটে ঢোকার যে অনুমতি, সেটাই এখানে
        foreach ($abilities as $ability) {
            $this->authorize($ability);
        }

        $share = $this->trail->share(
            routeName: $data['route'],
            routeParams: $data['params'] ?? [],
            documentType: $data['document_type'],
            documentId: (int) $data['document_id'],
            paper: $data['paper'],
            documentNo: $data['document_no'] ?? null,
        );

        return back()->with('shared_link', route('paper.shared', $share->token));
    }

    /**
     * ঐ রুটের `can:` শর্তগুলো।
     *
     * @return list<string>
     */
    private function abilitiesOf(\Illuminate\Routing\Route $route): array
    {
        $out = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $out[] = explode(',', substr($middleware, 4))[0];
            }
        }

        return $out;
    }
}
