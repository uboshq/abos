@props(['record', 'readonly' => false])

{{--
    নিজস্ব ঘরগুলো — কোম্পানির নিজের যোগ করা।

    একই কম্পোনেন্ট দুই কাজে: ফর্মে লেখার ঘর, শো পর্দায় পড়ার লেখা। দুইটা
    আলাদা ফাইল হলে একটায় নতুন ধরন যোগ হত আর অন্যটায় হত না, আর তখন একই
    তথ্য দুই পর্দায় দুই রকম দেখাত।

    কোনো ঘর সাজানো না থাকলে কিছুই আঁকা হয় না — খালি একটা "নিজস্ব তথ্য"
    শিরোনাম প্রতিটা ফর্মে বসে থাকলে সেটা শুধু জায়গা নিত।
--}}
@php
    $service = app(\App\Core\Services\CustomFieldService::class);
    $fields = $service->fieldsFor($record::drillSourceType());
    $values = $record->exists ? $service->valuesFor($record) : [];
@endphp

@if ($fields->isNotEmpty())
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-3 font-semibold">{{ __('core.custom_field.title') }}</h2>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($fields as $field)
                @php
                    $name = 'custom['.$field->key.']';
                    $current = old('custom.'.$field->key, $values[$field->key] ?? null);
                @endphp

                @if ($readonly)
                    <div>
                        <dt class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            {{ $field->label() }}
                        </dt>
                        <dd class="mt-0.5 text-sm">
                            @if ($field->type === 'boolean')
                                {{ $current ? __('core.yes') : __('core.no') }}
                            @else
                                {{ $current !== null && $current !== '' ? $current : '—' }}
                            @endif
                        </dd>
                    </div>
                @elseif ($field->type === 'boolean')
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        {{-- লুকানো ঘরটা দরকার: টিক না দিলে ব্রাউজার
                             কিছুই পাঠায় না, আর তখন "না" আর "ছুঁইনি"
                             আলাদা করা যেত না --}}
                        <input type="hidden" name="{{ $name }}" value="0">
                        <input type="checkbox" name="{{ $name }}" value="1"
                               @checked((bool) $current) class="size-4">
                        {{ $field->label() }}
                    </label>
                @elseif ($field->type === 'select')
                    {{-- errorKey ছাড়া ভুলের বার্তা কোনোদিন দেখা যেত না:
                         ঘরের নাম custom[zone], কিন্তু ভুল লেখা হয়
                         custom.zone নামে --}}
                    <x-ui.select :name="$name" :error-key="'custom.'.$field->key"
                                 :label="$field->label()"
                                 :options="collect($field->optionList())->mapWithKeys(fn ($o) => [$o => $o])"
                                 :selected="$current" placeholder="-"
                                 :required="$field->is_required" />
                @else
                    <x-ui.field :name="$name" :error-key="'custom.'.$field->key"
                                :label="$field->label()"
                                :type="match ($field->type) { 'number' => 'number', 'date' => 'date', default => 'text' }"
                                :value="$current"
                                :required="$field->is_required" />
                @endif
            @endforeach
        </div>
    </section>
@endif
