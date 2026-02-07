(function () {
  if (!window.kurukinSettings || !kurukinSettings.root || !kurukinSettings.nonce) {
    console.error("[Kurukin] kurukinSettings missing");
    return;
  }

  const rootEl = document.getElementById("kurukin-app-root");
  if (!rootEl) return;

  const tenantInstanceId = String(kurukinSettings?.user || "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9_-]/g, "");

  const state = {
    view: "dashboard", // dashboard | settings | wallet
    status: null,
    statusMsg: null,
    qr: null,
    wallet: null,
    settings: null,
    loading: {
      status: false,
      qr: false,
      wallet: false,
      settings: false,
      saving: false,
    },
    error: null,
  };

  // -----------------------------
  // Helpers
  // -----------------------------
  const esc = (s) =>
    String(s ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  const setHTML = (html) => (rootEl.innerHTML = html);

  const now = () => Math.floor(Date.now() / 1000);

  const apiFetch = async (path, method = "GET", body = null) => {
    const url = kurukinSettings.root.replace(/\/$/, "") + "/kurukin/v1/" + path.replace(/^\//, "");

    const opts = {
      method,
      headers: {
        "X-WP-Nonce": kurukinSettings.nonce,
      },
      credentials: "same-origin",
    };

    if (body !== null) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(url, opts);
    const text = await res.text();

    const ct = (res.headers.get("content-type") || "").toLowerCase();
    if (!ct.includes("application/json")) {
      console.error("[Kurukin] Non-JSON response", { url, status: res.status, ct, preview: text.slice(0, 500) });
      throw new Error("Respuesta inválida del servidor (NO JSON). Revisa logs.");
    }

    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      console.error("[Kurukin] JSON parse error", { url, preview: text.slice(0, 500) });
      throw new Error("Respuesta inválida del servidor (JSON corrupto).");
    }

    if (!res.ok) {
      const msg = json?.message || json?.data?.message || `Error HTTP (${res.status})`;
      throw new Error(msg);
    }

    if (json && json.state === "error") {
      throw new Error(json.message || "Error interno");
    }

    return json;
  };

  // -----------------------------
  // Data loaders
  // -----------------------------
  const loadStatus = async () => {
    state.loading.status = true;
    render();
    try {
      const d = await apiFetch("connection/status");
      state.status = d.state || null;
      state.statusMsg = d.message || null;
      if (state.status === "open") state.qr = null;
    } catch (e) {
      state.status = "error";
      state.statusMsg = e.message || "Error";
    } finally {
      state.loading.status = false;
      render();
    }
  };

  const loadQr = async (force = false) => {
    if (state.loading.qr) return;
    if (state.qr && !force) return;

    state.loading.qr = true;
    render();
    try {
      const d = await apiFetch("connection/qr");
      state.qr = d?.base64 || null;
    } catch (e) {
      console.error("[Kurukin] QR error", e);
    } finally {
      state.loading.qr = false;
      render();
    }
  };

  const loadWallet = async () => {
    state.loading.wallet = true;
    render();
    try {
      const p = tenantInstanceId ? `wallet?instance_id=${encodeURIComponent(tenantInstanceId)}` : "wallet";
      const w = await apiFetch(p);
      state.wallet = w || null;
    } catch (e) {
      state.wallet = null;
      state.error = e.message || "No se pudo cargar wallet";
    } finally {
      state.loading.wallet = false;
      render();
    }
  };

  const loadSettings = async () => {
    state.loading.settings = true;
    render();
    try {
      const s = await apiFetch("settings");
      state.settings = s || null;
    } catch (e) {
      state.settings = null;
      state.error = e.message || "No se pudo cargar settings";
    } finally {
      state.loading.settings = false;
      render();
    }
  };

  const saveSettings = async () => {
    if (!state.settings) return;
    state.loading.saving = true;
    render();
    try {
      await apiFetch("settings", "POST", state.settings);
      state.error = null;
    } catch (e) {
      state.error = e.message || "No se pudo guardar";
    } finally {
      state.loading.saving = false;
      render();
    }
  };

  const resetInstance = async () => {
    if (!confirm("¿Detener/Reiniciar instancia?")) return;
    try {
      await apiFetch("connection/reset", "POST", {});
      state.status = "initializing";
      state.qr = null;
      render();
      setTimeout(loadStatus, 1200);
    } catch (e) {
      state.error = e.message || "No se pudo resetear";
      render();
    }
  };

  // -----------------------------
  // UI (mínimo)
  // -----------------------------
  const nav = () => {
    return `
      <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
        <button data-nav="dashboard">Dashboard</button>
        <button data-nav="settings">Configuración</button>
        <button data-nav="wallet">Wallet</button>
        <span style="margin-left:auto; font-family:monospace; opacity:.8;">tenant=${esc(kurukinSettings.user || "")}</span>
      </div>
      <hr />
    `;
  };

  const errorBox = () => {
    if (!state.error) return "";
    return `
      <div style="background:#300; color:#fbb; padding:10px; margin:10px 0; border:1px solid #600;">
        <b>Error:</b> ${esc(state.error)}
        <button data-act="clear_error" style="margin-left:10px;">x</button>
      </div>
    `;
  };

  const dashboardView = () => {
    const st = state.status || "unknown";
    const msg = state.statusMsg || "";
    const w = state.wallet || {};
    const bal = Number(w.credits_balance ?? 0);
    const min = Number(w.min_required ?? 1);
    const can = !!w.can_process;

    const qrHtml =
      state.loading.qr
        ? `<div>Cargando QR...</div>`
        : state.qr
        ? `<img src="${esc(state.qr)}" style="max-width:320px; width:100%; height:auto; border:1px solid #333;" />`
        : `<div>Sin QR</div>`;

    return `
      <h2>Dashboard</h2>

      <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin:12px 0;">
        <div style="border:1px solid #333; padding:10px;">
          <div><b>Estado</b></div>
          <div style="font-family:monospace;">${esc(String(st).toUpperCase())}</div>
        </div>
        <div style="border:1px solid #333; padding:10px;">
          <div><b>Saldo</b></div>
          <div style="font-family:monospace;">${esc(bal.toFixed(2))}</div>
        </div>
        <div style="border:1px solid #333; padding:10px;">
          <div><b>Min</b></div>
          <div style="font-family:monospace;">${esc(min.toFixed(2))}</div>
        </div>
        <div style="border:1px solid #333; padding:10px;">
          <div><b>Puede Procesar</b></div>
          <div style="font-family:monospace;">${can ? "SI" : "NO"}</div>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
        <button data-act="refresh_status">${state.loading.status ? "..." : "Actualizar estado"}</button>
        <button data-act="refresh_wallet">${state.loading.wallet ? "..." : "Actualizar saldo"}</button>
        <button data-act="refresh_qr">${state.loading.qr ? "..." : "Actualizar QR"}</button>
        <button data-act="reset_instance">Detener/Reiniciar</button>
      </div>

      <div style="margin:8px 0; font-family:monospace; opacity:.85;">Mensaje: ${esc(msg)}</div>

      <h3>QR</h3>
      ${qrHtml}

      <style>
        @media (max-width: 900px) {
          #kurukin-app-root div[style*="grid-template-columns:repeat(4"] { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
        }
        @media (max-width: 520px) {
          #kurukin-app-root div[style*="grid-template-columns:repeat(4"] { grid-template-columns:repeat(1,minmax(0,1fr)) !important; }
          #kurukin-app-root button { width:100%; }
        }
      </style>
    `;
  };

  const walletView = () => {
    const w = state.wallet || {};
    return `
      <h2>Wallet</h2>
      <button data-act="refresh_wallet">${state.loading.wallet ? "..." : "Actualizar saldo"}</button>
      <pre style="margin-top:10px; padding:10px; border:1px solid #333; white-space:pre-wrap;">
${esc(JSON.stringify(w, null, 2))}
      </pre>
    `;
  };

  const settingsView = () => {
    const s = state.settings || {};
    return `
      <h2>Configuración</h2>
      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
        <button data-act="refresh_settings">${state.loading.settings ? "..." : "Recargar"}</button>
        <button data-act="save_settings">${state.loading.saving ? "Guardando..." : "Guardar"}</button>
      </div>

      <div style="margin:10px 0; border:1px solid #333; padding:10px;">
        <div><b>System Prompt</b></div>
        <textarea data-field="system_prompt" style="width:100%; min-height:120px;">${esc(s?.brain?.system_prompt || "")}</textarea>
      </div>

      <div style="margin:10px 0; border:1px solid #333; padding:10px;">
        <div><b>Audio Enabled</b></div>
        <label>
          <input type="checkbox" data-field="voice_enabled" ${s?.voice?.enabled ? "checked" : ""} />
          Activar audio
        </label>
      </div>

      <div style="margin:10px 0; border:1px solid #333; padding:10px;">
        <div><b>ElevenLabs BYOK</b></div>
        <label>
          <input type="checkbox" data-field="eleven_byok" ${s?.voice?.byok_enabled ? "checked" : ""} />
          Usar mi llave
        </label>
        <div style="margin-top:8px;">
          <input data-field="eleven_key" type="password" placeholder="ElevenLabs API Key" style="width:100%;" value="${esc(s?.voice?.eleven_api_key || "")}" />
        </div>
        <div style="margin-top:8px;">
          <input data-field="eleven_voice_id" type="text" placeholder="Voice ID" style="width:100%;" value="${esc(s?.voice?.voice_id || "")}" />
        </div>
      </div>

      <div style="margin:10px 0; border:1px solid #333; padding:10px;">
        <div><b>Business</b></div>
        <div style="margin-top:8px;">
          <div>Perfil</div>
          <textarea data-field="biz_profile" style="width:100%; min-height:80px;">${esc(s?.business?.profile || "")}</textarea>
        </div>
        <div style="margin-top:8px;">
          <div>Servicios</div>
          <textarea data-field="biz_services" style="width:100%; min-height:80px;">${esc(s?.business?.services || "")}</textarea>
        </div>
        <div style="margin-top:8px;">
          <div>Reglas</div>
          <textarea data-field="biz_rules" style="width:100%; min-height:80px;">${esc(s?.business?.rules || "")}</textarea>
        </div>
      </div>

      <pre style="margin-top:10px; padding:10px; border:1px solid #333; white-space:pre-wrap;">
${esc(JSON.stringify(s, null, 2))}
      </pre>
    `;
  };

  const render = () => {
    let viewHtml = "";
    if (state.view === "dashboard") viewHtml = dashboardView();
    if (state.view === "wallet") viewHtml = walletView();
    if (state.view === "settings") viewHtml = settingsView();

    setHTML(`
      <div style="font-family:system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:#eee; background:#111; padding:12px; border:1px solid #222;">
        ${nav()}
        ${errorBox()}
        ${viewHtml}
      </div>
    `);

    // bind nav
    rootEl.querySelectorAll("[data-nav]").forEach((btn) => {
      btn.onclick = () => {
        state.view = btn.getAttribute("data-nav");
        state.error = null;
        render();
        if (state.view === "wallet" && !state.wallet) loadWallet();
        if (state.view === "settings" && !state.settings) loadSettings();
      };
    });

    // bind actions
    const act = (name, fn) => {
      const el = rootEl.querySelector(`[data-act="${name}"]`);
      if (el) el.onclick = fn;
    };

    act("clear_error", () => { state.error = null; render(); });
    act("refresh_status", loadStatus);
    act("refresh_wallet", loadWallet);
    act("refresh_qr", () => loadQr(true));
    act("reset_instance", resetInstance);
    act("refresh_settings", loadSettings);
    act("save_settings", () => {
      // pull values from inputs into state.settings
      if (!state.settings) return;

      const s = JSON.parse(JSON.stringify(state.settings));

      const get = (sel) => rootEl.querySelector(sel);

      const sp = get('[data-field="system_prompt"]')?.value ?? "";
      s.brain = s.brain || {};
      s.brain.system_prompt = sp;

      const ve = !!get('[data-field="voice_enabled"]')?.checked;
      s.voice = s.voice || {};
      s.voice.enabled = ve;

      const byok = !!get('[data-field="eleven_byok"]')?.checked;
      s.voice.byok_enabled = byok;

      const ek = get('[data-field="eleven_key"]')?.value ?? "";
      s.voice.eleven_api_key = ek;

      const vid = get('[data-field="eleven_voice_id"]')?.value ?? "";
      s.voice.voice_id = vid;

      s.business = s.business || {};
      s.business.profile = get('[data-field="biz_profile"]')?.value ?? "";
      s.business.services = get('[data-field="biz_services"]')?.value ?? "";
      s.business.rules = get('[data-field="biz_rules"]')?.value ?? "";

      state.settings = s;
      render();
      saveSettings();
    });
  };

  // boot
  render();
  loadStatus();
  loadWallet();
  loadSettings();

  // poll status (simple)
  setInterval(() => {
    if (state.loading.status) return;
    loadStatus();
    if (state.status === "close" && state.view === "dashboard") loadQr(false);
  }, 5000);
})();
