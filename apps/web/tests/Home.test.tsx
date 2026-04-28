import { describe, it, expect } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter, Routes, Route, useNavigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '@/lib/auth';
import Home from '@/routes/Home';
import RequireAuth from '@/routes/RequireAuth';

function makeApp(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<div>LOGIN PAGE</div>} />
            <Route element={<RequireAuth />}>
              <Route path="/" element={<Home />} />
            </Route>
          </Routes>
        </AuthProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

function LoginAndGoHome() {
  const auth = useAuth();
  const navigate = useNavigate();
  return (
    <button
      onClick={async () => {
        await auth.login('a@b.test', 'p');
        navigate('/');
      }}
    >
      auth-login
    </button>
  );
}

describe('Home route + RequireAuth', () => {
  it('redirects to /login when unauthenticated', () => {
    makeApp('/');
    expect(screen.getByText('LOGIN PAGE')).toBeInTheDocument();
  });

  it('renders Home with welcome message when authenticated', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <MemoryRouter initialEntries={['/']}>
        <QueryClientProvider client={queryClient}>
          <AuthProvider>
            <LoginAndGoHome />
            <Routes>
              <Route path="/login" element={<div>LOGIN PAGE</div>} />
              <Route element={<RequireAuth />}>
                <Route path="/" element={<Home />} />
              </Route>
            </Routes>
          </AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>,
    );

    await act(async () => {
      await userEvent.click(screen.getByText('auth-login'));
    });

    expect(await screen.findByText(/welcome.*admin user/i)).toBeInTheDocument();
  });
});
