<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\PaperTrail;
use App\Core\Support\CompanyContext;
use App\Models\DocumentShare;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
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

        $documentId = (int) $data['document_id'];

        // ⓘ প্যারামিটার নথি থেকে বানানো — কারণটা [[PaperTrail::routeParamsFor()]]-এ
        $params = PaperTrail::routeParamsFor($data['document_type'], $documentId);

        abort_if($params === null, 404);

        /*
         * ⛔⛔ পাঠানো `params` — কেবল ঐ একটা ঘর, আর মানটা ঐ নথিরই আইডি।
         *
         * ── ⚠️ কী ভাঙা ছিল (অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩) ─────────────
         * `params` যেমন এল তেমনই সারিতে বসত। ⓘ ফলে নাম নিজের ভাউচারের,
         * `params`-এ অন্য শাখার ভাউচারের নম্বর — আর খোলা লিংক ঐ অন্য
         * কাগজটা আঁকত। বাড়তি ঘর (`paper`, `download`) দিয়ে লিংকের কাগজও
         * বাইরে থেকে বদলানো যেত।
         *
         * ── ⭐ কেন যাচাইয়ের ভুল হয়ে ফেরত, চুপচাপ ফেলে দেওয়া নয় ──────
         * (ওয়েবে সেটা ভুলসহ আগের পাতায় ফেরা; ৪২২ এই অ্যাপে কেবল `api/*`-এ)
         * আমাদের নিজের বোতাম ([[x-ui.print-menu]]) ঠিক এই একটা ঘরই পাঠায়।
         * ⓘ তাই অন্য কিছু এলে সেটা হয় ভাঙা একটা পাতা, নয় হাতে বানানো
         * অনুরোধ — আর দুই ক্ষেত্রেই ফেলে দিয়ে লিংক বানালে মানুষটা ভাবতেন
         * তিনি যা চেয়েছেন তা-ই পাঠিয়েছেন। ⚠️ ফেরত দিলে ভুলটা চোখে পড়ে।
         *
         * ⓘ না পাঠালে বা খালি পাঠালে চলে — প্যারামিটার তো নথি থেকেই বানানো।
         * ⚠️ পাঠানো মানটা কোথাও ব্যবহার হয় না, কেবল মাপা হয়।
         */
        $key = (string) array_key_first($params);

        $request->validate([
            'params' => ['array:'.$key],
            'params.'.$key => ['required_with:params', Rule::in([(string) $documentId])],
        ]);

        /*
         * ⛔⛔ নথিটা খোঁজা হয় **ছাপার দরজার নিজের পথে**, এই মানুষটার চোখে।
         *
         * ── ⚠️ কেন এটা না থাকা ছিল আসল ফাঁক ─────────────────────────
         * লিংক খোলার সময় কেউ লগ-ইন নেই, তাই শাখার ছাঁকনি
         * ([[ScopedToUserBranch]]) সেখানে ঘুমিয়ে থাকে — দাঁড়িয়ে থাকে কেবল
         * কোম্পানির দেয়াল। ⓘ অর্থাৎ শাখার দেয়াল মাপার **একমাত্র মুহূর্ত**
         * এটাই, আর আগে এখানে নথিটা খোঁজাই হত না: ময়মনসিংহে আটকানো
         * হিসাবরক্ষক নেত্রকোনার ভাউচারের খোলা লিংক বানাতে পারতেন।
         *
         * ⭐ খোঁজাটা রুটের নিজের মডেল-বাঁধন দিয়ে — ছাপার পাতায় ঢুকলে যে
         * কোয়েরি চলে, ঠিক সেটাই (কোম্পানি + শাখা + গুদাম, মডেলে যা বসানো)।
         * ⚠️ হাতে আলাদা কোয়েরি লিখলে একদিন মডেলে নতুন দেয়াল বসত আর এখানে
         * বসত না।
         */
        $document = $this->documentAsTheDoorSeesIt($route, $params, $documentId);

        /*
         * ⓘ নথির নিজের "দেখা" নীতি থাকলে সেটাও — বেতনের পাতার শাখা যেমন
         * রানের ভিতরে ([[PayslipPolicy]]), আর তার ছাপার দরজা ঠিক এটাই মাপে।
         *
         * ⚠️ ৪০৪, ৪০৩ নয়: চাবি উপরে মাপা হয়ে গেছে, তাই এখানে "না" মানে
         * "আপনার নাগালের বাইরে" — আর নাগালের বাইরের নথির অস্তিত্বও জানানো নয়।
         */
        $policy = Gate::getPolicyFor($document);

        if ($policy !== null && method_exists($policy, 'view')) {
            abort_unless(Gate::allows('view', $document), 404);
        }

        $this->retireBentLinks($data['route'], $data['document_type'], $documentId, $data['paper']);

        $share = $this->trail->share(
            routeName: $data['route'],
            routeParams: $params,
            documentType: $data['document_type'],
            documentId: $documentId,
            paper: $data['paper'],

            /*
             * ⓘ নম্বরটা নথির নিজের, পাঠানো লেখা নয় — নাহলে ইতিহাসের পাতায়
             * যেকোনো নম্বর বসানো যেত। ⚠️ ঘরটা যাচাইয়ে রাখা হয়েছে কেবল
             * পুরনো পাতা যাতে ভেঙে না যায়; মানটা আর পড়া হয় না।
             */
            documentNo: $this->numberOf($document),
        );

        return back()->with('shared_link', route('paper.shared', $share->token));
    }

    /**
     * ছাপার রুটের নিজের মডেল-বাঁধন — লগইন করা মানুষটার সব ছাঁকনি সহ।
     *
     * ⓘ রুটের একটা **কপি** বাঁধা হয়: আসল রুট-বস্তুটা গোটা অ্যাপের, তার
     * প্যারামিটার বদলে রাখার কোনো কারণ নেই।
     *
     * ⛔ না পেলে ৪০৪ — অন্য শাখা, অন্য কোম্পানি, বা নেই, তিনটাই একই উত্তর।
     *
     * @param  array<string, int>  $params
     */
    private static function documentAsTheDoorSeesIt(RoutingRoute $route, array $params, int $documentId): Model
    {
        $route = clone $route;

        $target = Request::create(route((string) $route->getName(), $params, false), 'GET');
        $target->setRouteResolver(fn () => $route);

        $router = app(Router::class);

        try {
            $router->substituteBindings($route->bind($target));
            $router->substituteImplicitBindings($route);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $document = $route->parameter((string) array_key_first($params));

        // ⚠️ বাঁধন না চললে এখানে কাঁচা আইডি থাকত — সেটাও "পাওয়া যায়নি"
        abort_unless($document instanceof Model && (int) $document->getKey() === $documentId, 404);

        return $document;
    }

    /**
     * ২৭ সেপ্টেম্বর ২০২৬-এর আগের দরজায় বসা বাঁকা লিংক — এখনই মেরে ফেলা।
     *
     * ── ⚠️ কেন লিংক বানানোর সময় ────────────────────────────────────
     * [[PaperTrail::share()]] একই কাগজের বেঁচে থাকা লিংক ফেরত দেয়। ⓘ তাই
     * পুরনো একটা বাঁকা সারি থাকলে নতুন প্রতিটা "পাঠান" ঐ সারিটাই পেত —
     * যেটা খোলা দরজা আর আঁকে না ([[SharedPaperController]]), অর্থাৎ
     * গ্রাহকের হাতে একটা মরা লিংক, আর কেউ বুঝত না কেন।
     */
    private function retireBentLinks(string $routeName, string $documentType, int $documentId, string $paper): void
    {
        DocumentShare::query()
            ->alive()
            ->where('route_name', $routeName)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('paper', $paper)
            ->get()
            ->reject(fn (DocumentShare $share): bool => PaperTrail::isBoundToItsDocument($share))
            ->each(fn (DocumentShare $share) => $share->forceFill(['revoked_at' => Carbon::now()])->save());
    }

    private function numberOf(Model $document): ?string
    {
        $number = $document->getAttribute('document_no');

        return is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * ⛔ লিংকটা এখনই মেরে ফেলা — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⚠️ কেন এটা না থাকা একটা ফাঁক ছিল ────────────────────────────
     * `revoked_at` ঘরটা প্রথম দিন থেকেই ছিল আর [[DocumentShare::isAlive()]]
     * ওটা পড়তও — কিন্তু **কেউ কোনোদিন লিখত না**। ⓘ অর্থাৎ ভুল নম্বরে
     * বিলটা পাঠিয়ে ফেললে ৩০ দিন ধরে অচেনা কারো হাতে কাগজটা খোলা থাকত,
     * আর থামানোর কোনো পথ ছিল না (abos-8b-র অডিট, খ৪)।
     *
     * ⓘ অনুমতি পাঠানোরই — যিনি পাঠাতে পারেন, তিনিই ফেরাতে পারেন। ⚠️ আলাদা
     * ক্ষমতা বানালে যে মানুষটা ভুলটা করেছেন তিনিই সেটা শোধরাতে পারতেন না।
     */
    public function revoke(Request $request, DocumentShare $share): RedirectResponse
    {
        /*
         * ⛔ অন্য কোম্পানির লিংক ছোঁয়া যায় না।
         *
         * ⓘ `DocumentShare`-এ কোম্পানির ছাঁকনি আছে, কিন্তু রুট বাইন্ডিং
         * গ্লোবাল স্কোপ মানে — তাই এখানে আবার মাপা হয়। ⚠️ ৪০৪, কারণ
         * অন্য কোম্পানির সারিটার অস্তিত্বও আপনার জানার কথা নয়।
         */
        abort_unless((int) $share->company_id === (int) CompanyContext::id(), 404);

        $abilities = PaperTrail::abilitiesFor($share->document_type);

        abort_if($abilities === [], 403);

        foreach ($abilities as $ability) {
            $this->authorize($ability);
        }

        // ⛔ আসল কাগজটা এই মানুষের দেখার নাগালে কি না — শাখা, গুদাম, নিজের কাগজ (পুরো-ERP অডিট; [[visibleDocument()]])
        self::visibleDocument((string) $share->document_type, (int) $share->document_id);

        $share->forceFill(['revoked_at' => Carbon::now()])->save();

        return back()->with('saved', __('core.print.link_revoked'));
    }

    /**
     * ⭐ কাগজটা যেভাবে তার নিজের ছাপার দরজা দেখে — রুটের বাঁধন (শাখা, গুদাম, কোম্পানির সব ছাঁকনি) আর নীতির `view`
     * (পুরো-ERP অডিট, নিরাপত্তা ও সিস্টেম; fe, ১০ অক্টোবর ২০২৬; [[ARevokeAndTheHistoryStayInsideTheWallTest]])।
     *
     * ⛔ আগে লিংক বাতিল আর ছাপার ইতিহাস কেবল চাবি মাপত, কাগজটা নয়: অন্য শাখায় আটকানো কেউ চাবির জোরে নাগালের বাইরের
     * কাগজের লিংক মেরে ফেলতে পারতেন, আর ইতিহাসের পাতায় কে কবে কাকে পাঠাল — সব পড়তে পারতেন। ⓘ লিংক বানানোর পথ
     * ([[store()]]) এটা আগে থেকেই করত; এখন তিন দরজায় একই প্রশ্ন। না দেখা গেলে ৪০৪ — "আছে, কিন্তু আপনার নয়" নয়।
     */
    public static function visibleDocument(string $documentType, int $documentId): Model
    {
        $route = Route::getRoutes()->getByName(PaperTrail::DOCUMENT_ROUTES[$documentType] ?? '');
        $params = PaperTrail::routeParamsFor($documentType, $documentId);

        abort_if($route === null || $params === null, 404);

        $document = self::documentAsTheDoorSeesIt($route, $params, $documentId);
        $policy = Gate::getPolicyFor($document);

        if ($policy !== null && method_exists($policy, 'view')) {
            abort_unless(Gate::allows('view', $document), 404);
        }

        return $document;
    }

    /**
     * ঐ রুটের `can:` শর্তগুলো।
     *
     * @return list<string>
     */
    private function abilitiesOf(RoutingRoute $route): array
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
