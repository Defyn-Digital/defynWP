import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';

describe('static ui primitives', () => {
  it('Separator renders a separator role', () => {
    render(<Separator />);
    expect(screen.getByRole('separator')).toBeInTheDocument();
  });

  it('Skeleton renders an element with the pulse class', () => {
    render(<Skeleton data-testid="sk" className="h-4 w-10" />);
    expect(screen.getByTestId('sk').className).toContain('animate-pulse');
  });

  it('Avatar renders its fallback', () => {
    render(<Avatar><AvatarFallback>PD</AvatarFallback></Avatar>);
    expect(screen.getByText('PD')).toBeInTheDocument();
  });
});
