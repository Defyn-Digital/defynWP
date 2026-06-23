import { describe, it, expect } from 'vitest';
import * as React from 'react';
import { renderHook, act, waitFor } from '@testing-library/react';
import { AuthProvider, useAuth } from '../src/lib/auth';

describe('loginWithGoogle', () => {
  it('exchanges a Google credential for a session', async () => {
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <AuthProvider>{children}</AuthProvider>
    );
    const { result } = renderHook(() => useAuth(), { wrapper });
    await act(async () => {
      await result.current.loginWithGoogle('google-credential');
    });
    await waitFor(() => expect(result.current.status).toBe('authenticated'));
    expect(result.current.user?.email).toBe('admin@defyn.test');
  });

  it('clears state and rethrows on wrong-domain error', async () => {
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <AuthProvider>{children}</AuthProvider>
    );
    const { result } = renderHook(() => useAuth(), { wrapper });
    await expect(
      act(async () => {
        await result.current.loginWithGoogle('wrong-domain');
      }),
    ).rejects.toThrow();
    await waitFor(() => expect(result.current.status).toBe('unauthenticated'));
    expect(result.current.user).toBeNull();
  });
});
