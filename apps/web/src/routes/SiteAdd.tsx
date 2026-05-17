import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { apiClient, ApiError } from '@/lib/apiClient';
import { createSiteSchema, createSiteResponseSchema, type CreateSiteInput } from '@/types/api';

export default function SiteAdd() {
  const navigate = useNavigate();
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<CreateSiteInput>({
    resolver: zodResolver(createSiteSchema),
    defaultValues: { url: '', label: '', code: '' },
  });

  const mutation = useMutation({
    mutationFn: async (input: CreateSiteInput) => {
      const data = await apiClient.post<unknown>('/sites', input);
      return createSiteResponseSchema.parse(data);
    },
    onSuccess: (data) => navigate(`/sites/${data.site_id}`),
  });

  const onSubmit = handleSubmit((values) => mutation.mutate(values));

  return (
    <div className="min-h-screen p-8">
      <Card className="max-w-xl mx-auto">
        <CardHeader>
          <CardTitle>Add Site</CardTitle>
        </CardHeader>
        <CardContent>
          {mutation.isError && (
            <Alert className="mb-4">
              <AlertDescription>
                {(mutation.error as ApiError).message || 'Something went wrong.'}
              </AlertDescription>
            </Alert>
          )}
          <form onSubmit={onSubmit} className="space-y-4">
            <div className="space-y-1">
              <Label htmlFor="url">URL</Label>
              <Input id="url" placeholder="https://example.com" {...register('url')} />
              {errors.url && <p className="text-xs text-red-600">{errors.url.message}</p>}
            </div>
            <div className="space-y-1">
              <Label htmlFor="label">Label</Label>
              <Input id="label" placeholder="Optional name" {...register('label')} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="code">Code</Label>
              <Input id="code" placeholder="12-character code from the connector" {...register('code')} />
              {errors.code && <p className="text-xs text-red-600">{errors.code.message}</p>}
            </div>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? 'Adding…' : 'Add Site'}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
