# 🧠 Kurukin Core (SaaS Engine)

> **Versión:** 1.8.0
> **Estado:** 🟢 Producción / Estable
> **Arquitectura:** User-Centric Multi-Tenancy
> **Frontend:** React (WP Element)

## 📖 Descripción del Proyecto

**Kurukin Core** es el motor central del SaaS **Kurukin IA**. Este plugin transforma WordPress en una plataforma de orquestación de IA, actuando como puente entre la gestión de usuarios (MemberPress), la infraestructura de mensajería (Evolution API v2) y la lógica de negocio (n8n).

A diferencia de las versiones anteriores, la v1.8.0 introduce un **Frontend Dashboard** basado en React, permitiendo a los usuarios finales escanear su código QR y gestionar su conexión de WhatsApp sin jamás tocar el panel de administración de WordPress.

---

## 🏗️ Arquitectura del Sistema

El sistema opera bajo un modelo híbrido de **Gestión + Conectividad**:

1. **Identity & Access:** WordPress + MemberPress gestionan quién puede tener un bot.
2. **Smart Provisioning:** El sistema "auto-sana". Si un usuario pide un QR y su instancia no existe en Evolution API, el núcleo la crea, configura y conecta en tiempo real.
3. **Frontend App:** Una SPA (Single Page Application) ligera incrustada mediante shortcode para la vinculación de WhatsApp.
4. **AI Context Hub:** Centraliza Prompts, Voz (ElevenLabs) y Datos de Negocio (RAG Lite) para enviarlos a n8n en una sola petición.

```mermaid
graph TD
    User((Usuario Final)) -->|1. Escanea QR| Front[React App [kurukin_connect]]
    Front -->|2. REST API| WP[Kurukin Core]
    WP -->|3. Auto-Create/Connect| Evo[Evolution API v2]
    Evo -->|4. Webhook Mensaje| N8N[n8n Workflow]
    N8N -->|5. GET /config| WP
    WP -->|6. JSON Context (RAG+Voz)| N8N
    N8N -->|7. Respuesta IA| Evo

```

---

## 🚀 Características Principales

### 🔌 Conectividad & Frontend

* **Dashboard React (Shortcode):** Interfaz moderna tipo "Stripe" para conectar WhatsApp. Maneja estados de carga, errores de red y reintentos automáticos.
* Uso: `[kurukin_connect]`


* **Smart QR Generation:** El sistema detecta si la instancia existe. Si no, la crea, configura los webhooks y genera el QR en un solo flujo transparente para el usuario.
* **Zombie Killer:** Lógica de "Reset" que elimina instancias corruptas, crea una nueva y genera un nuevo QR con un solo clic.

### 🧠 Inteligencia & Contexto

* **RAG Lite (Contexto de Negocio):** Campos estructurados para definir *Perfil de Empresa*, *Servicios* y *Reglas*. Estos se inyectan dinámicamente en el prompt del sistema.
* **Módulo de Voz (ElevenLabs):** Configuración nativa para TTS (Text-to-Speech), incluyendo validación de API Key y selectores de Voice ID.
* **Sharding Ready:** Campos de arquitectura (`cluster_node`, `business_vertical`) preparados para enrutamiento de tráfico en entornos de múltiples servidores.

### 🛡️ Seguridad & Estabilidad (DevOps)

* **Fail Fast Validation:** Botones AJAX en el admin para probar credenciales (OpenAI/ElevenLabs) antes de guardar. Evita errores en tiempo de ejecución.
* **Encriptación AES-256:** Todas las API Keys se almacenan encriptadas en la base de datos.
* **Error Handling:** Controladores API blindados con `try/catch` para evitar errores fatales (Error 500) y logs detallados en Docker.

---

## ⚙️ Instalación y Configuración

### 1. Requisitos del Servidor

* PHP 8.0+.
* WordPress 6.0+.
* **Evolution API v2** desplegado y accesible internamente.

### 2. Constantes en `wp-config.php`

```php
// Seguridad
define('KURUKIN_ENCRYPTION_KEY', 'tu_clave_super_secreta_32_chars');
define('KURUKIN_API_SECRET', 'token_compartido_seguro_n8n');

// Configuración Evolution (Opcional, tiene fallbacks internos)
define('KURUKIN_EVOLUTION_GLOBAL_KEY', 'cdfedf0ae18a2b08cdd180823fad884d');

```

---

## 📲 Uso del Frontend (Cliente Final)

Para mostrar el panel de conexión al usuario, crea una página en WordPress y pega el siguiente shortcode:

```text
[kurukin_connect]

```

*Nota: El usuario debe haber iniciado sesión. Si no, verá un mensaje de advertencia.*

---

## 🔌 Documentación de API (Para n8n)

**Endpoint:** `GET /wp-json/kurukin/v1/config`

**Auth:** Header `x-kurukin-secret`

### Respuesta JSON (Modelo v1.8)

El payload ahora incluye configuración de enrutamiento, cerebro IA, voz y datos de negocio.

```json
{
  "status": "success",
  "router_logic": {
    "version": "1.3",
    "plan_status": "active",
    "business_vertical": "real_estate",
    "cluster_node": "alpha-01"
  },
  "ai_brain": {
    "provider": "openai",
    "api_key": "sk-proj-...",
    "model": "gpt-4o",
    "system_prompt": "Eres un asistente..."
  },
  "voice_config": {
    "provider": "elevenlabs",
    "enabled": true,
    "api_key": "xi-...",
    "voice_id": "JBFqnCBsd6RMkjVDRZzb",
    "model_id": "eleven_multilingual_v2"
  },
  "business_data": [
    {
      "category": "COMPANY_PROFILE",
      "content": "Somos una inmobiliaria líder..."
    },
    {
      "category": "SERVICES_LIST",
      "content": "- Venta de casas\n- Alquileres"
    }
  ]
}

```

---

## 🛠️ Estructura del Proyecto

```text
kurukin-core/
├── kurukin-core.php                 # Loader & Constantes Globales
├── assets/
│   ├── css/
│   │   └── connection-app.css       # Estilos Dashboard (Stripe-like)
│   └── js/
│       └── connection-app.js        # React App (QR Logic)
├── includes/
│   ├── class-kurukin-fields.php     # Admin UI & Validadores AJAX
│   ├── api/
│   │   ├── class-kurukin-api-controller.php        # Config Endpoint (n8n)
│   │   └── class-kurukin-connection-controller.php # QR/Status Endpoint (React)
│   ├── integrations/
│   │   └── class-kurukin-memberpress.php           # MemberPress Hooks
│   └── services/
│       └── class-kurukin-bridge.php                # Webhooks Salientes

```

---

## 📜 Historial de Versiones (Changelog)

### [1.8.0] - 2026-01-30 (Versión Actual)

* **Feat:** Dashboard Frontend en React (`[kurukin_connect]`) con UX mejorada.
* **Feat:** Lógica "Smart QR" que crea instancias en Evolution API v2 automáticamente si no existen.
* **Feat:** Manejo de reintentos y timeouts en la generación de QR.
* **Fix:** Solución a Fatal Error por carga de constantes.

### [1.7.0] - 2026-01-30

* **Feat:** Módulo "Fail Fast" para validar API Keys en el admin.
* **Feat:** Soporte para Sharding (`cluster_node`) y Verticales de Negocio.
* **Feat:** Integración de configuración para ElevenLabs (Voz).

### [1.3.0] - 2026-01-28

* **Feat:** Integración base con MemberPress y CPT `saas_instance`.

---

**Javier Quiroz** Lead Architect @ Kurukin IA