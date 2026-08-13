{{--
    মিলে গেলে শুধু একটা টিক — শূন্য লেখা থাকলে চোখ প্রতিটা সারিতে থামে,
    অথচ মিলে যাওয়াটাই স্বাভাবিক অবস্থা।
--}}
@if ($count->matches())
    <span class="text-(--color-success)" aria-label="{{ __('accounts::message.count_matches') }}">✓</span>
@else
    <span class="num font-semibold text-(--color-danger)">
        {{ $count->isSurplus() ? '+' : '' }}{{ \App\Core\Support\Money::format($count->difference) }}
    </span>
@endif
