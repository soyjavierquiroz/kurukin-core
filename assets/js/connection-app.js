(function(wp) {
    const { useState, useEffect, useCallback, render, createElement } = wp.element;
    
    const ConnectionApp = () => {
        const [status, setStatus] = useState('initializing');
        const [qrCode, setQrCode] = useState(null);
        const [loading, setLoading] = useState(false);
        const [errorMsg, setErrorMsg] = useState('');
        const [hasError, setHasError] = useState(false);

        const apiFetch = async (path, options = {}) => {
            const res = await fetch(kurukinSettings.root + 'kurukin/v1/connection/' + path, {
                ...options,
                headers: { 'X-WP-Nonce': kurukinSettings.nonce, 'Content-Type': 'application/json' }
            });
            const text = await res.text();
            try { return JSON.parse(text); } catch(e) { throw new Error("Server Error: " + text.substring(0, 100)); }
        };

        const checkStatus = useCallback(async () => {
            if (hasError) return;
            try {
                const data = await apiFetch('status');
                
                if (data.state === 'error' || data.state === 'network_error') {
                     throw new Error(data.message || "Error Evolution API");
                }

                setStatus(data.state);

                if (data.state === 'open') {
                    setQrCode(null);
                    setErrorMsg('');
                } else if (data.state === 'close' && !qrCode && !loading) {
                    // Si está cerrado y no tengo QR, pedirlo
                    fetchQr();
                } else if (data.state === 'connecting') {
                    // Si dice "connecting", a veces Evolution necesita un empujón para mostrar el QR
                    if(!qrCode && !loading) fetchQr();
                }

            } catch (err) {
                console.error(err);
                // No bloqueamos fatalmente por status poll, solo log
            }
        }, [qrCode, loading, hasError]);

        useEffect(() => {
            checkStatus();
            const interval = setInterval(() => {
                if (status !== 'open' && !hasError) checkStatus();
            }, 5000);
            return () => clearInterval(interval);
        }, [status, checkStatus, hasError]);

        const fetchQr = async () => {
            setLoading(true);
            try {
                const data = await apiFetch('qr');
                if (data.code && data.code >= 400) throw new Error(data.message || "Error QR");
                
                if (data.base64) {
                    setQrCode(data.base64);
                } else {
                    // Si devolvió null (timeout), reintentamos en 3 segundos
                    setTimeout(() => { if(!qrCode) fetchQr(); }, 3000);
                }
            } catch (e) {
                // Error fatal en fetch QR
                setErrorMsg("Fallo QR: " + e.message);
                setStatus('error');
            } finally {
                setLoading(false);
            }
        };

        const handleReset = async () => {
            if(!confirm("¿Reiniciar?")) return;
            setLoading(true); setQrCode(null); setHasError(false); setErrorMsg('');
            try {
                const res = await apiFetch('reset', { method: 'POST' });
                if (res.base64) { setQrCode(res.base64); setStatus('close'); }
                else { setStatus('initializing'); }
            } catch (e) {
                setErrorMsg(e.message); setStatus('error');
            } finally { setLoading(false); }
        };

        // UI
        return createElement('div', { className: 'k-dashboard' },
            createElement('div', { className: 'k-header' },
                createElement('h2', null, 'Estado del Bot'),
                createElement('span', { className: 'k-badge' }, kurukinSettings.user)
            ),
            createElement('div', { className: 'k-card' },
                
                (status === 'error' || hasError) && createElement('div', { className: 'k-state error' },
                    createElement('h3', {style:{color:'red'}}, '⚠️ Error'),
                    createElement('p', {style:{background:'#ffebeb',padding:'10px'}}, errorMsg),
                    createElement('button', { className: 'k-btn k-btn-secondary', onClick: () => window.location.reload() }, 'Recargar Página')
                ),

                (status === 'initializing' && !hasError) && createElement('div', { className: 'k-state' },
                    createElement('div', { className: 'k-spinner' }), createElement('p', null, 'Conectando...')
                ),

                (status === 'open') && createElement('div', { className: 'k-state success' },
                    createElement('div', { className: 'k-icon' }, '✅'),
                    createElement('h3', null, 'Conectado'),
                    createElement('button', { className: 'k-btn k-btn-text', onClick: handleReset }, 'Desconectar')
                ),

                ((status === 'close' || status === 'connecting') && !hasError) && createElement('div', { className: 'k-state' },
                    createElement('h3', null, 'Escanea el QR'),
                    loading && !qrCode ? 
                        createElement('div', { className: 'k-loading-qr' }, 
                            createElement('div', { className: 'k-spinner-small' }),
                            createElement('span', null, 'Generando QR...')
                        ) :
                        (qrCode ? createElement('img', { src: qrCode, className: 'k-qr-img' }) : createElement('p', null, 'Reintentando...'))
                )
            )
        );
    };

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.getElementById('kurukin-connection-app');
        if(root) render(createElement(ConnectionApp), root);
    });
})(window.wp);