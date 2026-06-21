import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { SidebarNav } from '@/components/layout/SidebarNav';

function renderNav(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <SidebarNav />
    </MemoryRouter>,
  );
}

describe('SidebarNav', () => {
  it('renders every top-level destination, including Sites', () => {
    renderNav('/overview');
    for (const label of ['Dashboard', 'Sites', 'Monitoring', 'Security', 'Insights', 'Jobs', 'Activity', 'Settings']) {
      expect(screen.getByRole('link', { name: new RegExp(label, 'i') })).toBeInTheDocument();
    }
  });

  it('points Sites at /sites', () => {
    renderNav('/overview');
    expect(screen.getByRole('link', { name: /sites/i })).toHaveAttribute('href', '/sites');
  });

  it('marks the active route with aria-current', () => {
    renderNav('/security');
    expect(screen.getByRole('link', { name: /security/i })).toHaveAttribute('aria-current', 'page');
  });
});
