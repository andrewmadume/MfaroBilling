export const apiBase = (import.meta.env.VITE_API_BASE_URL || "/api/v1").replace(
  /\/$/,
  "",
);
let csrf = "";
let organisation = "";
export function setOrganisation(id: string) {
  organisation = id;
}
export function setCsrf(token: string) {
  csrf = token;
}
export async function api<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const response = await fetch(apiBase + path, {
    ...options,
    credentials: "include",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(csrf ? { "X-CSRF-TOKEN": csrf } : {}),
      ...(organisation ? { "X-Organisation-ID": organisation } : {}),
      ...options.headers,
    },
  });
  if (!response.ok) {
    const data = await response
      .json()
      .catch(() => ({
        message: "The service could not complete your request.",
      }));
    throw new Error(
      data.errors
        ? Object.values(data.errors).flat().join(" ")
        : data.message || `Request failed (${response.status})`,
    );
  }
  return response.json() as Promise<T>;
}
export const post = <T>(
  path: string,
  data: unknown = {},
  headers: Record<string, string> = {},
) => api<T>(path, { method: "POST", body: JSON.stringify(data), headers });
export async function download(path: string, name: string) {
  const r = await fetch(apiBase + path, {
    credentials: "include",
    cache: "no-store",
    headers: {
      "X-Organisation-ID": organisation,
      Accept: "application/pdf, text/csv, application/json",
    },
  });
  if (!r.ok) throw new Error("Download could not be authorised.");
  const url = URL.createObjectURL(await r.blob());
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
