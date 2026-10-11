<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Services\DocumentGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * শেয়ার — ABOS-এর ভিতরের মানুষ বা ভূমিকাকে, দেখা বা নামানো, ঐচ্ছিক মেয়াদসহ (§১৪; চতুর্থ ধাপ)।
 *
 * ⛔ ইন্টারনেটের খোলা লিংক নয়। ⓘ কেবল এই কোম্পানির মানুষ বা ভূমিকা ([[DocumentGrants]])।
 * চাবি রুটের `can:share,document`-এ ([[DocumentPolicy::share()]])।
 */
final class DocumentShareController extends Controller
{
    public function __construct(private readonly DocumentGrants $grants) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $type = (string) $request->input('grantee_type');
        $allowed = $type === DocumentGrant::ROLE ? $this->grants->roles() : $this->grants->people();

        $data = $request->validate([
            'grantee_type' => ['required', Rule::in(DocumentGrants::TYPES)],
            'grantee_id' => ['required', 'integer', Rule::in(array_keys($allowed))],
            'download' => ['nullable', 'boolean'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], [
            'grantee_type' => __('documents::field.grantee_type'),
            'grantee_id' => __('documents::field.grantee'),
            'expires_on' => __('documents::field.share_until'),
        ]);

        /*
         * ⛔ নিজেকে শেয়ার নয়, আর যা নিজের হাতে নেই তা দেওয়া নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️৪)। ⓘ আগে কেবল
         * `documents.share` থাকা মানুষ নিজেকেই "নামানো সহ" শেয়ার করে নামিয়ে নিতেন — নামানোর চাবি ছাড়াই।
         */
        $this->refuseSelfAndBeyond($request, $data['grantee_type'], (int) $data['grantee_id'],
            (bool) ($data['download'] ?? false) && ! ($request->user()?->can('download', $document) ?? false));

        $this->grants->share(
            $document,
            $data['grantee_type'],
            (int) $data['grantee_id'],
            (bool) ($data['download'] ?? false),
            filled($data['expires_on'] ?? null) ? Carbon::parse($data['expires_on']) : null,
        );

        return redirect()->to(route('documents.show', $document).'#share')->with('saved', __('documents::message.shared'));
    }

    private function refuseSelfAndBeyond(Request $request, string $type, int $granteeId, bool $givesWhatTheyLack): void
    {
        if ($type === DocumentGrant::USER && $granteeId === (int) $request->user()?->getKey()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['grantee_id' => __('documents::message.not_to_yourself')]);
        }

        if ($givesWhatTheyLack) {
            throw \Illuminate\Validation\ValidationException::withMessages(['download' => __('documents::message.cannot_give_download')]);
        }
    }
}
