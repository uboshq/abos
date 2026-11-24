<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\ListFilter\ChoiceFacet;
use App\Core\ListFilter\DateRangeFacet;
use App\Core\ListFilter\FilterSet;
use App\Core\ListFilter\Panel;
use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Services\PaperTrail;
use App\Core\Services\PartyMessageResender;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\SmsOutbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ পার্টিকে বার্তা — লেখাগুলো (বাংলা/English, কোন কাগজের জন্য) আর পাঠানোর খাতা (মালিকের "ok", ১০ নভেম্বর ২০২৬)।
 *
 * ⓘ লেখা বদলাতে কোড লাগে না — মালিক বা যাঁকে চাবি দেওয়া হয়েছে, তিনি এখান থেকেই বদলান।
 * ⛔ লেখা মুছে ফেলা যায় না, বন্ধ করা যায় — খাতার পুরনো সারিগুলো কোন লেখায় গিয়েছিল, তা যেন হারিয়ে না যায়।
 */
class PartyMessageController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * ⭐ খাতার ট্যাব → যে অবস্থাগুলো (নমুনা ট্যাব গ: সব · পৌঁছেছে · অপেক্ষায় · ব্যর্থ)।
     * ⓘ "পৌঁছেছে" = পৌঁছেছে বা পড়েছেন; "অপেক্ষায়" = সারিতে বা গেছে কিন্তু পৌঁছানোর খবর আসেনি। "খোলা হয়েছে" (wa.me) কেবল "সব"-এ —
     *   ব্রাউজার জানে না শেষে পাঠান চাপা হয়েছে কি না, তাই সেটা পৌঁছানো বা অপেক্ষা কোনোটাই নয়।
     *
     * @var array<string, list<string>>
     */
    public const TABS = [
        'all' => [],
        'delivered' => [PartyMessage::DELIVERED, PartyMessage::READ],
        'waiting' => [PartyMessage::WAITING, PartyMessage::SENT],
        'failed' => [PartyMessage::FAILED],
    ];

    public function __construct(private readonly MenuBuilder $menu) {}

    /**
     * ⭐ লেখার পাতার ট্যাব (নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ: সব · WhatsApp · SMS · বন্ধ)।
     * ⓘ পথের ট্যাবে চালু আর বন্ধ দুইটাই; "বন্ধ" কেবল বন্ধগুলো, দুই পথের।
     */
    public const TEMPLATE_TABS = ['all', PartyMessageTemplate::WHATSAPP, PartyMessageTemplate::SMS, 'off'];

    /**
     * ⭐ লেখার পাতা — বাঁয়ে তালিকা, পাশে (সরু পর্দায় নিচে) বাছা লেখার সম্পাদক (ধাপ ৩, নমুনা ট্যাব খ)।
     * ⓘ কিছু বাছা না থাকলে তালিকার প্রথমটা খোলা; তালিকা খালি হলে নতুন লেখা।
     */
    public function templates(Request $request): View
    {
        return $this->form($request, null);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new PartyMessageTemplate([
            'channel' => PartyMessageTemplate::WHATSAPP, 'is_active' => true, 'sort' => 100,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $template = PartyMessageTemplate::query()->create($this->validated($request) + ['created_by' => $request->user()?->id]);

        return redirect()->route('notification.party_templates.edit', [$template] + $this->tabOf($request))->with('saved', __('notification::party.saved'));
    }

    public function edit(Request $request, PartyMessageTemplate $template): View
    {
        return $this->form($request, $template);
    }

    public function update(Request $request, PartyMessageTemplate $template): RedirectResponse
    {
        // ⓘ লেখা বদলালে Meta-র অনুমোদনের দাগ মুছে যায় — মডেলের নিজের নিয়ম ([[PartyMessageTemplate::booted()]])
        $template->fill($this->validated($request))->save();

        return redirect()->route('notification.party_templates.edit', [$template] + $this->tabOf($request))->with('saved', __('notification::party.saved'));
    }

    /** @return array<string, string> লেখার পাতার ট্যাব — সংরক্ষণের পরে একই ট্যাবে ফেরা */
    private function tabOf(Request $request): array
    {
        $tab = (string) $request->input('tab', '');

        return in_array($tab, self::TEMPLATE_TABS, true) && $tab !== 'all' ? ['tab' => $tab] : [];
    }

    /**
     * ⭐ খাতা — WhatsApp আর SMS এক তালিকায়, প্রতিটার অবস্থাসহ (ধাপ ৩, মালিকের "ok" ২৩ নভেম্বর ২০২৬, নমুনা
     * HUXqpfhxng8823eEFrvH54, ট্যাব গ)।
     *
     * ⓘ মাথা: নাম · ট্যাব (সব / পৌঁছেছে / অপেক্ষায় / ব্যর্থ) · ছাঁকনি … নামান (Excel) — টুলবারের মান; নিচে আজকের চার সংখ্যা।
     * ⓘ ছাঁকনি সাধারণ প্যানেলে ([[Panel]]): তারিখ (বারে), কাগজ, কে পাঠালেন; খোঁজা টুলবারে — আগের ঠিকানার চাবিগুলোই
     *   (`from`, `to`, `document_type`, `sent_by`, `q`), তাই পুরনো বুকমার্ক চলে।
     * ⓘ শাখার দেয়াল: যিনি সব শাখা দেখেন না, তিনি নিজের নাগালের শাখার সারিই দেখেন ([[ScopedToUserBranch]])।
     */
    public function messages(Request $request, PartyMessageResender $resender, PartyRegistry $parties): View
    {
        $tab = in_array($request->query('tab'), array_keys(self::TABS), true) ? (string) $request->query('tab') : 'all';
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $filters = $this->filters();

        $base = fn () => PartyMessage::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('document_no', 'like', '%'.$search.'%')
                ->orWhere('party_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')));

        // ⓘ লেখাটা পুরো — Cloud পাঠাতে পারে কি না তার প্রশ্ন লেখার নিজের ঘর পড়ে ([[WhatsAppCloud::canSend()]])
        $query = $base()->with(['sender:id,name', 'template']);
        $filters->apply($query, $request);

        if (self::TABS[$tab] !== []) {
            $query->whereIn('status', self::TABS[$tab]);
        }

        $rows = $query->orderByDesc('id')->paginate(PerPage::of(self::PER_PAGE))->withQueryString();
        $items = collect($rows->items());

        $title = (string) __('notification::party_log.title');
        app(ListExport::class)->describe(['title' => $title]);

        return view('notification::party.messages', [
            'menu' => $this->menu->forUser($request->user()),
            'title' => $title,
            'rows' => $rows,
            'tab' => $tab,
            'tabs' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => __('notification::party_log.tabs.'.$key),
                'url' => $request->url().'?'.http_build_query(array_merge($request->except(['tab', 'page', 'export']), $key === 'all' ? [] : ['tab' => $key])),
                'active' => $key === $tab,
            ], array_keys(self::TABS)),
            'filters' => Panel::of($filters, $base, $request),
            'today' => $this->today(),
            // ⓘ "WhatsApp নেই → SMS-এ গেছে" — ব্যর্থ Cloud সারির পরের SMS সারি আছে কি না
            'fellBack' => PartyMessage::query()->whereIn('fallback_of_id', $items->pluck('id')->all() ?: [0])
                ->pluck('fallback_of_id')->map(fn ($id) => (int) $id)->flip()->all(),
            // ⓘ "নিজে থেকে · অফিসের ফোন ১" — কোন ফোন SMS-টা নিয়েছিল
            'phones' => SmsOutbox::query()->with('gatewayPhone:id,name')
                ->whereIn('id', $items->pluck('sms_outbox_id')->filter()->all() ?: [0])
                ->get(['id', 'gateway_phone_id'])
                ->mapWithKeys(fn (SmsOutbox $o) => [(int) $o->id => $o->gatewayPhone?->name])->all(),
            // ⓘ "পার্টির খাতা খুলুন" — পার্টির নিজের পাতা ([[PartyRegistry::routesOf()]]; বন্ধ মডিউলে নেই)
            'partyRoutes' => $parties->routesOf($items->map(fn (PartyMessage $m) => [(string) $m->party_type, (int) $m->party_id])->all()),
            'roads' => $this->roads($items, $resender),
        ]);
    }

    /** ⭐ "লেখাটা দেখুন" — ঠিক যে লেখা গেছে; তালিকার উপর পপআপে খোলে (সাধারণ লিংক, [[peek]]) */
    public function message(Request $request, PartyMessage $message): View
    {
        return view('notification::party.message', [
            'menu' => $this->menu->forUser($request->user()),
            'message' => $message->loadMissing(['sender:id,name', 'template:id,name']),
        ]);
    }

    /**
     * ⭐ আবার পাঠান — একই পথে, নতুন সারি ([[PartyMessageResender::again()]]); wa.me-র সারি আবার wa.me খোলে।
     * ⛔ কাগজের সারিতে কাগজের নিজের ছাপার চাবি আর নাগালও — প্রথমবার পাঠানোর একই অধিকার ([[PartyMessageGuard]])।
     */
    public function resend(PartyMessage $message, PartyMessageResender $resender): RedirectResponse
    {
        return $this->afterSend(fn () => $resender->again($message));
    }

    /** ⭐ অন্য পথে পাঠান — SMS ↔ WhatsApp (Cloud তৈরি থাকলে Cloud, নইলে wa.me) */
    public function otherRoad(PartyMessage $message, PartyMessageResender $resender): RedirectResponse
    {
        return $this->afterSend(fn () => $resender->otherRoad($message));
    }

    /** @param  \Closure(): array{message: PartyMessage, url: ?string}  $send */
    private function afterSend(\Closure $send): RedirectResponse
    {
        try {
            $sent = $send();
        } catch (ValidationException $e) {
            return back()->with('failed', (string) collect($e->errors())->flatten()->first());
        }

        if ($sent['url'] !== null) {
            return redirect()->away($sent['url']);
        }

        return back()->with('saved', $sent['message']->status === PartyMessage::FAILED
            ? __('notification::party_log.resent_failed', ['reason' => (string) $sent['message']->error])
            : __('notification::party_log.resent'));
    }

    /**
     * আজকের চার সংখ্যা — পাঠানো · পৌঁছেছে · অপেক্ষায় · ব্যর্থ (দেয়ালের ভিতরে, ছাঁকনি ছাড়া)।
     *
     * @return array{sent: int, delivered: int, waiting: int, failed: int}
     */
    private function today(): array
    {
        $by = PartyMessage::query()->where('created_at', '>=', Carbon::today())
            ->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s) => $by[$s] ?? 0);

        return [
            'sent' => (int) $by->sum(),
            'delivered' => $sum(self::TABS['delivered']),
            'waiting' => $sum(self::TABS['waiting']),
            'failed' => $sum(self::TABS['failed']),
        ];
    }

    /**
     * সারির মেনুর দুই কাজ এখন চলে কি না — না চললে কারণ (মেনুতে ধূসর, নিচে কারণ)।
     *
     * ⓘ পথের সুইচ একবারই পড়া, সারি ধরে নয় ([[PartyMessageResender]] মনে রাখে)।
     *
     * @param  \Illuminate\Support\Collection<int, PartyMessage>  $items
     * @return array<int, array{again: ?string, other: ?string, other_road: ?string}>  id => [আবারের বাধা, অন্য পথের বাধা, অন্য পথ]
     */
    private function roads(\Illuminate\Support\Collection $items, PartyMessageResender $resender): array
    {
        $out = [];

        foreach ($items as $row) {
            $other = $resender->otherRoadOf($row);
            $out[(int) $row->id] = ['again' => $resender->againBlockedBy($row), 'other' => $other['why'], 'other_road' => $other['road']];
        }

        return $out;
    }

    private function filters(): FilterSet
    {
        $types = [];
        foreach (array_keys(PaperTrail::DOCUMENT_ROUTES) as $type) {
            $types[$type] = ['label' => (string) __('notification::party.types.'.$type), 'apply' => fn (Builder $q) => $q->where('document_type', $type)];
        }
        $types[PartyMessage::PARTY_ACCOUNT] = ['label' => (string) __('notification::party_log.party_account'),
            'apply' => fn (Builder $q) => $q->where('document_type', PartyMessage::PARTY_ACCOUNT)];

        $who = [];
        foreach (User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereIn('id', PartyMessage::query()->select('sent_by')->whereNotNull('sent_by')->distinct())
            ->orderBy('name')->get(['id', 'name']) as $sender) {
            $id = (int) $sender->id;
            $who[(string) $id] = ['label' => (string) $sender->name, 'apply' => fn (Builder $q) => $q->where('sent_by', $id)];
        }

        return new FilterSet([
            (new DateRangeFacet('date', __('notification::party_log.date'), function (Builder $q, ?string $from, ?string $to): void {
                if ($from !== null) {
                    $q->where('created_at', '>=', Carbon::parse($from)->startOfDay());
                }
                if ($to !== null) {
                    $q->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay());
                }
            }, fromKey: 'from', toKey: 'to'))->look('sky', '◷')->onTheBar(),
            (new ChoiceFacet('document_type', __('notification::party_log.paper'), $types))->look('blue', '▣'),
            (new ChoiceFacet('sent_by', __('notification::party_log.who'), $who))->look('violet', '●'),
            // ⓘ নিজে থেকে পাঠানোর নিয়ম — "খাতায় দেখুন" এখানে আসে `rule=payment_received` নিয়ে ([[PartyAutoController]])
            (new ChoiceFacet('rule', __('notification::party_log.rule'), [
                PartyMessage::DUE_REMINDER => ['label' => (string) __('notification::party_log.rules.due_reminder'),
                    'apply' => fn (Builder $q) => $q->where('rule', PartyMessage::DUE_REMINDER)],
                PartyMessage::PAYMENT_RECEIVED => ['label' => (string) __('notification::party_log.rules.payment_received'),
                    'apply' => fn (Builder $q) => $q->where('rule', PartyMessage::PAYMENT_RECEIVED)],
            ]))->look('amber', '◆'),
        ]);
    }

    /**
     * ⭐ লেখার পাতা — তালিকা (ট্যাব ধরে) আর পাশে সম্পাদক; তালিকা, নতুন আর সম্পাদনা একই পাতা (নমুনা ট্যাব খ)।
     */
    private function form(Request $request, ?PartyMessageTemplate $template): View
    {
        PartyMessageTemplate::ensureStarters((int) CompanyContext::id());

        $tab = in_array($request->query('tab'), self::TEMPLATE_TABS, true) ? (string) $request->query('tab') : 'all';

        $all = PartyMessageTemplate::query()->orderBy('channel')->orderByRaw('document_type IS NULL DESC')
            ->orderBy('document_type')->orderBy('sort')->orderBy('id')->get();

        $rows = $all->filter(fn (PartyMessageTemplate $t): bool => match ($tab) {
            PartyMessageTemplate::WHATSAPP, PartyMessageTemplate::SMS => $t->channel === $tab,
            'off' => ! $t->is_active,
            default => true,
        });

        // ⓘ খোঁজা আর ছাঁকনি — কেবল তালিকার সারিতে; ট্যাবের গোনা আর পাশের সম্পাদক আগের মতো
        $find = mb_strtolower($request->string('q')->trim()->value());
        $type = $request->string('type')->value();
        $typeNames = self::templateTypes();
        $typeNames = collect($typeNames)
            ->mapWithKeys(fn ($key) => [(string) $key => (string) __('notification::party.types.'.$key)])->all();
        $rows = $rows->filter(fn (PartyMessageTemplate $t): bool => ($find === '' || str_contains(mb_strtolower((string) $t->name), $find))
            && ($type === '' || $t->document_type === $type))->values();

        $template ??= $rows->first() ?? new PartyMessageTemplate([
            'channel' => $tab === PartyMessageTemplate::SMS ? PartyMessageTemplate::SMS : PartyMessageTemplate::WHATSAPP,
            'is_active' => true, 'sort' => 100,
        ]);

        return view('notification::party.templates', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'tab' => $tab,
            'counts' => [
                'all' => $all->count(),
                PartyMessageTemplate::WHATSAPP => $all->where('channel', PartyMessageTemplate::WHATSAPP)->count(),
                PartyMessageTemplate::SMS => $all->where('channel', PartyMessageTemplate::SMS)->count(),
                'off' => $all->where('is_active', false)->count(),
            ],
            'template' => $template,
            'types' => self::templateTypes(),
            'typeNames' => $typeNames,
            // ⓘ Meta-র অনুমোদনে পাঠানো — Cloud API-র কাজ যেদিন বসে (আলাদা সাহায্যকারীর রুট); না থাকলে বোতামই নেই
            'metaSubmit' => \Illuminate\Support\Facades\Route::has('notification.party_templates.meta_submit'),
        ]);
    }

    /**
     * ⓘ "কোন কাগজ" — ছাপার দরজার সব কাগজ, আর "পার্টির হিসাব" (বাকির তাগাদা, হিসাব বিবরণী — একটা কাগজের নয়, পার্টির পুরো খাতার)।
     *
     * @return list<string>
     */
    public static function templateTypes(): array
    {
        return array_merge([PartyMessage::PARTY_ACCOUNT], array_keys(PaperTrail::DOCUMENT_ROUTES));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⭐ ধাপ ৩: WhatsApp বা SMS — প্রতিটা লেখা একটা পথের (নমুনা ট্যাব খ, "পথ")
            'channel' => ['required', Rule::in(PartyMessageTemplate::CHANNELS)],
            'document_type' => ['nullable', Rule::in(self::templateTypes())],
            'body_bn' => ['required', 'string', 'max:1000'],
            'body_en' => ['required', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $errors = [];

        foreach (['body_bn', 'body_en'] as $part) {
            if ($unknown = PartyMessageTemplate::unknownIn($data[$part])) {
                $errors[$part] = __('notification::party.unknown', ['names' => implode(', ', array_map(fn ($n) => '{'.$n.'}', $unknown))]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'channel' => $data['channel'],
            'document_type' => $data['document_type'] ?? null,
            'body_bn' => str_replace("\r\n", "\n", $data['body_bn']),
            'body_en' => str_replace("\r\n", "\n", $data['body_en']),
            'sort' => (int) ($data['sort'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
