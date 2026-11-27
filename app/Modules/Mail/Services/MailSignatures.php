<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailProfile;
use Illuminate\Support\Facades\Storage;

/**
 * ⭐ মেইলের সই — নমুনা v4 পর্দা ৪: 20টা নকশা, আর উৎসবের দিনে সইয়ের পাশে শুভেচ্ছা (নিজে বসে, পরে নিজে সরে)।
 *
 * ⓘ মেইল প্রোগ্রামের নিয়মে লেখা — টেবিল আর ইনলাইন সাজ; গ্রেডিয়েন্ট না চললে নিচে সাধারণ রং থাকে।
 * ⓘ ছবি আর লোগো মেইলের ভিতরে বসানো (cid) — বাইরের সার্ভার থেকে টানা হয় না, তাই প্রাপকের দিকে "ছবি বন্ধ" হয় না আর কর্মীর ছবি
 *   কোনো খোলা ঠিকানায় থাকে না। পর্দায় দেখাতে একই জায়গায় data: ছবি।
 * ⓘ ছবি না থাকলে নামের আদ্যক্ষর; লোগো কোম্পানির নামের পাশে (মালিক, v3)। নাম, পদ, ফোন HR/প্রোফাইল থেকে।
 */
final class MailSignatures
{
    public const PHOTO = '{{SIG_PHOTO}}';

    public const LOGO = '{{SIG_LOGO}}';

    /** ⓘ নকশার নাম — পর্দায় তালিকা ([[mail::field.sign.styles]]) */
    public const COUNT = 20;

    /** ⓘ "নিজে বানান" — কর্মীর নিজের সই */
    public const OWN = 21;

    /** ⓘ সইয়ে বসার মতো লোগোর সবচেয়ে বড় মাপ — এর বেশি হলে লোগো বাদ, কোম্পানির নাম লেখায় */
    public const LOGO_MAX_BYTES = 512 * 1024;

    /** @return array{name: string, title: string, name_bn: string, title_bn: string, phone: string, email: string, web: string, company: string, address: string, links: array<string, string>, initials: string, c1: string, c2: string, c3: string} */
    public function facts(User $user, ?string $fromEmail = null): array
    {
        $profile = $this->profile($user);
        $company = Company::query()->find(CompanyContext::id());
        $name = (string) $user->name;
        $parts = preg_split('/\s+/u', trim($name)) ?: [''];

        return [
            'name' => $name,
            'title' => (string) ($profile->title ?: ($user->designation ?? '')),
            // ⓘ নকশা 8 (বাংলা ও ইংরেজি পাশাপাশি) — বাংলা নাম/পদ না থাকলে ইংরেজিটাই
            'name_bn' => trim((string) ($user->name_bn ?? '')) ?: $name,
            'title_bn' => trim((string) ($user->designation_bn ?? '')) ?: (string) ($profile->title ?: ($user->designation ?? '')),
            'phone' => (string) ($profile->phone ?: ($user->mobile ?? '')),
            'email' => (string) ($fromEmail ?: $user->email),
            'web' => (string) ($company?->website ?? ''),
            'company' => (string) ($company?->name_en ?? ''),
            'address' => (string) ($company?->address_en ?? ''),
            'links' => array_filter((array) ($profile->links ?? []), fn ($v) => is_string($v) && $v !== ''),
            'initials' => mb_strtoupper(mb_substr($parts[0], 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '')),
            'c1' => '#1a3fd0', 'c2' => '#00ab3f', 'c3' => '#f5121d',
        ];
    }

    /** ⓘ এক অনুরোধে একবার — স্বাক্ষরের পাতা 20টা নকশা আঁকে, প্রতিটায় আলাদা প্রশ্ন নয় */
    private array $memo = [];

    public function profile(User $user): MailProfile
    {
        return $this->memo['profile'][$user->id] ??= MailProfile::query()->firstOrNew(['user_id' => $user->id]);
    }

    /**
     * সইয়ের HTML — ছবি/লোগোর জায়গায় চিহ্ন ({{SIG_PHOTO}}, {{SIG_LOGO}}); পাঠানোর সময় cid, দেখানোর সময় data:।
     */
    public function html(User $user, int $style, ?string $fromEmail = null, bool $withFestival = true): string
    {
        $f = $this->facts($user, $fromEmail);
        $hasPhoto = $this->photoBytes($user) !== null;
        $custom = trim((string) ($this->profile($user)->custom ?? ''));
        // ⓘ নকশা 21 = "নিজে বানান" — কর্মীর নিজের লেখা সই (নিরাপদ করা); না থাকলে 1
        $body = $style === self::OWN && $custom !== ''
            ? \App\Modules\Mail\Http\Controllers\MailController::safe(nl2br($custom, false), true)
            : $this->design(max(1, min(self::COUNT, $style)), $f, $hasPhoto);

        if ($withFestival && $style !== 10 && $this->profile($user)->festival !== false && ($fest = $this->festivalToday()) !== null) {
            $body = '<table cellpadding="0" cellspacing="0" role="presentation"><tr><td style="vertical-align:middle;padding-right:14px">'.$body
                .'</td><td style="vertical-align:middle">'.$this->festivalBanner($fest, $f['company']).'</td></tr></table>';
        }

        return '<div class="abos-signature" style="margin-top:16px;font-family:Segoe UI,Arial,sans-serif;color:#1d2433">'.$body.'</div>';
    }

    /** ⓘ পর্দায় দেখানো — চিহ্নের জায়গায় data: ছবি */
    public function preview(User $user, int $style, ?string $fromEmail = null): string
    {
        return $this->inline($user, $this->html($user, $style, $fromEmail), true);
    }

    /**
     * চিহ্ন বদলানো — `$data` সত্য হলে data: ছবি, নাহলে cid (পাঠানোর জন্য)।
     */
    public function inline(User $user, string $html, bool $data): string
    {
        $photo = $this->photoBytes($user);
        $logo = $this->logoBytes();

        return strtr($html, [
            self::PHOTO => $photo ? ($data ? 'data:'.$photo['mime'].';base64,'.base64_encode($photo['bytes']) : 'cid:sig-photo') : '',
            self::LOGO => $logo ? ($data ? 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']) : 'cid:sig-logo') : '',
        ]);
    }

    /**
     * পাঠানোর সময় মেইলে যে ছবিগুলো বসবে।
     *
     * @return list<array{cid: string, name: string, mime: string, bytes: string}>
     */
    public function embeds(User $user, string $html): array
    {
        $out = [];

        if (str_contains($html, 'cid:sig-photo') && ($p = $this->photoBytes($user))) {
            $out[] = ['cid' => 'sig-photo', 'name' => 'photo.'.$p['ext'], 'mime' => $p['mime'], 'bytes' => $p['bytes']];
        }

        if (str_contains($html, 'cid:sig-logo') && ($l = $this->logoBytes())) {
            $out[] = ['cid' => 'sig-logo', 'name' => 'logo.'.$l['ext'], 'mime' => $l['mime'], 'bytes' => $l['bytes']];
        }

        return $out;
    }

    public function festivalToday(): ?MailFestival
    {
        if (! class_exists(MailFestival::class)) {
            return null;
        }

        if (! array_key_exists('festival', $this->memo)) {
            $this->memo['festival'] = MailFestival::query()->where('active', true)->whereNotNull('on_date')
                ->whereBetween('on_date', [now()->toDateString(), now()->addDay()->toDateString()])
                ->orderBy('on_date')->first();
        }

        return $this->memo['festival'];
    }

    public function festivalBanner(MailFestival $fest, string $company): string
    {
        [$from, $to, $accent] = array_pad((array) $fest->colours, 3, '#ffffff');

        return '<table cellpadding="0" cellspacing="0" role="presentation" style="border-radius:12px;background:'.e($to).';background-image:linear-gradient(135deg,'.e($from).','.e($to).')">'
            .'<tr><td style="padding:12px 16px;color:#fff;font-family:Segoe UI,Arial,sans-serif">'
            .'<span style="font-size:30px;line-height:1">'.e((string) $fest->mark).'</span> '
            .'<b style="font-size:17px;color:'.e($accent).'">'.e($fest->greeting).'</b>'
            .'<div style="font-size:12px;margin-top:3px">'.e($fest->line).'</div>'
            .'<div style="font-size:11px;opacity:.85;margin-top:3px">— '.e($company).' '.__('mail::field.sign.family').'</div></td></tr></table>';
    }

    // ── ছবি ─────────────────────────────────────────────────────────

    /** @return array{bytes: string, mime: string, ext: string}|null */
    public function photoBytes(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo['photo'] ?? [])) {
            return $this->memo['photo'][$user->id];
        }

        return $this->memo['photo'][$user->id] = $this->readPhoto($user);
    }

    /** @return array{bytes: string, mime: string, ext: string}|null */
    private function readPhoto(User $user): ?array
    {
        $path = $this->profile($user)->photo_path;

        foreach (['local', 'public'] as $disk) {
            if ($path && Storage::disk($disk)->exists($path)) {
                $mime = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';

                return ['bytes' => (string) Storage::disk($disk)->get($path), 'mime' => $mime, 'ext' => $mime === 'image/png' ? 'png' : 'jpg'];
            }
        }

        return null;
    }

    /** @return array{bytes: string, mime: string, ext: string}|null */
    public function logoBytes(): ?array
    {
        if (array_key_exists('logo', $this->memo)) {
            return $this->memo['logo'];
        }

        return $this->memo['logo'] = $this->readLogo();
    }

    /** @return array{bytes: string, mime: string, ext: string}|null */
    private function readLogo(): ?array
    {
        $company = Company::query()->find(CompanyContext::id());

        if (! $company?->logo_path || ! Storage::disk('public')->exists($company->logo_path)) {
            return null;
        }

        // ⛔ বড় লোগো সইয়ে নয় — প্রতিটা মেইলে বসে, পর্দায় base64-এ আরও বড়; Mac run maili-তে 7 MB-এর লোগোয় লেখার পাতা
        //   memory শেষ করে ভেঙেছিল (২৭ নভেম্বর)। মেইলের সইয়ের লোগো ছোট রাখাই রীতি — বড় হলে লোগো বাদ, কোম্পানির নাম লেখায়
        if (Storage::disk('public')->size($company->logo_path) > self::LOGO_MAX_BYTES) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($company->logo_path) ?: 'image/png';

        // ⓘ SVG অনেক মেইল প্রোগ্রামে দেখায় না — তখন লোগো বাদ, কোম্পানির নাম লেখায়
        if (str_contains($mime, 'svg')) {
            return null;
        }

        return ['bytes' => (string) Storage::disk('public')->get($company->logo_path), 'mime' => $mime, 'ext' => $mime === 'image/png' ? 'png' : 'jpg'];
    }

    // ── নকশা ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $f */
    private function design(int $n, array $f, bool $hasPhoto): string
    {
        $e = fn (string $k) => e((string) $f[$k]);
        $c1 = $f['c1'];
        $c2 = $f['c2'];
        $c3 = $f['c3'];
        $photo = fn (int $w, int $h, string $radius = '10px', string $ring = '') => $hasPhoto
            ? '<img src="'.self::PHOTO.'" width="'.$w.'" height="'.$h.'" alt="" style="display:block;width:'.$w.'px;height:'.$h.'px;object-fit:cover;border-radius:'.$radius.';'.$ring.'">'
            : '<div style="width:'.$w.'px;height:'.$h.'px;border-radius:'.$radius.';background:'.$c1.';color:#fff;font:700 '.max(14, (int) ($w / 2.6)).'px/'.$h.'px Segoe UI,Arial,sans-serif;text-align:center;'.$ring.'">'.$e('initials').'</div>';
        // ⓘ লোগো না থাকলে (বা SVG হলে) ছবির ঘরই বাদ — ফাঁকা src মানে ভাঙা ছবি; তখন শুধু কোম্পানির নাম
        $logo = $this->logoBytes() !== null
            ? '<img src="'.self::LOGO.'" alt="'.$e('company').'" height="22" style="height:22px;vertical-align:middle;border:0">'
            : '';
        $brand = '<span style="font:800 15px Segoe UI,Arial,sans-serif;color:'.$c1.'">'.$e('company').'</span>';
        $mark = $logo.' '.$brand;
        $lines = fn (string $col = '') => '<div style="font-size:12px;line-height:1.6;color:#333">'
            .($f['phone'] !== '' ? '<b style="color:'.($col ?: $c2).'">✆</b> '.$e('phone').'<br>' : '')
            .'<b style="color:'.($col ?: $c2).'">✉</b> <a href="mailto:'.$e('email').'" style="color:#333;text-decoration:none">'.$e('email').'</a>'
            .($f['web'] !== '' ? '<br><b style="color:'.($col ?: $c2).'">⌂</b> <a href="'.e($this->url((string) $f['web'])).'" style="color:#333;text-decoration:none">'.$e('web').'</a>' : '').'</div>';
        $social = function (string $tint = '') use ($f) {
            $icons = ['facebook' => ['f', '#1877f2'], 'linkedin' => ['in', '#0a66c2'], 'youtube' => ['▶', '#ff0000'], 'whatsapp' => ['✆', '#25d366'], 'website' => ['⌂', '#1a3fd0']];
            $out = '';

            foreach ($f['links'] as $k => $url) {
                if (! isset($icons[$k])) {
                    continue;
                }
                $out .= '<a href="'.e($this->url((string) $url)).'" style="display:inline-block;width:22px;height:22px;line-height:22px;border-radius:50%;background:'.($tint ?: $icons[$k][1])
                    .';color:#fff;text-align:center;font:700 11px Segoe UI,Arial,sans-serif;text-decoration:none;margin-right:5px">'.$icons[$k][0].'</a>';
            }

            return $out === '' ? '' : '<div style="margin-top:5px">'.$out.'</div>';
        };
        $nm = fn (int $size = 19, string $col = '#1d2433') => '<div style="font:700 '.$size.'px Segoe UI,Arial,sans-serif;color:'.$col.'">'.$e('name').'</div>';
        $ds = fn (string $col) => $f['title'] !== '' ? '<div style="font:600 11.5px Segoe UI,Arial,sans-serif;letter-spacing:1.2px;text-transform:uppercase;color:'.$col.'">'.$e('title').'</div>' : '';
        $row = fn (string ...$cells) => '<table cellpadding="0" cellspacing="0" role="presentation"><tr>'.implode('', array_map(fn ($c) => '<td style="vertical-align:middle;padding-right:14px">'.$c.'</td>', $cells)).'</tr></table>';

        return match ($n) {
            1 => $row($photo(96, 132), $nm(19, $c1).$ds($c2).'<div style="height:6px"></div>'.$lines().$social().'<div style="margin-top:6px">'.$mark.'</div>'),
            2 => '<table cellpadding="0" cellspacing="0" role="presentation" style="background:#0e1a4a;background-image:linear-gradient(120deg,#0e1a4a,'.$c1.');border-radius:12px"><tr><td style="padding:14px 16px">'
                .$photo(80, 80, '50%', 'border:3px solid #f8cb46').'</td><td style="padding:14px 16px 14px 0;color:#fff">'.$nm(19, '#fff').$ds('#f8cb46')
                .'<div style="height:1px;background:#f8cb46;opacity:.5;margin:6px 0"></div><div style="font-size:12px;color:#dfe6ff">'.$e('phone').' · '.$e('email').'<br>'.$e('web').'</div>'.$social('#f8cb46').'</td></tr></table>',
            3 => '<table cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #e2e8f0;border-radius:12px"><tr><td style="background:'.$c2.';width:44px;text-align:center;color:#fff;font:800 13px Segoe UI,Arial;border-radius:12px 0 0 12px">'.$e('company').'</td>'
                .'<td style="padding:10px">'.$photo(90, 120).'</td><td style="padding:10px 14px 10px 0">'.$nm().$ds($c2).$lines().$social().'</td></tr></table>',
            4 => $row($photo(70, 70, '14px'), $nm(17).$ds($c3).$lines().$social())
                .'<div style="margin-top:10px;border-radius:10px;padding:9px 14px;background:'.$c2.';background-image:linear-gradient(90deg,'.$c2.','.$c1.');color:#fff;font:700 13px Segoe UI,Arial">'.$e('company').' — '.$e('web').'</div>',
            5 => $row('<div style="width:5px;height:38px;background:'.$c2.';border-radius:3px;margin-bottom:3px"></div><div style="width:5px;height:38px;background:'.$c3.';border-radius:3px;margin-bottom:3px"></div><div style="width:5px;height:38px;background:'.$c1.';border-radius:3px"></div>',
                '<div style="font:italic 600 24px Georgia,serif;color:#1b2440">'.$e('name').'</div>'.$ds('#6b7594').'<div style="font-size:12px;margin-top:6px">'.$e('phone').' &nbsp;|&nbsp; '.$e('email').' &nbsp;|&nbsp; '.$e('web').'</div>'.$social('#1b2440')),
            6 => $row('<div style="padding:4px;border-radius:50%;background:'.$c1.';background-image:conic-gradient('.$c2.','.$c3.','.$c1.','.$c2.')">'.$photo(86, 86, '50%', 'border:3px solid #fff').'</div>', $nm().$ds($c1).$lines($c1).$social()),
            7 => '<table cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #e2e8f0;border-radius:12px;min-width:460px"><tr><td colspan="3" style="background:'.$c1.';background-image:linear-gradient(90deg,'.$c1.','.$c2.');padding:8px 14px;border-radius:12px 12px 0 0">'
                .'<span style="background:#fff;border-radius:6px;padding:1px 8px">'.$mark.'</span></td></tr><tr><td style="padding:10px 14px">'.$photo(64, 64).'</td><td style="padding:10px 14px 10px 0">'.$nm(17).$ds($c2).'</td><td style="padding:10px 14px 10px 0">'.$lines().$social().'</td></tr></table>',
            // ⓘ নমুনা v4: বাঁয়ে বাংলা নাম ও পদ, ডানে ইংরেজি — ⛔ আগে ডানে "Designation" শব্দটাই বসত (ডেমো, ২৭ নভেম্বর)
            // ⓘ বাংলা নাম-পদ না থাকলে একই লেখা দুবার নয় — তখন একটাই কলাম
            8 => $row($photo(90, 120), '<table cellpadding="0" cellspacing="0" role="presentation"><tr>'
                .($f['name_bn'] !== $f['name'] || $f['title_bn'] !== $f['title']
                    ? '<td style="padding-right:14px"><div style="font:700 18px Segoe UI,Arial,sans-serif;color:#1d2433">'.$e('name_bn').'</div>'.($f['title_bn'] !== '' ? '<div style="color:'.$c3.';font-weight:600">'.$e('title_bn').'</div>' : '').'</td>'
                    .'<td style="border-left:2px solid '.$c3.';padding-left:14px">'
                    : '<td>')
                .$nm(18).($f['title'] !== '' ? '<div style="color:'.$c3.';font-weight:600">'.$e('title').'</div>' : '').'</td></tr></table>'
                .'<div style="height:4px;margin:6px 0;background:'.$c2.';background-image:linear-gradient(90deg,'.$c2.' 33%,'.$c3.' 33% 66%,'.$c1.' 66%)"></div>'.$lines().$social()),
            9 => $row($photo(84, 112), $nm(18).$ds('#8a5a00').$lines().$social()),
            10 => '<table cellpadding="0" cellspacing="0" role="presentation" style="border-top:2px solid #e2e8f0;padding-top:8px"><tr><td style="padding:8px 10px 0 0">'.$photo(36, 36, '50%').'</td><td style="padding-top:8px;font-size:13px"><b>'.$e('name').'</b>'
                .' <span style="color:#6b7594">· '.$e('title').' · '.$e('company').' · '.$e('phone').'</span></td></tr></table>',
            11 => '<table cellpadding="0" cellspacing="0" role="presentation" style="background:#e9eefc;border-radius:16px"><tr><td style="padding:12px"><table cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffffcc;border:1px solid #fff;border-radius:12px"><tr><td style="padding:8px 14px">'
                .$photo(78, 104, '12px').'</td><td style="padding:8px 14px 8px 0">'.$nm().$ds($c1).$lines($c1).'</td><td style="padding:8px 14px 8px 0;text-align:right">'.$mark.$social().'</td></tr></table></td></tr></table>',
            12 => '<table cellpadding="0" cellspacing="0" role="presentation" style="border-radius:14px;border:1px solid #e2e8f0"><tr><td style="background:'.$c1.';padding:12px 18px;border-radius:14px 0 0 14px">'.$photo(96, 120, '10px', 'border:3px solid #fff')
                .'</td><td style="padding:12px 18px">'.$nm(22).$ds($c3).'<div style="width:60px;height:3px;background:'.$c2.';margin:6px 0"></div>'.$lines().$social().'</td></tr></table>',
            13 => $row($photo(90, 120, '12px', 'box-shadow:10px 10px 0 #f8cb46'), '<div style="font:900 28px/1 Segoe UI,Arial;letter-spacing:-1px;color:#1b2440;text-transform:uppercase">'.$e('name').'</div>'.$ds('#6b7594'),
                '<div style="border-left:3px solid #f8cb46;padding-left:12px">'.$lines('#8a5a00').$social('#1b2440').'</div>'),
            14 => '<table cellpadding="0" cellspacing="0" role="presentation" style="background:#0b1020;border-radius:16px;border:2px solid '.$c1.'"><tr><td style="padding:12px 16px">'.$photo(84, 84, '50%', 'box-shadow:0 0 16px 3px '.$c1)
                .'</td><td style="padding:12px 16px 12px 0;color:#e8ecff">'.$nm(19, '#fff').$ds('#4ade80').'<div style="font-size:12px;color:#aab3d6">'.$e('phone').' · '.$e('email').'<br>'.$e('web').'</div>'.$social().'</td></tr></table>',
            15 => $row('<table cellpadding="0" cellspacing="0" role="presentation" style="width:150px;border:1px solid #dde2ee;border-radius:12px;text-align:center"><tr><td style="background:'.$c2.';height:40px;border-radius:12px 12px 0 0"></td></tr><tr><td style="padding:0 0 10px"><div style="margin:-28px auto 4px;width:56px">'
                .$photo(56, 56, '50%', 'border:3px solid #fff').'</div><b style="font-size:12px">'.$e('name').'</b><div style="font:700 10px Segoe UI,Arial;color:'.$c2.'">'.$e('company').'</div></td></tr></table>', $nm().$ds($c2).$lines().$social()),
            16 => $row('<div style="padding:4px;border-radius:24px;background:'.$c1.';background-image:linear-gradient(135deg,'.$c2.','.$c1.','.$c3.')">'.$photo(96, 110, '20px').'</div>', $nm(21).$ds($c1).$lines().'<div style="margin-top:6px">'.$social().$mark.'</div>'),
            17 => $row($photo(90, 120), '<div style="font:400 32px/1.1 Segoe Script,Brush Script MT,cursive;color:'.$c1.'">'.$e('name').'</div><div style="width:200px;height:2px;background:'.$c1.';margin:2px 0 6px"></div>'.$ds('#6b7594').$lines().$social()),
            18 => '<table cellpadding="0" cellspacing="0" role="presentation"><tr><td style="background:#1b2440;border-radius:12px;padding:20px 26px;text-align:center"><span style="background:#fff;border-radius:8px;padding:4px 12px">'.$mark.'</span></td><td style="width:10px"></td>'
                .'<td style="border:1px solid #dde2ee;border-radius:12px;padding:10px 14px">'.$row($photo(70, 94), $nm(16).$ds($c1).$lines($c1).$social()).'</td></tr></table>',
            19 => $row($photo(90, 120, '12px'), $nm().$ds($c2).'<div style="font-size:12px;line-height:1.6">📍 '.$e('address').'<br>🕘 '.e(__('mail::field.sign.hours')).'<br>✆ '.$e('phone').'</div>'.$social(),
                '<a href="mailto:'.$e('email').'" style="display:block;background:'.$c2.';color:#fff;border-radius:10px;padding:10px 16px;font-weight:700;text-decoration:none;text-align:center;margin-bottom:8px">📅 '.e(__('mail::field.sign.meeting')).'</a>'),
            default => $row('<div style="border:3px solid #f8cb46;border-radius:12px">'.$photo(84, 112).'</div>', $nm().$ds('#8a5a00').$lines().$social(), '<div style="background:#fff4d6;border-radius:8px;padding:8px 12px;font-size:11px;text-align:center"><b style="font-size:14px;color:#8a5a00">'.$e('company').'</b><br>'.$e('web').'</div>'),
        };
    }

    private function url(string $u): string
    {
        $u = trim($u);

        return preg_match('#^https?://#i', $u) ? $u : 'https://'.ltrim($u, '/');
    }
}
