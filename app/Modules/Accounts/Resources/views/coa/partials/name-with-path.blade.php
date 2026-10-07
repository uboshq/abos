{{--
    খোঁজার ফলে নামের সাথে পথটাও।

    গাছ থেকে বেরিয়ে এলে "ভাড়া" একা অস্পষ্ট — অফিস ভাড়া না গুদাম ভাড়া,
    খরচ না সম্পদ, বলা যায় না। পথটা সেই প্রসঙ্গটা ফিরিয়ে দেয়।
--}}
<span class="block">
    <span class="block">{{ $account->name() }}</span>

    @php $path = $account->ancestors() @endphp

    @if ($path->isNotEmpty())
        <span class="block truncate text-2xs text-(--color-ink-muted)">
            {{ $path->map(fn ($a) => $a->name())->implode(' › ') }}
        </span>
    @endif
</span>
