import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Sheet, SheetContent } from '@/components/ui/sheet';

describe('Sheet', () => {
  it('renders its content when open', () => {
    render(
      <Sheet open onOpenChange={() => {}}>
        <SheetContent side="left">drawer-body</SheetContent>
      </Sheet>,
    );
    expect(screen.getByText('drawer-body')).toBeInTheDocument();
  });
});
