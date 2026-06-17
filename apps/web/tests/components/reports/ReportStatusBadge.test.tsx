import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ReportStatusBadge } from '@/components/reports/ReportStatusBadge';

describe('ReportStatusBadge', () => {
  it('renders "Auto-sent" with violet styling when sent + sentMethod=auto', () => {
    render(<ReportStatusBadge status="sent" sentMethod="auto" />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Auto-sent');
    expect(badge.className).toContain('text-violet-700');
    expect(badge.className).toContain('bg-violet-100');
  });

  it('renders "Sent" when sent + sentMethod=manual', () => {
    render(<ReportStatusBadge status="sent" sentMethod="manual" />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Sent');
    expect(badge).not.toHaveTextContent('Auto-sent');
    expect(badge.className).toContain('bg-green-100');
  });

  it('renders "Sent" when sent + sentMethod null', () => {
    render(<ReportStatusBadge status="sent" sentMethod={null} />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Sent');
    expect(badge).not.toHaveTextContent('Auto-sent');
  });

  it('renders "Sent" when sent + sentMethod omitted', () => {
    render(<ReportStatusBadge status="sent" />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Sent');
    expect(badge).not.toHaveTextContent('Auto-sent');
  });

  it('renders other statuses unchanged regardless of sentMethod', () => {
    render(<ReportStatusBadge status="ready" sentMethod="auto" />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Ready');
    expect(badge.className).toContain('bg-blue-100');
  });

  it('keeps the spinner on the generating status', () => {
    render(<ReportStatusBadge status="generating" />);
    const badge = screen.getByTestId('report-status-badge');
    expect(badge).toHaveTextContent('Generating');
    expect(badge.querySelector('.animate-spin')).not.toBeNull();
  });
});
