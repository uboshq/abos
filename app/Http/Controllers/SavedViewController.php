<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\Rule;

/**
 * সংরক্ষিত দৃশ্য — নিজের ছাঁকনি, নাম দিয়ে রাখা।
 *
 * ── কেন কোনো `index` নেই ─────────────────────────────────────────────
 * দৃশ্যগুলো দেখা যায় ঠিক সেখানেই যেখানে কাজে লাগে — তালিকার পর্দার
 * শিরোনামের ড্রপডাউনে। "সংরক্ষিত দৃশ্যের তালিকা" নামে আলাদা একটা পাতা
 * বানালে সেটা এমন একটা জায়গা হত যেখানে কেউ যেতেন না, আর ওখানে গিয়ে
 * একটা দৃশ্য বাছার মানেই হত না — দৃশ্য বাছা হয় পর্দায় বসে।
 *
 * একই যুক্তিতে বিজ্ঞপ্তির আলাদা তালিকাও বানানো হয়নি (routes/web.php)।
 *
 * ── কেন প্রতিটা কাজ ফিরে যায় দৃশ্যটার নিজের ঠিকানায় ──────────────────
 * সংরক্ষণের পর মানুষ ওই তালিকাটাই দেখতে চান, ফাঁকা তালিকা নয়। তাই
 * সংরক্ষণ করে ওই ছাঁকনিসহ পথেই ফেরত পাঠানো হয় — বদলটা সাথে সাথে
 * পর্দায় দেখা যায়।
 */
class SavedViewController extends Controller
{
    /**
     * এখন যা দেখা যাচ্ছে, সেটাই একটা নামে রেখে দেওয়া।
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $view = SavedView::query()->create([
            'user_id' => $request->user()->id,
            'screen' => $data['screen'],
            'name' => $data['name'],
            'query' => $data['query'],
            'is_default' => false,
        ]);

        if ($request->boolean('is_default')) {
            $view->makeDefault();
        }

        return redirect()->to($view->url())
            ->with('saved', __('core.view.saved', ['name' => $view->name]));
    }

    /**
     * পর্দাটা খুললেই যেন এই দৃশ্যটা আসে।
     */
    public function makeDefault(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->mustBeMine($request, $savedView);

        $savedView->makeDefault();

        return redirect()->to($savedView->url())
            ->with('saved', __('core.view.default_set', ['name' => $savedView->name]));
    }

    public function destroy(Request $request, SavedView $savedView): RedirectResponse
    {
        $this->mustBeMine($request, $savedView);

        $screen = $savedView->screen;
        $name = $savedView->name;

        $savedView->delete();

        /*
         * মুছে ফেলার পর ছাঁকনিহীন পর্দাটা — মুছে ফেলা দৃশ্যের ছাঁকনি
         * নিয়ে ফিরলে মনে হত কিছুই হয়নি।
         */
        return redirect()->route($screen)
            ->with('saved', __('core.view.removed', ['name' => $name]));
    }

    /**
     * এটা কি সত্যিই এই ব্যবহারকারীর দৃশ্য।
     *
     * ── কেন কোম্পানিটাও দেখা হয় না এখানে ─────────────────────────────
     * `BelongsToCompany`-র গ্লোবাল স্কোপ রুট-মডেল বাঁধার সময়ই অন্য
     * কোম্পানির সারি আড়াল করে — তাই ওটা পর্যন্ত পৌঁছালেই কোম্পানি ঠিক।
     * বাকি থাকে কেবল "কোন ব্যক্তির", আর সেটা এখানে।
     */
    private function mustBeMine(Request $request, SavedView $view): void
    {
        abort_unless($view->user_id === $request->user()->id, 403);
    }

    /**
     * @return array{screen: string, name: string, query: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            /*
             * পর্দাটা সত্যিই আছে কি না — নাহলে সংরক্ষিত দৃশ্যটা এমন
             * একটা রুটের নাম ধরে রাখত যেটা ডাকলে `route()` ছুড়ে ফেলত,
             * আর ড্রপডাউনটাই ভেঙে যেত।
             */
            'screen' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, callable $fail): void {
                $route = RouteFacade::getRoutes()->getByName((string) $value);

                if ($route === null) {
                    $fail(__('core.view.unknown_screen'));

                    return;
                }

                /*
                 * প্যারামিটার লাগে এমন পর্দার দৃশ্য রাখা যায় না।
                 *
                 * `$view->url()` তখন `route()`-এ ছুড়ে ফেলত, আর সেটা
                 * ঘটত **মেনু আঁকার সময়** — অর্থাৎ একটা ভুল সারি গোটা
                 * পাতাটাকে ৫০০ করে দিত, প্রতিবার, যতক্ষণ না কেউ
                 * ডাটাবেস থেকে সারিটা মুছত।
                 *
                 * পর্দায় ওই রুটগুলোয় মেনুটাই দেখানো হয় না, কিন্তু
                 * পাহারাটা এখানেও দরকার: পর্দার শর্ত হাতে বানানো POST
                 * ঠেকায় না।
                 */
                if ($route->parameterNames() !== []) {
                    $fail(__('core.view.screen_needs_a_record'));
                }
            }],

            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('ui_saved_views', 'name')
                    ->where('user_id', $request->user()->id)
                    ->where('company_id', $request->user()->current_company_id)
                    ->where('screen', $request->input('screen')),
            ],

            /*
             * কোয়েরিটা ঐচ্ছিক: ছাঁকনি ছাড়া "সব সারি" নিজেও একটা কাজের
             * দৃশ্য — D365-এর "All Accounts" ঠিক সেটাই।
             */
            'query' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['query'] = ltrim((string) ($data['query'] ?? ''), '?&');

        return $data;
    }
}
