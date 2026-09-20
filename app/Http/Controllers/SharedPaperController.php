<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\PaperTrail;
use App\Models\DocumentShare;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * গ্রাহকের হাতে যাওয়া লিংক — একটা কাগজ, তিরিশ দিন, আর কিছু নয়।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * বিলটা হোয়াটসঅ্যাপে পাঠাতে হলে গ্রাহকের খোলার একটা পথ লাগে, অথচ তাঁর
 * লগইন নেই — থাকার কথাও নয়। ⓘ তাই লিংকটাই চাবি, আর সেই চাবির নিরাপত্তা
 * দাঁড়িয়ে আছে তিনটা জিনিসের উপর:
 *   ১. অনুমান করা যায় না এমন ৬৪ অক্ষর ([[DocumentShare]])
 *   ২. একটা মাত্র কাগজ — যে রুট আর প্যারামিটার সারিতে লেখা, কেবল সেটাই
 *   ৩. নিজে থেকে মরে যাওয়া — ৩০ দিন
 *
 * ── ⛔ যা এই পথে **হয় না** ───────────────────────────────────────────
 * কোনো তালিকা নয়, কোনো মেনু নয়, লগইনের পর্দাও নয় — অর্থাৎ লিংকটা হাতে
 * পেয়ে কেউ বাকি ব্যবস্থার অস্তিত্বই টের পায় না। ⚠️ আর গ্রাহকের সেশনও
 * বানানো হয় না: এই অনুরোধে কেউ "লগ-ইন" হয় না, কেবল একটা PDF বেরোয়।
 *
 * ── কেন মূল রুটটাই আবার চালানো হয়, নতুন করে আঁকা নয় ────────────────
 * ⓘ মালিকের কথায় PDF-ও পাঠানো যাবে। ⛔ তখন যদি লিংকের কাগজ আর নামানো
 * কাগজ আলাদা কোড আঁকত, একদিন একটায় ভ্যাটের সারি যোগ হত আর অন্যটায় না —
 * গ্রাহকের কপি আর আমাদের কপি দুই কথা বলত, আর পর্দা দেখে কেউ ধরতেও পারত না।
 * ⭐ তাই এখানে ঐ রুটের কন্ট্রোলারটাই ডাকা হয়, একই প্যারামিটার নিয়ে।
 */
class SharedPaperController extends Controller
{
    public function __construct(private readonly PaperTrail $trail) {}

    public function show(Request $request, string $token): BaseResponse
    {
        $share = $this->trail->open($token, $request);

        /*
         * ⚠️ মেয়াদ শেষ হলেও ৪০৪ — "মেয়াদ শেষ" নয়। ⓘ পার্থক্যটা ইচ্ছাকৃত:
         * কোন চাবি একদিন সত্যি ছিল, সেটা বাইরের কাউকে জানিয়ে দেওয়ার
         * কোনো কারণ নেই।
         */
        abort_if($share === null, Response::HTTP_NOT_FOUND);

        return $this->draw($share, $request);
    }

    /**
     * কাগজটা আঁকা — যে রুটে সে বানানো হয়েছিল, ঠিক সেই রুটেই।
     *
     * ⚠️ মিডলওয়্যার চলে না, আর সেটাই উদ্দেশ্য: এই অনুরোধে কোনো লগইন নেই,
     * চাবিটাই অনুমতি। ⓘ কোম্পানির প্রসঙ্গ আগেই বসানো হয়েছে
     * ([[PaperTrail::open()]]), তাই ভেতরের প্রতিটা কোয়েরি সঠিক কোম্পানির
     * সারিই দেখে।
     */
    private function draw(DocumentShare $share, Request $request): BaseResponse
    {
        $route = Route::getRoutes()->getByName($share->route_name);

        abort_if($route === null, Response::HTTP_NOT_FOUND);

        $target = $this->requestFor($share, $route);

        app()->instance('request', $target);

        try {
            return $route->bind($target)
                ->setContainer(app())
                ->run();
        } finally {
            app()->instance('request', $request);
        }
    }

    /**
     * ভেতরের অনুরোধটা — ঠিক ঐ কাগজের, ঐ মাপে।
     *
     * ⓘ `download` ঘরটা বাইরে থেকে আসা ঠিকানার নয়, আমাদের নিজের:
     * গ্রাহক চাইলে ফাইল হিসেবেও নামাতে পারেন (`?download=1`), আর তখন
     * গোনাটা "খোলা" থেকে আলাদা হয় না — কাগজ একটাই, পথও একটাই।
     */
    private function requestFor(DocumentShare $share, RoutingRoute $route): Request
    {
        $params = [...$share->route_params, 'paper' => $share->paper];

        $url = route($share->route_name, $params, false);

        $target = Request::create($url, 'GET');
        $target->setRouteResolver(fn () => $route);

        // ⓘ মডেলগুলো আইডি থেকে খোলা হয় — রুটের নিজের নিয়মেই
        app(Router::class)->substituteBindings($route->bind($target));

        return $target;
    }
}
