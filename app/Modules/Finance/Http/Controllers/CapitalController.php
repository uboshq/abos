<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Accounts\Services\BalanceSheetService;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use App\Modules\MasterData\Services\PersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * মূলধন ও বিনিয়োগ — কে ব্যবসায় টাকা দিলেন, আর কে কোথায় দাঁড়িয়ে।
 *
 * ── কেন এই পর্দাটা ───────────────────────────────────────────────────
 * মালিক ব্যবসার পথটা ক্রমে বললেন, আর প্রথম ধাপেই ABOS-এ কিছু ছিল না।
 * খাত ছিল, ভাউচার ছিল — পর্দা ছিল না, তাই ব্যবসার প্রথম কাজটা হত একটা
 * হাতে লেখা জাবেদা।
 */
class CapitalController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CapitalService $capital,
        private readonly PersonResolver $people,
        private readonly AttachmentEngine $attachments,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.capital.view', only: ['index']),
            /*
             * ⭐ সম্পাদনা `create`-এর চাবিতেই, নতুন কোনো চাবি নয়।
             *
             * যিনি একটা খসড়া বসাতে পারেন, তিনি সেটা শুধরাতেও পারেন —
             * দুইটা একই কাজের দুই ধাপ। ⓘ আলাদা চাবি দিলে কাউকে ভুল
             * বসানোর অধিকার দেওয়া হত, শোধরানোর নয়।
             */
            new Middleware('can:finance.capital.create', only: ['create', 'store', 'edit', 'update']),

            /*
             * ⛔ মোছা আলাদা, আর সেটা ইচ্ছাকৃত।
             *
             * বসানো আর ফেলে দেওয়া এক অধিকার নয়: হিসাবরক্ষক রোজ খসড়া
             * বসান, কিন্তু একটা সারি মুছে ফেলা মানে নথির নম্বরটা খাতা
             * থেকে উধাও হওয়া — আর সেটা ব্যাখ্যা করতে হয়।
             */
            new Middleware('can:finance.capital.delete', only: ['destroy']),

            new Middleware('can:finance.capital.post', only: ['post']),
        ];
    }

    public function index(Request $request): View
    {
        /*
         * ⭐ দুইটা ট্যাব — লেনদেন, আর মালিক ও বিনিয়োগকারী (১৯ সেপ্টেম্বর ২০২৬)।
         *
         * ── মালিকের প্রস্তাব ─────────────────────────────────────────────
         * *"মূলধন ও বিনিয়োগের ভিতরে একটা ট্যাবে 'মালিক ও বিনিয়োগকারী'…
         * বাকি বোতামগুলোতেও একই ভাবে।"* ⓘ অর্থাৎ নাম আলাদা খাতায় যায় না —
         * মানুষ থাকেন একটাই তালিকায় (মাস্টার ডেটার ব্যক্তি), আর এই ট্যাব
         * কেবল এই খাতার লেনদেন থেকে প্রতি জনের হিসাব দেখায়।
         *
         * ⓘ নামে ক্লিক করলে লেনদেন ট্যাবে কেবল তাঁর সারি (`?person=`)।
         * ⚠️ অচেনা ট্যাব চুপচাপ মানা হয় না — লেনদেনেই ফেরে।
         */
        $tab = $request->query('tab') === 'owners' ? 'owners' : 'entries';
        $personId = $request->integer('person') ?: null;

        return view('finance::capital.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'person' => $personId === null ? null : Person::query()->find($personId),
            /*
             * ⓘ `person`-ও সাথেই — প্রতিটা সারিতে নামটা দেখানো হয়, আর
             * আলাদা করে আনলে পঞ্চাশ সারির পাতায় পঞ্চাশটা বাড়তি কোয়েরি হত।
             */
            'entries' => CapitalEntry::query()->with(['account', 'person'])
                ->when($personId, fn ($q, $id) => $q->where('person_id', $id))
                ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50)->withQueryString(),
            /*
             * ⓘ মুনাফাটা স্থিতিপত্র থেকে — **একটাই উৎস**।
             *
             * ⛔ এখানে নতুন করে হিসাব করা যেত, কিন্তু তখন দুই পর্দায়
             * দুইটা মুনাফা থাকত, আর একদিন ওরা আলাদা কথা বলত।
             * ⚠️ চলতি বছরের ফল খতিয়ানেই আছে, তাই সেটাই পড়া হয়
             * ([[BalanceSheetService::build()]])।
             */
            'positions' => $this->capital->positions(
                (string) (app(BalanceSheetService::class)->build()['profit'] ?? '0'),
            ),

            /*
             * কে দিতে পারেন — মালিক, অংশীদার, আত্মীয়।
             *
             * ⓘ কেবল সক্রিয়রা: নিষ্ক্রিয় করা মানুষ আর নতুন কাগজে বসেন
             * না, কিন্তু তাঁর পুরনো সারিগুলো অটুট থাকে (সফট-ডিলিট)।
             */
            'people' => $this->peopleForPicker(),

            /*
             * টাকা যেখানে আসতে পারে — নগদ, ব্যাংক, টিল।
             *
             * গোটা ছক দিলে কেউ "বিক্রয়" খাতে মূলধন বসিয়ে দিতে পারতেন,
             * আর সেটা সারানোর একমাত্র উপায় হত একটা বিপরীত এন্ট্রি।
             */
            /*
             * নগদ ও ব্যাংকের নিচের খাতগুলো — আদায়ের পর্দা যেভাবে বাছে।
             *
             * ── কেন `is_cash` পতাকা দিয়ে নয় ─────────────────────────
             * প্রথমে ওটাই লেখা হয়েছিল, আর তালিকা খালি এল: বসানো ছকে
             * পতাকাটা কেউ তোলে না। মাথার নিচে খোঁজাটা ছকের গড়ন ধরে
             * চলে, আর ওই গড়নটা `StandardChart` নিজেই বসায়।
             */
            'accounts' => Account::query()
                ->where('is_group', false)
                ->whereIn('parent_id', Account::query()
                    ->whereIn('code', StandardChart::MONEY_PARENTS)->select('id'))
                ->orderBy('code')->get(),
        ]);
    }

    /**
     * লেখার নিয়ম — `store()` আর `update()` দুইটাই এখান থেকে নেয়।
     *
     * ⚠️ দুই জায়গায় হাতে লিখলে একদিন একটা বদলাত আর অন্যটা না — আর তখন
     * সম্পাদনার পথ দিয়ে এমন একটা মান বসানো যেত যা তৈরির পথে আটকাত।
     * ⓘ আজকের দিনের রোগটাই: একই প্রশ্নের দুইটা উত্তর।
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            /*
             * ⓘ দুইটা পথ, একটাই লাগে: তালিকা থেকে বাছা, নয় নতুন নাম
             * লেখা ([[App\Modules\MasterData\Services\PersonResolver]])।
             *
             * ⚠️ `exists`-এ `company_id` — নাহলে ঠিকানায় অন্য কোম্পানির
             * একটা আইডি বসিয়ে দিলে সেই মানুষের নামে এই কোম্পানির মূলধন
             * বসে যেত, আর কোনো পর্দায় সেটা দেখা যেত না।
             */
            'person_id' => ['nullable', 'integer', 'required_without:person_new',
                Rule::exists('mdm_people', 'id')->where('company_id', $companyId)],
            'person_new' => ['nullable', 'string', 'max:120', 'required_without:person_id'],
            'person_mobile' => ['nullable', 'string', 'max:32'],
            /*
             * ⭐ পক্ষের তিনটা ঘর — মানুষটার সাথে যায়, সারির সাথে নয়
             * ([[App\Modules\MasterData\Services\PersonResolver]])।
             *
             * ⛔ এগুলো `validate()`-এ না থাকলে **নীরবে হারায়**: Laravel
             * কেবল যাচাই করা চাবিগুলোই ফেরায়, তাই ফর্ম পাঠালেও
             * PersonResolver ঘরগুলো পেত না আর সারি বসত `NULL` নিয়ে।
             * ⓘ ১৫ সেপ্টেম্বর ২০২৬-এ লোকালে জমা দিয়ে ধরা পড়েছে।
             */
            'person_relationship' => ['nullable', 'string', 'max:60'],
            'person_address' => ['nullable', 'string', 'max:191'],
            'person_nid_tin' => ['nullable', 'string', 'max:40'],
            'contributor_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::WHO)],
            'entry_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::KINDS)],

            /*
             * ⭐ নমুনার তিনটা ঘর — ১৫ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ `received_into_account_id` ঐচ্ছিক, আর সেটাই মূল কথা:
             * খালি রাখলে রসিদের পর্দাই খাতটা ঠিক করে। ⚠️ `exists`-এ
             * `company_id`, নাহলে অন্য কোম্পানির খাতে টাকা বসত।
             */
            'in_kind' => ['nullable', Rule::in(CapitalEntry::IN_KINDS)],
            'received_into_account_id' => ['nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],

            /*
             * ⛔ কাগজের সীমা এখানে **লেখা হয় না** — নিয়মটা এক জায়গায়,
             * [[App\Core\Engines\Attachment\AttachmentEngine]]-এ। ⓘ এখানে
             * দ্বিতীয় সীমা বসালে একদিন দুইটা আলাদা সংখ্যা হত, আর
             * ব্যবহারকারী দুই রকম বার্তা পেতেন।
             */
            'paper' => ['nullable', 'file'],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * নতুন সারির পর্দা।
     *
     * ⓘ তালিকার ভেতরে গোঁজা ফর্মটা এখান থেকে সরানো হয়েছে, আর উপরে
     * "+ নতুন" বোতাম বসেছে — বাকি পর্দাগুলোর মতোই।
     */
    public function create(Request $request): View
    {
        return view('finance::capital.form', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => $this->peopleForPicker(),
            'entry' => null,
            'writingFor' => $this->writingFor(),
            'moneyAccounts' => $this->moneyAccountsForForm(),
            'carriers' => $this->carriers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $data['person_id'] = $this->people->resolve($data);

        $entry = $this->capital->record($data);

        $this->keepThePaper($request, $entry);

        return redirect()->route('finance.capital.index')
            ->with('saved', __('finance::message.capital_recorded', ['no' => $entry->document_no]));
    }

    /**
     * সম্পাদনার পর্দা — ⛔ কেবল খসড়া।
     *
     * ── কেন পোস্ট হওয়ার পর নয় ─────────────────────────────────────
     * পোস্ট মানে টাকাটা খাতায় বসে গেছে: একটা ভাউচার, দুইটা দাখিলা,
     * আর সম্ভবত একটা ব্যাংক রেফারেন্স। ⚠️ সারিটা পরে বদলালে খাতা আর
     * তালিকা দুই কথা বলত — আর কোনটা সত্যি তা বলার উপায় থাকত না।
     *
     * ⭐ নিয়মটা এই রিপোর নিজেরই, আর অন্য দুইটা মডিউলে হুবহু লেখা আছে
     * ([[Purchase\order
orm]], [[Sales\challan
orm]]): *"সম্পাদনা
     * কেবল খসড়া অবস্থায়। নিশ্চিত হওয়ার পর বদলাতে হলে বাতিল করে নতুন
     * করে।"* নতুন কিছু আবিষ্কার করা হয়নি।
     */
    public function edit(Request $request, CapitalEntry $entry): View
    {
        $this->assertStillADraft($entry);

        return view('finance::capital.form', [
            'menu' => $this->menu->forUser($request->user()),
            'people' => $this->peopleForPicker(),
            'entry' => $entry,
            'writingFor' => $this->writingFor(),
            'moneyAccounts' => $this->moneyAccountsForForm(),
            'carriers' => $this->carriers(),
        ]);
    }

    public function update(Request $request, CapitalEntry $entry): RedirectResponse
    {
        $this->assertStillADraft($entry);

        $data = $request->validate($this->rules());
        $data['person_id'] = $this->people->resolve($data);

        $this->capital->revise($entry, $data);

        return redirect()->route('finance.capital.index')
            ->with('saved', __('finance::message.capital_updated', ['no' => $entry->document_no]));
    }

    /**
     * ⛔ মোছা — আর সেটাও কেবল খসড়া।
     *
     * ⓘ পোস্ট হওয়া সারি মোছার কোনো পথ নেই, আর থাকবেও না: খতিয়ানে বসে
     * যাওয়া টাকা মুছলে ট্রায়াল ব্যালেন্স মেলে না, আর নিরীক্ষায় একটা
     * গর্ত থাকে যার কোনো ব্যাখ্যা নেই। ভুল হলে বিপরীত দাখিলা, মোছা নয়।
     */
    public function destroy(CapitalEntry $entry): RedirectResponse
    {
        $this->assertStillADraft($entry);

        $no = $entry->document_no;
        $this->capital->discard($entry);

        return redirect()->route('finance.capital.index')
            ->with('saved', __('finance::message.capital_discarded', ['no' => $no]));
    }

    /**
     * ⚠️ পাহারাটা কন্ট্রোলারে, পর্দায় নয়।
     *
     * পর্দা পোস্ট হওয়া সারির বোতামগুলো লুকায়, কিন্তু সেটা সৌজন্য —
     * ঠিকানা টাইপ করে বা পুরনো ট্যাব থেকে জমা দিলে পর্দাটা কিছুই
     * আটকাত না। ⓘ আজ ঠিক এই পার্থক্যটা মালিকানার পর্দাতেও ধরা পড়েছে:
     * **মেনুতে লুকানো আর দরজায় তালা দেওয়া এক জিনিস নয়।**
     */
    /**
     * বাছাইয়ের তালিকা — তিন জায়গা থেকে ডাকা হয়, তাই এক জায়গায়।
     *
     * ⓘ নিষ্ক্রিয় ব্যক্তি তালিকায় আসেন না, কিন্তু তাঁর পুরনো সারিগুলো
     * অটুট থাকে (সফট-ডিলিট)। ⚠️ তিন জায়গায় হাতে লিখলে একদিন একটায়
     * `active()` থাকত আর অন্যটায় না — আর তখন এক পর্দায় যাঁকে বাছা যায়
     * অন্য পর্দায় তাঁকে যেত না, কোনো ব্যাখ্যা ছাড়াই।
     *
     * @return Collection<int, string>
     */
    private function peopleForPicker(): Collection
    {
        return Person::query()->active()->orderBy('name_en')->pluck('name_en', 'id');
    }

    private function assertStillADraft(CapitalEntry $entry): void
    {
        if ($entry->status !== CapitalEntry::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('finance::validation.capital_already_posted', [
                    'no' => $entry->document_no,
                ]),
            ]);
        }
    }

    /**
     * টাকাটা এসেছে — খাতায় বসাও।
     *
     * কোন খাতে এসেছে সেটা এখানেই জিজ্ঞেস করা হয়, লেখার সময় নয়:
     * তখনো জানা ছিল না।
     */
    public function post(Request $request, CapitalEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'received_into_account_id' => ['required', 'integer',
                'exists:accounts,id'],

            /*
             * ব্যাংক বা বিকাশ যা কেটে রেখেছে।
             *
             * ⓘ `nullable`, কারণ বেশিরভাগ জমায় চার্জ থাকে না, আর
             * প্রতিবার শূন্য লিখতে বাধ্য করা মানে রোজকার কাজে একটা
             * বাড়তি ধাপ। ⚠️ "চার্জ মোটের চেয়ে ছোট" শর্তটা এখানে নয়,
             * [[CapitalService::lines()]]-এ — সেখানে মোট অঙ্কটা জানা।
             */
            'charge' => ['nullable', 'numeric', 'gte:0'],
            /*
             * ব্যাংক বা MFS হলে যে নম্বরটা লাগে — চেক নম্বর, TrxID।
             *
             * ⛔ `required` **নয়**, আর সেটা ইচ্ছাকৃত: নগদে নম্বর হয় না,
             * আর কখন নম্বর লাগবে সেটা ইতিমধ্যেই এক জায়গায় জানা —
             * [[App\Modules\Accounts\Services\VoucherService::assertBankReferenceIsFree]]।
             * এখানে `required` করলে নিয়মটা দুই জায়গায় থাকত, আর একদিন
             * দুইটা আলাদা কথা বলত (নগদেও চাওয়া, বা ব্যাংকে না চাওয়া)।
             */
            'instrument_no' => ['nullable', 'string', 'max:64'],
        ]);

        $this->capital->post(
            $entry,
            Account::query()->findOrFail($data['received_into_account_id']),
            ($data['instrument_no'] ?? '') ?: null,
            ($data['charge'] ?? '') !== '' ? (string) $data['charge'] : null,
        );

        return back()->with('saved', __('finance::message.capital_posted', ['no' => $entry->document_no]));
    }

    /**
     * কোন কোম্পানির, কোন শাখার খাতায় লেখা হচ্ছে।
     *
     * ── ⛔ কেন এটা একটা বাছাইয়ের ঘর নয় ─────────────────────────────
     * নকশার কাগজে ঘরটা ড্রপডাউন। ⚠️ কিন্তু লাইভে কোম্পানি ও শাখা আগেই
     * বাছা হয়ে আছে — শেলের উপরে, আর পুরো সেশন ধরে
     * ([[App\Core\Support\CompanyContext]])।
     *
     * ⛔ এখানে দ্বিতীয় একটা বাছাই বসালে **দুই জায়গায় দুই উত্তর** থাকত,
     * আর একদিন কেউ শেলে এক কোম্পানি দেখে অন্য কোম্পানির খাতায় লিখে
     * ফেলতেন — কিছুই ভাঙত না, শুধু টাকাটা ভুল বইয়ে বসত।
     *
     * ⓘ তাই তথ্যটা দেখানো হয়, বাছাই নয়: *"আপনি কার খাতায় লিখছেন"*।
     */
    private function writingFor(): string
    {
        $company = Company::query()->find(CompanyContext::id());
        $branch = Branch::query()->find(CompanyContext::branchId());

        return trim(implode(' — ', array_filter([
            $company?->name(),
            $branch?->name(),
        ]))) ?: '—';
    }

    /**
     * টাকার খাত — নমুনার "যে খাতে জমা"।
     *
     * ── ⭐ মালিকের নির্দেশ, ১৫ সেপ্টেম্বর ২০২৬ ───────────────────────
     * *"sample er 100% lagbe, 99.99% o na"* — সাতবার বলা।
     *
     * ⓘ আমার আপত্তি ছিল: খাতটা রসিদেও চাওয়া হয়, তাই দুই জায়গায় দুই
     * উত্তর থাকতে পারে। ⚠️ সেই ঝুঁকিটা কমানো হয়েছে **ঘরটা ঐচ্ছিক রেখে**
     * — খালি রাখলে আগের মতোই রসিদই ঠিক করে, আর ভরলে সেটা রসিদের
     * পর্দায় আগে থেকে বসানো থাকে।
     *
     * ⛔ অর্থাৎ ঘরটা **প্রস্তাব**, দ্বিতীয় দরজা নয়। টাকা নড়ে এখনো
     * একটাই জায়গা থেকে — রসিদ ভাউচার।
     *
     * @return Collection<int, Account>
     */
    private function moneyAccountsForForm(): Collection
    {
        return Account::query()
            ->money()
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_bn']);
    }

    /**
     * ফর্মের সাথে আসা কাগজটা — সারিটা বসার **পরেই**।
     *
     * ── ⛔ কেন আগে নয় ───────────────────────────────────────────────
     * কাগজ বসে `(উৎস, আইডি)` জোড়ার উপর, আর সারিটা তৈরি হওয়ার আগে
     * আইডিটাই নেই। ⓘ তাই নমুনার ঘরটা ফর্মে থাকলেও কাজটা হয় পরে।
     *
     * ── ⚠️ কাগজ আটকালে সারিটা কী হবে ────────────────────────────────
     * ⭐ সারিটা থাকে, আর ব্যবহারকারী একটা সতর্কবার্তা পান।
     *
     * ⛔ পুরোটা ফিরিয়ে দিলে যা হত: মূলধনের তথ্যটা — কে, কত, কবে —
     * হারিয়ে যেত একটা **ছবির দোষে**। ⓘ টাকার খবরটা কাগজের চেয়ে দামি,
     * আর কাগজটা পরে সারির নিজের পাতা থেকে তোলা যায়।
     */
    private function keepThePaper(Request $request, CapitalEntry $entry): void
    {
        if (! $request->hasFile('paper')) {
            return;
        }

        try {
            $this->attachments->store(
                file: $request->file('paper'),
                module: 'finance',
                entity: CapitalEntry::drillSourceType(),
                entityId: (int) $entry->getKey(),
            );
        } catch (AttachmentException $refused) {
            /*
             * ⓘ `saved` নয়, `warning` — কাজটা হয়েছে, কিন্তু অর্ধেক।
             * ⚠️ নীরবে গিলে ফেললে ব্যবহারকারী ভাবতেন কাগজটা জমা আছে,
             * আর ঝগড়ার দিন খুঁজে পেতেন না।
             */
            session()->flash('warning', __('core.attachment.refused', [
                'reason' => $refused->getMessage(),
            ]));
        }
    }

    /**
     * কে টাকাটা বয়ে এনেছেন — নমুনার "কার মাধ্যমে"।
     *
     * ⓘ তালিকাটা পক্ষের নিবন্ধন থেকেই আসে, আলাদা কোনো তালিকা নয়:
     * ক্যাশিয়ার, ডেলিভারি ম্যান, হিসাবরক্ষক — সবাই ঐ একই তালিকার সারি।
     *
     * ⚠️ দ্বিতীয় তালিকা বানালে একই মানুষ দুই জায়গায় দুই নামে থাকতেন।
     *
     * @return array<int, string>
     */
    private function carriers(): array
    {
        return Person::query()->active()->orderBy('name_en')
            ->pluck('name_en', 'id')->all();
    }
}
