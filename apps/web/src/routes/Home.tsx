import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/lib/auth';

export default function Home() {
  const { user, logout } = useAuth();

  return (
    <div className="min-h-screen p-8">
      <Card className="max-w-2xl mx-auto">
        <CardHeader>
          <CardTitle>Welcome, {user?.display_name ?? 'there'}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-zinc-600">Signed in as {user?.email}</p>
          <Button variant="outline" onClick={() => logout()}>
            Sign out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
