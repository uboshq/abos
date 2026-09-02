<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Dashboard\ActivityRegistry;
use App\Core\Dashboard\DashboardRegistry;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\Accent;
use App\Core\Support\LookRegistry;
use App\Core\Support\Ui;
use App\Models\LookSkin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * শেলের নিজের কাজ — ড্যাশবোর্ড, কোম্পানি সুইচ, ভাষা সুইচ।
 *
 * মডিউলের কোনো কাজ এখানে নেই এবং থাকবে না; মডিউল নিজের কন্ট্রোলার নিজের
 * ফোল্ডারে রাখে (সেকশন ১৯.১)।
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DashboardRegistry $widgets,
        private readonly DashboardEngine $engine,
        private readonly ActivityRegistry $activity,
    ) {}

    /**
     * হোম পর্দা।
     *
     * ── এখানে কোনো মডিউলের নাম নেই ──────────────────────────────────
     * সংখ্যাগুলো মডিউলরা নিজেরা দেয় (module.php → widgets), আর কোর
     * কেবল জিজ্ঞেস করে। নতুন মডিউল যোগ হলে তার সংখ্যা এখানে আপনিই
     * উঠবে — এই ফাইল ছোঁয়া লাগবে না (সেকশন ১৯.৭)।
     */
    public function dashboard(Request $request): View
    {
        return view('workspace.dashboard', [
            'menu' => $this->menu->forUser($request->user()),
            'groups' => $this->widgets->forUser($request->user()),

            /*
             * ── গোটা ব্যবসার এক সারি ─────────────────────────────────
             * প্রতিটা মডিউলের মাথার সংখ্যাটা, পাশাপাশি। মালিকের
             * নির্দেশ, ২ সেপ্টেম্বর ২০২৬: Home-এ একটা **কেন্দ্রীয়**
             * ড্যাশবোর্ড থাকবে, সব নিয়ে।
             *
             * মডিউলের নিজের পর্দা গভীর — এক বিষয়ের ছয়টা সংখ্যা। এটা
             * চওড়া — বারো বিষয়ের একটা করে, আর কোথায় নামতে হবে সেই
             * সিদ্ধান্ত।
             */
            'overall' => $this->engine->overall($request->user()),

            /*
             * সদ্য যা হয়েছে — করণীয়ের পাশে।
             *
             * করণীয় বলে কী আটকে আছে; এটা বলে কী হয়ে গেছে। দিনের
             * শুরুতে মালিকের প্রথম প্রশ্নটা দ্বিতীয়টাই — "আমি না
             * থাকতে কী কী হলো"। আজ পর্যন্ত সেটার উত্তর পেতে বিক্রয়,
             * আদায়, ক্রয় ও নগদ গণনার চারটা তালিকা আলাদা করে খুলতে হত।
             */
            'happenings' => $this->activity->forUser($request->user()),
        ]);
    }

    /**
     * কম্পোনেন্ট গ্যালারি — সেকশন ১৫.২৭।
     *
     * নতুন স্ক্রিন লেখার আগে কী কী আছে দেখে নেওয়ার জায়গা, আর কোনো
     * কম্পোনেন্ট বদলালে চার প্রস্থে চোখে দেখে নেওয়ার জায়গা।
     */
    public function components(Request $request): View
    {
        return view('workspace.components', [
            'menu' => $this->menu->forUser($request->user()),
            'sampleRows' => [
                ['date' => '০৪/০৮/২০২৬', 'document' => 'INV-2026-2027-0001', 'party' => 'করিম স্টোর', 'debit' => '11,500.00', 'credit' => ''],
                ['date' => '০৪/০৮/২০২৬', 'document' => 'RCV-2026-2027-0001', 'party' => 'করিম স্টোর', 'debit' => '', 'credit' => '5,000.00'],
                ['date' => '০৫/০৮/২০২৬', 'document' => 'PUR-2026-2027-0001', 'party' => 'রহিম ট্রেডার্স', 'debit' => '8,250.50', 'credit' => ''],
            ],
        ]);
    }

    /**
     * কোম্পানি বদলানো।
     *
     * switchCompany() নিজেই যাচাই করে ব্যবহারকারীর ওই কোম্পানিতে ঢোকার
     * অধিকার আছে কি না — এখানে আবার করা হয় না, কারণ দুই জায়গায় একই
     * যাচাই মানে একদিন একটা বদলাবে আর অন্যটা থেকে যাবে।
     */
    public function switchCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $request->user()->switchCompany(
            (int) $validated['company_id'],
            isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );

        // রিডাইরেক্ট, রেন্ডার নয় — কোম্পানি বদলের পর আগের পাতার ডাটা
        // অন্য কোম্পানির, আর সেটা দেখানো মানে দুই কোম্পানি মিশে যাওয়া।
        return redirect()->route('dashboard');
    }

    /**
     * শাখা বদলানো — কোম্পানি একই, শুধু জায়গা আলাদা।
     *
     * আলাদা পদ্ধতি, কারণ প্রশ্ন দুইটা আলাদা: "আমি কোন প্রতিষ্ঠানের হয়ে
     * কাজ করছি" আর "আমি কোন শাখায় বসে আছি"। দ্বিতীয়টা দিনে কয়েকবার
     * বদলায় (গুদাম থেকে কাউন্টার), প্রথমটা মাসে একবারও নয়।
     *
     * switchCompany()-ই ডাকা হয় চলতি কোম্পানি দিয়ে — তাতে শাখাটা ওই
     * কোম্পানির কি না সেই যাচাইটা এক জায়গাতেই থাকে।
     */
    public function switchBranch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        $user->switchCompany((int) $user->current_company_id, (int) $validated['branch_id']);

        /*
         * যেখানে ছিলেন সেখানেই ফেরা — কোম্পানি বদলের মতো ড্যাশবোর্ডে নয়।
         *
         * শাখা বদলালে ডাটা বদলায় কিন্তু পর্দাটা প্রাসঙ্গিকই থাকে: গুদামের
         * তালিকা দেখতে দেখতে শাখা বদলালে মানুষটা ওই তালিকাই দেখতে চান,
         * অন্য শাখার। ড্যাশবোর্ডে ফেরত পাঠালে তাকে আবার হেঁটে আসতে হত।
         */
        return back();
    }

    /**
     * চেহারা — রং, থিম, ভাষা। ব্যক্তির পছন্দ, কোম্পানির সেটিং নয়।
     */
    public function appearance(Request $request): View
    {
        $user = $request->user();

        return view('workspace.appearance', [
            'menu' => $this->menu->forUser($user),
            'accents' => Accent::all(),
            'uis' => Ui::all(),

            /*
             * কোম্পানির নিজের রূপগুলো — কেবল প্রকাশিতগুলো।
             *
             * ── কেন এটা লাগল ────────────────────────────────────────
             * ধাপ ৩-এ Control Panel-এ রূপ বানানো যায়, কিন্তু বাছাইয়ের
             * পর্দায় ছিল কেবল দশটা কোড-রূপ। ফলে একটা কোম্পানি নিজের
             * রূপ বানাতে পারত আর **কেউ সেটা পরতে পারত না** —
             * ডাটাবেজে হাত না দিয়ে।
             *
             * যে ফিচার চালু করা যায় না, সেটা শেষ হয়নি।
             */
            'skins' => LookSkin::query()->published()->orderBy('name')->get(),
            'current' => [
                'accent' => $user->accent ?? Accent::DEFAULT,
                'theme' => $user->theme ?? 'light',
                'ui' => Ui::clean($user->ui),
                'locale' => $user->locale ?? config('app.locale'),
            ],
        ]);
    }

    public function saveAppearance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // মুক্ত পিকার নয় — যাচাই করা তালিকার বাইরের কোনো মান নেওয়া হয় না,
            // কারণ একটা অপঠনযোগ্য রঙে সেভ বোতামটাই হারিয়ে যায়।
            'accent' => ['required', 'string', Rule::in(Accent::keys())],
            'theme' => ['required', 'string', 'in:light,dark'],
            /*
             * চেহারা — এলে যাচাই হয়, না এলে যা আছে তাই থাকে।
             *
             * ── কেন `sometimes`, `required` নয় ──────────────────────
             * প্রথমে `required` লেখা হয়েছিল, আর তাতে একটা পুরোনো
             * পরীক্ষা ভাঙল: সে রং-থিম-ভাষা পাঠায়, চেহারা পাঠায় না।
             * পরীক্ষাটা সহজেই বদলানো যেত, কিন্তু সে আসলে একটা
             * সত্যিকারের ভঙ্গুরতা ধরিয়ে দিচ্ছিল।
             *
             * `required` মানে **যে কেউ এই ঠিকানায় কিছু পাঠাতে গেলে
             * চারটাই পাঠাতে হবে**। ভাষার সুইচ আর আলো/আঁধারের সুইচ
             * ইতিমধ্যেই আলাদা ঠিকানায় বসে, আর কাল যদি টপবারে একটা
             * চেহারার সুইচ বসে, তাকেও রং ও ভাষা পাঠাতে হত — নাহলে
             * ব্যবহারকারীর রং নীরবে ফিরে যেত ডিফল্টে।
             *
             * `sometimes` তাই: এলে তালিকার বাইরের মান নেওয়া হয় না
             * (নাহলে বাছাইটা নীরবে হারাত), আর না এলে কলামটা অক্ষত
             * থাকে — কারণ "পাঠাইনি" মানে "বদলাতে চাই না", "মুছে
             * ফেলো" নয়।
             */
            /*
             * কোড-রূপের নাম, নয়তো কোম্পানির একটা **প্রকাশিত** রূপের
             * `public_id` — দুইটাই এই একটা ঘরে বসে।
             *
             * খসড়া তালিকায় নেই, তাই বাছা যায় না। কেউ হাতে একটা খসড়ার
             * id পাঠালে যাচাই ফেরায় — প্রকাশের গেটটা এভাবেই একমাত্র
             * দরজা থাকে।
             */
            'ui' => ['sometimes', 'string', Rule::in([
                ...Ui::keys(),
                ...LookSkin::query()->published()->pluck('public_id')->all(),
            ])],
            /*
             * রূপের সাথে রং — বাক্সে টিক থাকলে।
             *
             * ── কেন এটা চেকবক্স, চুপচাপ নয় ───────────────────────────
             * প্রতিটা রূপের একটা মানানসই রং আছে (Odoo-র বেগুনি,
             * ক্লাসিকের অ্যাম্বার)। চুপচাপ বসিয়ে দিলে যিনি ইচ্ছে করে
             * সবুজ বেছে রেখেছিলেন, রূপ বদলানোর পর তাঁর সবুজ হারিয়ে
             * যেত — আর কেন, তা কোথাও লেখা থাকত না।
             *
             * তাই বাছাইটা দৃশ্যমান: বাক্সে টিক থাকলে রূপের নিজের রং
             * বসে, টিক তুললে আপনার রংই থাকে।
             */
            'match_accent' => ['sometimes', 'boolean'],
            'locale' => ['required', 'string', 'in:bn,en'],
        ]);

        /*
         * রূপের রং বসানোর কাজটা যাচাইয়ের পরে, সেভের আগে।
         *
         * `match_accent` নিজে কোনো কলাম নয় — ওটা একটা নির্দেশ, তাই
         * বাদ দিয়ে তার ফলটা `accent`-এ লেখা হয়। নাহলে Eloquent
         * অস্তিত্বহীন একটা কলামে লিখতে গিয়ে ভাঙত।
         */
        $matchAccent = (bool) ($validated['match_accent'] ?? false);
        unset($validated['match_accent']);

        if ($matchAccent && isset($validated['ui'])) {
            /*
             * রূপের নিজের রং — স্কিন হলে তার গোড়ার কোড-রূপের রং।
             *
             * `Ui::accent()`-কে সরাসরি একটা `public_id` দিলে সে
             * ডিফল্ট নীলে নামত। ফলে Odoo-র উপর দাঁড়ানো একটা রূপ
             * বেছে "রূপের রংটাও বসুক" টিক দিলে অবার্জিনের বদলে নীল
             * বসত — টিকটা ভুল কাজ করত, চুপচাপ।
             */
            $validated['accent'] = Ui::accent(LookRegistry::lookFor($validated['ui']));
        }

        $request->user()->forceFill($validated)->save();

        return back()->with('saved', true);
    }

    public function switchLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:bn,en'],
        ]);

        // ব্যবহারকারীর রেকর্ডে, সেশনে নয় — অন্য ডিভাইসেও একই ভাষা (নিয়ম ৯)।
        $request->user()->forceFill(['locale' => $validated['locale']])->save();

        return back();
    }

    /**
     * আলো আর অন্ধকারের মাঝে অদলবদল — টপবারের এক চাপে।
     *
     * চেহারা পাতায় এটা আগেই ছিল, কিন্তু একটা পাতা খুলে, রেডিও বেছে, সেভ
     * চেপে তবে থিম বদলানো — যে জিনিস দিনে দুবার বদলায় তার জন্য বেশি।
     * সন্ধ্যায় ডিপোর আলো কমে, আর তখনই লোকে অন্ধকার থিম চায়।
     *
     * ভাষার সুইচের মতোই ব্যবহারকারীর রেকর্ডে সেভ হয়, সেশনে নয় — বাড়ির
     * ফোনে খুললেও একই থিম (নিয়ম ৯)।
     */
    public function switchTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark'],
        ]);

        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return back();
    }
}
