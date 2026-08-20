{{--
    একটা চেহারার ছোট নমুনা।

    ── কেন রঙের গোল্লা যথেষ্ট নয় ───────────────────────────────────────
    অ্যাকসেন্ট বাছার সময় গোল্লাই যথেষ্ট, কারণ ওখানে সত্যিই কেবল রংটাই
    বদলায়। এখানে বদলায় গোটা ERP — সাইডবারের চওড়া, টপবারের উচ্চতা, আর
    সবচেয়ে বেশি যেটা টের পাওয়া যায়: **এক পর্দায় কয়টা সারি ধরে**।

    দুইটা গোল্লা পাশাপাশি রাখলে ব্যবহারকারী ভাবতেন দুইটাই এক জিনিস,
    শুধু রং আলাদা। তারপর বেছে নিয়ে দেখতেন গোটা সফটওয়্যার বদলে গেছে।

    তাই নমুনায় সত্যিকারের সারি আঁকা হয়, আর ঘন চেহারায় বেশি সারি —
    ছবিটা দেখেই বোঝা যায় বাছাইটা কী বদলাবে।
--}}
@php
    $rows = $ui['density'] === 'dense' ? 7 : 5;
    $rowGap = $ui['density'] === 'dense' ? 3 : 5;
@endphp

<label @class([
    'group flex cursor-pointer flex-col gap-2 rounded-(--radius-card) border p-3 transition-colors',
    'border-(--color-brand-500) bg-(--color-surface-selected)' => $selected,
    'border-(--color-border) hover:bg-(--color-surface-hover)' => ! $selected,
])>
    <input type="radio" name="ui" value="{{ $key }}" @checked($selected) class="sr-only">

    {{-- নমুনাটা — বাঁয়ে সাইডবার, উপরে টপবার, তারপর সারি।
         রংদুইটা এই চেহারারই, তাই ছবিটা মিথ্যা বলে না। --}}
    <span class="flex h-24 overflow-hidden rounded-(--radius-field) ring-1 ring-black/10"
          style="background: #fff" aria-hidden="true">
        <span class="w-1/5 shrink-0" style="background: {{ $ui['ink'] }}"></span>

        <span class="flex min-w-0 flex-1 flex-col">
            <span class="h-3 shrink-0" style="background: {{ $ui['swatch'] }}"></span>

            <span class="flex flex-1 flex-col justify-start gap-px p-1">
                @for ($i = 0; $i < $rows; $i++)
                    <span class="block w-full rounded-[1px]"
                          style="height: {{ $rowGap }}px; background: {{ $ui['ink'] }}1a"></span>
                @endfor
            </span>
        </span>
    </span>

    <span class="flex items-center gap-2">
        <span class="text-sm font-medium">{{ __($ui['label']) }}</span>

        @if ($selected)
            <span class="text-(--color-brand-500)" aria-hidden="true">✓</span>
        @endif
    </span>

    {{-- কোনটা কার নকল — এটাই এখানকার সবচেয়ে কাজের তথ্য।

         যিনি বিশ বছর SAP চালিয়েছেন তিনি "টাইলস" নামটা চেনেন না,
         কিন্তু "SAP Fiori" দেখলেই জানেন কোনটা তাঁর। --}}
    @if ($ui['imitates'])
        <span class="inline-flex w-fit rounded-(--radius-field) bg-(--color-surface-sunken)
                     px-2 py-0.5 text-2xs text-(--color-ink-muted)">
            {{ __('core.ui.like', ['erp' => $ui['imitates']]) }}
        </span>
    @endif

    <span class="text-2xs text-(--color-ink-muted)">{{ __($ui['blurb']) }}</span>
</label>
