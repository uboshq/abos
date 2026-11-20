{{--
    ⭐ একটা রোলের পুরো অনুমতির ছক — ছাপুন · Excel-এর উৎস ([[RoleController::matrix()]]; মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬)।
    ⓘ `?export=print` / `?export=xlsx` হলে সাধারণ রপ্তানির পথ (ExportListing) এই টেবিলটাই কাগজ বা ফাইল বানায়; খালি ঠিকানায়
      পাতাটা পর্দাতেই ছক দেখায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::permission.matrix_title', ['name' => \App\Core\Support\RoleLabel::for($role->name)]) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::permission.matrix_title', ['name' => \App\Core\Support\RoleLabel::for($role->name)])"
                          :subtitle="__('system_admin::permission.matrix_legend')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('system_admin.role.edit', $role)">{{ __('system_admin::permission.back_to_role') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <section data-boxed data-role-matrix-export class="role-main">
        <div class="role-table-wrap">
            <table class="role-list-table">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-role-matrix-row>
                            @foreach ($columns as $column)
                                <td>{{ $row[$column['key']] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
