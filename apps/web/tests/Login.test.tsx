import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '@/lib/auth';
import Login from '@/routes/Login';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

function renderLogin() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <Login />
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('Login route', () => {
  it('renders email + password fields and a submit button', () => {
    renderLogin();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
  });

  it('shows client-side validation when email is invalid', async () => {
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'not-an-email');
    await userEvent.type(screen.getByLabelText(/password/i), 'password123');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/valid email/i)).toBeInTheDocument();
  });

  it('shows the backend error message on auth.invalid_credentials', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json(
          { error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } },
          { status: 401 },
        ),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/invalid email or password/i)).toBeInTheDocument();
  });

  it('shows a rate-limit banner on auth.rate_limited', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json(
          { error: { code: 'auth.rate_limited', message: 'Too many login attempts. Try again in a minute.' } },
          { status: 429 },
        ),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'p');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/too many login attempts/i)).toBeInTheDocument();
  });

  it('disables submit while authenticating', async () => {
    let resolveLogin: ((v: unknown) => void) | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        new Promise((resolve) => {
          resolveLogin = (v) => resolve(v as Response);
        }),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'pass');
    const submit = screen.getByRole('button', { name: /sign in/i });
    await userEvent.click(submit);
    await waitFor(() => expect(submit).toBeDisabled());
    resolveLogin!(HttpResponse.json({ access_token: 'x' }, { status: 200 }));
  });
});
