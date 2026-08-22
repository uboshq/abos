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

{{--
    বাছাইয়ের ছাপটা রেডিও থেকে, সার্ভার থেকে নয়।

    ── কী ভাঙা ছিল ─────────────────────────────────────────────────
    ক্লাসগুলো `$selected` দেখে বসত, আর `$selected` আসত সার্ভার
    থেকে। রেডিওটা `sr-only`, অর্থাৎ চোখে পড়ে না। ফলে কার্ডে ক্লিক
    করলে রেডিওটা ঠিকই বাছা হত, কিন্তু **পর্দায় একটুও কিছু বদলাত
    না** — বর্ডার একই, ✓ একই জায়গায়।

    ব্যবহারকারীর কাছে সেটা "মাউস কাজ করছে না"। তিনি আবার ক্লিক
    করেন, আবার কিছু হয় না, তৃতীয়বারে লেখাটা সিলেক্ট হয়ে যায়।
    সংরক্ষণ টিপলে বদলটা ঘটত — কিন্তু ততক্ষণে তিনি ধরে নিয়েছেন
    পাতাটা ভাঙা, আর টেপেনই না।

    `has-[:checked]` ব্যবহারকারীর ক্লিকের সাথে সাথেই ছাপটা বসায়,
    আর সার্ভারের `@checked` প্রথম রেন্ডারেই ঠিক কার্ডটা বেছে রাখে —
    তাই দুইটা উৎস আর আলাদা কথা বলে না।
--}}
<label class="group flex cursor-pointer select-none flex-col gap-2 rounded-(--radius-card)
              border border-(--color-border) p-3 transition-colors
              hover:bg-(--color-surface-hover)
              has-[:checked]:border-(--color-brand-500)
              has-[:checked]:bg-(--color-surface-selected)
              has-[:focus-visible]:outline-2
              has-[:focus-visible]:outline-offset-2
              has-[:focus-visible]:outline-(--color-brand-500)">
    <input type="radio" name="ui" value="{{ $key }}" @checked($selected) class="sr-only">

    {{--
        নমুনাটা — রং, ঘনত্ব, **আর মেনু কোথায়**।

        ── প্রথম লেখায় ছবিটা মিথ্যা বলত ────────────────────────────
        প্রতিটা কার্ডে বাঁয়ে একটা রেল আঁকা হত, অথচ আটটার চারটায়
        (Classic · Tiles · Suite · Apps) রেল **নেই** — মেনু উপরে।
        অর্থাৎ ছবিটা ওই চারটার সবচেয়ে বড় তফাতটাই লুকিয়ে রাখত, আর
        বাছার পর মানুষ অন্য জিনিস পেতেন।

        একটা নমুনার একমাত্র কাজ সত্যি বলা। রং ঠিক অথচ বিন্যাস ভুল
        হলে ওটা নমুনা নয়, সাজসজ্জা।
    --}}
    <span class="flex h-24 overflow-hidden rounded-(--radius-field) ring-1 ring-black/10"
          style="background: #fff" aria-hidden="true">

        @if ($ui['nav'] === 'rail')
            <span class="w-1/5 shrink-0" style="background: {{ $ui['ink'] }}"></span>
        @endif

        <span class="flex min-w-0 flex-1 flex-col">
            <span class="h-3 shrink-0" style="background: {{ $ui['swatch'] }}"></span>

            {{-- মেনু উপরে হলে বারের নিচে আরেকটা সরু পটি — ওটাই
                 নেভিগেশন, আর ওটা থাকা মানেই বাঁয়ে কিছু নেই। --}}
            @if ($ui['nav'] === 'top')
                <span class="flex h-2 shrink-0 items-stretch gap-px px-0.5"
                      style="background: {{ $ui['ink'] }}">
                    @for ($i = 0; $i < 5; $i++)
                        <span class="block flex-1"
                              style="background: {{ $i === 1 ? $ui['swatch'] : 'transparent' }}"></span>
                    @endfor
                </span>
            @endif

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

        {{-- ✓ সবসময় আঁকা থাকে, কেবল বাছা অবস্থায় দেখা যায় — নাহলে
             ওটা বসানোর জন্য আরেকটা সার্ভার রেন্ডার লাগত। --}}
        <span class="hidden text-(--color-brand-500) group-has-[:checked]:inline"
              aria-hidden="true">✓</span>
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
