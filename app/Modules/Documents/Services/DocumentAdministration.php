<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Modules\Documents\Models\AbeRule;
use App\Modules\Documents\Models\DocumentCategory;
use App\Modules\Documents\Models\DocumentTag;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Models\MetadataField;
use App\Modules\Documents\Models\RetentionPolicy;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * প্রশাসনের লেখার কাজ — ধরন, ফোল্ডার, ট্যাগ আর বাড়তি ঘর (§২০; দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কোড একবার বসলে বদলায় না ─────────────────────────────────────────
 * কাগজের সারিতে ধরন আর ফোল্ডারের **কোড** লেখা থাকে। কোড বদলালে পুরনো কাগজগুলো অনাথ হত;
 * তাই নাম বদলায়, চালু-বন্ধ হয়, মোছে না। ⓘ মালিকের তালিকার কোড ([[DocumentCatalog]]) নেওয়া
 * যায় না — একই কোডে দুই নাম হত।
 */
final class DocumentAdministration
{
    /** @var list<string> */
    public const KINDS = ['types', 'categories', 'tags', 'fields', 'classify', 'extract', 'retention'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function add(string $kind, array $input): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $companyId = (int) CompanyContext::id();
        $actor = Actor::userId();

        match ($kind) {
            'types' => DocumentType::query()->create([
                ...$this->named($input, 'dms_document_types', DocumentCatalog::TYPES),
                'company_id' => $companyId, 'is_active' => true, 'created_by' => $actor, 'updated_by' => $actor,
            ]),
            'categories' => DocumentCategory::query()->create([
                ...$this->named($input, 'dms_categories', DocumentCatalog::FOLDERS),
                'company_id' => $companyId, 'is_active' => true, 'created_by' => $actor, 'updated_by' => $actor,
            ]),
            'tags' => DocumentTag::query()->create([
                ...$this->validate($input, [
                    'name' => ['required', 'string', 'max:40',
                        Rule::unique('dms_tags', 'name')->where('company_id', $companyId)],
                ]),
                'company_id' => $companyId, 'created_by' => $actor, 'updated_by' => $actor,
            ]),
            // ⭐ ষষ্ঠ ধাপ — ABE-র নিয়ম (§৮, §২০ Intelligence Settings)
            'classify' => AbeRule::query()->create([
                ...$this->validate($input, [
                    'doc_type' => ['required', Rule::in(array_keys(app(DocumentChoices::class)->types(true)))],
                    'keywords' => ['required', 'string', 'max:500'],
                    'weight' => ['nullable', 'integer', 'between:1,10'],
                ]),
                'kind' => AbeRule::CLASSIFY, 'company_id' => $companyId, 'is_active' => true,
                'created_by' => $actor, 'updated_by' => $actor,
            ]),
            'extract' => AbeRule::query()->create([
                ...$this->validate($input, [
                    'doc_type' => ['required', Rule::in(array_keys(app(DocumentChoices::class)->types(true)))],
                    'label' => ['required', 'string', 'max:120'],
                    // ⛔ ভাঙা প্যাটার্ন আগেই ফেরে — নইলে প্রতিটা কাগজে চুপচাপ খালি ফল আসত
                    'pattern' => ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                        if (! DocumentIntelligence::validPattern((string) $value)) {
                            $fail(__('documents::message.abe_bad_pattern'));
                        }
                    }],
                ]),
                'kind' => AbeRule::EXTRACT, 'company_id' => $companyId, 'is_active' => true,
                'created_by' => $actor, 'updated_by' => $actor,
            ]),
            // ⭐ সপ্তম ধাপ — রাখার নিয়ম (§২০ Retention / Archive Policies); ⛔ চিরতরে মোছা কখনো নয়
            'retention' => RetentionPolicy::query()->create([
                ...$this->validate($input, [
                    'folder' => ['nullable', Rule::in(array_keys(app(DocumentChoices::class)->folders(true)))],
                    'doc_type' => ['nullable', Rule::in(array_keys(app(DocumentChoices::class)->types(true)))],
                    'basis' => ['required', Rule::in(RetentionPolicy::BASES)],
                    'archive_after_days' => ['nullable', 'integer', 'between:1,36500', 'required_without:bin_after_days'],
                    // ⓘ বিনের দিন আর্কাইভের পরে — আগে আর্কাইভ, তারপর বিন
                    'bin_after_days' => ['nullable', 'integer', 'between:1,36500', function ($attr, $value, $fail) use ($input) {
                        if (filled($input['archive_after_days'] ?? null) && (int) $value <= (int) $input['archive_after_days']) {
                            $fail(__('documents::message.bin_after_archive'));
                        }
                    }],
                ]),
                'company_id' => $companyId, 'is_active' => true, 'created_by' => $actor, 'updated_by' => $actor,
            ]),
            'fields' => MetadataField::query()->create([
                ...$this->named($input, 'dms_metadata_fields', [], [
                    'kind' => ['required', Rule::in(MetadataField::KINDS)],
                    'doc_type' => ['nullable', Rule::in(array_keys(app(DocumentChoices::class)->types(true)))],
                    'is_required' => ['nullable', 'boolean'],
                ]),
                'company_id' => $companyId, 'is_active' => true, 'created_by' => $actor, 'updated_by' => $actor,
            ]),
        };
    }

    /** চালু ↔ বন্ধ; ট্যাগের চালু-বন্ধ নেই, তাই ট্যাগ মোছে (কাগজের লেখা ট্যাগ থাকে) */
    public function toggle(string $kind, int $id): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        if ($kind === 'tags') {
            DocumentTag::query()->findOrFail($id)->delete();

            return;
        }

        $model = match ($kind) {
            'types' => DocumentType::class,
            'categories' => DocumentCategory::class,
            'classify', 'extract' => AbeRule::class,
            'retention' => RetentionPolicy::class,
            default => MetadataField::class,
        };

        $row = $model::query()->findOrFail($id);
        $row->update(['is_active' => ! $row->is_active, 'updated_by' => Actor::userId()]);
    }

    /**
     * কোড আর দুই ভাষার নাম — কোড ছোট হাতের ইংরেজি, অনন্য, আর মালিকের তালিকার বাইরে।
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $reserved
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function named(array $input, string $table, array $reserved, array $extra = []): array
    {
        $data = $this->validate($input, [
            'code' => ['required', 'string', 'max:24', 'regex:/^[a-z][a-z0-9_]*$/', Rule::notIn($reserved),
                Rule::unique($table, 'code')->where('company_id', CompanyContext::id())],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            ...$extra,
        ]);

        if (array_key_exists('is_required', $extra)) {
            $data['is_required'] = (bool) ($data['is_required'] ?? false);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules, [], [
            'code' => __('documents::field.code'),
            'name_en' => __('documents::field.name_en'),
            'name_bn' => __('documents::field.name_bn'),
            'name' => __('documents::field.tag'),
            'kind' => __('documents::field.field_kind'),
            'doc_type' => __('documents::field.doc_type'),
            'keywords' => __('documents::field.keywords'),
            'weight' => __('documents::field.weight'),
            'label' => __('documents::field.rule_label'),
            'pattern' => __('documents::field.pattern'),
            'basis' => __('documents::field.basis'),
            'archive_after_days' => __('documents::field.archive_after_days'),
            'bin_after_days' => __('documents::field.bin_after_days'),
        ])->validate();
    }
}
