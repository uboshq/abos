@if ($job->printUrl() !== null)
    <a class="text-(--color-link) underline"
       href="{{ $job->printUrl() }}" target="_blank" rel="noopener">
        {{ __('sales::action.print_again') }}
    </a>
@endif

<form method="POST" class="ms-3 inline"
      action="{{ route('sales.print_queue.settle', ['job' => $job->id]) }}">
    @csrf
    <button type="submit" class="text-(--color-ink-muted) underline">
        {{ __('sales::action.print_settled') }}
    </button>
</form>
