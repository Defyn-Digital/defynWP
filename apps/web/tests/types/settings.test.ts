import { describe, it, expect } from 'vitest';
import { settingsSchema } from '@/types/api';

const DEFAULT_BRANDING = { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' };

describe('settingsSchema', () => {
  it('parses a webhook + null', () => {
    expect(
      settingsSchema.parse({ slack_webhook_url: 'https://hooks.slack.com/x', report_branding: DEFAULT_BRANDING }).slack_webhook_url
    ).toBe('https://hooks.slack.com/x');
    expect(
      settingsSchema.parse({ slack_webhook_url: null, report_branding: DEFAULT_BRANDING }).slack_webhook_url
    ).toBeNull();
  });
});
