<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PaperTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'document_type' => ['required', 'string', 'max:40'],
            'document_id' => ['required', 'integer', 'min:1'],
            'document_no' => ['nullable', 'string', 'max:60'],
            'paper' => ['required', Rule::in(PaperSize::all())],
        ]);

        $route = Route::getRoutes()->getByName($data['route']);

        abort_if($route === null, 404);

        /*
         * ⛔ কেবল ছাপার রুট — আর কিছু নয়। ⓘ নামের শেষে `.print` বা মাঝে
         * `print.` থাকা রুটগুলোই কাগজ আঁকে; বাকি সব রুট (তালিকা, ফর্ম,
         * সেটিংস) এই পথে লিংক পেতে পারে না।
         */
        abort_unless(str_contains($data['route'], 'print'), 404);

        // ⓘ ঐ রুটে ঢোকার যে অনুমতি, সেটাই এখানে
        foreach ($this->abilitiesOf($route) as $ability) {
            abort_unless(Gate::allows($ability), 403);
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
