import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { apiClient } from '@/lib/apiClient';
import { downloadReportPdf } from '@/lib/downloadReportPdf';

// Mock only getBlob — the download helper never touches get/post/delete.
vi.mock('@/lib/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/apiClient')>();
  return {
    ...actual,
    apiClient: { ...actual.apiClient, getBlob: vi.fn() },
  };
});

const mockedGetBlob = vi.mocked(apiClient.getBlob);

beforeEach(() => {
  mockedGetBlob.mockReset();
  // jsdom does not implement these — assign stubs.
  URL.createObjectURL = vi.fn(() => 'blob:mock');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('downloadReportPdf', () => {
  it('requests the auth-scoped PDF endpoint with from/to query params', async () => {
    mockedGetBlob.mockResolvedValue(new Blob(['%PDF-1.7'], { type: 'application/pdf' }));
    // Stub the real anchor's click so jsdom doesn't attempt navigation.
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    await downloadReportPdf(1, '2026-05-16', '2026-06-15');

    expect(mockedGetBlob).toHaveBeenCalledWith(
      '/sites/1/report.pdf?from=2026-05-16&to=2026-06-15',
    );
  });

  it('triggers an anchor download with a .pdf filename and revokes the object URL', async () => {
    mockedGetBlob.mockResolvedValue(new Blob(['%PDF-1.7'], { type: 'application/pdf' }));

    // Capture the anchor at click time (its href/download are already set by then).
    let captured: HTMLAnchorElement | undefined;
    const clickSpy = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        captured = this;
      });

    await downloadReportPdf(1, '2026-05-16', '2026-06-15');

    expect(clickSpy).toHaveBeenCalledTimes(1);
    // getAttribute avoids URL normalization of the blob: value.
    expect(captured?.getAttribute('href')).toBe('blob:mock');
    expect(captured?.download).toMatch(/\.pdf$/);
    expect(captured?.download).toContain('2026-05-16');
    expect(captured?.download).toContain('2026-06-15');
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock');
  });

  it('rejects (does not throw synchronously) when the download fails', async () => {
    mockedGetBlob.mockRejectedValue(new Error('Download failed (500)'));
    const clickSpy = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => {});

    // Calling it must not throw synchronously — it returns a rejecting promise.
    const promise = downloadReportPdf(1, '2026-05-16', '2026-06-15');
    await expect(promise).rejects.toThrow(/Download failed/);

    // No anchor click or URL churn on the failure path.
    expect(clickSpy).not.toHaveBeenCalled();
    expect(URL.revokeObjectURL).not.toHaveBeenCalled();
  });
});
