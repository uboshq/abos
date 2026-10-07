<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\PaperTrail;
use App\Models\DocumentShare;
use Illuminate\Database\Eloquent\Model;
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
        /*
         * ⛔⛔ সারিটা নিজের নথিতে বাঁধা কি না — **খোলার গোনার আগে**।
         *
         * ── ⚠️ কী ভাঙা ছিল (অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩) ─────────────
         * লিংক বানানোর দরজা বাইরে থেকে আসা `params` যেমন এল তেমনই বসাত,
         * আর এই দরজা ঐ `params` দিয়েই কাগজ আঁকত। ⓘ ফলে নাম এক ভাউচারের,
         * আঁকা অন্য ভাউচার। বানানোর দরজা এখন আর এমন সারি বসায় না, কিন্তু
         * আগের সারিগুলো ৩০ দিন বাঁচে — তাই এখানেও মাপা হয়।
         *
         * ⚠️ `open()`-এর **আগে**, কারণ ওটা খোলা গোনে — ফিরিয়ে দেওয়া লিংকের
         * খোলা গুনলে "গ্রাহক খুলেছেন" সংখ্যাটা মিথ্যা বলত।
         */
        $candidate = DocumentShare::byToken($token);

        abort_if($candidate !== null && ! PaperTrail::isBoundToItsDocument($candidate), Response::HTTP_NOT_FOUND);

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

        /*
         * ⛔⛔ দ্বিতীয় তালা — রুটটা তালিকাভুক্ত ছাপার রুট, আর GET।
         *
         * ── ⚠️ কেন লিংক বানানোর জায়গায় পাহারা যথেষ্ট নয় ─────────────
         * এই পথটা মিডলওয়্যার **ইচ্ছাকৃতভাবে** এড়িয়ে যায় (গ্রাহকের লগইন
         * নেই), তাই এখানে যা চলে তা লগইন ছাড়া, CSRF ছাড়া, আর ৩০ দিন ধরে
         * বারবার চলে। ⛔ একটা POST রুট এই পথে ঢুকলে সেটা আর "কাগজ দেখা"
         * নয় — সেটা বাইরের কারো হাতে দেওয়া একটা বোতাম।
         *
         * ⓘ সারিটা আগেই যাচাই হয়ে বসেছে ([[PaperShareController]]), তবু
         * এখানে আবার — কারণ সারিটা ডেটাবেসে, আর ডেটাবেসের সারি একদিন
         * অন্য কোনো পথে বসতে পারে। ⚠️ পাহারা যেখানে কাজটা হয়, সেখানেই।
         */
        abort_unless(
            in_array($share->route_name, PaperTrail::DOCUMENT_ROUTES, true)
                && PaperTrail::isBoundToItsDocument($share)
                && in_array('GET', $route->methods(), true),
            Response::HTTP_NOT_FOUND,
        );

        /*
         * ── ⭐ লিংকটা সেই মুহূর্তের ছবি, পরে আবার মাপা নয় ──────────────
         * শাখার দেয়াল মাপা হয় লিংক **বানানোর** সময়, বানানেওয়ালার চোখে
         * ([[PaperShareController::store()]])। পরে তিনি শাখা হারালেও
         * লিংকটা ঐ একটা কাগজই আঁকে — আর কিছু নয়।
         *
         * ⓘ কেন আবার মাপা হয় না:
         *   · কাগজটা যেদিন পাঠানো হয়েছিল, সেদিন সেটা পাঠানোর অধিকার তাঁর
         *     ছিল — আর গ্রাহক ততক্ষণে সেটা দেখেছেন, হয়তো নামিয়েও রেখেছেন।
         *     পরে মাপলে যা বেরিয়ে গেছে তা ফেরে না, কেবল গ্রাহকের লিংকটা মরে।
         *   · আবার মাপতে হলে এই লগইনহীন অনুরোধে বানানেওয়ালার **পরিচয়
         *     ধার** করতে হত — আর তখন এটা আর "একটা কাগজের চাবি" থাকত না।
         *   · একজন বিক্রয়কর্মী অন্য শাখায় বদলি হলেই তাঁর পাঠানো সব বিল
         *     গ্রাহকের হাতে চুপচাপ ৪০৪ দিত, আর কেউ বলতে পারত না কেন।
         *
         * ⛔ ফেরানোর পথ আছে, আর সেটাই ইচ্ছাকৃত পথ: "লিংক বাতিল"
         * ([[PaperShareController::revoke()]]) আর ৩০ দিনের মেয়াদ।
         *
         * ⚠️ অর্থাৎ ছবিটা **কোন কাগজের**, সেটা স্থির — নিচে বাঁধা নথির আইডি
         * আবার মেলানো হয়; সারির বাইরে থেকে কিছুই কাগজটা বদলাতে পারে না।
         */
        $target = $this->requestFor($share, $route);

        app()->instance('request', $target);

        try {
            /*
             * ⛔ এখানে আবার `bind()` নয় — ২৭ সেপ্টেম্বর ২০২৬-এ সরানো।
             * ⓘ `bind()` প্যারামিটারগুলো ঠিকানা থেকে নতুন করে পড়ে, অর্থাৎ
             * উপরে খুঁজে পাওয়া মডেলটা মুছে আবার কাঁচা আইডি বসায় — আর
             * কন্ট্রোলার তখন একটা খালি মডেল আঁকত। বাঁধাটা [[requestFor()]]-এ
             * একবারই হয়, আর যা মেলানো হয়েছে ঠিক সেটাই চলে।
             */
            return $route->setContainer(app())->run();
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
        /*
         * ⓘ প্যারামিটার সারি থেকে নয়, নথির আইডি থেকে বানানো
         * ([[PaperTrail::routeParamsFor()]]) — সারিটা উপরে মেলানো হয়ে গেছে,
         * তবু আঁকার পথে কেবল নথির আইডি আর সারির মাপ ঢোকে, আর কিছু নয়।
         * ⚠️ বাইরের অনুরোধের ঠিকানা (`?paper=`, `?download=`, অন্য নম্বর)
         * এখানে কোথাও পড়া হয় না।
         */
        $documentParams = PaperTrail::routeParamsFor($share->document_type, (int) $share->document_id);

        abort_if($documentParams === null, Response::HTTP_NOT_FOUND);

        $url = route($share->route_name, [...$documentParams, 'paper' => $share->paper], false);

        $target = Request::create($url, 'GET');
        $target->setRouteResolver(fn () => $route);

        /*
         * ⓘ মডেলগুলো আইডি থেকে খোলা হয় — রুটের নিজের নিয়মেই।
         *
         * ── ⛔ দ্বিতীয় লাইনটা ছিল না, আর তাতে প্রতিটা খোলা লিংক **ফাঁকা**
         * কাগজ আঁকত (২৭ সেপ্টেম্বর ২০২৬-এ ধরা পড়ল) ─────────────────────
         * `substituteBindings()` কেবল হাতে বসানো বাঁধন চালায়; মডেলের
         * টাইপ-হিন্ট থেকে খোঁজাটা `substituteImplicitBindings()`-এর কাজ।
         * ⚠️ ওটা না চললে কন্ট্রোলার পেত একটা **নতুন, খালি** মডেল — নম্বর
         * নেই, সারি নেই — আর উত্তরটা তবু ২০০, PDF। পুরনো পরীক্ষা কেবল
         * "২০০ আর PDF" দেখত, তাই কেউ টের পায়নি।
         */
        $router = app(Router::class);
        $router->substituteBindings($route->bind($target));
        $router->substituteImplicitBindings($route);

        /*
         * ⛔ যা বাঁধা হলো, সেটাই লিংকের নথি কি না — আইডি ধরে আবার মেলানো।
         * ⓘ লগইন নেই বলে শাখার ছাঁকনি এখানে ঘুমায়; দেয়ালটা মাপা হয়েছে
         * বানানোর সময় — এখানে কেবল নিশ্চিত হওয়া যে ঠিক ঐ কাগজটাই।
         */
        $bound = $route->parameter((string) array_key_first($documentParams));

        abort_unless(
            $bound instanceof Model && (int) $bound->getKey() === (int) $share->document_id,
            Response::HTTP_NOT_FOUND,
        );

        return $target;
    }
}
