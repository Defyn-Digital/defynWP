import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { Sidebar } from '@/components/layout/Sidebar';

const user = { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' };

describe('Sidebar', () => {
  it('renders the brand, nav, and account', () => {
    render(
      <MemoryRouter initialEntries={['/overview']}>
        <Sidebar user={user} onSignOut={() => {}} />
      </MemoryRouter>,
    );
    expect(screen.getByText(/DefynWP/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument();
    expect(screen.getByText('Pradeep')).toBeInTheDocument();
  });
});
