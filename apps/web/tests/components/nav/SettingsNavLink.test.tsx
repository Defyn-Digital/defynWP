import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { SettingsNavLink } from '@/components/nav/SettingsNavLink';

describe('SettingsNavLink', () => {
  it('links to /settings', () => {
    render(<MemoryRouter><SettingsNavLink /></MemoryRouter>);
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/settings');
  });
});
