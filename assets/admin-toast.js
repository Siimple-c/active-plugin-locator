(function () {
  if (!window.APL_TOAST_DATA || !window.APL_TOAST_DATA.payload) return;

  const root = document.getElementById("apl-toast-root");
  if (!root) return;

  const { payload, i18n } = window.APL_TOAST_DATA;

  const toast = document.createElement("div");
  toast.className = "apl-toast";
  toast.setAttribute("role", "status");

  const header = document.createElement("div");
  header.className = "apl-toast__header";

  const title = document.createElement("div");
  title.className = "apl-toast__title";
  title.textContent = payload.title || "Plugin activated";

  const closeBtn = document.createElement("button");
  closeBtn.className = "apl-toast__close";
  closeBtn.type = "button";
  closeBtn.textContent = (i18n && i18n.close) ? i18n.close : "Dismiss";
  closeBtn.addEventListener("click", () => toast.remove());

  header.appendChild(title);
  header.appendChild(closeBtn);

  const body = document.createElement("div");
  body.className = "apl-toast__body";

  const list = document.createElement("ul");
  list.className = "apl-toast__list";

  (payload.items || []).forEach((item) => {
    const li = document.createElement("li");
    li.className = "apl-toast__item";

    const name = document.createElement("div");
    name.className = "apl-toast__item-name";
    name.textContent = item.name || item.plugin || "";

    const msg = document.createElement("div");
    msg.className = "apl-toast__item-msg";
    msg.textContent = item.message || "";

    li.appendChild(name);
    li.appendChild(msg);
    list.appendChild(li);
  });

  body.appendChild(list);

  toast.appendChild(header);
  toast.appendChild(body);

  root.appendChild(toast);

  // Auto-hide after ~10s (still dismissible).
  window.setTimeout(() => {
    if (toast && toast.parentNode) toast.remove();
  }, 10000);

  // ESC closes.
  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && toast && toast.parentNode) toast.remove();
  });
})();