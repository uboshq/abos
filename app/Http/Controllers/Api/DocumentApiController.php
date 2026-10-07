<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Contracts\GuardsThePrint;
use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PaperTrail;
use App\Core\Services\PhoneModules;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use App\Http\Middleware\RefuseWorkWithoutALicence;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response;

/**
 * ফোন থেকে ছাপা, রসিদ আর শেয়ার — চুক্তি §১০।
 *
 * ── ⭐ ফোনে কোনো কাগজ আঁকা হয় না, এখানেও না ─────────────────────────
 * কাগজটা আঁকে ওয়েবের ছাপার কন্ট্রোলার ([[SalesPrintController]],
 * [[PurchasePrintController]], [[VoucherPrintController]] …), আর এই দরজা
 * **ঐ রুটটাই চালায়**, ঠিক যেভাবে গ্রাহকের লিংক চালায়
 * ([[SharedPaperController]])। ⛔ এখানে একটা আলাদা PDF বানালে একদিন ফোনের
 * বিলে ভ্যাটের সারি থাকত আর ওয়েবেরটায় না — একই বিলের দুই কপি দুই কথা
 * বলত, আর কেউ ধরত না কোনটা আসল (চুক্তির নিয়ম ঘ)।
 *
 * ── ⭐ ধরনের শব্দভাণ্ডার ওয়েবের তালিকা থেকে ─────────────────────────
 * কোন কাগজ ছাপা যায় তার একমাত্র তালিকা [[PaperTrail::DOCUMENT_ROUTES]] —
 * গ্রাহকের লিংক, ছাপার হিসাব, আর এখন ফোন, তিনজনই ওটা পড়ে। ধরনের নাম
 * ঐ রুটের মডেলের ইংরেজি নাম (`class_basename`), আর §৫-এর `documentType`
 * হুবহু তাই ([[ApprovalApiController]])। ⛔ হাতে লেখা দ্বিতীয় একটা তালিকা
 * থাকলে অনুমোদনের তালিকা থেকে নথি খোলা একদিন নীরবে ৪০৪ দিত।
 *
 * ── ⛔ দরজার ক্রম — ওয়েব যে ক্রমে ফেরায় ─────────────────────────────
 *   ৪০৪  অচেনা ধরন, বা নথি নেই — ক্রমিক `id`, অন্য কোম্পানির, শাখার
 *        বাইরের (কোম্পানির দেয়াল, [[BelongsToCompany]])। ⓘ অন্য কোম্পানির
 *        কাগজের জন্য ৪০৩ দিলে বলা হত "আছে", আর সেটুকুই গোনার খবর।
 *   ৪০৪  বন্ধ মডিউল বা পর্দা — ওয়েবের [[RefuseSwitchedOffScreens]] নিজেই,
 *        ঐ রুটের নামে; নকল নয়।
 *   ৪০৩  ঐ রুটের `can:` চাবি নেই (চুক্তির নিয়ম খ)। ⓘ চাবিগুলো রুট থেকেই
 *        পড়া ([[PaperTrail::abilitiesFor()]]) — ⛔ এখানে আবার লিখলে ওয়েবের
 *        চাবি বদলানোর দিন ফোন পুরনোটা চাইত।
 *   ৪০৩  ক্রয়মূল্য-বহনকারী কাগজ, অথচ ক্রয়মূল্য দেখার চাবি নেই (নিয়ম ক)।
 *   ৪২২  অচেনা কাগজ (নিয়ম গ) — ⚠️ ওয়েব এখানে চুপচাপ A4 দেয়; ফোন দেয় না।
 *
 * ── ⚠️ রুটে `can:` নেই, ইচ্ছা করে ───────────────────────────────────────
 * দরজা একটা, কাগজ এগারো রকম, আর প্রতিটার চাবি আলাদা — ছাঁকনিটা তাই
 * হ্যান্ডলারে (চুক্তি §১০ খ)।
 *
 * ── ⓘ অনলাইন-only (নিয়ম ঙ) ─────────────────────────────────────────
 * উত্তরে `no-store` — একটা কাগজ সেই মুহূর্তের অবস্থা, আর ক্যাশ থেকে পুরনো
 * একটা PDF বর্তমান বলে ভান করত।
 */
final class DocumentApiController extends Controller
{
    /**
     * ⛔ ডেস্কের মডিউল — জনবল/বেতন ফোনে আসে না (চুক্তি §৪, §৫-এর একই সিদ্ধান্ত)।
     *
     * ⓘ বেতনশিটের ছাপার রুট [[PaperTrail::DOCUMENT_ROUTES]]-এ আছে (`hr_payslip`)।
     * ⛔ এখানে না ছাঁকলে §৫ যে দরজা বন্ধ করেছিল, এই দরজা দিয়ে বেতনশিট ঠিকই
     * ফোনে নামত — আর দরজার নাম "documents", "payroll" নয়, তাই কেউ ধরত না।
     */
    private const DESK_ONLY_PREFIX = 'hr_';

    /** `GET /documents/{type}/{id}/pdf?paper=` — বাইট, ওয়েবের কাগজটাই। */
    public function pdf(Request $request, string $type, string $id): Response
    {
        return $this->through($request, $type, $id, function (Request $target, RoutingRoute $route): Response {
            /** @var Response $response */
            $response = $route->run();

            /*
             * ⚠️ ওয়েবের উত্তরটাই যায় — নামসহ (`PB-2609-0007.pdf`), কারণ ফোন
             * ফাইলটা সেভ ও শেয়ার করে (চুক্তি §১০ ⓵)। ⓘ কেবল ক্যাশের হেডার
             * যোগ হয় (নিয়ম ঙ)।
             */
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

            return $response;
        }, paperAsked: true);
    }

    /**
     * `GET /documents/{type}/{id}/papers` — কোন কোন কাগজে এটা ছাপা যায়।
     *
     * ⓘ ওয়েবের ছাপার বোতাম ([[x-ui.print-menu]]) প্রতিটা কাগজের জন্য
     * [[PaperSize::all()]] দেখায়, আর কন্ট্রোলারগুলোও তিনটাই আঁকে। ⚠️ তবু
     * উত্তরটা নথি ধরে, দরজার সব পাহারা পেরিয়ে — যে কাগজ এই মানুষ ছাপতে
     * পারেন না, তার মাপও তিনি জানেন না।
     */
    public function papers(Request $request, string $type, string $id): Response
    {
        return $this->through($request, $type, $id, fn (): JsonResponse => response()
            ->json(PaperSize::all())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate'));
    }

    /**
     * নথি খোঁজা → ওয়েবের পাহারা → তারপর কাজ — দুই দরজার এক পথ।
     *
     * ⛔ দুই দরজা আলাদা করে পাহারা দিলে একদিন `papers` খোলা থাকত আর `pdf`
     * বন্ধ — আর তালিকা দেখে ফোন এমন বোতাম দেখাত যেটা চাপলে ৪০৩।
     *
     * @param  Closure(Request, RoutingRoute): Response  $then
     */
    private function through(Request $request, string $type, string $id, Closure $then, bool $paperAsked = false): Response
    {
        /** @var User $user */
        $user = $request->user();

        [$documentType, $class] = $this->printable()[$type] ?? abort(404);

        /*
         * ⛔ ফোনে বন্ধ মডিউলের কাগজ — ৪০৩ `module_off` ([[PhoneModules]])। মডিউল
         * মডেলের নামস্থান থেকে (`App\Modules\Sales\…` → sales), হাতে লেখা তালিকা নয়।
         */
        $phone = app(PhoneModules::class);
        $phone->refuseUnlessReachable($phone->moduleOfClass($class)?->code);

        /*
         * ⛔ কেবল `public_id` (চুক্তি §৩ ক) — ক্রমিক সংখ্যা এখানে কিছুই খোঁজে না।
         * ⓘ মডেলের নিজের ছাঁকনি (কোম্পানি, শাখা) ওয়েবের রুট-বাঁধনের মতোই চলে।
         */
        $document = $class::query()->wherePublicId($id)->first() ?? abort(404);

        $routeName = PaperTrail::DOCUMENT_ROUTES[$documentType];
        $route = Route::getRoutes()->getByName($routeName) ?? abort(404);

        $target = $this->requestFor($request, $route, $routeName, $document, $user, $paperAsked);

        app()->instance('request', $target);

        try {
            /*
             * ⭐ ওয়েবের দুইটা "আজ কি এই পর্দা খোলা" পাহারা — নিজেরাই, নকল নয়।
             *
             * ⓘ ঐ রুটের নাম ধরে চলে, তাই বিক্রয় বন্ধ করা কোম্পানির বিল ফোনেও
             * ৪০৪ — ওয়েবে যেমন। ⚠️ লাইসেন্সের তালা ওয়েবে লাইসেন্স-পাতায়
             * পাঠায়; ফোনের কাছে ঐ পাতার মানে নেই, তাই সেটা ৪০৩।
             */
            $response = (new Pipeline(app()))
                ->send($target)
                ->through([RefuseSwitchedOffScreens::class, RefuseWorkWithoutALicence::class])
                ->then(function (Request $target) use ($request, $user, $documentType, $class, $route, $then, $paperAsked, $document): Response {
                    $this->mayOpen($user, $documentType, $class, $document);

                    /*
                     * ⛔ ওয়েবের ছাপার রুটের নিজের পাহারা — যেমন পরিবহন না বাছলে চালান নয় (অডিট ফোন ⚠️৮)। কন্ট্রোলার সরাসরি চলে
                     * বলে মিডলওয়্যার চলত না; তাই রুটের যে মিডলওয়্যার [[GuardsThePrint]] সই করেছে, তাকে জিজ্ঞেস — ৪২২, তার কারণসহ।
                     */
                    foreach ($route->gatherMiddleware() as $middleware) {
                        $guard = is_string($middleware) ? explode(':', $middleware, 2)[0] : null;
                        if ($guard !== null && class_exists($guard) && is_subclass_of($guard, GuardsThePrint::class)) {
                            $why = app($guard)->whyNotPrint($target);
                            if ($why !== null) {
                                throw ValidationException::withMessages(['print' => $why]);
                            }
                        }
                    }

                    if ($paperAsked) {
                        /*
                         * ⛔ অচেনা কাগজ ৪২২ (নিয়ম গ) — চাবির **পরে**, যাতে চাবিহীন
                         * মানুষ ৪২২ দেখে জানতে না পারেন নথিটা আছে।
                         */
                        $request->validate(['paper' => ['nullable', 'string', Rule::in(PaperSize::all())]]);
                    }

                    return $then($target, $route);
                });
        } finally {
            app()->instance('request', $request);
        }

        abort_if($response->isRedirection(), 403);

        return $response;
    }

    /**
     * ⛔ ঐ রুটের চাবি, আর ক্রয়মূল্যের কাগজে ক্রয়মূল্যের চাবিও।
     *
     * ⚠️ খালি চাবির তালিকা মানে "সবার জন্য খোলা" নয়, "জানি না" — আর অজানা
     * পাহারায় দরজা বন্ধ ([[PaperShareController]]-এর একই শিক্ষা: `foreach`
     * একবারও না চললে অনুরোধটা পাশ করে যেত)।
     *
     * @param  class-string<Model>  $class
     */
    private function mayOpen(User $user, string $documentType, string $class, Model $document): void
    {
        $abilities = PaperTrail::abilitiesFor($documentType);

        abort_if($abilities === [], 403);

        /*
         * ⭐ ক্যাশিয়ার নিজের লেখা ভাউচার ছাপেন — আদায় বা পরিশোধের রসিদ পক্ষের হাতে দেওয়া এই কাজেরই অংশ (সমন্বয়ক, ৭ অক্টোবর
         * ২০২৬)। ⓘ রিপোর্টের চাবি (`accounts.report`) থাকলে সব ভাউচার, আগের মতো; না থাকলে কেবল লেখার চাবিওয়ালার নিজের লেখা
         * ভাউচার। শাখার দেয়াল মডেলের নিজের (খোঁজাতেই, [[ScopedToUserBranch]])।
         */
        if ($documentType === 'accounts_voucher' && ! $user->can('accounts.report')) {
            abort_unless($user->can('accounts.voucher.create') && (int) $document->getAttribute('created_by') === (int) $user->id, 403);

            return;
        }

        foreach ($abilities as $ability) {
            abort_unless($user->can($ability), 403);
        }

        /*
         * ⓘ ক্রয়মূল্য এখানে আলাদা করে ফেরানো হয় না — ২৭ সেপ্টেম্বর ২০২৬ থেকে
         * ওয়েবের ক্রয়ের কাগজ নিজেই দাম ঢাকে ([[PurchasePrintController]],
         * `41abd596`), আর ফোন সেই কাগজটাই পায়: চাবি ছাড়া দাম-ছাড়া, চাবিসহ দামসহ।
         */

    }

    /**
     * ভেতরের অনুরোধ — ঐ কাগজের রুট, ঐ নথি, ফোনের চাওয়া মাপ।
     *
     * ⓘ মডেলটা সরাসরি বসানো হয়, আইডি থেকে আবার খোলা নয়: উপরে যা খোঁজা
     * হয়েছে, ঠিক সেটাই ছাপা হয়।
     */
    private function requestFor(
        Request $request,
        RoutingRoute $route,
        string $routeName,
        Model $document,
        User $user,
        bool $paperAsked,
    ): Request {
        $parameter = $route->parameterNames()[0] ?? abort(404);

        $query = [];

        if ($paperAsked && is_string($request->query('paper')) && $request->query('paper') !== '') {
            $query['paper'] = $request->query('paper');
        }

        /*
         * ⓘ `download=1` — ফাইল হিসেবে নামানো, ছাপা নয়। ওয়েবের কাগজের হিসাব
         * ([[PaperTrail]]) দুইটা আলাদা গোনে, তাই ফোনও চাইলে বলতে পারে।
         */
        if ($request->boolean('download')) {
            $query['download'] = '1';
        }

        $target = Request::create(route($routeName, [$parameter => $document->getKey()], false), 'GET', $query);
        $target->setRouteResolver(fn () => $route);
        $target->setUserResolver(fn () => $user);

        $route->bind($target);
        $route->setParameter($parameter, $document);

        return $target;
    }

    /**
     * ধরনের নাম → [ছাপার তালিকার ধরন, মডেল]।
     *
     * ⓘ মডেলটা ঐ রুটের কন্ট্রোলারের সই থেকে পড়া — ওয়েব যে মডেল বাঁধে,
     * ঠিক সেটা। ⛔ বেতনের কাগজ বাদ (উপরে, [[DESK_ONLY_PREFIX]])।
     *
     * @return array<string, array{0: string, 1: class-string<Model>}>
     */
    private function printable(): array
    {
        $out = [];

        foreach (PaperTrail::DOCUMENT_ROUTES as $documentType => $routeName) {
            if (str_starts_with($documentType, self::DESK_ONLY_PREFIX)) {
                continue;
            }

            $route = Route::getRoutes()->getByName($routeName);

            foreach ($route?->signatureParameters(['subClass' => Model::class]) ?? [] as $parameter) {
                $type = $parameter->getType();
                $class = $type instanceof ReflectionNamedType ? $type->getName() : null;

                if (is_string($class) && is_subclass_of($class, Model::class)) {
                    $out[class_basename($class)] = [$documentType, $class];

                    break;
                }
            }
        }

        return $out;
    }
}
