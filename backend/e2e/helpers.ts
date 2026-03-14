import { Page } from '@playwright/test';

/**
 * Make an API call from the page context with proper CSRF handling.
 */
export async function apiJson(
  page: Page,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  url: string,
  payload?: Record<string, unknown>,
) {
  // Ensure we have a CSRF cookie
  await page.context().request.get('/sanctum/csrf-cookie');

  return page.evaluate(
    async ({ method, url, payload }) => {
      const cookies = document.cookie.split('; ');
      const xsrf = cookies
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };

      if (xsrf) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
      }

      const res = await fetch(url, {
        method,
        headers,
        credentials: 'include',
        body: payload ? JSON.stringify(payload) : undefined,
      });

      let data: unknown;
      const text = await res.text();
      try {
        data = JSON.parse(text);
      } catch {
        data = text;
      }

      return { status: res.status, ok: res.ok, data };
    },
    { method, url, payload },
  );
}
