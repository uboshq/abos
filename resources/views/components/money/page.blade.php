@props(['kind', 'title', 'icon' => 'wallet', 'count' => null])
{{--
    ⭐ টাকার পাতার রঙিন মাথা — মালিক, ৬ নভেম্বর ২০২৬ ("ঋণ, আদায়, পরিশোধ, খরচ, জাবেদা, কন্ট্রা, সব ভাউচার, টাকা ও হেফাজত, টাকা
    হস্তান্তর — ei skin gulo update koro r colorfull koro"; নমুনা "ok")।

    পাতার নিজের রঙে একটা সরু পট্টি: চিহ্ন, নাম, কয়টা, আর "নতুন" বোতাম। নিচে পাতার বাকিটা — টুলবার, তালিকা, নিচের মোটের
    সারি — একই রং পায় `data-kind` থেকে (`app.css`)।

    ⛔ মালিক: চারটা অঙ্কের ঘর নয় ("ei box gulor dorkar nai"); নামের নিচে কাজের লেখা নয় ("ei likha gulo na dile fita ta ro
    cikon hoy skin e jayga bare"); টুলবারের সব বোতাম থাকবে ("egulo kintu thakbe"); নিচের সারি থাকবে ("egulo dibe")।
--}}
<div data-kind="{{ $kind }}" {{ $attributes }}>
    <div data-money-hero class="mb-3 flex flex-wrap items-center gap-3 rounded-(--radius-card) px-3 py-2 shadow-(--shadow-card)">
        <span data-hero-icon class="grid size-9 shrink-0 place-items-center rounded-(--radius-field)" aria-hidden="true">
            <x-ui.icon :name="$icon" :size="20" />
        </span>

        <h1 class="text-lg leading-tight font-bold">{{ $title }}</h1>

        @if ($count)
            <span data-hero-count class="text-sm font-medium opacity-90">{{ $count }}</span>
        @endif

        @isset($actions)
            <div data-hero-actions class="print-hide ms-auto flex flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
    <span data-money-hero-end hidden></span>

    {{ $slot }}
</div>
