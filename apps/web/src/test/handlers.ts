import { http, HttpResponse } from 'msw';

export const handlers = [
  // Default: login succeeds with a fake access token.
  http.post('*/wp-json/defyn/v1/auth/login', async ({ request }) => {
    const body = (await request.json()) as { email?: string; password?: string };
    if (!body.email || !body.password) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_fields', message: 'Email and password are required.' } },
        { status: 400 },
      );
    }
    if (body.password === 'wrong') {
      return HttpResponse.json(
        { error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ access_token: 'fake.access.token' }, { status: 200 });
  }),

  // /auth/me returns a fixed user when given any Bearer token.
  http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
    const auth = request.headers.get('Authorization') ?? '';
    if (!auth.startsWith('Bearer ')) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_token', message: 'Authorization: Bearer <token> required.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ id: 1, email: 'admin@defyn.test', display_name: 'Admin User' }, { status: 200 });
  }),

  // /auth/refresh — generic success.
  http.post('*/wp-json/defyn/v1/auth/refresh', () =>
    HttpResponse.json({ access_token: 'fake.access.token.v2' }, { status: 200 }),
  ),

  // /auth/logout — always 204.
  http.post('*/wp-json/defyn/v1/auth/logout', () => new HttpResponse(null, { status: 204 })),
];
