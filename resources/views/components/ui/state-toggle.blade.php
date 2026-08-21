@props([
    'active' => true,
    'size' => 'md',
    'action' => null,
    'method' => 'DELETE',
    'confirm' => null,
    'label' => null,
])

{{--
    সক্রিয় / নিষ্ক্রিয় — সুইচের চেহারায়।

    মালিকের দেওয়া নমুনা (২০২৬-০৮-০৭): সবুজ পিলে লেখা, ডানে সাদা বৃত্তে
    সবুজ টিক; লাল পিলে বাঁয়ে সাদা বৃত্তে লাল ক্রস।

    ── নবের অবস্থানটাই আসল খবর ─────────────────────────────────────────
    চালু হলে নব ডানে, বন্ধ হলে বাঁয়ে — আসল সুইচের মতোই। ফলে লেখা বা
    চিহ্ন না পড়েও এক নজরে বোঝা যায়, আর সাদাকালো প্রিন্টেও অবস্থানটা
    থেকে যায় যেখানে রং হারিয়ে যায়।

    লেখাটা তবু থাকে, আর সেটাই মূল: রং একা অর্থ বহন করে না (নিয়ম —
    ব্যাজের ফাইলের মাথায় একই কথা)। কালার-ব্লাইন্ড চোখে সবুজ আর লাল
    প্রায় এক দেখায়; তখন লেখাটাই বাঁচায়।

    ── সব মাপ em-এ, শতাংশে নয় ──────────────────────────────────────────
    প্রথমে বৃত্তের মাপ পিলের উচ্চতার শতাংশে দেওয়া হয়েছিল, আর মালিকের
    পর্দায় লেখা কেটে যাচ্ছিল — "Inactive"-এর "In" বৃত্তের নিচে ঢাকা
    পড়ত। ফন্ট বড় হলে বৃত্ত বাড়ত, পিল বাড়ত না।

    em-এ বাঁধলে ফন্টের সাথে তিনটাই একসাথে বাড়ে, আর লেখায় flex-none
    থাকায় জায়গা কম পড়লে পিলটাই চওড়া হয় — লেখা কখনো চাপে না।

    ── ক্লিক করলে বদলায়, নাকি শুধু দেখায় ───────────────────────────────
    action দিলে এটা সত্যিকারের বোতাম; না দিলে শুধু ব্যাজ।

    সুইচের চেহারা দিয়ে ক্লিকে কিছু না হওয়াটা পর্দার মিথ্যা কথা — মানুষ
    চাপবেই। তাই যেখানে বদলানোর অনুমতি আছে সেখানে action দেওয়া হয়, আর
    যেখানে নেই সেখানে চেহারাটা এক থাকলেও সেটা নিছক তথ্য।
--}}
@php
    $sizes = ['lg' => '15px', 'md' => '12.5px', 'sm' => '11px'];
    $font = $sizes[$size] ?? $sizes['md'];

    $text = $label ?? ($active ? __('core.state.active') : __('core.state.inactive'));

    /*
        পিলের রং টোকেন থেকে — থিম বদলালে এগুলোও বদলায়।

        ── কেন `--state-fill` নামে আরেকটা টোকেন ────────────────────────
        বেশিরভাগ ERP-তে অবস্থা একটা ভরাট পিল। Fiori-তে নয়: ওখানে একটা
        ছোট রঙিন ফোঁটা আর তার পাশে একই রঙের লেখা — জমিন নেই।

        `--state-fill` শূন্য হলে জমিনটা স্বচ্ছ হয়ে যায় আর লেখাটা
        অবস্থার রং নেয়। এক কম্পোনেন্ট, দুইটা চেহারা — নাহলে ফিওরির
        জন্য আলাদা একটা status কম্পোনেন্ট লিখতে হত, আর তখন দুই
        জায়গায় "সক্রিয়/নিষ্ক্রিয়"-র সংজ্ঞা থাকত।
    */
    $tone = $active ? 'var(--color-state-on)' : 'var(--color-state-off)';
    $side = $active ? 'padding-left: .85em;' : 'padding-right: .85em;';

    $skin = 'background: color-mix(in srgb, '.$tone.' calc(100% * var(--state-fill, 1)), transparent);'
        .'color: color-mix(in srgb, '.$tone.' calc(100% * (1 - var(--state-fill, 1))),'
        .' var(--color-ink-inverse) calc(100% * var(--state-fill, 1)));'
        .$side;

    $mark = $active
        ? '<path fill="var(--color-state-on)" d="M9.6 16.6 5 12l1.8-1.8 2.8 2.8L17.2 5 19 6.8l-9.4 9.8Z"/>'
        : '<path fill="var(--color-state-off)" d="m12 9.9 4.2-4.2 2.1 2.1L14.1 12l4.2 4.2-2.1 2.1L12 14.1l-4.2 4.2-2.1-2.1L9.9 12 5.7 7.8l2.1-2.1L12 9.9Z"/>';

    $pill = 'inline-flex items-center gap-[.45em] rounded-full p-[.28em] font-semibold '
        .'leading-none whitespace-nowrap select-none';

    $knob = '<span data-state-dot class="grid size-[1.5em] flex-none place-items-center'
        .' rounded-full" style="background: color-mix(in srgb, var(--color-ink-inverse)'
        .' calc(100% * var(--state-fill, 1)), '.$tone.' calc(100% * (1 - var(--state-fill, 1))))">'
        .'<svg viewBox="0 0 24 24" class="size-[.95em] block" aria-hidden="true">'.$mark.'</svg></span>';
@endphp

@if ($action)
    <form method="POST" action="{{ $action }}" class="inline">
        @csrf
        @method($method)

        <button type="submit"
                @if ($confirm) onclick="return confirm('{{ $confirm }}')" @endif
                data-state-pill {{ $attributes->merge(['class' => $pill.' cursor-pointer transition-opacity hover:opacity-85']) }}
                style="{{ $skin }} font-size: {{ $font }}">
            @if ($active)
                <span class="flex-none">{{ $text }}</span>
                {!! $knob !!}
            @else
                {!! $knob !!}
                <span class="flex-none">{{ $text }}</span>
            @endif
        </button>
    </form>
@else
    <span data-state-pill {{ $attributes->merge(['class' => $pill]) }}
          style="{{ $skin }} font-size: {{ $font }}">
        @if ($active)
            <span class="flex-none">{{ $text }}</span>
            {!! $knob !!}
        @else
            {!! $knob !!}
            <span class="flex-none">{{ $text }}</span>
        @endif
    </span>
@endif
