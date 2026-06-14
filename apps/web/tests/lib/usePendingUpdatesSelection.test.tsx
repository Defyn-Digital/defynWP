import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';

// Generic rows — the hook only touches the common fields, so this fixture
// stands in for both PendingPluginUpdateRow and PendingThemeUpdateRow.
const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', current_version: '5.3', target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast', current_version: '22.5', target_version: '22.6' },
  { site_id: 2, site_label: 'AcmeBlog', slug: 'jetpack', current_version: '13.1', target_version: '13.2' },
];

const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', current_version: '5.3', target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast', current_version: '22.5', target_version: '22.6' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog', slug: 'jetpack', current_version: '13.1', target_version: '13.2' },
];

describe('usePendingUpdatesSelection', () => {
  it('seedsAllRowsCheckedOnMount', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    expect(result.current.totalCount).toBe(3);
    expect(result.current.siteCount).toBe(2);
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.selectedPairs).toHaveLength(3);
  });

  it('groupsRowsBySiteLabelPreservingServerOrder', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    const labels = result.current.grouped.map(([label]) => label);
    expect(labels).toEqual(['SmartCoding', 'AcmeBlog']);
    expect(result.current.grouped[0][1]).toHaveLength(2);
    expect(result.current.grouped[1][1]).toHaveLength(1);
  });

  it('toggleRowUnchecksAndReChecksASingleKey', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(2);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(false);
    expect(result.current.selectedPairs).toEqual([
      { site_id: 1, slug: 'yoast' },
      { site_id: 2, slug: 'jetpack' },
    ]);
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(3);
  });

  it('toggleGroupChecksAndUnchecksAllRowsForASite', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    // Uncheck the whole SmartCoding group (2 rows, currently all checked).
    act(() => result.current.toggleGroup(['1:akismet', '1:yoast'], true));
    expect(result.current.checkedCount).toBe(1);
    expect(result.current.checkedKeys.has('2:jetpack')).toBe(true);
    // Re-check it.
    act(() => result.current.toggleGroup(['1:akismet', '1:yoast'], false));
    expect(result.current.checkedCount).toBe(3);
  });

  it('skipMajorDefaultsOffShowingAllRows', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    expect(result.current.skipMajor).toBe(false);
    expect(result.current.totalCount).toBe(4);
    expect(result.current.checkedCount).toBe(4);
  });

  it('skipMajorOnHidesMajorRowsAndReDerivesCounts', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    act(() => result.current.setSkipMajor(true));
    expect(result.current.totalCount).toBe(3); // elementor 3.x → 4.x hidden
    expect(result.current.siteCount).toBe(2);
    expect(result.current.visibleRows.some((r) => r.slug === 'elementor')).toBe(false);
    // selectedPairs never contains the hidden major row.
    expect(result.current.selectedPairs.some((p) => p.slug === 'elementor')).toBe(false);
  });

  it('skipMajorFlipReSeedsCheckedKeysToVisibleRows', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    // Manually uncheck akismet (toggle still OFF): 3 of 4 checked.
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.totalCount).toBe(4);
    // Flip skipMajor ON — re-seed to all 3 visible rows (akismet checked again).
    act(() => result.current.setSkipMajor(true));
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.totalCount).toBe(3);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(true);
  });

  it('preservesChecksWhenRefetchReturnsIdenticalContent', () => {
    // A background refetch (staleTime, window focus) commonly yields a NEW array
    // reference with the SAME rows. Re-seeding on mere reference identity — the
    // original behavior — both wiped the operator's manual unchecks AND spun an
    // infinite render loop when a caller passed `data ?? []` (a fresh [] every
    // render). Identical content must be a no-op so selections survive.
    const { result, rerender } = renderHook(
      ({ rows }) => usePendingUpdatesSelection(rows),
      { initialProps: { rows: ROWS } },
    );
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(2);
    // New array reference, identical keys → checks preserved (no re-seed).
    rerender({ rows: [...ROWS] });
    expect(result.current.checkedCount).toBe(2);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(false);
  });

  it('reSeedsWhenRowsContentChanges', () => {
    // When a fresh fetch actually changes the visible key set (rows added or
    // removed), re-seed to all-visible so newly-arrived updates are pre-checked.
    const { result, rerender } = renderHook(
      ({ rows }) => usePendingUpdatesSelection(rows),
      { initialProps: { rows: ROWS } },
    );
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(2);
    // Drop jetpack → different key set → re-seed to all visible rows.
    rerender({ rows: ROWS.slice(0, 2) });
    expect(result.current.totalCount).toBe(2);
    expect(result.current.checkedCount).toBe(2);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(true); // uncheck discarded on re-seed
  });
});
