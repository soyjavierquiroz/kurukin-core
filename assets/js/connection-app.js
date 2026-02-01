(function(wp) {
    const { useState, useEffect, createElement, Fragment } = wp.element;

    // =========================================================================
    // 🧬 UI ATOMS (TOKENS DEL SISTEMA - KURUKIN v2.6 FINAL)
    // =========================================================================

    const Card = ({ children, className = "", title, headerAction }) => 
        createElement('div', { 
            className: `bg-surface-800 border border-surface-700 rounded-card p-6 shadow-lg relative overflow-hidden ${className}` 
        }, 
            (title || headerAction) && createElement('div', { className: "flex justify-between items-center mb-6 border-b border-surface-700/50 pb-4" },
                title && createElement('h3', { className: "text-white font-bold text-sm tracking-wide uppercase" }, title),
                headerAction
            ),
            children
        );

    const Button = ({ children, variant = "primary", onClick, isLoading, disabled, className = "", icon }) => {
        const base = "relative px-4 py-2.5 rounded-input font-medium text-sm transition-all duration-200 flex items-center justify-center gap-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed";
        
        const variants = {
            primary: "bg-primary-600 hover:bg-primary-500 text-white shadow-[0_0_15px_-3px_rgba(99,102,241,0.4)] hover:shadow-[0_0_20px_-3px_rgba(99,102,241,0.6)] border border-primary-500",
            secondary: "bg-surface-700 hover:bg-surface-600 text-white border border-surface-600",
            danger: "bg-red-500/10 hover:bg-red-500/20 text-red-500 border border-red-500/20",
            ghost: "text-surface-400 hover:text-white hover:bg-surface-800"
        };

        return createElement('button', { onClick, disabled: isLoading || disabled, className: `${base} ${variants[variant]} ${className}` },
            isLoading ? createElement('div', { className: "w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" }) : icon,
            children
        );
    };

    const Input = ({ label, type = "text", placeholder, value, onChange, icon }) => 
        createElement('div', { className: "mb-5" },
            label && createElement('label', { className: "block text-xs font-semibold text-surface-400 uppercase tracking-wide mb-2" }, label),
            createElement('div', { className: "relative group" },
                createElement('input', {
                    type, value, onChange: e => onChange(e.target.value), placeholder,
                    className: "w-full bg-surface-900 border border-surface-700 text-white text-sm rounded-input focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 block p-3 placeholder-surface-600 outline-none transition-all font-sans shadow-inner"
                }),
                icon && createElement('div', { className: "absolute inset-y-0 right-0 pr-3 flex items-center z-10" }, icon)
            )
        );

    const TextArea = ({ label, rows = 3, placeholder, value, onChange }) => 
        createElement('div', { className: "mb-5" },
            label && createElement('label', { className: "block text-xs font-semibold text-surface-400 uppercase tracking-wide mb-2" }, label),
            createElement('textarea', {
                rows, value, onChange: e => onChange(e.target.value), placeholder,
                className: "w-full bg-surface-900 border border-surface-700 text-white text-sm rounded-input focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 block p-3 placeholder-surface-600 outline-none transition-all font-sans resize-none shadow-inner"
            })
        );

    const Toggle = ({ label, checked, onChange, description }) => 
        createElement('div', { 
            className: "flex items-center justify-between p-4 bg-surface-900/50 border border-surface-700 rounded-card cursor-pointer group hover:border-surface-600 transition-colors", 
            onClick: () => onChange(!checked) 
        },
            createElement('div', null,
                createElement('div', { className: "text-sm font-medium text-white group-hover:text-primary-400 transition-colors" }, label),
                description && createElement('div', { className: "text-xs text-surface-500 mt-1" }, description)
            ),
            createElement('div', { className: `relative w-11 h-6 rounded-full transition-colors duration-200 ease-in-out ${checked ? 'bg-primary-600' : 'bg-surface-700'}` },
                createElement('div', { className: `absolute left-[2px] top-[2px] bg-white w-5 h-5 rounded-full transition-transform duration-200 shadow-md ${checked ? 'translate-x-5' : ''}` })
            )
        );

    const Badge = ({ status = "offline" }) => {
        const config = {
            online: { bg: "bg-emerald-500/10", text: "text-emerald-400", border: "border-emerald-500/20", label: "ONLINE", dot: "bg-emerald-500" },
            offline: { bg: "bg-surface-700/50", text: "text-surface-400", border: "border-surface-600", label: "OFFLINE", dot: "bg-surface-500" },
            error: { bg: "bg-red-500/10", text: "text-red-400", border: "border-red-500/20", label: "ERROR", dot: "bg-red-500" },
            sync: { bg: "bg-amber-500/10", text: "text-amber-400", border: "border-amber-500/20", label: "SYNC", dot: "bg-amber-500" }
        };
        const s = config[status] || config.offline;
        return createElement('div', { className: `inline-flex items-center gap-2 px-3 py-1 rounded-full border ${s.bg} ${s.border}` },
            createElement('span', { className: `w-1.5 h-1.5 rounded-full ${s.dot} ${status === 'online' || status === 'sync' ? 'animate-pulse' : ''}` }),
            createElement('span', { className: `text-[10px] font-bold ${s.text} tracking-wider` }, s.label)
        );
    };

    const StatBox = ({ label, value, sub }) => 
        createElement('div', { className: "bg-surface-900 rounded-input p-4 border border-surface-700 text-center" },
            createElement('div', { className: "text-[10px] font-bold text-surface-500 uppercase tracking-widest mb-1" }, label),
            createElement('div', { className: "text-lg font-mono font-medium text-white" }, value),
            sub && createElement('div', { className: "text-[10px] text-emerald-500 font-medium mt-1" }, sub)
        );

    // =========================================================================
    // 🧠 APP LOGIC
    // =========================================================================

    const apiFetch = async (path, method = 'GET', body = null) => {
        const opts = { method, headers: { 'X-WP-Nonce': kurukinSettings.nonce, 'Content-Type': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(kurukinSettings.root + 'kurukin/v1/' + path, opts);
        const text = await res.text();
        try { 
            const json = JSON.parse(text);
            if (json.state === 'error') throw new Error(json.message || "Error interno del Bot");
            if (!res.ok) throw new Error(json.message || 'Error HTTP');
            return json;
        } catch(e) { throw new Error(e.message || "Error Fatal"); }
    };

    // --- DASHBOARD VIEW ---
    const DashboardView = ({ status, qr, loading, doReset, errorMsg, reloadQr }) => {
        return createElement(Fragment, null,
            // Error Alert
            errorMsg && createElement('div', { className: "mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-lg flex items-center justify-between animate-in slide-in-from-top-2" },
                createElement('div', { className: "flex items-center gap-3" },
                    createElement('span', { className: "text-xl" }, "⚠️"),
                    createElement('div', null,
                        createElement('h4', { className: "text-red-400 font-bold text-xs uppercase" }, "Alerta de Sistema"),
                        createElement('p', { className: "text-red-200 text-sm font-mono" }, errorMsg)
                    )
                ),
                createElement(Button, { variant: "danger", onClick: reloadQr, className: "text-xs py-1" }, "Reintentar")
            ),

            createElement('div', { className: "grid grid-cols-1 lg:grid-cols-12 gap-6" },
                
                // MAIN CARD
                createElement(Card, { className: "lg:col-span-8 flex flex-col justify-between min-h-[300px]" },
                    createElement('div', { className: "flex items-start justify-between" },
                        createElement('div', { className: "flex items-center gap-4" },
                            createElement('div', { className: "w-12 h-12 rounded-xl bg-surface-900 border border-surface-700 flex items-center justify-center text-2xl" }, "🤖"),
                            createElement('div', null,
                                createElement('h3', { className: "text-white font-bold text-lg" }, "Instancia Core"),
                                createElement('p', { className: "text-surface-500 text-xs font-mono mt-0.5" }, `ID: ${kurukinSettings.user} • v2.1.0`)
                            )
                        ),
                        createElement(Badge, { status: status })
                    ),
                    createElement('div', { className: "mt-8 grid grid-cols-3 gap-4" },
                        createElement(StatBox, { label: "CPU Load", value: "12%" }),
                        createElement(StatBox, { label: "Memory", value: "2.4 GB" }),
                        createElement(StatBox, { label: "Uptime", value: "99.9%", sub: "Stable" })
                    )
                ),

                // QR CARD (Interactive)
                createElement(Card, { className: "lg:col-span-4 flex flex-col items-center justify-center text-center bg-surface-900/50" },
                    status === 'open' ? createElement(Fragment, null,
                        createElement('div', { className: "w-16 h-16 bg-emerald-500/10 rounded-2xl flex items-center justify-center mb-4 border border-emerald-500/20 shadow-[0_0_30px_-5px_rgba(16,185,129,0.3)] shrink-0" },
                            createElement('span', { className: "text-2xl animate-pulse" }, "⚡")
                        ),
                        createElement('h3', { className: "text-white font-medium text-lg mb-2" }, "Sistema Activo"),
                        createElement(Button, { variant: "danger", onClick: doReset, className: "mt-4 text-xs px-4 py-2" }, "Detener Instancia")
                    ) : createElement(Fragment, null,
                        // QR AREA
                        createElement('div', { className: "relative mb-6" },
                            loading && !qr ? 
                                createElement('div', { className: "w-72 h-72 flex flex-col items-center justify-center bg-surface-900 rounded-lg border border-surface-700 shadow-inner" },
                                    createElement('span', { className: "animate-spin text-3xl mb-4 text-primary-500" }, "⏳"),
                                    createElement('span', { className: "text-surface-400 text-xs font-bold tracking-widest animate-pulse" }, "SINCRONIZANDO...")
                                ) : 
                                (qr ? 
                                    createElement('img', { src: qr, className: "w-72 h-72 rounded-lg border-4 border-white shadow-2xl mix-blend-screen" }) : 
                                    createElement('div', { className: "w-72 h-72 bg-surface-900 rounded-lg flex items-center justify-center border border-surface-700" },
                                        createElement('span', { className: "text-surface-500 text-xs" }, "No QR Data")
                                    )
                                )
                        ),
                        // Refresh Button
                        createElement('div', { className: "flex flex-col items-center gap-3 w-full px-4" },
                            createElement(Button, { 
                                variant: "primary", 
                                onClick: reloadQr, 
                                disabled: loading, 
                                icon: "🔄", 
                                className: "w-auto px-8 shadow-lg shadow-primary-500/20" 
                            }, "Actualizar Código QR"),
                            createElement('p', { className: "text-surface-500 text-[10px] font-bold uppercase tracking-widest mt-2" }, "Escanea para Vincular")
                        )
                    )
                )
            )
        );
    };

    // --- SETTINGS VIEW ---
    const SettingsView = ({ settings, setSettings, onValidate }) => {
        if (!settings) return null;
        const update = (s, f, v) => setSettings(p => ({...p, [s]: {...p[s], [f]: v}}));
        
        return createElement('div', { className: "max-w-4xl mx-auto space-y-12 animate-in fade-in" },
            createElement(Card, { title: "Cerebro IA" },
                createElement('div', { className: "grid grid-cols-1 md:grid-cols-2 gap-6" },
                    createElement('div', { className: "md:col-span-2" },
                        createElement(TextArea, { label: "System Prompt", rows: 5, value: settings.brain?.system_prompt || '', onChange: v => update('brain', 'system_prompt', v) })
                    ),
                    createElement('div', { className: "md:col-span-2" },
                        createElement(Input, { 
                            label: "API Key (OpenAI)", type: "password", value: settings.brain?.openai_api_key || '', onChange: v => update('brain', 'openai_api_key', v),
                            icon: createElement('button', { onClick: () => onValidate('openai', settings.brain.openai_api_key), className: "text-primary-500 text-xs font-bold hover:text-white mr-2 transition-colors uppercase" }, "VALIDAR")
                        })
                    )
                )
            ),
            createElement('div', { className: "grid grid-cols-1 md:grid-cols-2 gap-6" },
                createElement(Card, { title: "Voz & Audio" },
                    createElement(Toggle, { 
                        label: "Respuestas de Audio", description: "TTS vía ElevenLabs",
                        checked: settings.voice?.enabled || false, onChange: v => update('voice', 'enabled', v) 
                    }),
                    createElement('div', { className: "mt-6 space-y-4" },
                        createElement(Input, { 
                            label: "ElevenLabs Key", type: "password", value: settings.voice?.eleven_api_key || '', onChange: v => update('voice', 'eleven_api_key', v),
                            icon: createElement('button', { onClick: () => onValidate('elevenlabs', settings.voice.eleven_api_key), className: "text-primary-500 text-xs font-bold hover:text-white mr-2" }, "VALIDAR")
                        }),
                        createElement(Input, { label: "Voice ID", value: settings.voice?.voice_id || '', onChange: v => update('voice', 'voice_id', v) })
                    )
                ),
                createElement(Card, { title: "Datos de Negocio" },
                    createElement(TextArea, { label: "Perfil", rows: 2, value: settings.business?.profile || '', onChange: v => update('business', 'profile', v) }),
                    createElement(TextArea, { label: "Servicios", rows: 2, value: settings.business?.services || '', onChange: v => update('business', 'services', v) }),
                    createElement(TextArea, { label: "Reglas", rows: 2, value: settings.business?.rules || '', onChange: v => update('business', 'rules', v) })
                )
            )
        );
    };

    // --- MAIN APP ---
    const KurukinApp = () => {
        const [view, setView] = useState('dashboard');
        const [settings, setSettings] = useState(null);
        const [status, setStatus] = useState('initializing');
        const [errorMsg, setErrorMsg] = useState(null);
        const [qr, setQr] = useState(null);
        const [loadingQr, setLoadingQr] = useState(false);
        const [saving, setSaving] = useState(false);

        // Init Data & Polling
        useEffect(() => { 
            apiFetch('settings').then(setSettings).catch(console.error);
            const i = setInterval(() => { if (status !== 'error') checkStatus(); }, 5000);
            checkStatus(); 
            const qrInterval = setInterval(() => { 
                if (status === 'close' && view === 'dashboard') loadQr(true); 
            }, 30000);
            return () => { clearInterval(i); clearInterval(qrInterval); };
        }, [status, view]);

        const checkStatus = async () => {
            try {
                const d = await apiFetch('connection/status');
                if (d.state === 'error') throw new Error(d.message);
                setStatus(d.state); setErrorMsg(null);
                if (d.state === 'close' && !qr && !loadingQr) loadQr();
                else if (d.state === 'open') setQr(null);
            } catch(e) {
                console.error("Status Error:", e);
                setStatus('error'); setErrorMsg(e.message);
            }
        };

        const loadQr = async (force = false) => {
            if (loadingQr) return;
            if (qr && !force) return; 
            setLoadingQr(true);
            try {
                const d = await apiFetch('connection/qr');
                if (d.base64) { setQr(d.base64); setErrorMsg(null); } 
                else { setTimeout(() => { if(!qr) loadQr(); }, 3000); }
            } catch(e) { console.error("QR Error:", e); } 
            finally { setLoadingQr(false); }
        };

        const doReset = async () => {
            if(!confirm("¿Reiniciar instancia?")) return;
            setQr(null); setStatus('initializing'); setErrorMsg(null);
            try { await apiFetch('connection/reset', 'POST'); setTimeout(checkStatus, 1000); } 
            catch(e) { setErrorMsg(e.message); setStatus('error'); }
        };

        const save = async () => {
            setSaving(true);
            try { await apiFetch('settings', 'POST', settings); alert('Guardado correctamente'); }
            catch(e) { alert("Error: " + e.message); } finally { setSaving(false); }
        };

        const validate = async (p, k) => {
            try { const r = await apiFetch('settings/validate-credential', 'POST', { provider: p, key: k }); alert(r.message); }
            catch(e) { alert("Error: " + e.message); }
        };

        if(!settings && view === 'settings') return createElement('div', { className: "flex h-96 items-center justify-center text-surface-500 animate-pulse font-mono tracking-widest text-xs" }, "CARGANDO KURUKIN CORE...");

        // FIX: Aumentamos el padding inferior global a 'pb-40' para asegurar scroll en móviles
        return createElement('div', { className: "space-y-8 animate-in fade-in duration-500 pb-40" },
            // Top Nav
            createElement('div', { className: "flex justify-end gap-2 mb-6 bg-surface-900 p-1 rounded-lg w-fit ml-auto border border-surface-700 shadow-sm" },
                createElement(Button, { variant: view === 'dashboard' ? 'primary' : 'ghost', onClick: () => setView('dashboard'), className: "text-xs px-3 h-8" }, "Dashboard"),
                createElement(Button, { variant: view === 'settings' ? 'primary' : 'ghost', onClick: () => setView('settings'), className: "text-xs px-3 h-8", icon: "⚙️" }, "Configuración")
            ),

            // Views
            view === 'dashboard' 
                ? createElement(DashboardView, { status, qr, loading: loadingQr, doReset, errorMsg, reloadQr: () => loadQr(true) })
                : createElement(SettingsView, { settings, setSettings, onValidate: validate }),

            // Save Button Section (Estático, Responsive y con Espaciado)
            view === 'settings' && createElement('div', { className: "mt-12 pt-8 border-t border-surface-700 flex flex-col md:flex-row justify-end" },
                createElement(Button, { 
                    variant: "primary", 
                    onClick: save, 
                    isLoading: saving, 
                    className: "shadow-2xl px-10 py-3 rounded-full text-sm font-bold tracking-wide w-full md:w-auto" // w-full en móvil, w-auto en PC
                }, "Guardar Cambios")
            )
        );
    };

    const root = document.getElementById('kurukin-app-root');
    if (root) wp.element.render(createElement(KurukinApp), root);

})(window.wp);