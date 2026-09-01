{{--
    "দেখেছি" — একটা ছোট ফর্ম, কারণ এটা একটা লেখা, লিংক নয়।

    GET লিংক দিয়ে করলে ব্রাউজারের প্রি-ফেচ বা কারও বুকমার্কই সারিটাকে
    দেখা-হয়েছে বলে চিহ্নিত করে দিত, আর কেউ বুঝতেও পারত না।
--}}
<form method="POST" action="{{ route('governance.error.acknowledge', $row) }}">
    @csrf
    <button type="submit"
            class="rounded-(--radius-field) border border-(--color-border) px-2 py-0.5 text-2xs
                   text-(--color-ink-muted) hover:bg-(--color-surface-hover)">
        {{ __('governance::action.mark_seen') }}
    </button>
</form>
