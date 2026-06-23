import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { GoogleOAuthProvider, GoogleLogin } from '@react-oauth/google';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAuth } from '@/lib/auth';
import { ApiError, apiClient } from '@/lib/apiClient';

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
    <div className="min-h-screen flex items-center justify-center p-6 bg-zinc-50">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>Sign in to DefynWP</CardTitle>
        </CardHeader>
        <CardContent>
          {serverError && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          )}
          <div className="flex justify-center">
            {clientId === null && <p className="text-sm text-muted-foreground">Loading…</p>}
            {clientId === '' && (
              <p className="text-sm text-muted-foreground text-center">
                Single sign-on isn&apos;t configured yet. Ask your administrator to set the Google
                Client ID in <strong>Settings → DefynWP</strong>.
              </p>
            )}
            {clientId !== null && clientId !== '' && (
              <GoogleOAuthProvider clientId={clientId}>
                <GoogleLogin
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
        </CardContent>
      </Card>
    </div>
  );
}
