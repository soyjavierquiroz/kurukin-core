# 🧠 Kurukin Core (SaaS Engine)

> **Arquitectura:** User-Centric Multi-Tenancy
> **Frontend:** React (WP Element) + Tailwind CSS (Tokens)
> **Backend:** WordPress REST API + Evolution API v2

## 📖 Descripción del Proyecto

**Kurukin Core** es el motor central del SaaS **Kurukin IA**. Este plugin transforma WordPress en una plataforma de orquestación de IA, actuando como puente entre la gestión de usuarios (MemberPress), la infraestructura de mensajería (Evolution API v2) y la lógica de negocio (n8n).

La versión actual introduce un **Frontend Dashboard** basado en React (`connection-app.js`), permitiendo a los usuarios finales escanear su código QR, gestionar su conexión de WhatsApp y configurar su "Cerebro IA" sin jamás tocar el panel de administración de WordPress.

---

## 🏗️ Arquitectura del Sistema

El sistema opera bajo un modelo híbrido de **Gestión + Conectividad**:

1. **Identity & Access:** WordPress + MemberPress gestionan la autenticación y los planes.
2. **Smart Provisioning:** El sistema "auto-sana". Si un usuario solicita un QR y su instancia no existe en Evolution API, el núcleo la crea, configura los webhooks y conecta en tiempo real.
3. **Frontend App:** Una SPA (Single Page Application) ligera incrustada mediante shortcode.
4. **AI Context Hub:** Centraliza Prompts, Voz (ElevenLabs) y Datos de Negocio para enviarlos a n8n en una sola petición.

```mermaid
graph TD
    User((Usuario Final)) -->|1. Escanea QR| Front[React App]
    Front -->|2. REST API (WP)| WP[Kurukin Core]
    WP -->|3. Auto-Create/Connect| Evo[Evolution API v2]
    Evo -->|4. Webhook Mensaje| N8N[n8n Workflow]
    N8N -->|5. GET /context| WP
    WP -->|6. JSON Context (RAG+Voz)| N8N
    N8N -->|7. Respuesta IA| Evo

```

---

## ⚙️ Requisitos del Sistema

Para garantizar el funcionamiento de la encriptación y la comunicación con APIs externas:

### Servidor & Entorno

* **PHP:** Versión **7.4** o superior (Recomendado 8.1+).
* **WordPress:** Versión **6.2** o superior (Requerido para soporte completo de React/WP-Element).
* **Extensiones PHP:** `cURL` (comunicación API), `OpenSSL` (encriptación de credenciales).

### Infraestructura Externa

* **Evolution API v2:** Desplegado y accesible vía HTTP/HTTPS desde el servidor de WordPress.
* **Redis (Opcional):** Recomendado para caché de objetos si hay alta concurrencia.

---

## 🔌 Integración con Evolution API

Kurukin Core actúa como un "Manager" de Evolution API. No requiere configuración manual por usuario. La integración se define globalmente y el plugin gestiona las instancias individuales.

### Configuración en `wp-config.php`

Define estas constantes en tu archivo de configuración para conectar el núcleo:

```php
// 1. Seguridad Interna (Encriptación de Keys en BD)
define('KURUKIN_ENCRYPTION_KEY', 'tu_string_aleatorio_32_caracteres_minimo');
define('KURUKIN_API_SECRET', 'token_seguro_para_validar_peticiones_de_n8n');

// 2. Conexión a Evolution API (Infraestructura)
define('KURUKIN_EVOLUTION_URL', 'https://api.whatsapp.tuservidor.com'); // Sin slash al final
define('KURUKIN_EVOLUTION_GLOBAL_KEY', 'tu_global_api_key_de_evolution');

```

### Lógica de Mapeo

El sistema mapea automáticamente:

* **Usuario WP:** `javierquiroz`
* **Instancia Evolution:** `javierquiroz` (El `post_name` o `user_login` se usa como ID de instancia).

---

## 📡 Documentación de API (Endpoints & Payloads)

El plugin expone endpoints REST para el Frontend (React) y para el Backend de IA (n8n).

### A. Endpoints Frontend (React App)

Autenticación vía **WordPress Nonce** (`X-WP-Nonce`).

#### 1. Obtener Estado de Conexión

`GET /wp-json/kurukin/v1/connection/status`

**Respuesta JSON:**

```json
{
  "state": "open", // open | close | connecting
  "instance": "javierquiroz",
  "phone": "59177777777", // Si está conectado
  "platform": "whatsapp"
}

```

#### 2. Guardar Configuración (Cerebro/Voz)

`POST /wp-json/kurukin/v1/settings`

**Payload Esperado (Body):**

```json
{
  "brain": {
    "system_prompt": "Eres un asistente experto en ventas...",
    "openai_api_key": "sk-proj-..."
  },
  "voice": {
    "enabled": true,
    "eleven_api_key": "xi-...",
    "voice_id": "JBFqnCBsd6RMkjVDRZzb"
  },
  "business": {
    "profile": "Empresa de Logística...",
    "services": "Rastreo GPS, Envíos...",
    "rules": "No dar precios sin cotización..."
  }
}

```

---

### B. Endpoints Backend (Para n8n)

Autenticación vía Header: `x-kurukin-secret`.

#### 1. Obtener Contexto Completo

`GET /wp-json/kurukin/v1/context?user_id=javierquiroz`

Este endpoint es consumido por n8n antes de procesar un mensaje. Devuelve todo lo necesario para armar el prompt.

**Respuesta JSON:**

```json
{
  "status": "success",
  "router_logic": {
    "plan_status": "active",
    "business_vertical": "logistics",
    "cluster_node": "alpha-01"
  },
  "ai_brain": {
    "provider": "openai",
    "api_key_decrypted": "sk-proj-...", // Desencriptada al vuelo
    "model": "gpt-4o",
    "system_prompt": "Eres un asistente..."
  },
  "voice_config": {
    "provider": "elevenlabs",
    "enabled": true,
    "api_key_decrypted": "xi-...",
    "voice_id": "JBFqnCBsd6RMkjVDRZzb"
  },
  "business_data": {
    "formatted_context": "PERFIL:\nEmpresa de Logística...\n\nSERVICIOS:\nRastreo GPS..."
  }
}

```

---

## 🚀 Características Principales

### 🔌 Conectividad & Frontend

* **Dashboard React:** Interfaz moderna ("Dark Mode" nativo) que utiliza Design Tokens para consistencia visual.
* **Smart QR:** Detección de estados, auto-creación de instancias y regeneración de QR en caso de timeout.
* **Cache Busting:** Sistema inteligente (`filemtime`) que fuerza la recarga de scripts JS en el navegador del cliente cuando se actualiza el plugin.

### 🛡️ Seguridad & Estabilidad

* **Fail Fast Validation:** El frontend valida las API Keys de OpenAI y ElevenLabs contra sus servidores reales antes de permitir guardar.
* **Encriptación AES-256:** Las llaves sensibles nunca se guardan en texto plano en la base de datos `wp_postmeta`.
* **Manejo de Errores:** Controladores blindados para evitar que una falla en Evolution tumbe el sitio WordPress.

---

## 📲 Instalación y Uso

1. Subir la carpeta `kurukin-core` a `/wp-content/plugins/`.
2. Activar el plugin en WordPress.
3. Configurar las constantes en `wp-config.php`.
4. Crear una página en WordPress y añadir el shortcode:
```text
[kurukin_connect]

```



---

**Javier Quiroz** Lead Architect @ Kurukin IA