import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { GoogleLogin } from '@react-oauth/google';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAuth } from '@/lib/auth';
import { ApiError } from '@/lib/apiClient';

export default function Login() {
  const [serverError, setServerError] = useState<string | null>(null);
  const auth = useAuth();
  const navigate = useNavigate();

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
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
