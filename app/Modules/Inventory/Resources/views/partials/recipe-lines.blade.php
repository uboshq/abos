{{-- উপকরণের সংখ্যা।

     শূন্য মানে ওই খাবার বেচলে গুদাম থেকে কিছুই কমবে না — তাই সতর্কতার
     রঙে, আর রং একা অর্থ বহন করে না বলে `title`-এ কথাটাও লেখা
     (নিয়ম ১৪.৯)। --}}
@if ($recipe->lines->isEmpty())
    <span class="num font-semibold text-(--color-danger)"
          title="{{ __('inventory::message.recipe_has_no_lines') }}">0</span>
@else
    <span class="num">{{ $recipe->lines->count() }}</span>
@endif
