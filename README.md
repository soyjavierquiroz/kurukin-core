# 🧠 Kurukin Core (SaaS Engine)

![Version](https://img.shields.io/badge/version-2.6.0-blueviolet) ![PHP](https://img.shields.io/badge/php-%3E%3D7.4-blue) ![WP](https://img.shields.io/badge/wordpress-%3E%3D6.2-blue) ![Status](https://img.shields.io/badge/status-production-success)

> **Arquitectura:** User-Centric Multi-Tenancy
> **Frontend:** React (WP Element) + Tailwind CSS (Tokens)
> **Backend:** WordPress REST API + Evolution API v2 (Service Layer)

## 📖 Descripción del Proyecto

**Kurukin Core** es el motor central del SaaS **Kurukin IA**. Este plugin transforma WordPress en una plataforma de orquestación de IA, actuando como puente entre la gestión de usuarios (MemberPress), la infraestructura de mensajería (Evolution API v2) y la lógica de negocio (n8n).

La versión actual (v2.6) introduce una arquitectura de **Servicios Desacoplados** y un **Frontend Dashboard** basado en React, permitiendo a los usuarios gestionar su conexión de WhatsApp con una experiencia de usuario (UX) nativa y responsiva.

---

## 🏗️ Arquitectura del Sistema

El sistema opera bajo un modelo híbrido de **Gestión + Conectividad + Servicios**:

1.  **Identity & Access:** WordPress + MemberPress gestionan la autenticación.
2.  **Service Layer (Backend):** Abstracción total de lógica externa (Evolution API) y auditoría interna (Logger).
3.  **Smart Provisioning:** El sistema "auto-sana". Si un usuario solicita un QR, el núcleo orquesta la creación y configuración en Evolution API sin intervención humana.
4.  **AI Context Hub:** Centraliza Prompts, Voz (ElevenLabs) y Datos de Negocio para enviarlos a n8n en una sola petición serializada.

```mermaid
graph TD
    User((Usuario Final)) -->|1. Escanea QR| Front[React App]
    Front -->|2. REST API (WP)| Controller[API Controller]
    Controller -->|3. Delegate| Service[Evolution Service]
    Service -->|4. HTTP Request (Internal Docker Network)| Evo[Evolution API v2]
    Evo -->|5. Webhook| N8N[n8n Workflow]
    N8N -->|6. GET /context| Controller
    Controller -->|7. JSON Context| N8N

```

---

## ⚙️ Requisitos del Sistema

### Servidor & Entorno

* **PHP:** Versión **7.4** o superior (Recomendado 8.1+).
* **WordPress:** Versión **6.2** o superior.
* **Extensiones PHP:** `cURL` (comunicación API), `OpenSSL` (encriptación).

### Infraestructura Externa (Docker)

* **Evolution API v2:** Accesible vía red interna (recomendado) o HTTP.
* **Redis:** Recomendado para caché de objetos en alta concurrencia.

---

## 🔌 Integración con Evolution API

La integración se define globalmente en el `wp-config.php` (o `docker-compose.yml`) y el plugin gestiona las instancias individuales mediante el `Evolution_Service`.

### Constantes Requeridas

```php
// 1. Seguridad Interna
define('KURUKIN_ENCRYPTION_KEY', 'tu_clave_segura_32_chars');
define('KURUKIN_API_SECRET', 'token_validacion_n8n');

// 2. Conexión a Infraestructura (Red Interna Docker recomendada)
define('KURUKIN_EVOLUTION_URL', 'http://evolution_evolution_api:8080');
define('KURUKIN_EVOLUTION_GLOBAL_KEY', 'tu_global_api_key');

```

---

## 🚀 Características Principales

### 🔌 Conectividad & Frontend (React v2.6)

* **Mobile-First Dashboard:** Interfaz responsiva que elimina problemas de scroll y visualización en dispositivos móviles.
* **Smart QR:** Detección de estados, auto-creación de instancias y regeneración automática.
* **Cache Busting:** Sistema inteligente (`filemtime`) que fuerza la recarga de scripts JS automáticamente al actualizar el plugin.

### 🛡️ Backend & Estabilidad (Core v2.6)

* **Service Layer Pattern:** Lógica de negocio separada de los controladores REST (`Evolution_Service`).
* **Secure Logging:** Sistema de logs interno (`Kurukin_Logger`) con rotación diaria y protección `.htaccess` automática.
* **Fail Fast Validation:** Validación de credenciales externas (OpenAI/ElevenLabs) antes de guardar.
* **Encriptación AES-256:** Protección de API Keys en base de datos.

---

## 🛠️ Estructura del Proyecto

```text
kurukin-core/
├── kurukin-core.php                 # Loader & Constantes Globales
├── assets/
│   └── js/
│       └── connection-app.js        # React App (QR Logic)
├── includes/
│   ├── api/                         # REST API Controllers
│   │   ├── class-kurukin-connection-controller.php
│   │   └── ...
│   ├── services/                    # Business Logic & Utilities (NUEVO)
│   │   ├── class-evolution-service.php  # Abstracción API WhatsApp
│   │   └── class-kurukin-logger.php     # Auditoría Segura
│   ├── integrations/
│   │   └── class-kurukin-memberpress.php
│   └── class-kurukin-fields.php     # Admin Helpers

```

---

## 📜 Historial de Versiones (Changelog)

### [2.6.0] - 2026-02-01 (Stable Release)

* **Refactor:** Implementación de Arquitectura de Servicios (`Evolution_Service`).
* **Feat:** Sistema de Logging Interno Seguro (`Kurukin_Logger`).
* **UX/Fix:** Solución definitiva al scroll en móviles y layout responsivo en React.
* **DevOps:** Inyección de configuración de Evolution API vía variables de entorno Docker.
* **Core:** Implementación de Cache Busting automático para assets JS.

### [1.8.0] - 2026-01-30

* **Feat:** Dashboard Frontend inicial en React.
* **Feat:** Lógica "Smart QR" básica.

---

**Javier Quiroz** Lead Architect @ Kurukin IA
