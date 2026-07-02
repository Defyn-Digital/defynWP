import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/lib/auth';

export default function RequireAuth() {
  const { status } = useAuth();

  // While the boot-time session restore is in flight, don't redirect — wait,
  // otherwise a reload always flashes/redirects to /login before the refresh
  // cookie can restore the session.
  if (status === 'authenticating') {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">
        Loading…
      </div>
    );
  }

  if (status === 'authenticated') return <Outlet />;
  return <Navigate to="/login" replace />;
}
