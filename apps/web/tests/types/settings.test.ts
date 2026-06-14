import { describe, it, expect } from 'vitest';
import { settingsSchema } from '@/types/api';

describe('settingsSchema', () => {
  it('parses a webhook + null', () => {
    expect(settingsSchema.parse({ slack_webhook_url: 'https://hooks.slack.com/x' }).slack_webhook_url).toBe('https://hooks.slack.com/x');
    expect(settingsSchema.parse({ slack_webhook_url: null }).slack_webhook_url).toBeNull();
  });
});
