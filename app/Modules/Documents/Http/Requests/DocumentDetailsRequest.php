<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentChoices;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ডকুমেন্টের বিবরণ — নাম, ধরন, ফোল্ডার, শাখা, বিভাগ, মালিক, তারিখ, মেয়াদ, গোপনীয়তা,
 * ট্যাগ, বিবরণ (§৬; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ বদলের ফর্ম এটাই; তোলার ফর্ম ([[DocumentUploadRequest]]) এর উপর ফাইল যোগ করে।
 * ⛔ প্রতিটা বাছাই কেবল পর্দায় যা দেখানো হয় তা-ই নেয় ([[DocumentChoices]]) —
 * অন্য শাখা, অন্য কোম্পানির বিভাগ বা মানুষ, নিজে না-দেখা গোপনীয়তার ধাপ, কোনোটাই নয়।
 */
class DocumentDetailsRequest extends FormRequest
{
    /** ⓘ অনুমতি রুটের `can:`-এ আর কন্ট্রোলারে — এখানে কেবল ঘরের যাচাই */
    public function authorize(): bool
    {
        return true;
    }

    /** ⓘ নাম ঐচ্ছিক কেবল তোলার সময় — ফাঁকা থাকলে ফাইলের নাম বসে */
    protected function nameIsRequired(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $choices = app(DocumentChoices::class);
        $companyId = CompanyContext::id();

        $levels = $user instanceof User ? array_keys($choices->levels($user)) : [];

        /* ⓘ বদলের সময় কাগজের এখনকার ধাপটাও চলে — নিজের "সংরক্ষিত" কাগজের নাম বদলাতে
           গিয়ে মালিককে ধাপ নামাতে বাধ্য করা হয় না */
        $current = $this->route('document');

        if ($current instanceof Document) {
            $levels[] = (string) $current->confidentiality;
        }
        $branches = $user instanceof User ? array_keys($choices->branches($user)) : [];
        $companyWide = $user instanceof User && $choices->companyWideAllowed($user);

        return [
            'name' => [$this->nameIsRequired() ? 'required' : 'nullable', 'string', 'max:191'],
            // ⓘ মালিকের তালিকা আর কোম্পানির নিজের চালু ধরন/ফোল্ডার ([[DocumentChoices]])
            'doc_type' => ['required', Rule::in(array_keys($choices->types()))],
            'folder' => ['required', Rule::in(array_keys($choices->folders()))],

            // ⛔ শাখা কেবল নিজের দেয়ালের ভিতরের; ফাঁকা (গোটা কোম্পানি) কেবল যিনি সব দেখেন
            'branch_id' => [$companyWide ? 'nullable' : 'required', 'integer', Rule::in($branches)],

            'department_id' => ['nullable', 'integer',
                Rule::exists('mdm_departments', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],

            'owner_id' => ['nullable', 'integer',
                Rule::exists('company_user', 'user_id')->where('company_id', $companyId)],

            'document_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:document_date'],

            'confidentiality' => ['required', Rule::in($levels)],

            'tags' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],

            ...$this->metadataRules($choices),
        ];
    }

    /**
     * বাড়তি ঘরের নিয়ম (§২০) — কেবল বাছা ধরনের কাগজে যে ঘরগুলো আসে, তাদের ধরন মেনে।
     *
     * @return array<string, mixed>
     */
    private function metadataRules(DocumentChoices $choices): array
    {
        $rules = ['meta' => ['nullable', 'array']];

        foreach ($choices->metadataFields((string) $this->input('doc_type')) as $field) {
            $rules['meta.'.$field->id] = [
                $field->is_required ? 'required' : 'nullable',
                ...match ($field->kind) {
                    'number' => ['numeric'],
                    'date' => ['date'],
                    default => ['string', 'max:500'],
                },
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('documents::field.name'),
            'doc_type' => __('documents::field.doc_type'),
            'folder' => __('documents::field.folder'),
            'branch_id' => __('documents::field.branch'),
            'department_id' => __('documents::field.department'),
            'owner_id' => __('documents::field.owner'),
            'document_date' => __('documents::field.document_date'),
            'expiry_date' => __('documents::field.expiry_date'),
            'confidentiality' => __('documents::field.confidentiality'),
            'tags' => __('documents::field.tags'),
            'description' => __('documents::field.description'),
            'files' => __('documents::field.files'),
            'files.*' => __('documents::field.file'),
            'file' => __('documents::field.file'),
            'comment' => __('documents::field.comment'),
            ...app(DocumentChoices::class)->metadataFields(all: true)
                ->mapWithKeys(fn ($f) => ['meta.'.$f->id => $f->name()])->all(),
        ];
    }
}
