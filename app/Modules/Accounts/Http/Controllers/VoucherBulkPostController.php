<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ ভাউচারের তালিকা থেকে একসাথে পোস্ট বা অনুমোদনে পাঠানো — অনুমোদন পর্দা ভাগ ২, দফা ১৪ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬)।
 *
 * ── দুই ধাপ: আগে দেখা, তারপর করা ───────────────────────────────────────────
 * ⓘ [[review()]] কিছু লেখে না — প্রতিটা খসড়া "পোস্ট হবে" (অনুমোদন লাগে না, বা আগেই অনুমোদিত), "অনুমোদনে যাবে", নাকি "বাদ"
 * (কেন), আর প্রতিটা দলের সংখ্যা আর মোট। [[post()]] একই নিয়ম আবার চালায় (মাঝে কেউ বদলালে পুরনো দেখা মানা হয় না), তারপর
 * প্রতিটা খসড়ায় ঠিক সেই দরজা যেটা ভাউচারের নিজের "এখন পোস্ট করুন" বোতাম ডাকে ([[VoucherWriter::post()]]) — তাই একসাথে পাঠানো
 * কোনো নিয়ম এড়াতে পারে না: সই, ব্যাংকের লেনদেন নম্বর, অন্য কাগজের ভাউচার, তালা — সব একই।
 *
 * ⛔ কোথায় যাবে সেটা [[VoucherApproval::waitFor()]]-এর উত্তর — পোস্টের সারাংশ যেটা দেখায় ([[VoucherOverview]]); নিজে আলাদা হিসাব
 * করলে দেখার পাতা এক কথা বলত আর পোস্ট আরেক কাজ করত।
 *
 * ⓘ একটা আটকালে বাকিগুলো থামে না — কোনটা কেন আটকাল তা ফলের বার্তায় লেখা থাকে ([[BulkApproval]]-এর একই নীতি)।
 */
class VoucherBulkPostController extends Controller implements HasMiddleware
{
    /** ⓘ এক বারে সর্বোচ্চ — তালিকার এক পাতার মাপ (৫০), একসাথে অনুমোদনের সীমার (১০০) ভেতরে */
    public const MAX = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly VoucherApproval $approvals,
        private readonly VoucherWriter $writer,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.voucher.update')];
    }

    /** আগে দেখা — কিছু লেখে না, তাই GET */
    public function review(Request $request): View
    {
        $plan = $this->plan($this->ids($request), $request->user());

        return view('accounts::voucher.bulk-review', [
            'menu' => $this->menu->forUser($request->user()),
            ...$plan,
            'back' => $this->backUrl($request),
        ]);
    }

    public function post(Request $request): RedirectResponse
    {
        $plan = $this->plan($this->ids($request), $request->user());

        $posted = 0;
        $asked = 0;
        $refused = [];

        foreach ([...$plan['post'], ...$plan['ask']] as $voucher) {
            try {
                $stopping = $this->writer->post($voucher);
            } catch (ValidationException $e) {
                $refused[] = $voucher->document_no.': '.collect($e->errors())->flatten()->first();

                continue;
            }

            match (true) {
                $stopping === null => $posted++,
                $stopping->status === Approval::REJECTED => $refused[] = $voucher->document_no.': '.__('accounts::message.bulk_post_was_rejected'),
                default => $asked++,
            };
        }

        $note = __('accounts::message.bulk_post_done', ['posted' => $posted, 'asked' => $asked]);

        if ($plan['skip'] !== []) {
            $note .= ' · '.__('accounts::message.bulk_post_left_out', ['count' => count($plan['skip'])]);
        }

        $redirect = redirect()->to($this->backUrl($request))->with('saved', $note);

        return $refused === []
            ? $redirect
            : $redirect->with('warning', __('accounts::message.bulk_post_refused').' '.implode(' · ', $refused));
    }

    /**
     * ⭐ প্রতিটা খসড়া কোন দলে — পোস্ট, অনুমোদনে, নাকি বাদ (কারণসহ)।
     *
     * ⓘ বাদের কারণ, এই ক্রমে: খসড়া নয় → পোস্টের অনুমতি নেই → আগেই অনুমোদনের অপেক্ষায় → প্রত্যাখ্যাত আর বদলায়নি।
     *
     * @param  list<int>  $ids
     * @return array{post: list<Voucher>, ask: list<Voucher>, skip: list<array{voucher: Voucher, why: string}>, postTotal: string, askTotal: string}
     */
    private function plan(array $ids, User $user): array
    {
        $post = [];
        $ask = [];
        $skip = [];
        $postTotal = '0';
        $askTotal = '0';

        foreach (Voucher::query()->whereKey($ids)->orderBy('trx_date')->orderBy('id')->get() as $voucher) {
            $state = $voucher->isDraft() && $user->can('update', $voucher) ? $this->approvals->waitFor($voucher) : null;
            $pending = $state === 'awaiting'
                && ($action = $this->approvals->actionFor($voucher)) !== null
                && app(\App\Core\Engines\Approval\ApprovalEngine::class)->latestFor($voucher, $action)?->status === Approval::PENDING;

            $why = match (true) {
                ! $voucher->isDraft() => 'not_draft',
                ! $user->can('update', $voucher) => 'not_allowed',
                $pending => 'already_waiting',
                $state === 'rejected' => 'rejected',
                default => null,
            };

            if ($why !== null) {
                $skip[] = ['voucher' => $voucher, 'why' => $why];
            } elseif ($state === 'awaiting') {
                $ask[] = $voucher;
                $askTotal = bcadd($askTotal, (string) ($voucher->amount ?? '0'), 4);
            } else {
                $post[] = $voucher;
                $postTotal = bcadd($postTotal, (string) ($voucher->amount ?? '0'), 4);
            }
        }

        return ['post' => $post, 'ask' => $ask, 'skip' => $skip, 'postTotal' => $postTotal, 'askTotal' => $askTotal];
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX],
            'ids.*' => ['integer'],
            'back' => ['nullable', 'string', 'max:500'],
        ]);

        return array_values(array_unique(array_map('intval', $data['ids'])));
    }

    /**
     * ⓘ ফেরার ঠিকানা — কেবল এই সাইটের ভাউচার তালিকা (একই ট্যাব, একই তারিখ); অন্য কিছু এলে তালিকার মাথা। ⛔ বাইরের ঠিকানা
     * ফেরার পথে ঢুকলে পাতাটা অন্য সাইটে পাঠানোর দরজা হত।
     */
    private function backUrl(Request $request): string
    {
        $list = route('accounts.voucher.list');
        $back = (string) $request->input('back', '');

        return str_starts_with($back, $list) ? $back : $list;
    }
}
