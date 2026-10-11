<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\DocumentChoices;
use App\Modules\Documents\Services\DocumentTemplates;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ছাঁচ আর সম্পাদক — ছাঁচের তালিকা, লেখা (সম্পাদক), আর ভরে কাগজ বানানো (§২; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ চাবি: তালিকা দেখা `documents.view`; ছাঁচ লেখা `documents.templates`; ভরে কাগজ বানানো `documents.upload`।
 */
final class DocumentTemplateController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentChoices $choices,
        private readonly DocumentTemplates $templates,
    ) {}

    public function index(Request $request): View
    {
        return view('documents::templates.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => DocumentTemplate::query()->orderBy('title')->paginate(50)->withQueryString(),
            'choices' => $this->choices,
        ]);
    }

    /** সম্পাদক — নতুন ছাঁচ বা পুরনোটা বদল, নিচে আজকের নমুনা */
    public function editor(Request $request, ?DocumentTemplate $template = null): View
    {
        $template ??= new DocumentTemplate(['doc_type' => 'letter', 'folder' => 'company', 'body' => '']);

        return view('documents::templates.editor', [
            'menu' => $this->menu->forUser($request->user()),
            'template' => $template,
            'types' => $this->choices->types(),
            'folders' => $this->choices->folders(),
            'builtIns' => DocumentTemplates::BUILT_IN,
            'preview' => $template->exists ? $this->templates->html((string) $template->body, [], $this->user($request)) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = DocumentTemplate::query()->create([
            ...$this->validated($request, null),
            'company_id' => CompanyContext::id(),
            'is_active' => true,
            'created_by' => Actor::userId(),
            'updated_by' => Actor::userId(),
        ]);

        return redirect()->route('documents.templates.edit', $template)->with('saved', __('documents::message.template_saved'));
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $template->update([...$this->validated($request, $template), 'updated_by' => Actor::userId()]);

        return redirect()->route('documents.templates.edit', $template)->with('saved', __('documents::message.template_saved'));
    }

    public function fill(Request $request, DocumentTemplate $template): View
    {
        $user = $this->user($request);

        return view('documents::templates.fill', [
            'menu' => $this->menu->forUser($user),
            'template' => $template,
            'variables' => $this->templates->variables((string) $template->body),
            'branches' => $this->choices->branches($user),
            'companyWide' => $this->choices->companyWideAllowed($user),
            'defaultBranch' => $this->choices->defaultBranch($user),
            'levels' => $this->choices->levels($user),
            'internal' => DocumentCatalog::INTERNAL,
        ]);
    }

    public function generate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($template->is_active, 404);

        $variables = $this->templates->variables((string) $template->body);
        $companyWide = $this->choices->companyWideAllowed($user);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'branch_id' => [$companyWide ? 'nullable' : 'required', 'integer', Rule::in(array_keys($this->choices->branches($user)))],
            'confidentiality' => ['required', Rule::in(array_keys($this->choices->levels($user)))],
            'values' => ['nullable', 'array'],
            ...collect($variables)->mapWithKeys(fn ($v) => ['values.'.$v => ['nullable', 'string', 'max:2000']])->all(),
        ], [], ['branch_id' => __('documents::field.branch'), 'confidentiality' => __('documents::field.confidentiality')]);

        $document = $this->templates->generate($template, array_map('strval', array_intersect_key((array) ($data['values'] ?? []), array_flip($variables))), [
            'name' => $data['name'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'confidentiality' => $data['confidentiality'],
            'owner_id' => $user->getKey(),
        ], $user);

        return redirect()->route('documents.show', $document)->with('saved', __('documents::message.uploaded_one', ['no' => $document->document_no]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DocumentTemplate $template): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('dms_templates', 'code')->where('company_id', CompanyContext::id())->ignore($template?->id)],
            'title' => ['required', 'string', 'max:120'],
            'doc_type' => ['required', Rule::in(array_keys($this->choices->types()))],
            'folder' => ['required', Rule::in(array_keys($this->choices->folders()))],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'code' => __('documents::field.code'), 'title' => __('documents::field.template_title'),
            'doc_type' => __('documents::field.doc_type'), 'folder' => __('documents::field.folder'),
            'body' => __('documents::field.template_body'),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
