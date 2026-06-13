import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { JobStateChip } from '@/components/jobs/JobStateChip';

describe('JobStateChip', () => {
  it('queued renders zinc treatment', () => {
    render(<JobStateChip state="queued" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip).toHaveTextContent('queued');
    expect(chip.className).toContain('bg-zinc-100');
    expect(chip.className).toContain('text-zinc-600');
  });

  it('started renders blue treatment with spinner', () => {
    render(<JobStateChip state="started" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip.className).toContain('bg-blue-100');
    expect(chip.querySelector('.animate-spin')).not.toBeNull();
  });

  it('succeeded renders green treatment', () => {
    render(<JobStateChip state="succeeded" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-green-100');
  });

  it('failed renders red treatment', () => {
    render(<JobStateChip state="failed" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-red-100');
  });

  it('cancelled renders strikethrough zinc treatment', () => {
    render(<JobStateChip state="cancelled" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip.className).toContain('line-through');
    expect(chip.className).toContain('text-zinc-500');
  });

  it('in_progress renders blue treatment with spinner and a space in the label', () => {
    render(<JobStateChip state="in_progress" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip).toHaveTextContent('in progress');
    expect(chip.className).toContain('bg-blue-100');
    expect(chip.querySelector('.animate-spin')).not.toBeNull();
  });

  it('completed renders green treatment', () => {
    render(<JobStateChip state="completed" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-green-100');
  });

  it('partial renders red treatment (dominant failed state)', () => {
    render(<JobStateChip state="partial" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-red-100');
  });

  it('queued job-level state reuses the item queued treatment', () => {
    // Job-level 'queued' and item-level 'queued' share the literal key —
    // single STATE_CLASSES map (guardrail #20).
    render(<JobStateChip state="queued" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('text-zinc-600');
  });
});
