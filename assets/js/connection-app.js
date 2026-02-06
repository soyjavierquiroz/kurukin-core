(function (wp) {
  const { useState, useEffect, useRef, createElement, Fragment } = wp.element;

  // =========================================================================
  // UI ATOMS
  // =========================================================================

  const Card = ({ children, className = "", title, subtitle, headerAction }) =>
    createElement(
      "div",
      {
        className: `bg-surface-800 border border-surface-700 rounded-card p-6 shadow-lg relative overflow-hidden ${className}`,
      },
      (title || headerAction || subtitle) &&
        createElement(
          "div",
          { className: "flex items-start justify-between gap-4 mb-6 border-b border-surface-700/50 pb-4" },
          createElement(
            "div",
            null,
            title &&
              createElement("h3", { className: "text-white font-bold text-sm tracking-wide uppercase" }, title),
            subtitle &&
              createElement("p", { className: "text-surface-500 text-xs mt-2 leading-relaxed max-w-2xl" }, subtitle)
          ),
          headerAction
        ),
      children
    );

  const Button = ({
    children,
    variant = "primary",
    onClick,
    isLoading,
    disabled,
    className = "",
    icon,
    type = "button",
  }) => {
    const base =
      "relative px-4 py-2.5 rounded-input font-medium text-sm transition-all duration-200 inline-flex items-center justify-center gap-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed";

    const variants = {
      primary:
        "bg-primary-600 hover:bg-primary-500 text-white shadow-[0_0_15px_-3px_rgba(99,102,241,0.4)] hover:shadow-[0_0_20px_-3px_rgba(99,102,241,0.6)] border border-primary-500",
      secondary: "bg-surface-700 hover:bg-surface-600 text-white border border-surface-600",
      danger: "bg-red-500/10 hover:bg-red-500/20 text-red-300 border border-red-500/20",
      ghost: "text-surface-300 hover:text-white hover:bg-surface-800 border border-transparent",
    };

    return createElement(
      "button",
      { type, onClick, disabled: isLoading || disabled, className: `${base} ${variants[variant]} ${className}` },
      isLoading
        ? createElement("div", { className: "w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" })
        : icon,
      children
    );
  };

  const HelpText = ({ children }) =>
    createElement("p", { className: "text-[11px] text-surface-500 mt-2 leading-relaxed" }, children);

  const Input = ({ label, type = "text", placeholder, value, onChange, icon, disabled, help }) =>
    createElement(
      "div",
      { className: "mb-6" },
      label &&
        createElement("label", { className: "block text-xs font-semibold text-surface-300 tracking-wide mb-2" }, label),
      createElement(
        "div",
        { className: "relative group" },
        createElement("input", {
          type,
          value,
          disabled,
          onChange: (e) => onChange(e.target.value),
          placeholder,
          className:
            "w-full bg-surface-900 border border-surface-700 text-white text-sm rounded-input focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 block p-3 placeholder-surface-600 outline-none transition-all font-sans shadow-inner disabled:opacity-60 pr-28",
        }),
        icon && createElement("div", { className: "absolute inset-y-0 right-0 pr-2 flex items-center z-10" }, icon)
      ),
      help && createElement(HelpText, null, help)
    );

  const TextArea = ({ label, rows = 3, placeholder, value, onChange, disabled, help }) =>
    createElement(
      "div",
      { className: "mb-6" },
      label &&
        createElement("label", { className: "block text-xs font-semibold text-surface-300 tracking-wide mb-2" }, label),
      createElement("textarea", {
        rows,
        value,
        disabled,
        onChange: (e) => onChange(e.target.value),
        placeholder,
        className:
          "w-full bg-surface-900 border border-surface-700 text-white text-sm rounded-input focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 block p-3 placeholder-surface-600 outline-none transition-all font-sans resize-none shadow-inner disabled:opacity-60",
      }),
      help && createElement(HelpText, null, help)
    );

  const Toggle = ({ label, checked, onChange, description }) =>
    createElement(
      "div",
      {
        className:
          "flex items-center justify-between p-4 bg-surface-900/50 border border-surface-700 rounded-card cursor-pointer group hover:border-surface-600 transition-colors",
        onClick: () => onChange(!checked),
      },
      createElement(
        "div",
        null,
        createElement("div", { className: "text-sm font-medium text-white group-hover:text-primary-400 transition-colors" }, label),
        description && createElement("div", { className: "text-xs text-surface-500 mt-1 leading-relaxed" }, description)
      ),
      createElement(
        "div",
        {
          className: `relative w-11 h-6 rounded-full transition-colors duration-200 ease-in-out ${
            checked ? "bg-primary-600" : "bg-surface-700"
          }`,
        },
        createElement("div", {
          className: `absolute left-[2px] top-[2px] bg-white w-5 h-5 rounded-full transition-transform duration-200 shadow-md ${
            checked ? "translate-x-5" : ""
          }`,
        })
      )
    );

  const Badge = ({ status = "offline" }) => {
    const config = {
      online: { bg: "bg-emerald-500/10", text: "text-emerald-300", border: "border-emerald-500/20", label: "ONLINE", dot: "bg-emerald-500" },
      offline: { bg: "bg-surface-700/50", text: "text-surface-300", border: "border-surface-600", label: "OFFLINE", dot: "bg-surface-500" },
      error: { bg: "bg-red-500/10", text: "text-red-300", border: "border-red-500/20", label: "ERROR", dot: "bg-red-500" },
      sync: { bg: "bg-amber-500/10", text: "text-amber-200", border: "border-amber-500/20", label: "SYNC", dot: "bg-amber-500" },
    };
    const s = config[status] || config.offline;
    return createElement(
      "div",
      { className: `inline-flex items-center gap-2 px-3 py-1 rounded-full border ${s.bg} ${s.border}` },
      createElement("span", {
        className: `w-1.5 h-1.5 rounded-full ${s.dot} ${status === "online" || status === "sync" ? "animate-pulse" : ""}`,
      }),
      createElement("span", { className: `text-[10px] font-bold ${s.text} tracking-wider` }, s.label)
    );
  };

  const StatBox = ({ label, value, sub, statusTone = null }) => {
    const subCls =
      statusTone === "good" ? "text-emerald-400" : statusTone === "bad" ? "text-red-300" : "text-surface-500";

    return createElement(
      "div",
      { className: "bg-surface-900 rounded-input p-4 border border-surface-700 text-center" },
      createElement("div", { className: "text-[10px] font-bold text-surface-500 uppercase tracking-widest mb-1" }, label),
      createElement("div", { className: "text-lg font-mono font-medium text-white" }, value),
      sub && createElement("div", { className: `text-[10px] font-medium mt-1 ${subCls}` }, sub)
    );
  };

  const Toast = ({ toast, onClose }) => {
    if (!toast) return null;
    const variants = {
      success: "bg-emerald-500/10 border-emerald-500/20 text-emerald-200",
      error: "bg-red-500/10 border-red-500/20 text-red-200",
      info: "bg-surface-800 border-surface-700 text-surface-200",
      warning: "bg-amber-500/10 border-amber-500/20 text-amber-200",
    };
    const cls = variants[toast.type] || variants.info;

    return createElement(
      "div",
      { className: "fixed bottom-6 right-6 z-[9999] max-w-md w-[calc(100%-3rem)] md:w-auto animate-in slide-in-from-bottom-2" },
      createElement(
        "div",
        { className: `border rounded-card p-4 shadow-xl ${cls}` },
        createElement(
          "div",
          { className: "flex items-start justify-between gap-4" },
          createElement(
            "div",
            null,
            toast.title && createElement("div", { className: "text-xs font-bold uppercase tracking-wider mb-1 opacity-90" }, toast.title),
            createElement("div", { className: "text-sm font-mono leading-relaxed" }, toast.message || "")
          ),
          createElement("button", { onClick: onClose, className: "text-white/60 hover:text-white text-lg leading-none" }, "×")
        )
      )
    );
  };

  const ValidationBadge = ({ state }) => {
    const status = state?.status || "idle";
    const map = {
      idle: { label: "NO VALIDADA", cls: "bg-surface-700/50 border-surface-600 text-surface-200" },
      validating: { label: "VALIDANDO...", cls: "bg-amber-500/10 border-amber-500/20 text-amber-200" },
      valid: { label: "VÁLIDA", cls: "bg-emerald-500/10 border-emerald-500/20 text-emerald-200" },
      invalid: { label: "INVÁLIDA", cls: "bg-red-500/10 border-red-500/20 text-red-200" },
    };
    const cfg = map[status] || map.idle;

    return createElement(
      "div",
      { className: `inline-flex items-center gap-2 px-3 py-1 rounded-full border ${cfg.cls}` },
      createElement("span", { className: "text-[10px] font-bold tracking-wider" }, cfg.label),
      state?.ts ? createElement("span", { className: "text-[10px] opacity-70" }, new Date(state.ts * 1000).toLocaleTimeString()) : null
    );
  };

  // =========================================================================
  // API (HARDENED: JSON ONLY + sanitize errors)
  // =========================================================================

  const stripHtml = (s) => String(s || "").replace(/<[^>]*>/g, "");
  const safeShort = (s, n = 180) => stripHtml(s).trim().slice(0, n);

  const apiFetch = async (path, method = "GET", body = null) => {
    const opts = { method, headers: { "X-WP-Nonce": kurukinSettings.nonce } };
    if (body) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }

    const url = kurukinSettings.root + "kurukin/v1/" + path;

    let res;
    let text = "";
    try {
      res = await fetch(url, opts);
      text = await res.text();
    } catch (networkErr) {
      throw new Error(`Red no disponible: ${networkErr?.message || "Network error"}`);
    }

    const ct = (res.headers.get("content-type") || "").toLowerCase();

    // ✅ NEVER allow HTML to propagate to UI
    if (!ct.includes("application/json")) {
      throw new Error("Respuesta inválida del servidor (NO JSON). Revisa logs del backend.");
    }

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (e) {
      throw new Error("Respuesta inválida del servidor (JSON corrupto).");
    }

    if (!res.ok) {
      const msg = json?.message || json?.data?.message || `Error HTTP (${res.status || "unknown"})`;
      throw new Error(safeShort(msg));
    }

    if (json && json.state === "error") {
      throw new Error(safeShort(json.message || "Error interno del Bot"));
    }

    return json;
  };

  const hydrateValidationFromSettings = (data) => {
    const raw = data?.validation || {};
    const mapState = (v) => {
      if (!v) return { status: "idle", ts: null, message: "" };
      if (v.status === "valid") return { status: "valid", ts: v.ts || null, message: "" };
      if (v.status === "expired") return { status: "invalid", ts: v.ts || null, message: "Expirada. Revalida." };
      return { status: "idle", ts: null, message: "" };
    };
    return { elevenlabs: mapState(raw?.elevenlabs) };
  };

  const mapStatusToBadge = (state) => {
    if (state === "open") return "online";
    if (state === "close") return "offline";
    if (state === "network_error") return "error";
    if (state === "error") return "error";
    if (state === "sync") return "sync";
    return "offline";
  };

  // =========================================================================
  // VIEWS
  // =========================================================================

  const DashboardView = ({ status, qr, loading, doReset, errorMsg, reloadQr, wallet, refreshingWallet, onRefreshWallet }) => {
    const badgeStatus = mapStatusToBadge(status);

    const balance = typeof wallet?.credits_balance === "number" ? wallet.credits_balance : 0;
    const canProcess = !!wallet?.can_process;
    const minReq = typeof wallet?.min_required === "number" ? wallet.min_required : 1;

    const walletSub = canProcess ? "ACTIVO" : "SUSPENDIDO";
    const walletTone = canProcess ? "good" : "bad";

    return createElement(
      Fragment,
      null,
      errorMsg &&
        createElement(
          "div",
          { className: "mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-lg flex items-start justify-between gap-4 animate-in slide-in-from-top-2" },
          createElement(
            "div",
            { className: "flex items-start gap-3" },
            createElement("span", { className: "text-xl mt-0.5" }, "⚠️"),
            createElement(
              "div",
              null,
              createElement("h4", { className: "text-red-300 font-bold text-xs uppercase tracking-wider" }, "Alerta de Sistema"),
              createElement("p", { className: "text-red-200 text-sm font-mono mt-1 leading-relaxed" }, errorMsg)
            )
          ),
          createElement(Button, { variant: "danger", onClick: reloadQr, className: "text-xs py-2 px-3 shrink-0" }, "Reintentar")
        ),

      createElement(
        "div",
        { className: "grid grid-cols-1 lg:grid-cols-12 gap-6" },

        // LEFT: CORE
        createElement(
          Card,
          {
            className: "lg:col-span-8",
            title: "Instancia Core",
            subtitle: "Estado y vinculación de WhatsApp. Si está OFFLINE, genera un QR y escanéalo desde WhatsApp para enlazar.",
            headerAction: createElement(Badge, { status: badgeStatus }),
          },
          createElement(
            "div",
            { className: "flex items-center gap-4 mb-6" },
            createElement(
              "div",
              { className: "w-12 h-12 rounded-xl bg-surface-900 border border-surface-700 flex items-center justify-center text-2xl shrink-0" },
              "🤖"
            ),
            createElement(
              "div",
              null,
              createElement("div", { className: "text-white font-bold text-lg leading-tight" }, "Kurukin Core"),
              createElement("div", { className: "text-surface-500 text-xs font-mono mt-1" }, `Tenant: ${kurukinSettings.user}`)
            )
          ),

          // ✅ 4 columns responsive (1 col mobile, 2 col sm, 4 col lg)
          createElement(
            "div",
            { className: "grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" },
            createElement(StatBox, { label: "Estado", value: (status || "unknown").toUpperCase() }),
            createElement(StatBox, { label: "Saldo", value: balance.toFixed(2), sub: walletSub, statusTone: walletTone }),
            createElement(StatBox, { label: "Min", value: minReq.toFixed(2) }),
            createElement(StatBox, { label: "Webhook", value: errorMsg ? "ERROR" : "OK", sub: errorMsg ? "Revisar red" : "Stable", statusTone: errorMsg ? "bad" : "good" })
          ),

          createElement(
            "div",
            { className: "mt-5 flex flex-col sm:flex-row sm:items-center gap-3" },
            createElement(
              "div",
              { className: "text-xs text-surface-500 leading-relaxed" },
              !canProcess
                ? "Sin crédito suficiente: el bot debe pausar."
                : "Crédito suficiente: el bot puede procesar."
            ),
            createElement(
              Button,
              {
                variant: "primary",
                onClick: onRefreshWallet,
                disabled: refreshingWallet,
                isLoading: refreshingWallet,
                icon: "💳",
                className: "sm:ml-auto w-full sm:w-auto px-6 py-3", // ✅ NOT full width on desktop
              },
              "Actualizar saldo"
            )
          )
        ),

        // RIGHT: QR
        createElement(
          Card,
          {
            className: "lg:col-span-4 text-center bg-surface-900/50 flex flex-col justify-between",
            title: "Vincular WhatsApp (QR)",
            subtitle: "WhatsApp → Dispositivos vinculados → Vincular un dispositivo → escanea el QR.",
          },
          status === "open"
            ? createElement(
                "div",
                { className: "flex flex-col items-center justify-center py-6" },
                createElement(
                  "div",
                  {
                    className:
                      "w-16 h-16 bg-emerald-500/10 rounded-2xl flex items-center justify-center mb-4 border border-emerald-500/20 shadow-[0_0_30px_-5px_rgba(16,185,129,0.3)]",
                  },
                  createElement("span", { className: "text-2xl animate-pulse" }, "⚡")
                ),
                createElement("h3", { className: "text-white font-semibold text-lg" }, "Sistema Activo"),
                createElement("p", { className: "text-surface-500 text-xs mt-2 max-w-xs leading-relaxed" }, "Tu WhatsApp está enlazado. Si deseas desvincular o regenerar, puedes detener la instancia."),
                createElement(Button, { variant: "danger", onClick: doReset, className: "mt-5 w-full sm:w-auto px-6 py-3" }, "Detener Instancia")
              )
            : createElement(
                Fragment,
                null,
                createElement(
                  "div",
                  { className: "relative my-2 w-full flex justify-center" },
                  loading && !qr
                    ? createElement(
                        "div",
                        { className: "w-full aspect-square flex flex-col items-center justify-center bg-surface-900 rounded-lg border border-surface-700 shadow-inner" },
                        createElement("span", { className: "animate-spin text-3xl mb-4 text-primary-500" }, "⏳"),
                        createElement("span", { className: "text-surface-400 text-xs font-bold tracking-widest animate-pulse" }, "SINCRONIZANDO...")
                      )
                    : qr
                    ? createElement("img", {
                        src: qr,
                        // ✅ full width on mobile, fixed width on desktop
                        className: "w-full sm:w-auto sm:max-w-[20rem] aspect-square rounded-lg border-4 border-white/90 shadow-2xl object-contain",
                      })
                    : createElement(
                        "div",
                        { className: "w-full aspect-square bg-surface-900 rounded-lg flex items-center justify-center border border-surface-700" },
                        createElement("span", { className: "text-surface-500 text-xs" }, "No QR Data")
                      )
                ),
                createElement(
                  "div",
                  { className: "flex flex-col items-center gap-3 w-full mt-4" },
                  createElement(
                    Button,
                    {
                      variant: "primary",
                      onClick: reloadQr,
                      disabled: loading,
                      icon: "🔄",
                      className: "w-full sm:w-auto px-8 py-3 shadow-lg shadow-primary-500/20", // ✅ not full width desktop
                    },
                    "Actualizar Código QR"
                  ),
                  createElement("p", { className: "text-surface-500 text-[10px] font-bold uppercase tracking-widest" }, "Escanea para Vincular")
                )
              )
        )
      )
    );
  };

  const WalletView = ({ wallet, refreshing, onRefresh }) => {
    const balance = typeof wallet?.credits_balance === "number" ? wallet.credits_balance : 0;
    const canProcess = !!wallet?.can_process;
    const minReq = typeof wallet?.min_required === "number" ? wallet.min_required : 1;

    return createElement(
      "div",
      { className: "max-w-4xl mx-auto space-y-8" },
      createElement(
        Card,
        {
          title: "Mi Billetera",
          subtitle: "Tu saldo determina si el sistema puede procesar mensajes. Si el saldo llega a 0, el servicio queda suspendido.",
          headerAction: createElement(Badge, { status: canProcess ? "online" : "offline" }),
        },
        createElement(
          "div",
          { className: "grid grid-cols-1 sm:grid-cols-3 gap-4" },
          createElement(StatBox, { label: "Saldo", value: balance.toFixed(2), sub: canProcess ? "ACTIVO" : "SUSPENDIDO", statusTone: canProcess ? "good" : "bad" }),
          createElement(StatBox, { label: "Min", value: minReq.toFixed(2) }),
          createElement(StatBox, { label: "Estado", value: canProcess ? "OK" : "PAUSADO" })
        ),
        createElement(
          "div",
          { className: "mt-6 flex flex-col sm:flex-row items-start sm:items-center gap-3" },
          createElement(
            "div",
            { className: "text-xs text-surface-500 leading-relaxed" },
            createElement("div", { className: "text-surface-300 font-semibold mb-1" }, "¿Qué significa esto?"),
            createElement("div", null, "• Mensajes de texto y audio se cobran por uso."),
            createElement("div", null, "• Audio (TTS) consume más créditos."),
            createElement("div", null, "• Puedes usar tu propia cuenta de ElevenLabs para no gastar créditos Kurukin en audio.")
          ),
          createElement(
            Button,
            {
              variant: "primary",
              onClick: onRefresh,
              disabled: refreshing,
              isLoading: refreshing,
              className: "sm:ml-auto w-full sm:w-auto px-6 py-3",
              icon: "💳",
            },
            "Actualizar saldo"
          )
        )
      )
    );
  };

  const SettingsView = ({ settings, setSettings, onValidate, validation, dirty, setDirty, validatingProvider }) => {
    if (!settings) return null;

    const update = (s, f, v) => {
      setSettings((p) => ({ ...p, [s]: { ...(p[s] || {}), [f]: v } }));
      if (s === "voice" && f === "eleven_api_key") setDirty((prev) => ({ ...prev, elevenlabs: true }));
    };

    const canValidateKey = (k) => !!k && typeof k === "string" && !k.includes("****") && k.trim().length >= 8;

    const elevenKey = settings?.voice?.eleven_api_key || "";
    const voiceByok = !!settings?.voice?.byok_enabled;
    const audioEnabled = !!settings?.voice?.enabled;

    return createElement(
      "div",
      { className: "max-w-4xl mx-auto space-y-8" },

      createElement(
        Card,
        {
          title: "Cerebro IA",
          subtitle: "Define el comportamiento del asistente. No necesitas configurar llaves: Kurukin gestiona la infraestructura de IA.",
        },
        createElement(TextArea, {
          label: "System Prompt",
          rows: 6,
          value: settings.brain?.system_prompt || "",
          onChange: (v) => update("brain", "system_prompt", v),
          help: "Describe rol, tono y objetivos. Ej: “Eres un asistente de ventas, responde claro, y pide datos para cotizar…”.",
        })
      ),

      createElement(
        Card,
        {
          title: "Voz & Audio",
          subtitle:
            "Activa respuestas en audio (TTS). Por defecto se cobran créditos Kurukin. Si deseas usar tus voces entrenadas, activa BYOK de ElevenLabs.",
          headerAction: createElement(ValidationBadge, { state: validation.elevenlabs }),
        },
        createElement(Toggle, {
          label: "Respuestas de Audio",
          description: "Si está activo, el bot puede responder con audio (text-to-speech).",
          checked: audioEnabled,
          onChange: (v) => update("voice", "enabled", v),
        }),
        createElement("div", { className: "mt-4" }),
        createElement(Toggle, {
          label: "Usar mi cuenta de ElevenLabs (Opcional)",
          description:
            "Actívalo solo si tienes tu propia cuenta con voces entrenadas. Si está apagado, Kurukin usa créditos para generar audio.",
          checked: voiceByok,
          onChange: (v) => update("voice", "byok_enabled", v),
        }),

        !audioEnabled &&
          createElement(
            "div",
            { className: "mt-4 text-xs text-surface-500 leading-relaxed" },
            "Audio está desactivado. Si lo activas, podrás elegir: créditos Kurukin o tu cuenta ElevenLabs."
          ),

        audioEnabled && !voiceByok &&
          createElement(
            "div",
            { className: "mt-4 p-4 bg-surface-900/40 border border-surface-700 rounded-card text-xs text-surface-400 leading-relaxed" },
            createElement("div", { className: "text-surface-200 font-semibold mb-1" }, "Modo Audio por Créditos (Kurukin)"),
            createElement("div", null, "No necesitas configurar nada. Kurukin generará el audio y se descontará de tu saldo.")
          ),

        audioEnabled && voiceByok &&
          createElement(
            Fragment,
            null,
            createElement("div", { className: "mt-6" }),
            createElement(Input, {
              label: "ElevenLabs Key",
              type: "password",
              value: elevenKey,
              onChange: (v) => update("voice", "eleven_api_key", v),
              icon: createElement(
                Button,
                {
                  variant: "ghost",
                  onClick: () => onValidate("elevenlabs", elevenKey),
                  disabled: !canValidateKey(elevenKey) || validatingProvider === "elevenlabs",
                  isLoading: validatingProvider === "elevenlabs",
                  className: "text-xs py-2 px-3 uppercase",
                },
                "Validar"
              ),
              help: "Pega tu llave de ElevenLabs. Debes VALIDARla antes de guardar si la cambiaste.",
            }),
            dirty.elevenlabs &&
              createElement("div", { className: "text-[11px] text-amber-200 mt-2 font-mono opacity-90" }, "⚠️ Key modificada: debes VALIDAR antes de guardar."),
            createElement(Input, {
              label: "Voice ID",
              value: settings.voice?.voice_id || "",
              onChange: (v) => update("voice", "voice_id", v),
              help: "ID de tu voz en ElevenLabs (voz entrenada o existente).",
            })
          )
      ),

      createElement(
        Card,
        {
          title: "Datos de Negocio",
          subtitle: "Este contexto se usa para que el bot responda con información de tu empresa. Entre más claro, mejor.",
        },
        createElement(TextArea, {
          label: "Perfil",
          rows: 3,
          value: settings.business?.profile || "",
          onChange: (v) => update("business", "profile", v),
          help: "Quién eres, qué haces, propuesta de valor y público objetivo.",
        }),
        createElement(TextArea, {
          label: "Servicios",
          rows: 3,
          value: settings.business?.services || "",
          onChange: (v) => update("business", "services", v),
          help: "Lista breve de servicios/productos y puntos clave (precios si aplica).",
        }),
        createElement(TextArea, {
          label: "Reglas",
          rows: 3,
          value: settings.business?.rules || "",
          onChange: (v) => update("business", "rules", v),
          help: "Políticas, restricciones, estilo de respuesta, cosas que NO debe hacer, etc.",
        })
      )
    );
  };

  // =========================================================================
  // MAIN APP
  // =========================================================================

  const KurukinApp = () => {
    const [view, setView] = useState("dashboard"); // dashboard | settings | wallet
    const [settings, setSettings] = useState(null);

    const [wallet, setWallet] = useState(null);
    const [walletLoading, setWalletLoading] = useState(false);

    const [status, setStatus] = useState("initializing");
    const [errorMsg, setErrorMsg] = useState(null);

    const [qr, setQr] = useState(null);
    const [loadingQr, setLoadingQr] = useState(false);

    const [saving, setSaving] = useState(false);

    const [validation, setValidation] = useState({
      elevenlabs: { status: "idle", ts: null, message: "" },
    });

    const [dirty, setDirty] = useState({
      elevenlabs: false,
    });

    const [validatingProvider, setValidatingProvider] = useState(null);

    const [toast, setToast] = useState(null);
    const toastTimerRef = useRef(null);

    const showToast = (type, title, message, ttlMs = 4500) => {
      if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
      setToast({ type, title, message });
      toastTimerRef.current = setTimeout(() => setToast(null), ttlMs);
    };

    const closeToast = () => {
      if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
      setToast(null);
    };

    const statusRef = useRef(status);
    const viewRef = useRef(view);
    const qrRef = useRef(qr);
    const loadingQrRef = useRef(loadingQr);

    useEffect(() => { statusRef.current = status; }, [status]);
    useEffect(() => { viewRef.current = view; }, [view]);
    useEffect(() => { qrRef.current = qr; }, [qr]);
    useEffect(() => { loadingQrRef.current = loadingQr; }, [loadingQr]);

    const loadWallet = async () => {
      setWalletLoading(true);
      try {
        const w = await apiFetch("wallet");
        setWallet(w);
      } catch (e) {
        showToast("error", "Wallet", e.message || "No se pudo cargar saldo");
      } finally {
        setWalletLoading(false);
      }
    };

    useEffect(() => {
      apiFetch("settings")
        .then((data) => {
          setSettings(data);
          setDirty({ elevenlabs: false });
          setValidation(hydrateValidationFromSettings(data));
        })
        .catch((e) => {
          console.error(e);
          showToast("error", "Error", e.message || "No se pudo cargar configuración");
        });

      const statusInterval = setInterval(() => {
        if (statusRef.current !== "error") checkStatus();
      }, 5000);

      const qrInterval = setInterval(() => {
        if (statusRef.current === "close" && viewRef.current === "dashboard") loadQr(true);
      }, 30000);

      checkStatus();
      loadWallet();

      return () => {
        clearInterval(statusInterval);
        clearInterval(qrInterval);
        if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
      };
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const checkStatus = async () => {
      try {
        const d = await apiFetch("connection/status");
        if (d.state === "error") throw new Error(d.message);

        setStatus(d.state);
        setErrorMsg(d.message || null);

        if (d.state === "close" && !qrRef.current && !loadingQrRef.current) loadQr();
        else if (d.state === "open") setQr(null);
      } catch (e) {
        console.error("Status Error:", e);
        setStatus("error");
        setErrorMsg(e.message);
      }
    };

    const loadQr = async (force = false) => {
      if (loadingQrRef.current) return;
      if (qrRef.current && !force) return;

      setLoadingQr(true);
      try {
        const d = await apiFetch("connection/qr");
        if (d.base64) {
          setQr(d.base64);
          setErrorMsg(null);
        } else {
          setTimeout(() => { if (!qrRef.current) loadQr(); }, 3000);
        }
      } catch (e) {
        console.error("QR Error:", e);
      } finally {
        setLoadingQr(false);
      }
    };

    const doReset = async () => {
      if (!confirm("¿Reiniciar instancia?")) return;
      setQr(null);
      setStatus("initializing");
      setErrorMsg(null);

      try {
        await apiFetch("connection/reset", "POST");
        setTimeout(checkStatus, 1000);
        showToast("info", "Reset", "Reiniciando instancia...");
      } catch (e) {
        setErrorMsg(e.message);
        setStatus("error");
        showToast("error", "Error", e.message);
      }
    };

    const canSave = () => {
      const byokEnabled = !!settings?.voice?.byok_enabled;
      const elevenBlocked = byokEnabled && dirty.elevenlabs && validation.elevenlabs.status !== "valid";
      return !elevenBlocked;
    };

    const save = async () => {
      if (!canSave()) {
        showToast("warning", "Bloqueado", "Debes VALIDAR la llave de ElevenLabs antes de guardar.");
        return;
      }

      setSaving(true);
      try {
        await apiFetch("settings", "POST", settings);
        showToast("success", "Guardado", "Configuración guardada correctamente.");
        setDirty({ elevenlabs: false });
        loadWallet();
      } catch (e) {
        showToast("error", "Error al guardar", e.message);
      } finally {
        setSaving(false);
      }
    };

    const validate = async (provider, key) => {
      if (provider !== "elevenlabs") return;

      if (!key || typeof key !== "string") {
        showToast("warning", "Validación", "Ingresa una llave válida primero.");
        return;
      }
      if (key.includes("****")) {
        showToast("warning", "Validación", "No se puede validar una llave oculta. Pega la llave completa.");
        return;
      }

      setValidatingProvider(provider);
      setValidation((prev) => ({
        ...prev,
        [provider]: { status: "validating", ts: null, message: "" },
      }));

      try {
        const r = await apiFetch("validate-credential", "POST", { provider, key });

        if (r && r.valid) {
          setValidation((prev) => ({
            ...prev,
            [provider]: { status: "valid", ts: Math.floor(Date.now() / 1000), message: r.message || "" },
          }));
          setDirty((prev) => ({ ...prev, elevenlabs: false }));
          showToast("success", "Validación OK", r.message || "Credencial válida.");
        } else {
          setValidation((prev) => ({
            ...prev,
            [provider]: { status: "invalid", ts: Math.floor(Date.now() / 1000), message: r?.message || "Credencial inválida" },
          }));
          showToast("error", "Validación fallida", r?.message || "Credencial inválida");
        }
      } catch (e) {
        setValidation((prev) => ({
          ...prev,
          [provider]: { status: "invalid", ts: Math.floor(Date.now() / 1000), message: e.message || "" },
        }));
        showToast("error", "Validación fallida", e.message || "Error");
      } finally {
        setValidatingProvider(null);
      }
    };

    if (!settings && view === "settings") {
      return createElement(
        "div",
        { className: "flex h-96 items-center justify-center text-surface-500 animate-pulse font-mono tracking-widest text-xs" },
        "CARGANDO KURUKIN CORE..."
      );
    }

    return createElement(
      "div",
      { className: "pb-28" },
      createElement(Toast, { toast, onClose: closeToast }),

      createElement(
        "div",
        { className: "max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8" },

        // top nav
        createElement(
          "div",
          { className: "flex justify-end" },
          createElement(
            "div",
            { className: "inline-flex gap-2 bg-surface-900 p-2 rounded-xl border border-surface-700 shadow-sm" },
            createElement(
              Button,
              {
                variant: view === "dashboard" ? "primary" : "ghost",
                onClick: () => setView("dashboard"),
                className: "text-xs px-4 h-9 rounded-lg",
              },
              "Dashboard"
            ),
            createElement(
              Button,
              {
                variant: view === "settings" ? "primary" : "ghost",
                onClick: () => setView("settings"),
                className: "text-xs px-4 h-9 rounded-lg",
                icon: "⚙️",
              },
              "Configuración"
            ),
            createElement(
              Button,
              {
                variant: view === "wallet" ? "primary" : "ghost",
                onClick: () => setView("wallet"),
                className: "text-xs px-4 h-9 rounded-lg",
                icon: "💳",
              },
              "Wallet"
            )
          )
        ),

        view === "dashboard"
          ? createElement(DashboardView, {
              status,
              qr,
              loading: loadingQr,
              doReset,
              errorMsg,
              reloadQr: () => loadQr(true),

              // ✅ wallet on dashboard
              wallet,
              refreshingWallet: walletLoading,
              onRefreshWallet: loadWallet,
            })
          : view === "wallet"
          ? createElement(WalletView, { wallet, refreshing: walletLoading, onRefresh: loadWallet })
          : createElement(SettingsView, {
              settings,
              setSettings,
              onValidate: validate,
              validation,
              dirty,
              setDirty,
              validatingProvider,
            }),

        // save area only in settings
        view === "settings" &&
          createElement(
            "div",
            { className: "pt-2 flex flex-col sm:flex-row items-start sm:items-center justify-end gap-3" },
            !canSave() &&
              createElement(
                "div",
                { className: "text-[11px] text-amber-200 font-mono opacity-90 sm:mr-auto" },
                "⚠️ Guardar bloqueado: valida la llave de ElevenLabs (si usas tu cuenta)."
              ),
            createElement(
              Button,
              {
                variant: "primary",
                onClick: save,
                isLoading: saving,
                disabled: saving || !canSave(),
                className: "w-full sm:w-auto px-10 py-3 rounded-full text-sm font-bold tracking-wide",
              },
              "Guardar Cambios"
            )
          )
      )
    );
  };

  const root = document.getElementById("kurukin-app-root");
  if (root) wp.element.render(createElement(KurukinApp), root);
})(window.wp);
