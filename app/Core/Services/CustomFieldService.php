<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Drill\DrillResolver;
use App\Core\Module\ModuleRegistry;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নিজস্ব ঘর — কোম্পানির নিজের যোগ করা তথ্য।
 *
 * ── কেন এটা কোরে ────────────────────────────────────────────────────
 * প্রতিটা মডিউলে আলাদা করে লিখলে ছয় জায়গায় একই কোড থাকত, আর যাচাইয়ের
 * ভুলটা যেকোনো একটাতে থেকে যেত। কোন জিনিসে নিজস্ব ঘর বসানো যায় সেটা
 * মডিউল নিজে ঘোষণা করে (module.php → custom_fields), তাই কোর কোনো
 * মডিউলের নাম জানে না।
 */
class CustomFieldService
{
    /** পাঁচটা ধরন — তার বেশি নয়, কারণ প্রতিটা নতুন ধরন মানে প্রতিটা পর্দা আবার দেখা। */
    public const TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly DrillResolver $drill,
    ) {}

    /**
     * যেসব জিনিসে নিজস্ব ঘর বসানো যায় — মডিউলদের ঘোষণা থেকে।
     *
     * @return array<string, string> entity => মডিউলের নাম
     */
    public function entities(): array
    {
        $entities = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->customFields as $entity) {
                $entities[$entity] = $module->label();
            }
        }

        return $entities;
    }

    /**
     * একটা জিনিসের সচল ঘরগুলো — ক্রম অনুযায়ী।
     *
     * @return Collection<int, CustomField>
     */
    public function fieldsFor(string $entity): Collection
    {
        return CustomField::query()
            ->where('entity', $entity)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * একটা রেকর্ডের মানগুলো — চাবি => মান।
     *
     * @return array<string, string|null>
     */
    public function valuesFor(Model $record): array
    {
        $entity = $this->entityOf($record);

        return CustomFieldValue::query()
            ->where('entity', $entity)
            ->where('entity_id', $record->getKey())
            ->with('field')
            ->get()
            ->mapWithKeys(fn (CustomFieldValue $value) => [$value->field?->key => $value->value])
            ->filter(fn ($value, $key) => $key !== null)
            ->all();
    }

    /**
     * রেকর্ডের নিজস্ব ঘরগুলো সংরক্ষণ।
     *
     * @param  array<string, mixed>  $input  চাবি => মান
     */
    public function save(Model $record, array $input): void
    {
        $entity = $this->entityOf($record);
        $fields = $this->fieldsFor($entity);

        if ($fields->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($record, $entity, $fields, $input) {
            foreach ($fields as $field) {
                $value = $this->clean($field, $input[$field->key] ?? null);

                /*
                 * খালি মান মানে সারিটাই থাকবে না।
                 *
                 * খালি লেখা জমা রাখলে "কোন ঘরগুলো ভরা হয়েছে" প্রশ্নের
                 * উত্তরে খালি সারিও গোনা হত, আর রিপোর্টে ফাঁকা ঘরও
                 * "আছে" দেখাত।
                 */
                if ($value === null) {
                    CustomFieldValue::query()
                        ->where('custom_field_id', $field->id)
                        ->where('entity_id', $record->getKey())
                        ->delete();

                    continue;
                }

                CustomFieldValue::query()->updateOrCreate(
                    [
                        'custom_field_id' => $field->id,
                        'entity_id' => $record->getKey(),
                    ],
                    [
                        'company_id' => $record->company_id,
                        'entity' => $entity,
                        'value' => $value,
                    ],
                );
            }
        });
    }

    /**
     * একটা মান — যাচাই করে, সংরক্ষণের রূপে।
     */
    private function clean(CustomField $field, mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : $raw;

        if ($field->type === 'boolean') {
            // পতাকার ঘরে "খালি" বলে কিছু নেই — হয় হ্যাঁ, নয় না
            return filled($value) && (string) $value !== '0' ? '1' : '0';
        }

        if (blank($value)) {
            if ($field->is_required) {
                throw ValidationException::withMessages([
                    'custom.'.$field->key => __('core.custom_field.required_field', ['label' => $field->label()]),
                ]);
            }

            return null;
        }

        return match ($field->type) {
            'number' => $this->number($field, (string) $value),
            'date' => $this->date($field, (string) $value),
            'select' => $this->choice($field, (string) $value),
            default => (string) $value,
        };
    }

    private function number(CustomField $field, string $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'custom.'.$field->key => __('core.custom_field.not_a_number', ['label' => $field->label()]),
            ]);
        }

        return $value;
    }

    private function date(CustomField $field, string $value): string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'custom.'.$field->key => __('core.custom_field.not_a_date', ['label' => $field->label()]),
            ]);
        }
    }

    /**
     * বাছাইয়ের ঘরে ঘোষিত বিকল্পের বাইরের মান নয়।
     *
     * না দেখলে ঠিকানায় হাতে লিখে যেকোনো মান বসানো যেত, আর তখন
     * রিপোর্টে এমন একটা দল দেখা যেত যেটা কেউ কখনো সংজ্ঞায়িত করেনি।
     */
    private function choice(CustomField $field, string $value): string
    {
        if (! in_array($value, $field->optionList(), true)) {
            throw ValidationException::withMessages([
                'custom.'.$field->key => __('core.custom_field.unknown_choice', ['label' => $field->label()]),
            ]);
        }

        return $value;
    }

    /**
     * রেকর্ডটা কোন জিনিস — drill source-এর নাম।
     */
    private function entityOf(Model $record): string
    {
        if (! method_exists($record, 'drillSourceType')) {
            throw ValidationException::withMessages([
                'custom' => __('core.custom_field.unknown_entity'),
            ]);
        }

        return $record::drillSourceType();
    }
}
