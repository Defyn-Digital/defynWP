import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '@/lib/auth';
import Login from '@/routes/Login';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

// Mock @react-oauth/google so tests don't need a real Google OAuth flow.
// GoogleOAuthProvider is a passthrough wrapper; GoogleLogin renders buttons that
// fire onSuccess / onError deterministically.
vi.mock('@react-oauth/google', () => ({
  GoogleOAuthProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  GoogleLogin: ({
    onSuccess,
    onError,
  }: {
    onSuccess: (cred: { credential: string }) => void;
    onError: () => void;
  }) => (
    <>
      <button onClick={() => onSuccess({ credential: 'google-credential' })}>
        Sign in with Google
      </button>
      <button onClick={() => onError()}>Trigger Google error</button>
    </>
  ),
}));

const navigateMock = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

function renderLogin() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <Login />
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('Login route (Google SSO)', () => {
  it('renders a "Sign in with Google" button once config loads', async () => {
    renderLogin();
    expect(
      await screen.findByRole('button', { name: /sign in with google/i }),
    ).toBeInTheDocument();
  });

  it('signs in with Google and navigates home on success', async () => {
    renderLogin();
    await userEvent.click(await screen.findByRole('button', { name: /sign in with google/i }));
    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/'));
  });

  it('shows a server error when Google sign-in returns domain rejection', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/google', () =>
        HttpResponse.json(
          {
            error: {
              code: 'auth.google_domain',
              message: 'Only defyn.com.au accounts may sign in.',
            },
          },
          { status: 403 },
        ),
      ),
    );
    renderLogin();
    await userEvent.click(await screen.findByRole('button', { name: /sign in with google/i }));
    expect(await screen.findByText(/only defyn\.com\.au accounts/i)).toBeInTheDocument();
  });

  it('shows a fallback error when Google fires onError', async () => {
    renderLogin();
    await userEvent.click(await screen.findByRole('button', { name: /trigger google error/i }));
    expect(await screen.findByText(/google sign-in was cancelled or failed/i)).toBeInTheDocument();
  });

  it('shows "ask your administrator" message and no Google button when client id is empty', async () => {
    server.use(
      http.get('*/auth/config', () =>
        HttpResponse.json({ google_client_id: '', error: null }, { status: 200 }),
      ),
    );
    renderLogin();
    expect(await screen.findByText(/ask your administrator/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /sign in with google/i })).not.toBeInTheDocument();
  });
});
