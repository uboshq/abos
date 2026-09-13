<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
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
            new Middleware('can:finance.capital.create', only: ['store', 'edit', 'update']),

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
        return view('finance::capital.index', [
            'menu' => $this->menu->forUser($request->user()),
            /*
             * ⓘ `person`-ও সাথেই — প্রতিটা সারিতে নামটা দেখানো হয়, আর
             * আলাদা করে আনলে পঞ্চাশ সারির পাতায় পঞ্চাশটা বাড়তি কোয়েরি হত।
             */
            'entries' => CapitalEntry::query()->with(['account', 'person'])
                ->orderByDesc('trx_date')->orderByDesc('id')->paginate(50),
            'positions' => $this->capital->positions(),

            /*
             * কে দিতে পারেন — মালিক, অংশীদার, আত্মীয়।
             *
             * ⓘ কেবল সক্রিয়রা: নিষ্ক্রিয় করা মানুষ আর নতুন কাগজে বসেন
             * না, কিন্তু তাঁর পুরনো সারিগুলো অটুট থাকে (সফট-ডিলিট)।
             */
            'people' => Person::query()->active()->orderBy('name_en')
                ->pluck('name_en', 'id'),

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
            'contributor_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::WHO)],
            'entry_type' => ['required', 'string', 'in:'.implode(',', CapitalEntry::KINDS)],
            'trx_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $data['person_id'] = $this->people->resolve($data);

        $entry = $this->capital->record($data);

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

        return $this->index($request)->with('editing', $entry);
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
        );

        return back()->with('saved', __('finance::message.capital_posted', ['no' => $entry->document_no]));
    }
}
