import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/lib/auth';

export default function RequireAuth() {
  const { status } = useAuth();
  if (status === 'authenticated') return <Outlet />;
  return <Navigate to="/login" replace />;
}
