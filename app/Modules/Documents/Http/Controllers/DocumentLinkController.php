<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentLink;
use App\Modules\Documents\Services\DocumentLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * কাগজ জোড়া — গ্রাহক, সরবরাহকারী, ক্রয়ের কাগজ, কর্মী (§১৫; চতুর্থ ধাপ)।
 *
 * ⓘ খোঁজ বিস্তারিত পাতায় (`?link_type=…&link_q=…`), জোড়া এখানে। চাবি রুটের `can:link,document`-এ।
 */
final class DocumentLinkController extends Controller
{
    public function __construct(private readonly DocumentLinks $links) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'source_type' => ['required', Rule::in(array_keys($this->links->types()))],
            'source_id' => ['required', 'integer', 'min:1'],
        ], [], [
            'source_type' => __('documents::field.link_type'),
            'source_id' => __('documents::field.link_record'),
        ]);

        $this->links->link($document, $data['source_type'], (int) $data['source_id'], $user);

        return redirect()->to(route('documents.show', $document).'#links')->with('saved', __('documents::message.linked'));
    }

    public function destroy(Document $document, DocumentLink $link): RedirectResponse
    {
        $this->links->unlink($document, $link);

        return redirect()->to(route('documents.show', $document).'#links')->with('saved', __('documents::message.unlinked'));
    }
}
