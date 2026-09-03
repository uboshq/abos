{{--
    পার্টির আচরণ — Status বলে না, কিন্তু কাউন্টারে যা জানা দরকার।

    চলমান পতাকা গুরুত্বের ক্রমে (ঝুঁকি আগে), প্রতিটায় কে ও কবে — পুরনো
    পতাকা তাজার চেয়ে হালকা পড়া উচিত। নামানো পতাকা মোছা হয় না, নিচে
    ইতিহাসে থাকে। ধরন বাঁধা তালিকা থেকে (মুক্ত লেখা নয়), OTHER-এ নোট লাগে।

    এক কোয়েরিতে সব নোট, তারপর PHP-তে চলমান/নামানো ভাগ।
--}}
@php
    use App\Modules\Customer\Support\ConductType;

    $notes = $customer->conductNotes;
    $rank = ['risk' => 0, 'notice' => 1, 'good' => 2];
    $active = $notes->where('is_active', true)
        ->sortBy(fn ($n) => $rank[$n->severity()] ?? 1)->values();
    $retired = $notes->where('is_active', false)->values();
    $tone = ['risk' => 'danger', 'notice' => 'pending', 'good' => 'success'];
@endphp

<section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="font-semibold">{{ __('customer::conduct.title') }}</h2>

    @can('customer.conduct.manage')
        <form method="POST" action="{{ route('customer.conduct.store', $customer) }}"
              class="mt-3 grid items-end gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto]">
            @csrf

            <div>
                <label for="conduct_type" class="mb-1 block text-sm font-medium">
                    {{ __('customer::conduct.type_field') }}
                </label>
                <select id="conduct_type" name="type"
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border px-3
                               bg-(--color-surface-card)
                               @error('type') border-(--color-danger) @else border-(--color-border) @enderror">
                    @foreach (ConductType::grouped() as $group => $types)
                        <optgroup label="{{ $group }}">
                            @foreach ($types as $code => $label)
                                <option value="{{ $code }}" @selected(old('type') === $code)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <x-ui.field name="note" :label="__('customer::conduct.note')"
                        :hint="__('customer::conduct.note_hint')" :value="old('note')" />

            <x-ui.button type="submit" tone="primary">{{ __('customer::conduct.record') }}</x-ui.button>
        </form>
    @endcan

    {{-- চলমান পতাকা --}}
    @if ($active->isNotEmpty())
        <div class="mt-4 space-y-2">
            @foreach ($active as $note)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-(--radius-field)
                            border border-(--color-border) px-3 py-2">
                    <x-ui.badge :tone="$tone[$note->severity()] ?? 'draft'">{{ $note->label() }}</x-ui.badge>

                    @if (filled($note->note))
                        <span class="text-sm text-(--color-ink)">{{ $note->note }}</span>
                    @endif

                    {{-- কে ও কবে — পুরনো পতাকা যেন হালকা পড়া যায় --}}
                    <span class="text-2xs text-(--color-ink-muted)">
                        {{ __('customer::conduct.by_line', [
                            'who' => $note->recorder?->name ?? '—',
                            'date' => \App\Core\Support\DateFormat::format($note->recorded_at),
                        ]) }}
                    </span>

                    @can('customer.conduct.manage')
                        <form method="POST" action="{{ route('customer.conduct.retire', $note) }}"
                              class="ms-auto"
                              onsubmit="return confirm('{{ __('customer::conduct.was_retired') }}')">
                            @csrf
                            <button type="submit"
                                    class="text-2xs text-(--color-ink-muted) underline hover:text-(--color-ink)">
                                {{ __('customer::conduct.retire') }}
                            </button>
                        </form>
                    @endcan
                </div>
            @endforeach
        </div>
    @else
        <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('customer::conduct.none') }}</p>
    @endif

    {{-- নামানো পতাকা — মোছা নয়, ইতিহাসে থাকে --}}
    @if ($retired->isNotEmpty())
        <details class="mt-4">
            <summary class="cursor-pointer text-2xs text-(--color-ink-muted)">
                {{ __('customer::conduct.retired_heading') }} ({{ $retired->count() }})
            </summary>
            <div class="mt-2 space-y-1">
                @foreach ($retired as $note)
                    <div class="flex flex-wrap items-center gap-x-3 px-3 py-1 text-2xs text-(--color-ink-muted)">
                        <span class="line-through">{{ $note->label() }}</span>
                        @if (filled($note->note))<span>{{ $note->note }}</span>@endif
                        <span>{{ __('customer::conduct.retired_by_line', [
                            'who' => $note->retirer?->name ?? '—',
                            'date' => $note->retired_at ? \App\Core\Support\DateFormat::format($note->retired_at) : '—',
                        ]) }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</section>
