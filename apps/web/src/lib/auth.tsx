import * as React from 'react';
import { apiClient, setAccessToken, clearAccessToken } from './apiClient';

interface User {
  id: number;
  email: string;
  display_name: string;
}

type AuthStatus = 'unauthenticated' | 'authenticating' | 'authenticated';

interface AuthState {
  status: AuthStatus;
  user: User | null;
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = React.createContext<AuthContextValue | null>(null);

interface LoginResponse {
  access_token: string;
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<AuthState>({ status: 'unauthenticated', user: null });

  const login = React.useCallback(async (email: string, password: string) => {
    setState((s) => ({ ...s, status: 'authenticating' }));
    try {
      const { access_token } = await apiClient.post<LoginResponse>('/auth/login', { email, password });
      setAccessToken(access_token);
      const user = await apiClient.get<User>('/auth/me');
      setState({ status: 'authenticated', user });
    } catch (e) {
      clearAccessToken();
      setState({ status: 'unauthenticated', user: null });
      throw e;
    }
  }, []);

  const logout = React.useCallback(async () => {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // logout is idempotent on the backend; ignore network/API errors here.
    }
    clearAccessToken();
    setState({ status: 'unauthenticated', user: null });
  }, []);

  const value = React.useMemo<AuthContextValue>(
    () => ({ ...state, login, logout }),
    [state, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = React.useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be called inside <AuthProvider>');
  return ctx;
}
