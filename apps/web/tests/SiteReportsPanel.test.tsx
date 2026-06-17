import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Report } from '@/types/api';
import { SiteReportsPanel } from '@/components/reports/SiteReportsPanel';

// --- mock the list query: one report of EACH status -------------------------

function makeReport(over: Partial<Report>): Report {
  return {
    id: 0,
    site_id: 7,
    title: 'Website Maintenance Report',
    range_from: '2026-05-01',
    range_to: '2026-05-31',
    status: 'ready',
    file_size: 1120,
    recipient_email: null,
    generated_at: '2026-06-01 02:00:00',
    sent_at: null,
    sent_method: null,
    created_at: '2026-06-01 01:59:00',
    ...over,
  };
}

const GENERATING = makeReport({ id: 10, status: 'generating', file_size: null, generated_at: null, title: 'May report' });
const READY = makeReport({ id: 11, status: 'ready', file_size: 2048, title: 'April report' });
const SENT = makeReport({ id: 12, status: 'sent', file_size: 4096, recipient_email: 'c@acme.test', sent_at: '2026-06-02 00:00:00', title: 'March report' });
const FAILED = makeReport({ id: 13, status: 'failed', file_size: null, generated_at: null, title: 'Feb report' });

const REPORTS: Report[] = [GENERATING, READY, SENT, FAILED];

vi.mock('@/lib/queries/useSiteReports', () => ({
  useSiteReports: () => ({
    data: { reports: REPORTS, total: REPORTS.length, page: 1, per_page: 20 },
    isLoading: false,
    isError: false,
  }),
}));

// --- spy the 4 mutation hooks + the download helper -------------------------

const generateSpy = vi.fn();
const sendSpy = vi.fn();
const deleteSpy = vi.fn();
const setClientEmailSpy = vi.fn();
const setAutoSendSpy = vi.fn();
const downloadSpy = vi.fn();

vi.mock('@/lib/mutations/useGenerateReport', () => ({
  useGenerateReport: () => ({ mutate: generateSpy, isPending: false }),
}));
vi.mock('@/lib/mutations/useSendReport', () => ({
  useSendReport: () => ({ mutate: sendSpy, isPending: false }),
}));
vi.mock('@/lib/mutations/useDeleteReport', () => ({
  useDeleteReport: () => ({ mutate: deleteSpy, isPending: false }),
}));
vi.mock('@/lib/mutations/useSetClientEmail', () => ({
  useSetClientEmail: () => ({ mutate: setClientEmailSpy, isPending: false }),
}));
vi.mock('@/lib/mutations/useSetAutoSend', () => ({
  useSetAutoSend: () => ({ mutate: setAutoSendSpy, isPending: false }),
}));
vi.mock('@/lib/downloadStoredReport', () => ({
  downloadStoredReport: (...args: unknown[]) => downloadSpy(...args),
}));

const SITE_ID = 7;

function renderPanel(
  clientEmail: string | null = 'client@acme.test',
  autoSendReports = false,
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(
    <SiteReportsPanel
      siteId={SITE_ID}
      clientEmail={clientEmail}
      autoSendReports={autoSendReports}
    />,
    { wrapper },
  );
}

/** Find the table row whose title cell matches `title`. */
function rowFor(title: string): HTMLElement {
  const cell = screen.getByText(title);
  const row = cell.closest('tr');
  if (!row) throw new Error(`no row for ${title}`);
  return row as HTMLElement;
}

describe('SiteReportsPanel', () => {
  beforeEach(() => {
    generateSpy.mockClear();
    sendSpy.mockClear();
    deleteSpy.mockClear();
    setClientEmailSpy.mockClear();
    setAutoSendSpy.mockClear();
    downloadSpy.mockClear();
  });

  it('shows a Generating badge on the generating row with Download + Send disabled', () => {
    renderPanel();
    const row = rowFor('May report');
    expect(within(row).getByText(/generating/i)).toBeInTheDocument();
    expect(within(row).getByRole('button', { name: /download/i })).toBeDisabled();
    expect(within(row).getByRole('button', { name: /send/i })).toBeDisabled();
  });

  it('opens the Send dialog pre-filled with clientEmail and sends on confirm', async () => {
    const user = userEvent.setup();
    renderPanel('client@acme.test');
    const row = rowFor('April report');
    await user.click(within(row).getByRole('button', { name: /send/i }));

    const input = await screen.findByLabelText(/recipient/i);
    expect(input).toHaveValue('client@acme.test');

    await user.click(screen.getByRole('button', { name: /^send report$/i }));
    expect(sendSpy).toHaveBeenCalledWith({
      reportId: 11,
      recipientEmail: 'client@acme.test',
      note: '',
    });
  });

  it('blocks send and shows an inline error for an invalid recipient', async () => {
    const user = userEvent.setup();
    renderPanel('client@acme.test');
    const row = rowFor('April report');
    await user.click(within(row).getByRole('button', { name: /send/i }));

    const input = await screen.findByLabelText(/recipient/i);
    await user.clear(input);
    await user.type(input, 'nope');
    await user.click(screen.getByRole('button', { name: /^send report$/i }));

    expect(screen.getByText(/valid email/i)).toBeInTheDocument();
    expect(sendSpy).not.toHaveBeenCalled();
  });

  it('opens the Delete dialog and deletes the report id on confirm', async () => {
    const user = userEvent.setup();
    renderPanel();
    const row = rowFor('April report');
    await user.click(within(row).getByRole('button', { name: /delete/i }));

    expect(await screen.findByText(/delete this report/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^delete report$/i }));
    expect(deleteSpy).toHaveBeenCalledWith(11);
  });

  it('opens the Generate dialog and generates a range on confirm', async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole('button', { name: /generate report/i }));

    await user.click(await screen.findByRole('button', { name: /^generate$/i }));
    expect(generateSpy).toHaveBeenCalledTimes(1);
    const arg = generateSpy.mock.calls[0][0];
    expect(arg).toHaveProperty('from');
    expect(arg).toHaveProperty('to');
    expect(typeof arg.from).toBe('string');
    expect(typeof arg.to).toBe('string');
  });

  it('downloads a ready report with a filename', async () => {
    const user = userEvent.setup();
    renderPanel();
    const row = rowFor('April report');
    await user.click(within(row).getByRole('button', { name: /download/i }));
    expect(downloadSpy).toHaveBeenCalledWith(SITE_ID, 11, 'report-11.pdf');
  });

  it('renders the auto-send toggle OFF and fires useSetAutoSend when toggled', async () => {
    const user = userEvent.setup();
    renderPanel('client@acme.test', false);

    const toggle = screen.getByRole('switch', { name: /auto-send monthly reports/i });
    expect(toggle).not.toBeChecked();

    await user.click(toggle);
    expect(setAutoSendSpy).toHaveBeenCalledWith(true);
  });

  it('renders the auto-send toggle ON when autoSendReports is true', () => {
    renderPanel('client@acme.test', true);
    const toggle = screen.getByRole('switch', { name: /auto-send monthly reports/i });
    expect(toggle).toBeChecked();
  });

  it('shows the missing-email hint when clientEmail is empty', () => {
    renderPanel(null, false);
    expect(
      screen.getByText(/set a client email to receive auto-sent reports/i),
    ).toBeInTheDocument();
  });
});
