{{-- ⓘ শেষ তারিখ — সময় পেরোলে লাল আর লেখায় "পেরিয়েছে" (রং একা নয়) --}}
@if ($o->isOverdue())
    <span class="font-medium text-(--color-danger)" data-obligation-overdue>{{ $o->due_on->format('d-m-Y') }} · {{ __('accounts::obligation.overdue') }}</span>
@else
    <span>{{ $o->due_on?->format('d-m-Y') }}</span>
@endif
