{{-- ছাঁচ থেকে লেখা শুরু — একটা ছোট ফর্ম, কারণ এটা অবস্থা বদলায় --}}
<form method="POST" action="{{ route('system_admin.notice.template.use', $row->id) }}">
    @csrf
    <button type="submit" class="text-(--color-link) hover:underline">
        {{ __('core.notice.use_template') }}
    </button>
</form>
