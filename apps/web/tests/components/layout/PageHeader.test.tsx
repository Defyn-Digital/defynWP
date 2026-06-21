import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PageHeader } from '@/components/layout/PageHeader';

describe('PageHeader', () => {
  it('renders the title, subtitle, and actions', () => {
    render(<PageHeader title="Overview" subtitle="Your fleet at a glance" actions={<button>Sync all</button>} />);
    expect(screen.getByRole('heading', { name: 'Overview' })).toBeInTheDocument();
    expect(screen.getByText('Your fleet at a glance')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sync all' })).toBeInTheDocument();
  });
});
