<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\DocumentChoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ দলিলের ছাঁচ ফোনে — তালিকা আর একটা ছাঁচ পড়া (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*; fe: লেখা ও বদল
 * ডেস্কের কাজ, ওয়েবেই)।
 *
 * ⓘ ওয়েবের পাতার ([[DocumentTemplateController::index()]]) একই সারি, একই চাবি `documents.view` (দলিলের পাতার দল-চাবি)। ⓘ ছাঁচ চেনা
 * যায় public_id-তে। ⛔ কেবল পড়া — বানানো, বদল আর কাগজ তৈরি ওয়েবে।
 */
final class DocumentTemplateApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly DocumentChoices $choices) {}

    public static function middleware(): array
    {
        return [new Middleware('can:documents.view')];
    }

    /** `GET /documents/templates?page=` */
    public function index(Request $request): JsonResponse
    {
        $rows = DocumentTemplate::query()->orderBy('title')->paginate(50, ['*'], 'page', max(1, (int) $request->query('page', 1)));
        $types = $this->choices->types(true);

        return response()->json([
            'rows' => collect($rows->items())->map(fn (DocumentTemplate $t) => [
                'id' => (string) $t->public_id,
                'code' => (string) $t->code,
                'title' => (string) $t->title,
                'type' => (string) ($types[$t->doc_type] ?? $t->doc_type),
                'folder' => $this->choices->folderName($t->folder),
                'active' => (bool) $t->is_active,
            ])->values(),
            'count' => $rows->total(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ]);
    }

    /** `GET /documents/templates/{id}` — ছাঁচের লেখা, যেমন লেখা আছে (খালি ঘরগুলো `{{…}}` চিহ্নসহ) */
    public function show(string $id): JsonResponse
    {
        $t = DocumentTemplate::query()->where('public_id', $id)->firstOrFail();
        $types = $this->choices->types(true);

        return response()->json([
            'id' => (string) $t->public_id,
            'code' => (string) $t->code,
            'title' => (string) $t->title,
            'type' => (string) ($types[$t->doc_type] ?? $t->doc_type),
            'folder' => $this->choices->folderName($t->folder),
            'active' => (bool) $t->is_active,
            // ⓘ লেখা মাত্র — HTML-এর ট্যাগ ছাড়া, অনুচ্ছেদ নতুন লাইনে
            'body' => trim(html_entity_decode(strip_tags((string) preg_replace('#<\s*(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", (string) $t->body)),
                ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ]);
    }
}
