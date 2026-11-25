{{-- ⓘ শেষ তারিখ — সময় পেরোলে লাল আর লেখায় "পেরিয়েছে" (রং একা নয়) --}}
@if ($o->isOverdue())
    <span class="font-medium text-(--color-danger)" data-obligation-overdue>{{ \App\Core\Support\DateFormat::format($o->due_on) }} · {{ __('accounts::obligation.overdue') }}</span>
@else
    <span>{{ \App\Core\Support\DateFormat::format($o->due_on) }}</span>
@endif
