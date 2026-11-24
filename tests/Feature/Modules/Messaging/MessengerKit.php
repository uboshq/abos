<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Services\MessengerAccess;
use Database\Seeders\DemoSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The messenger tests' one setup — the same company, people, keys and phone token as [[CallsTest]].
 *
 * ⓘ Every person is made here, without a demo role: a demo role changes, and a claim standing on it would one day be
 * red for the wrong reason. A role is given only where the allow-list needs its NAME.
 * ⓘ Every phone call forgets the guards first — otherwise the previous person's token answers the next request.
 */
trait MessengerKit
{
    protected Company $company;

    protected function bootMessenger(): void
    {
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->set(PhoneModules::key('messaging'), true);
    }

    /**
     * A person of `$company` (TDEPOT by default), with `messaging.use` unless `$use` is false, plus `$keys`.
     *
     * @param  list<string>  $keys
     */
    protected function person(string $name, array $keys = [], bool $use = true, ?string $role = null, ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create([
            'name' => $name,
            'current_company_id' => $company->id,
            'current_branch_id' => $company->defaultBranch()?->id,
            'is_active' => true,
        ]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        $this->grant($user, $use ? ['messaging.use', ...$keys] : $keys, $company);

        if ($role !== null) {
            $row = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web', 'company_id' => $company->id]);
            CompanyContext::forCompany($company->id, fn () => $user->assignRole($row));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user;
    }

    /** @param  list<string>  $keys */
    protected function grant(User $user, array $keys, ?Company $company = null): void
    {
        $company ??= $this->company;

        foreach ($keys as $key) {
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }


    /** A JSON phone call as `$user`. @param  array<string, mixed>  $body */
    protected function phone(User $user, string $method, string $url, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->json($method, $url, $body);
    }

    /** A multipart phone call (an upload) as `$user`. @param  array<string, mixed>  $body */
    protected function upload(User $user, string $url, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->post($url, $body, ['Accept' => 'application/json']);
    }

    /** The direct thread of a pair, opened by `$from` — its public id. */
    protected function direct(User $from, User $to): string
    {
        $response = $this->phone($from, 'POST', '/api/v1/messenger/direct', ['person' => (string) $to->id]);
        $this->assertContains($response->getStatusCode(), [200, 201], 'the direct thread did not open: '.$response->getContent());

        return (string) $response->json('data.id');
    }

    /** @param  list<User>  $people */
    protected function group(User $admin, string $title, array $people): string
    {
        return (string) $this->phone($admin, 'POST', '/api/v1/messenger/groups', [
            'title' => $title,
            'people' => array_map(fn (User $u): string => (string) $u->id, $people),
        ])->assertCreated()->json('data.id');
    }

    /** Sends one text — the message as the sender sees it. @return array<string, mixed> */
    protected function say(User $from, string $thread, string $text, array $extra = []): array
    {
        return $this->phone($from, 'POST', "/api/v1/messenger/threads/{$thread}/messages", ['kind' => 'text', 'text' => $text] + $extra)
            ->assertCreated()->json('data');
    }

    /** @return array<string, mixed> the whole answer of the messages door */
    protected function fetch(User $user, string $thread, ?int $after = null): array
    {
        $url = "/api/v1/messenger/threads/{$thread}/messages".($after === null ? '' : '?after='.$after);

        return $this->phone($user, 'GET', $url)->assertOk()->json();
    }

    /** One message of a list by its id. @param  list<array<string, mixed>>  $rows @return array<string, mixed>|null */
    protected function byId(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        return null;
    }

    /** The thread row of the list door, as `$user` sees it. @return array<string, mixed>|null */
    protected function threadRow(User $user, string $thread, string $query = ''): ?array
    {
        $rows = $this->phone($user, 'GET', '/api/v1/messenger/threads'.($query === '' ? '' : '?'.$query))->assertOk()->json('data');

        return $this->byId($rows, $thread);
    }

    /** @return list<string> the thread ids the list door gives `$user` for this query */
    protected function threadIds(User $user, string $query = ''): array
    {
        $rows = $this->phone($user, 'GET', '/api/v1/messenger/threads'.($query === '' ? '' : '?'.$query))->assertOk()->json('data');

        return array_map(fn (array $r): string => (string) $r['id'], $rows);
    }
}
