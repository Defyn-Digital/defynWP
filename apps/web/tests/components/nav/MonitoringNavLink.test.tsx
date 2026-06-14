import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { MonitoringNavLink } from '@/components/nav/MonitoringNavLink';

describe('MonitoringNavLink', () => {
  it('links to /monitoring', () => {
    render(<MemoryRouter><MonitoringNavLink /></MemoryRouter>);
    expect(screen.getByRole('link', { name: 'Monitoring' })).toHaveAttribute('href', '/monitoring');
  });
});
