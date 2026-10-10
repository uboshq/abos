<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Documents;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * ডকুমেন্টের টেস্টগুলোর এক ভিত — ডেমো কোম্পানি, মালিক, আর একটা সত্যিকারের PDF তোলা।
 *
 * ⓘ ফাইল তোলা হয় HTTP দিয়ে, মানুষ যেভাবে তোলেন — ফর্মের দুই ছাঁকনি আর সংযুক্তির ইঞ্জিন
 * সবই পেরিয়ে। ⚠️ সেবা সরাসরি ডাকলে ফর্মের পাহারা কখনো পরীক্ষায় আসত না।
 *
 * ⓘ দুই ডিস্কই নকল (`local`, `public`) — ফাইল কোথায় নামল সেটা দেখা যায়, আর আসল
 * `storage/`-এ কিছু থেকে যায় না।
 */
trait MakesDocuments
{
    protected Company $company;

    protected Branch $main;

    protected Branch $netrakona;

    protected User $owner;

    protected function setUpDocuments(): void
    {
        $this->seed(DemoSeeder::class);

        Storage::fake('local');
        Storage::fake('public');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->useCompany();

        $this->main = Branch::query()->where('code', 'MMS')->firstOrFail();
        $this->netrakona = Branch::query()->where('code', 'NTK')->firstOrFail();

        // ⓘ মালিক super_admin — সব চাবি, সব শাখা, সব গোপনীয়তার ধাপ
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** পরীক্ষার নিজের হাতে কোম্পানি — একটা অনুরোধ অন্য কোম্পানিতে বসালে ফেরানোর জন্যও */
    protected function useCompany(): void
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /** একটা সত্যিকারের PDF — বাইট পড়লেও PDF (finfo ঠিক এটাই দেখে) */
    protected function pdf(string $name = 'contract.pdf', string $body = 'first'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ".$body."\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        );
    }

    /**
     * মালিক একটা কাগজ তোলেন — প্রধান শাখায়, চাওয়া গোপনীয়তায়।
     *
     * @param  array<string, mixed>  $extra
     */
    protected function upload(
        string $name = 'Supply contract',
        string $level = DocumentCatalog::INTERNAL,
        ?UploadedFile $file = null,
        array $extra = [],
    ): Document {
        $this->actingAs($this->owner)
            ->post(route('documents.store'), [
                'name' => $name,
                'doc_type' => 'contract',
                'folder' => 'contracts',
                'branch_id' => $this->main->id,
                'confidentiality' => $level,
                'files' => [$file ?? $this->pdf()],
                ...$extra,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->useCompany();

        return Document::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('name', $name)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * এই কোম্পানির একজন মানুষ, কেবল দেওয়া চাবিগুলো নিয়ে।
     *
     * ⓘ শাখা না দিলে সব শাখা (দেয়ালের সারি নেই); দিলে কেবল সেই শাখা।
     *
     * @param  list<string>  $keys
     */
    protected function person(string $email, array $keys, ?Branch $limitedTo = null, ?Company $company = null): User
    {
        $company ??= $this->company;

        $user = new User;
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret-for-a-test'),
            'is_active' => true,
            'locale' => 'bn',
            'current_company_id' => $company->id,
        ])->save();

        $user->companies()->attach($company->id, ['is_active' => true]);

        CompanyContext::forCompany($company->id, function () use ($user, $keys, $limitedTo, $company) {
            foreach ($keys as $key) {
                Permission::findOrCreate($key, 'web');
            }

            $user->givePermissionTo($keys);

            if ($limitedTo !== null) {
                UserDataScope::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'scope_type' => UserDataScope::BRANCH,
                    'scope_id' => $limitedTo->id,
                ]);
            }
        });

        app(DataScope::class)->forget();

        return $user;
    }
}
