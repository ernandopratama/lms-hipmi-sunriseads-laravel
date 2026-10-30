// Laravel issues the token from the session (/api/csrf) and checks it from the X-CSRF-TOKEN header.
export async function csrfToken() {
  const response = await fetch("/api/csrf", { credentials: "same-origin", cache: "no-store" });
  if (!response.ok) throw new Error("Tidak dapat menyiapkan permintaan aman");
  const data = (await response.json()) as { token: string };
  return data.token;
}

export async function csrfFetch(input: RequestInfo | URL, init: RequestInit = {}) {
  const method = (init.method || "GET").toUpperCase();
  if (["GET", "HEAD", "OPTIONS"].includes(method)) return fetch(input, { credentials: "same-origin", ...init });
  const headers = new Headers(init.headers);
  headers.set("X-CSRF-TOKEN", await csrfToken());
  return fetch(input, { credentials: "same-origin", ...init, headers });
}
