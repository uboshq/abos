{{--
    একটা ঘরের মান — ধরন অনুযায়ী।

    সুইচ ✓ / — হিসেবে, কারণ "true"/"1" পড়তে হয় না, দেখা যায়। বাছাই করা
    মানগুলো অনুবাদ হয়ে আসে (ভ্যাট, খুচরা), কাঁচা কোড নয়।
--}}
@php $value = $record->{$column} @endphp

@switch ($field['type'])
    @case ('switch')
        <span @class(['text-(--color-success)' => $value, 'text-(--color-ink-placeholder)' => ! $value])>
            {{ $value ? '✓' : '—' }}
        </span>
        @break

    @case ('number')
        <span class="num">{{ $value === null ? '' : rtrim(rtrim(\App\Core\Support\Money::format($value, 4), '0'), '.') }}</span>
        @break

    @case ('select')
        @php
            $source = $field['options'];
            $list = $options[$source] ?? [];
        @endphp

        {{-- ⛔ দরকারি ঘর খালি — শুরুর সারি (seed) ইচ্ছে করে খাত ছাড়া বসে, কোম্পানি পরে বসায়; "—" দেখালে মনে হত সব ঠিক।
             লাল চিপ, আর চাপলে সম্পাদনায় (পাতা-ঝাড়ু ধাপ ০-এর ৮, ১৩ অক্টোবর ২০২৬) --}}
        @if (blank($value) && in_array('required', $field['rules'] ?? [], true) && isset($spec))
            <a href="{{ route('master_data.'.$spec['route'].'.edit', $record->id) }}" data-required-missing="{{ $column }}">
                <x-ui.badge tone="danger">{{ __('master_data::message.required_not_set') }}</x-ui.badge>
            </a>
        @elseif ($field['labels'] ?? false)
            {{-- ধ্রুবকের তালিকা — কোন ফাইলে অনুবাদ তা ঘরের ঘোষণায় --}}
            {{ $value ? __('master_data::' . $field['labels'] . '.' . $value) : '—' }}
        @else
            {{-- সম্পর্কিত রেকর্ড — id নয়, নাম --}}
            {{ collect($list)->firstWhere('id', $value)?->name() ?? '—' }}
        @endif
        @break

    @default
        {{ $value }}
@endswitch
