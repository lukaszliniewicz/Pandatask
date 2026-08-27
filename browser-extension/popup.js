import { apiRequest, getSettings } from "./common.js";

const ext = globalThis.browser ?? globalThis.chrome;
const promiseApi = Boolean(globalThis.browser);
const form = document.querySelector("#capture-form");
const title = document.querySelector("#title");
const destination = document.querySelector("#destination");
const note = document.querySelector("#note");
const includeSelection = document.querySelector("#include-selection");
const includeUrl = document.querySelector("#include-url");
const status = document.querySelector("#status");
const captureButton = document.querySelector("#capture");

const tabsQuery = (query) =>
  promiseApi
    ? ext.tabs.query(query)
    : new Promise((resolve) => ext.tabs.query(query, resolve));
const executeScript = (details) => {
  if (promiseApi) return ext.scripting.executeScript(details);
  return new Promise((resolve, reject) =>
    ext.scripting.executeScript(details, (output) => {
      const error = globalThis.chrome?.runtime?.lastError;
      if (error) reject(new Error(error.message));
      else resolve(output);
    }),
  );
};

const settings = await getSettings();
let activeTab = null;
let selectedText = "";

try {
  [activeTab] = await tabsQuery({ active: true, currentWindow: true });
  title.value = activeTab?.title || "";
  if (activeTab?.id && /^https?:/i.test(activeTab.url || "")) {
    const result = await executeScript({
      target: { tabId: activeTab.id },
      func: () => globalThis.getSelection?.().toString() || "",
    });
    selectedText = result?.[0]?.result || "";
    if (selectedText.trim())
      title.value = selectedText.trim().split(/\n/)[0].slice(0, 180);
  }
  const boardsPayload = await apiRequest(settings, "/users/me/boards");
  const boards = boardsPayload.boards || boardsPayload || [];
  for (const board of boards) {
    const option = document.createElement("option");
    option.value = board.id || board.board_name;
    option.textContent =
      board.name || board.label || board.id || board.board_name;
    destination.append(option);
  }
} catch (error) {
  status.textContent = `${
    error.message || "Not connected."
  } Open Settings to configure the extension.`;
}

document
  .querySelector("#settings")
  .addEventListener("click", () => ext.runtime.openOptionsPage());

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  captureButton.disabled = true;
  status.textContent = "Capturing…";
  try {
    const parts = [];
    if (note.value.trim()) parts.push(note.value.trim());
    if (includeSelection.checked && selectedText.trim())
      parts.push(`> ${selectedText.trim().replace(/\n+/g, "\n> ")}`);
    const description = parts.join("\n\n");
    const url =
      includeUrl.checked && /^https?:/i.test(activeTab?.url || "")
        ? activeTab.url
        : "";
    if (destination.value === "inbox") {
      await apiRequest(settings, "/users/me/inbox", {
        method: "POST",
        body: {
          name: title.value.trim(),
          description,
          ...(url
            ? {
                source_url: url,
                source_title: activeTab?.title || title.value.trim(),
              }
            : {}),
          capture_source: "browser_extension",
        },
      });
    } else {
      await apiRequest(
        settings,
        `/boards/${encodeURIComponent(destination.value)}/tasks`,
        {
          method: "POST",
          body: {
            name: title.value.trim(),
            description,
            status: "pending",
            priority: 5,
            task_type: "task",
            ...(url
              ? {
                  attachment_type: "link",
                  attachment_url: url,
                  attachment_filename: activeTab?.title || title.value.trim(),
                }
              : {}),
          },
        },
      );
    }
    status.textContent = "Captured.";
    setTimeout(() => globalThis.close(), 450);
  } catch (error) {
    status.textContent = error.message || "Capture failed.";
    captureButton.disabled = false;
  }
});
