import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { GoogleOAuthProvider, GoogleLogin } from '@react-oauth/google';
import { Lock } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { ApiError, apiClient } from '@/lib/apiClient';

function DefynMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 48 48" className={className} role="img" aria-label="DefynWP logo">
      <defs>
        <linearGradient id="defyn-mark" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="hsl(239 84% 72%)" />
          <stop offset="100%" stopColor="hsl(258 72% 56%)" />
        </linearGradient>
      </defs>
      <rect
        x="10"
        y="10"
        width="28"
        height="28"
        rx="8"
        transform="rotate(45 24 24)"
        fill="url(#defyn-mark)"
      />
      <rect
        x="10"
        y="10"
        width="28"
        height="28"
        rx="8"
        transform="rotate(45 24 24)"
        fill="none"
        stroke="white"
        strokeOpacity="0.25"
      />
    </svg>
  );
}

export default function Login() {
  const [clientId, setClientId] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const auth = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    apiClient
      .get<{ google_client_id: string }>('/auth/config')
      .then((data) => setClientId(data.google_client_id ?? ''))
      .catch(() => setClientId(import.meta.env.VITE_GOOGLE_CLIENT_ID ?? ''));
  }, []);

  return (
    <div className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-[hsl(245_47%_9%)] px-4 py-10">
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-gradient-to-br from-[hsl(245_47%_11%)] via-[hsl(246_45%_15%)] to-[hsl(249_42%_21%)]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -top-32 left-1/2 h-[460px] w-[460px] -translate-x-1/2 rounded-full bg-[hsl(239_84%_60%)] opacity-20 blur-[130px]"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-40 -right-24 h-[380px] w-[380px] rounded-full bg-[hsl(258_70%_55%)] opacity-10 blur-[130px]"
      />

      <main className="relative z-10 w-full max-w-sm motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-500">
        <div className="mb-8 flex flex-col items-center text-center">
          <DefynMark className="h-12 w-12" />
          <p className="mt-3 text-lg font-semibold tracking-tight text-white">
            Defyn<span className="text-[hsl(239_84%_74%)]">WP</span>
          </p>
        </div>

        <div className="rounded-2xl border border-white/10 bg-white/[0.06] p-8 shadow-2xl shadow-black/40 backdrop-blur-xl">
          <div className="mb-6 text-center">
            <h1 className="text-2xl font-semibold tracking-tight text-white">Welcome back</h1>
            <p className="mt-1.5 text-sm text-indigo-200/70">
              Sign in to manage your WordPress fleet
            </p>
          </div>

          {serverError && (
            <div
              role="alert"
              className="mb-5 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-200"
            >
              {serverError}
            </div>
          )}

          <div className="flex min-h-[44px] items-center justify-center">
            {clientId === null && <span className="text-sm text-indigo-200/60">Loading…</span>}
            {clientId === '' && (
              <p className="text-center text-sm text-indigo-200/70">
                Single sign-on isn&apos;t configured yet. Ask your administrator to set the Google
                Client ID in{' '}
                <strong className="font-medium text-white">Settings → DefynWP</strong>.
              </p>
            )}
            {clientId !== null && clientId !== '' && (
              <GoogleOAuthProvider clientId={clientId}>
                <GoogleLogin
                  theme="outline"
                  size="large"
                  shape="pill"
                  width="320"
                  text="signin_with"
                  onSuccess={async (cred) => {
                    setServerError(null);
                    try {
                      if (!cred.credential) throw new Error('No credential');
                      await auth.loginWithGoogle(cred.credential);
                      navigate('/');
                    } catch (e) {
                      setServerError(
                        e instanceof ApiError ? e.message : 'Sign-in failed. Please try again.',
                      );
                    }
                  }}
                  onError={() => setServerError('Google sign-in was cancelled or failed.')}
                />
              </GoogleOAuthProvider>
            )}
          </div>
        </div>

        <div className="mt-6 flex items-center justify-center gap-1.5 text-xs text-indigo-200/50">
          <Lock className="h-3.5 w-3.5" aria-hidden />
          <span>Restricted to defyn.com.au accounts</span>
        </div>
      </main>
    </div>
  );
}
