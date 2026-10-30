// Reads the backend's error message (string or validation array) with a fallback.
export async function apiError(response: Response, fallback: string) {
  const payload = await response.json().catch(() => null);
  const message = payload?.message;
  return Array.isArray(message) ? message.join(", ") : message || fallback;
}
