const ext = globalThis.browser ?? globalThis.chrome;
const promiseApi = Boolean(globalThis.browser);

export const storageGet = (keys) => {
  if (promiseApi) return ext.storage.local.get(keys);
  return new Promise((resolve, reject) =>
    ext.storage.local.get(keys, (value) => {
      const error = globalThis.chrome?.runtime?.lastError;
      if (error) reject(new Error(error.message));
      else resolve(value);
    }),
  );
};

export const storageSet = (values) => {
  if (promiseApi) return ext.storage.local.set(values);
  return new Promise((resolve, reject) =>
    ext.storage.local.set(values, () => {
      const error = globalThis.chrome?.runtime?.lastError;
      if (error) reject(new Error(error.message));
      else resolve();
    }),
  );
};

export const normalizeSiteUrl = (value) =>
  String(value || "")
    .trim()
    .replace(/\/+$/, "");

export const originPattern = (siteUrl) => {
  const url = new URL(normalizeSiteUrl(siteUrl));
  return `${url.origin}/*`;
};

export const requestSitePermission = async (siteUrl) => {
  const origins = [originPattern(siteUrl)];
  if (!ext.permissions?.request) return true;
  if (promiseApi) return ext.permissions.request({ origins });
  return new Promise((resolve) =>
    ext.permissions.request({ origins }, resolve),
  );
};

export const apiRequest = async (
  settings,
  path,
  { method = "GET", body } = {},
) => {
  const siteUrl = normalizeSiteUrl(settings.siteUrl);
  if (!siteUrl || !settings.username || !settings.appPassword) {
    throw new Error(
      "Configure the Pandatask site, username and application password first.",
    );
  }
  const token = btoa(
    unescape(
      encodeURIComponent(
        `${settings.username}:${settings.appPassword.replace(/\s+/g, "")}`,
      ),
    ),
  );
  const response = await fetch(`${siteUrl}/wp-json/pandatask/v1${path}`, {
    method,
    headers: {
      Accept: "application/json",
      Authorization: `Basic ${token}`,
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(
      payload?.message || `Pandatask returned HTTP ${response.status}.`,
    );
  }
  return payload;
};

export const getSettings = async () => {
  const values = await storageGet(["pandataskSettings"]);
  return values.pandataskSettings || {};
};
