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

interface FakeAnchor {
  href: string;
  download: string;
  click: ReturnType<typeof vi.fn>;
  remove: ReturnType<typeof vi.fn>;
}

let fakeAnchor: FakeAnchor;
let createElementSpy: ReturnType<typeof vi.spyOn>;
let appendChildSpy: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  mockedGetBlob.mockReset();

  // jsdom does not implement these — assign stubs.
  URL.createObjectURL = vi.fn(() => 'blob:mock');
  URL.revokeObjectURL = vi.fn();

  fakeAnchor = {
    href: '',
    download: '',
    click: vi.fn(),
    remove: vi.fn(),
  };

  // Intercept only the anchor; let everything else create real elements.
  const realCreateElement = document.createElement.bind(document);
  createElementSpy = vi
    .spyOn(document, 'createElement')
    .mockImplementation((tag: string, options?: ElementCreationOptions) => {
      if (tag === 'a') return fakeAnchor as unknown as HTMLElement;
      return realCreateElement(tag, options);
    });

  // The fake anchor isn't a real Node, so swallow the body.appendChild call.
  appendChildSpy = vi
    .spyOn(document.body, 'appendChild')
    .mockImplementation((node) => node);
});

afterEach(() => {
  createElementSpy.mockRestore();
  appendChildSpy.mockRestore();
});

describe('downloadReportPdf', () => {
  it('requests the auth-scoped PDF endpoint with from/to query params', async () => {
    mockedGetBlob.mockResolvedValue(new Blob(['%PDF-1.7'], { type: 'application/pdf' }));

    await downloadReportPdf(1, '2026-05-16', '2026-06-15');

    expect(mockedGetBlob).toHaveBeenCalledWith(
      '/sites/1/report.pdf?from=2026-05-16&to=2026-06-15',
    );
  });

  it('triggers an anchor download with a .pdf filename and revokes the object URL', async () => {
    mockedGetBlob.mockResolvedValue(new Blob(['%PDF-1.7'], { type: 'application/pdf' }));

    await downloadReportPdf(1, '2026-05-16', '2026-06-15');

    expect(fakeAnchor.href).toBe('blob:mock');
    expect(fakeAnchor.download).toMatch(/\.pdf$/);
    expect(fakeAnchor.download).toContain('2026-05-16');
    expect(fakeAnchor.download).toContain('2026-06-15');
    expect(fakeAnchor.click).toHaveBeenCalledTimes(1);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock');
  });

  it('rejects (does not throw synchronously) when the download fails', async () => {
    mockedGetBlob.mockRejectedValue(new Error('Download failed (500)'));

    // Calling it must not throw synchronously — it returns a rejecting promise.
    const promise = downloadReportPdf(1, '2026-05-16', '2026-06-15');
    await expect(promise).rejects.toThrow(/Download failed/);

    // No anchor click or URL churn on the failure path.
    expect(fakeAnchor.click).not.toHaveBeenCalled();
    expect(URL.revokeObjectURL).not.toHaveBeenCalled();
  });
});
