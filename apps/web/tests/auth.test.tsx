import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { AuthProvider, useAuth } from '@/lib/auth';
import { clearAccessToken } from '@/lib/apiClient';

function ProbeComponent() {
  const auth = useAuth();
  return (
    <div>
      <div data-testid="state">{auth.status}</div>
      <div data-testid="user">{auth.user?.email ?? 'none'}</div>
      <button onClick={() => auth.login('a@b.test', 'pass').catch(() => {})}>login</button>
      <button onClick={() => auth.logout()}>logout</button>
    </div>
  );
}

function renderWithAuth() {
  return render(
    <AuthProvider>
      <ProbeComponent />
    </AuthProvider>,
  );
}

describe('AuthContext', () => {
  beforeEach(() => {
    clearAccessToken();
  });

  it('starts in unauthenticated state', () => {
    renderWithAuth();
    expect(screen.getByTestId('state').textContent).toBe('unauthenticated');
  });

  it('successful login transitions to authenticated and loads user', async () => {
    renderWithAuth();
    await act(async () => {
      await userEvent.click(screen.getByText('login'));
    });
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
    expect(screen.getByTestId('user').textContent).toBe('admin@defyn.test');
  });

  it('logout clears state and returns to unauthenticated', async () => {
    renderWithAuth();
    await act(async () => {
      await userEvent.click(screen.getByText('login'));
    });
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
    await act(async () => {
      await userEvent.click(screen.getByText('logout'));
    });
    expect(screen.getByTestId('state').textContent).toBe('unauthenticated');
    expect(screen.getByTestId('user').textContent).toBe('none');
  });
});
