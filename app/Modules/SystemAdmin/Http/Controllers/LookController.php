<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\LookSkinService;
use App\Core\Services\MenuBuilder;
use App\Core\Support\LookFile;
use App\Core\Support\LookPreview;
use App\Core\Support\LookSchema;
use App\Core\Support\Ui;
use App\Http\Controllers\Controller;
use App\Models\LookSkin;
use App\Models\LookSkinVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * কোম্পানির নিজের রূপ — থিম ইঞ্জিনের ধাপ ৩ (অংশ ৭, ৮, ১০)।
 *
 * ── কেন Control Panel-এ, "চেহারা" পর্দায় নয় ─────────────────────────
 * `/appearance` ব্যক্তির পর্দা: এক কম্পিউটারে দিনে তিনজন বসেন, আর
 * প্রত্যেকে নিজের রং বাছেন। এটা কোম্পানির — একজন বানান, সবাই দেখেন।
 *
 * দুইটা এক পর্দায় রাখলে প্রথম প্রশ্নটাই হত "এটা কি শুধু আমার?", আর
 * ভুল উত্তরের দাম বড়: কেউ পরীক্ষা করতে গিয়ে গোটা ডিপোর রং বদলে ফেলতেন।
 *
 * ── তিনটা দরজা, তিনটা আলাদা কাজ ─────────────────────────────────────
 * সংরক্ষণ খসড়া বদলায় — কারো পর্দা বদলায় না।
 * প্রিভিউ কেবল নিজের সেশনে দেখায় — কারো পর্দা বদলায় না।
 * প্রকাশ সবার পর্দা বদলায় — আর সেখানেই গেট।
 */
class LookController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly LookSkinService $looks,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.look.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::looks.index', [
            'menu' => $this->menu->forUser($request->user()),
            'skins' => LookSkin::query()->orderBy('name')->get(),
            'parents' => $this->parentChoices(new LookSkin),
            'previewing' => LookPreview::skin(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new LookSkin([
            'parent' => Ui::DEFAULT,
            'tokens' => ['light' => [], 'dark' => []],
        ]));
    }

    public function edit(Request $request, LookSkin $skin): View
    {
        return $this->form($request, $skin);
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * `company_id` এখানে বসানো হয় না — `BelongsToCompany` বসায়।
         *
         * প্রথম লেখায় ছিল `$request->user()->company_id`, আর ওটা
         * **সবসময় নাল**: `users`-এ ওই ঘরটাই নেই, আছে
         * `current_company_id`। ট্রেইট নাল দেখে নিজেই ঠিকটা বসাত, তাই
         * কিছুই ভাঙত না — একটা ভুল লাইন যা কাজ করে বলে মনে হয়।
         *
         * ওরকম লাইন থাকলে পরের কেউ ওটা কপি করে এমন জায়গায় বসাত
         * যেখানে ট্রেইট নেই, আর তখন সারিটা কোম্পানি ছাড়াই বসত।
         */
        $skin = LookSkin::create([
            ...$this->validated($request, null),
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('system_admin.look.edit', $skin)
            ->with('saved', __('core.look.saved'));
    }

    /**
     * খসড়া সংরক্ষণ।
     *
     * `published_at` এখানে ছোঁয়া হয় না, আর সেটাই মূল কথা: সংরক্ষণ
     * করলে কারো পর্দা বদলায় না।
     */
    public function update(Request $request, LookSkin $skin): RedirectResponse
    {
        $skin->update($this->validated($request, $skin));

        return back()->with('saved', __('core.look.saved'));
    }

    /**
     * খসড়াটা সবার পর্দায় তোলা।
     *
     * গেটটা সেবার ভিতরে, এখানে নয় — আমদানি বা কোনো কমান্ডও একদিন
     * প্রকাশ করবে, আর তখন যাচাইটা কন্ট্রোলারে থাকলে সে পথটা ফাঁকা যেত।
     */
    public function publish(Request $request, LookSkin $skin): RedirectResponse
    {
        $note = $request->validate([
            'note' => ['nullable', 'string', 'max:200'],
        ])['note'] ?? null;

        $this->looks->publish($skin, $note, $request->user()->id);

        return back()->with('saved', __('core.look.published_ok'));
    }

    /** পুরনো একটা সংস্করণে ফেরা — ইতিহাস মুছে নয়, তার উপরে বসিয়ে। */
    public function revert(Request $request, LookSkin $skin, LookSkinVersion $version): RedirectResponse
    {
        $this->looks->revert($skin, $version, $request->user()->id);

        return back()->with('saved', __('core.look.reverted_ok', ['n' => $version->version]));
    }

    /**
     * নিজের সেশনে রূপটা পরে দেখা।
     *
     * খসড়াও দেখা যায় — ওটাই প্রিভিউয়ের গোটা কারণ। প্রকাশিতটা দেখতে
     * প্রিভিউ লাগে না, সেটা তো এমনিতেই পর্দায়।
     */
    public function preview(LookSkin $skin): RedirectResponse
    {
        LookPreview::start($skin);

        return back()->with('saved', __('core.look.preview_started'));
    }

    public function previewStop(): RedirectResponse
    {
        LookPreview::stop();

        return back()->with('saved', __('core.look.preview_stopped'));
    }

    /**
     * রূপটা একটা ফাইল হয়ে নেমে আসে।
     *
     * ── কেন রপ্তানির খাতায় (`ExportJournal`) তোলা হয় না ───────────────
     * ওই খাতাটা রাখা হয় কারণ একটা তালিকার রপ্তানিতে ক্রয়মূল্য ও
     * গ্রাহকের তথ্য বেরিয়ে যায়। রূপের ফাইলে **কোনো ব্যবসায়িক তথ্য
     * নেই** — কেবল রঙের কোড। ওটাকেও খাতায় তুললে খাতাটা এমন সারিতে
     * ভরে যেত যেগুলো কোনো প্রশ্নের উত্তর দেয় না, আর যেগুলো সত্যিই
     * দেয় সেগুলো খুঁজে পাওয়া কঠিন হত।
     */
    public function export(LookSkin $skin): Response
    {
        $said = LookFile::from($skin);

        /*
         * ফাইলের নামে `public_id`-র প্রথম আটটা অক্ষর।
         *
         * নামটা বাংলায় হতে পারে, আর কিছু ব্রাউজার ও উইন্ডোজের ফাইল
         * ম্যানেজার তাতে অদ্ভুত আচরণ করে। রূপের নামটা ফাইলের ভিতরেই
         * আছে, তাই ফাইলের নামটা কেবল আলাদা করতে পারলেই যথেষ্ট।
         */
        $file = 'abos-look-'.substr((string) $skin->public_id, 0, 8).'.json';

        return response(
            (string) json_encode($said, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$file.'"',
            ],
        );
    }

    /**
     * একটা ফাইল থেকে নতুন খসড়া রূপ।
     *
     * ── কেন সবসময় নতুন, কখনো "উপরে বসানো" নয় ─────────────────────────
     * একই নামের রূপ থাকলে ফাইলটা তার উপরে বসিয়ে দেওয়া যেত। বসানো
     * হয় না: তাতে কারো কয়েক সপ্তাহের কাজ এক ক্লিকে মুছে যেত, আর
     * ফাইলটা ঠিক না ভুল সেটা বসানোর **পরে** জানা যেত।
     *
     * নতুন একটা খসড়া বসে, নাম আলাদা হয়, আর দুইটা পাশাপাশি রেখে
     * মিলিয়ে দেখা যায়। পুরনোটা মুছতে চাইলে সেটা আলাদা সিদ্ধান্ত।
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            /*
             * `mimes` নয়, `extensions`— JSON ফাইলের MIME টাইপ
             * ব্রাউজার ও অপারেটিং সিস্টেম ভেদে `application/json`,
             * `text/plain`, এমনকি `application/octet-stream` হয়। ওটা
             * ধরে আটকালে ঠিক ফাইলও অর্ধেক মেশিনে ফেরত যেত।
             *
             * নিরাপত্তা এখান থেকে আসে না — আসে `LookFile`-এর যাচাই
             * থেকে, যা প্রতিটা নাম ও মান স্কিমার সাথে মেলায়।
             */
            'file' => ['required', 'file', 'extensions:json', 'max:512'],
        ]);

        $said = json_decode(
            (string) $request->file('file')->get(),
            true,
        );

        if (! is_array($said)) {
            return back()->withErrors(['file' => __('core.look.file_not_json')]);
        }

        $skin = LookFile::into($said, $request->user()->id);

        return redirect()
            ->route('system_admin.look.edit', $skin)
            ->with('saved', __('core.look.imported_ok', ['name' => $skin->name]));
    }

    private function form(Request $request, LookSkin $skin): View
    {
        return view('system_admin::looks.form', [
            'menu' => $this->menu->forUser($request->user()),
            'skin' => $skin,
            'parents' => $this->parentChoices($skin),
            'known' => LookSchema::known(),
            'versions' => $skin->exists
                ? $skin->versions()->with('publisher')->get()
                : collect(),
            'complaints' => $skin->exists ? $skin->complaints() : [],
        ]);
    }

    /**
     * কোন রূপের উপর দাঁড়ানো যায়।
     *
     * ── কেন নিজেকে বাদ দেওয়া হয় ─────────────────────────────────────
     * নিজের উপর দাঁড়ালে চেইনটা এক ধাপেই চক্র। `MAX_DEPTH` ওটা ধরে
     * ফেলে বটে, কিন্তু বাছাইয়ের তালিকাতেই না রাখাই ভালো — যে ভুলটা
     * করাই যায় না, সেটা ধরার দরকারও পড়ে না।
     *
     * @return array<string, string>
     */
    private function parentChoices(LookSkin $skin): array
    {
        $choices = [];

        foreach (Ui::keys() as $key) {
            $choices[$key] = __('core.ui.'.$key);
        }

        $others = LookSkin::query()
            ->when($skin->exists, fn ($q) => $q->whereKeyNot($skin->id))
            ->orderBy('name')
            ->get();

        foreach ($others as $other) {
            $choices[$other->public_id] = $other->name;
        }

        return $choices;
    }

    /**
     * ফর্মের মান — নাম, পূর্বপুরুষ, আর টোকেনের জোড়াগুলো।
     *
     * ── কেন টোকেনগুলো এখানেও যাচাই হয়, প্রকাশের গেট থাকা সত্ত্বেও ────
     * গেট প্রকাশ আটকায়। কিন্তু বানান-ভুল একটা টোকেন খসড়ায় চুপচাপ বসে
     * থাকলে মানুষ ভাবতেন সেটা কাজ করছে, আর সপ্তাহখানেক পরে প্রকাশের
     * সময় হঠাৎ একগাদা অভিযোগ দেখতেন — ততক্ষণে কোনটা কখন লেখা হয়েছিল
     * তা আর মনে নেই।
     *
     * ভুলটা যেখানে হয়, সেখানেই বলা হয়।
     *
     * ── কেন কনট্রাস্ট গেট এখানে নয় ───────────────────────────────────
     * অর্ধেক লেখা একটা রূপ প্রায় সবসময়ই গেট ফেল করে — জমিন বদলেছে,
     * কালি এখনো বদলায়নি। সেভেই আটকালে কাজটা শুরুই করা যেত না।
     *
     * @return array{name: string, parent: string, tokens: array{light: array<string, string>, dark: array<string, string>}}
     *
     * @throws ValidationException
     */
    private function validated(Request $request, ?LookSkin $skin): array
    {
        $said = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent' => [
                'required', 'string', 'max:64',
                Rule::in(array_keys($this->parentChoices($skin ?? new LookSkin))),
            ],
            'tokens' => ['array', 'max:200'],
            'tokens.*.name' => ['nullable', 'string', 'max:80'],
            'tokens.*.light' => ['nullable', 'string', 'max:200'],
            'tokens.*.dark' => ['nullable', 'string', 'max:200'],
        ]);

        $light = [];
        $dark = [];

        foreach ($said['tokens'] ?? [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;   // খালি নাম মানে "এই সারিটা বাদ দিলাম"
            }

            if (trim((string) ($row['light'] ?? '')) !== '') {
                $light[$name] = trim((string) $row['light']);
            }

            if (trim((string) ($row['dark'] ?? '')) !== '') {
                $dark[$name] = trim((string) $row['dark']);
            }
        }

        $complaints = [
            ...LookSchema::complaints($light),
            ...LookSchema::complaints($dark),
        ];

        if ($complaints !== []) {
            throw ValidationException::withMessages([
                'tokens' => array_values(array_unique($complaints)),
            ]);
        }

        return [
            'name' => $said['name'],
            'parent' => $said['parent'],
            'tokens' => ['light' => $light, 'dark' => $dark],
        ];
    }
}
