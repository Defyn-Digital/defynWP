<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/** P5.2 — per-operator report branding stored in user_meta. */
final class BrandingService
{
    private const KEY_AGENCY = 'defyn_report_agency_name';
    private const KEY_ACCENT = 'defyn_report_accent_color';
    private const KEY_LOGO   = 'defyn_report_logo_url';

    public const DEFAULT_AGENCY = 'Defyn Digital';
    public const DEFAULT_ACCENT = '#26215C';

    /** @return array{agency_name:string,accent_color:string,logo_url:string} */
    public function get(int $userId): array
    {
        $agency = (string) get_user_meta($userId, self::KEY_AGENCY, true);
        $accent = (string) get_user_meta($userId, self::KEY_ACCENT, true);
        $logo   = (string) get_user_meta($userId, self::KEY_LOGO, true);
        return [
            'agency_name'  => $agency !== '' ? $agency : self::DEFAULT_AGENCY,
            'accent_color' => $accent !== '' ? $accent : self::DEFAULT_ACCENT,
            'logo_url'     => $logo,
        ];
    }

    /** @param array<string,string> $partial only provided keys are written; '' resets to default. */
    public function set(int $userId, array $partial): void
    {
        $map = ['agency_name' => self::KEY_AGENCY, 'accent_color' => self::KEY_ACCENT, 'logo_url' => self::KEY_LOGO];
        foreach ($map as $field => $key) {
            if (!array_key_exists($field, $partial)) {
                continue;
            }
            $val = trim((string) $partial[$field]);
            if ($val === '') {
                delete_user_meta($userId, $key);
            } else {
                update_user_meta($userId, $key, $val);
            }
        }
    }
}
