<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/** P5.2 — team-wide report branding stored in site options (shared across all operators). */
final class BrandingService
{
    private const KEY_AGENCY = 'defyn_report_agency_name';
    private const KEY_ACCENT = 'defyn_report_accent_color';
    private const KEY_LOGO   = 'defyn_report_logo_url';

    public const DEFAULT_AGENCY = '';
    public const DEFAULT_ACCENT = '#26215C';

    /** @return array{agency_name:string,accent_color:string,logo_url:string} */
    public function get(int $userId): array // $userId retained for API compat; branding is team-wide
    {
        return [
            'agency_name'  => (string) get_option(self::KEY_AGENCY, ''),
            'accent_color' => (string) get_option(self::KEY_ACCENT, '#26215C'),
            'logo_url'     => (string) get_option(self::KEY_LOGO, ''),
        ];
    }

    /** @param array<string,string> $partial only provided keys are written; '' resets to default. */
    public function set(int $userId, array $partial): void // $userId retained for API compat; branding is team-wide
    {
        if (array_key_exists('agency_name', $partial)) {
            update_option(self::KEY_AGENCY, (string) $partial['agency_name']);
        }
        if (array_key_exists('accent_color', $partial)) {
            update_option(self::KEY_ACCENT, (string) $partial['accent_color']);
        }
        if (array_key_exists('logo_url', $partial)) {
            update_option(self::KEY_LOGO, (string) $partial['logo_url']);
        }
    }

    /** One-time: copy the legacy owner's per-user branding into the shared options. */
    public static function migrateLegacyToShared(): void
    {
        if (get_option('defyn_branding_migrated')) {
            return;
        }
        foreach ([self::KEY_AGENCY, self::KEY_ACCENT, self::KEY_LOGO] as $key) {
            $legacy = get_user_meta(1, $key, true);
            if (is_string($legacy) && $legacy !== '' && get_option($key, '') === '') {
                update_option($key, $legacy);
            }
        }
        update_option('defyn_branding_migrated', '1');
    }
}
