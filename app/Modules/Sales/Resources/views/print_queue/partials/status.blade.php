<x-ui.badge :tone="$job->status === \App\Modules\Sales\Models\PrintJob::FAILED ? 'danger' : 'warn'">
    {{ __('sales::message.print_'.$job->status) }}
</x-ui.badge>
