# Kurukin Core (WordPress Plugin) — Multi-Tenant Provisioning Control Plane

Este plugin convierte WordPress en el **Control Plane** de Kurukin SaaS:
- Administra **tenants** (usuarios) desacoplados de su infraestructura (stacks).
- Asigna automáticamente **stack** + **vertical** a cada tenant.
- Orquesta **Evolution API** (instancias WhatsApp) y conecta **webhooks** hacia n8n.
- Expone configuración vía API REST para que n8n sepa **a qué Evolution endpoint hablar** por tenant.

---

## Estado actual (SITREP #007)

✅ **Multi-tenant provisioning funcional**:
- Cada usuario tiene un `saas_instance` (CPT) con meta de ruteo:
  - `_kurukin_evolution_endpoint`
  - `_kurukin_evolution_apikey`
  - `_kurukin_n8n_webhook_url`
  - `_kurukin_business_vertical`
  - `_kurukin_stack_id`
  - `_kurukin_evolution_webhook_event` (nuevo)

✅ **Corrección DNS interno**:
- El host correcto dentro de Docker Swarm para Evolution v2 es el alias de red:
  - `http://evolution_api_v2:8080`
- Se detectó y corrigió el error histórico:
  - `http://evolution_evolution_api:8080` (no resolvía DNS en la red actual)

✅ **QR end-to-end**:
- `Evolution_Service::connect_and_get_qr()` termina con `base64` válido para QR.

✅ **/config actualizado**:
- `GET /wp-json/kurukin/v1/config?instance_id=...`
- Ahora incluye `evolution_connection` con **endpoint/apikey del tenant** (no global).

---

## Arquitectura (Conceptos clave)

### 1) Tenant ≠ Infraestructura (Desacople)
Un tenant (usuario) no pertenece fijo a un servidor.
El tenant se “pinnea” a un stack mediante meta del `saas_instance`.

### 2) Registry de Stacks (Source of Truth)
Los stacks viven en `wp_options`:
- `kurukin_infra_stacks` (lista)
- `kurukin_infra_rr_pointer` (puntero round-robin por vertical)

El plugin decide a qué stack asignar un tenant basado en:
- `supported_verticals`
- Round-Robin per vertical

### 3) Vertical (Modo de negocio)
El tenant tiene `business_vertical` (ej: `multinivel`, `general`, etc).
De ahí sale:
- ruta del webhook (n8n)
- selección de stack compatible (registry)

### 4) PRO Feature: `webhook_event_type` por stack (Opción C)
Evolution API cambia el nombre del evento permitido por versión.

En vez de “adivinar” (fragil), la política es:
✅ **Config explícita por stack**:
- Cada stack define `webhook_event_type`.
- Tenant lo persiste como `_kurukin_evolution_webhook_event`.
- `Evolution_Service` lo usa al setear webhook.

Ejemplo:
- Stack Alpha (Evolution v2.3.7): `MESSAGES_UPSERT`
- Stack Beta (legacy): `messages.upsert`

---

## Componentes relevantes

### A) `Infrastructure_Registry`
**Archivo:** `includes/services/class-infrastructure-registry.php`

Responsabilidad:
- Leer/normalizar stacks desde `kurukin_infra_stacks`.
- Tolerar formatos:
  - array nativo
  - string JSON (wp-cli)
  - serialized string
- Validar y default:
  - `supported_verticals` siempre incluye `general`
  - `webhook_event_type` siempre existe (default `MESSAGES_UPSERT`)

Campos de stack soportados:
```json
{
  "stack_id": "evo-alpha-01",
  "active": true,
  "evolution_endpoint": "http://evolution_api_v2:8080",
  "evolution_apikey": "XXXXX",
  "n8n_webhook_base": "http://n8n-v2_n8n_v2_webhook:5678",
  "supported_verticals": ["multinivel","general"],
  "webhook_event_type": "MESSAGES_UPSERT",
  "capacity": 1000
}
````

---

### B) `Tenant_Service`

**Archivo:** `includes/services/class-tenant-service.php`

Responsabilidad:

* Asegurar que el usuario tenga un `saas_instance`.
* Asignar routing meta desde registry **si falta**:

  * `_kurukin_evolution_endpoint`
  * `_kurukin_evolution_apikey`
  * `_kurukin_n8n_webhook_url`
  * `_kurukin_stack_id`
* Persistir siempre el evento si no existe:

  * `_kurukin_evolution_webhook_event`

Regla de pinning:

* Si el tenant ya tiene endpoint/apikey/webhook, NO se reescribe (tenant pinned).
* Pero si el evento falta, se setea (no cambia infraestructura).

---

### C) `Evolution_Service`

**Archivo:** `includes/services/class-evolution-service.php`

Responsabilidad:

* Ejecutar el protocolo confiable (Reliability First):

  1. **Ensure instance exists**

     * Check primero: `GET instance/connectionState/{instance}`
     * Si no existe, create: `POST instance/create`
     * Maneja “name in use” como éxito (algunas versiones devuelven 403 con mensaje).
  2. **Set webhook**

     * `POST webhook/set/{instance}`
     * Para Evolution v2.3.7+ se requiere wrapper `webhook`:

       ```json
       { "webhook": { ... } }
       ```
     * Usa `webhook_event_type` del tenant (persistido desde stack).
  3. **Connect / QR**

     * `GET instance/connect/{instance}`
     * Loop corto hasta obtener `base64`

Notas:

* Incluye retry en timeout.
* Incluye extractor robusto de mensajes de error (arrays anidados).

---

### D) REST API `/config`

**Archivo:** `includes/api/class-kurukin-api-controller.php`

Ruta:

* `GET /wp-json/kurukin/v1/config?instance_id=cliente_demo`

Auth:

* Header requerido:

  * `x-kurukin-secret: <KURUKIN_API_SECRET>`

Ahora incluye:

* `evolution_connection.endpoint` y `evolution_connection.apikey` por tenant.

Respuesta ejemplo:

```json
{
  "status": "success",
  "instance_id": "javierquiroz",
  "router_logic": { "workflow_mode": "multinivel", "cluster_node": "alpha-01", "version": "1.3" },
  "ai_brain": { "provider": "openai", "api_key": "...", "model": "gpt-4o", "system_prompt": "" },
  "voice_config": { "provider": "elevenlabs", "enabled": false, "api_key": "", "voice_id": "...", "model_id": "" },
  "business_data": [],
  "evolution_connection": {
    "endpoint": "http://evolution_api_v2:8080",
    "apikey": "sk_tenant_specific_key"
  }
}
```

**Importante:** este endpoint expone credenciales. Debe protegerse:

* Secret header obligatorio
* TLS externo (Traefik)
* Evitar logs del response
* Idealmente `Cache-Control: no-store`

---

## Configuración (WordPress / Docker)

### 1) Variables/Constantes globales (Fallback legacy)

Estas constantes pueden existir en `WORDPRESS_CONFIG_EXTRA` (docker compose):

* `KURUKIN_API_SECRET` (obligatorio para /config)
* `KURUKIN_EVOLUTION_URL` (fallback legacy)
* `KURUKIN_EVOLUTION_GLOBAL_KEY` (fallback legacy)
* `KURUKIN_ENCRYPTION_KEY` (para decrypt de llaves)

> Importante: en multi-tenant, NO se deben usar como fuente primaria. Son fallback.

---

## Operación: Comandos útiles (WP-CLI)

> Nota: dentro del contenedor WordPress, instala wp-cli si no existe:

```bash
curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x /usr/local/bin/wp
wp --info
```

### A) Setear stacks (registry)

```bash
wp --allow-root option update kurukin_infra_stacks '[
  {
    "stack_id":"evo-alpha-01",
    "active":true,
    "evolution_endpoint":"http://evolution_api_v2:8080",
    "evolution_apikey":"XXX",
    "n8n_webhook_base":"http://n8n-v2_n8n_v2_webhook:5678",
    "supported_verticals":["multinivel","general"],
    "webhook_event_type":"MESSAGES_UPSERT"
  }
]'
```

### B) Reprovision (simular login para asignar routing meta)

```bash
wp --allow-root eval '$u=get_user_by("id",1); do_action("wp_login",$u->user_login,$u); echo "REPROVISION_OK\n";'
```

### C) Ver routing meta del tenant

```bash
wp --allow-root eval '$id=18;
echo "STACK:".get_post_meta($id,"_kurukin_stack_id",true)."\n";
echo "VERT:".get_post_meta($id,"_kurukin_business_vertical",true)."\n";
echo "EVO:".get_post_meta($id,"_kurukin_evolution_endpoint",true)."\n";
echo "N8N:".get_post_meta($id,"_kurukin_n8n_webhook_url",true)."\n";
echo "EVENT:".get_post_meta($id,"_kurukin_evolution_webhook_event",true)."\n";
'
```

### D) QR (base64)

```bash
wp --allow-root eval '$svc=new \Kurukin\Core\Services\Evolution_Service();
$r=$svc->connect_and_get_qr(1);
if(is_wp_error($r)) echo "ERR: ".$r->get_error_message()."\n";
else echo "OK base64_len=".strlen($r["base64"]??"")."\n";
'
```

### E) Probar `/config` desde WP internamente

```bash
wp --allow-root eval '
$req = new WP_REST_Request("GET", "/kurukin/v1/config");
$req->set_param("instance_id", "javierquiroz");
$req->set_header("x-kurukin-secret", defined("KURUKIN_API_SECRET") ? KURUKIN_API_SECRET : "");
$res = rest_do_request($req);
echo wp_json_encode($res->get_data(), JSON_PRETTY_PRINT).PHP_EOL;
'
```

---

## Troubleshooting (Casos reales)

### 1) `cURL error 6: Could not resolve host`

Causa:

* endpoint apunta a host inexistente en la red swarm (ej: `evolution_evolution_api`).
  Solución:
* validar DNS dentro del contenedor WordPress:

```bash
getent hosts evolution_api_v2
curl -sS -D- http://evolution_api_v2:8080/ -o /dev/null | head
```

* corregir meta del tenant:

```bash
wp --allow-root post meta update <POST_ID> _kurukin_evolution_endpoint "http://evolution_api_v2:8080"
```

### 2) `webhook requires property "webhook"`

Causa:

* Evolution v2.3.7+ requiere wrapper `{ "webhook": {...} }`.
  Solución:
* Ya implementado en `Evolution_Service::set_webhook()`.

### 3) Error de evento no permitido

Causa:

* La versión de Evolution exige enum (ej: `MESSAGES_UPSERT`) y no acepta `messages.upsert`.
  Solución:
* Definir `webhook_event_type` por stack en registry.
* `Tenant_Service` persistirá `_kurukin_evolution_webhook_event`.

---

## Roadmap inmediato (siguiente fase)

* (Opcional) Exponer en `/config` también:

  * `webhook_event_type`
  * `webhook_url`
* Hardening:

  * `Cache-Control: no-store` en `/config`
  * Rotación de `KURUKIN_API_SECRET`
  * Rate limiting (Traefik middleware)
* Escala:

  * más stacks, balance RR por vertical
  * migraciones controladas (Admin-only)

---

## Licencia / Notas

Interno Kurukin. Este plugin es parte del core del SaaS y su comportamiento está acoplado al stack (Evolution API + n8n + Swarm).
