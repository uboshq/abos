<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Services\DocumentGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * কাগজ-ধরে অধিকার — একজন মানুষ বা ভূমিকাকে একটা কাগজে দেখা/নামানো/ছাপা/শেয়ার/বদল
 * (§১৩; দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ চাবি রুটের `can:grant,document`-এ ([[DocumentPolicy::grant()]])। ⛔ কেবল এই কোম্পানির
 * মানুষ বা ভূমিকা ([[DocumentGrants::people()]], [[DocumentGrants::roles()]]) — অন্য কোম্পানির
 * id দিলে ফর্মের ভুল।
 */
final class DocumentGrantController extends Controller
{
    public function __construct(private readonly DocumentGrants $grants) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $type = (string) $request->input('grantee_type');
        $allowed = $type === DocumentGrant::ROLE ? $this->grants->roles() : $this->grants->people();

        $data = $request->validate([
            'grantee_type' => ['required', Rule::in(DocumentGrants::TYPES)],
            'grantee_id' => ['required', 'integer', Rule::in(array_keys($allowed))],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => [Rule::in(array_keys(DocumentGrant::ABILITIES))],
        ], [], [
            'grantee_type' => __('documents::field.grantee_type'),
            'grantee_id' => __('documents::field.grantee'),
            'abilities' => __('documents::field.abilities'),
        ]);

        /*
         * ⛔ নিজেকে অধিকার নয় (১১ অক্টোবর ২০২৬, documents রিভিউ ⚠️১০)। ⓘ `documents.permissions` থাকা মানুষ যে কাগজ দেখেন তাতে
         * নিজেকে বদল, শেয়ার বা নামানোর অধিকার দিতে পারতেন — চাবির বাইরে নিজের হাত বাড়ানো। অন্যকে দেওয়া চলে, নিজেকে নয়।
         */
        if ($data['grantee_type'] === DocumentGrant::USER && (int) $data['grantee_id'] === (int) $request->user()?->getKey()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['grantee_id' => __('documents::message.not_to_yourself')]);
        }

        $this->grants->grant($document, $data['grantee_type'], (int) $data['grantee_id'], array_values($data['abilities'] ?? []));

        return redirect()->to(route('documents.show', $document).'#access')
            ->with('saved', __('documents::message.access_saved'));
    }

    public function destroy(Document $document, DocumentGrant $grant): RedirectResponse
    {
        $this->grants->revoke($document, $grant);

        return redirect()->to(route('documents.show', $document).'#access')
            ->with('saved', __('documents::message.access_removed'));
    }
}
