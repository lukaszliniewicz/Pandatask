import {
  apiRequest,
  getSettings,
  normalizeSiteUrl,
  requestSitePermission,
  storageSet,
} from "./common.js";

const form = document.querySelector("#settings-form");
const siteUrl = document.querySelector("#site-url");
const username = document.querySelector("#username");
const appPassword = document.querySelector("#app-password");
const status = document.querySelector("#status");

const existing = await getSettings();
siteUrl.value = existing.siteUrl || "";
username.value = existing.username || "";
appPassword.value = existing.appPassword || "";

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  status.textContent = "Checking…";
  try {
    const settings = {
      siteUrl: normalizeSiteUrl(siteUrl.value),
      username: username.value.trim(),
      appPassword: appPassword.value.trim(),
    };
    const granted = await requestSitePermission(settings.siteUrl);
    if (!granted) throw new Error("Host permission was not granted.");
    await apiRequest(settings, "/users/me/boards");
    await storageSet({ pandataskSettings: settings });
    status.textContent = "Connected and saved.";
  } catch (error) {
    status.textContent = error.message || "Could not connect.";
  }
});
