# Kurukin Core (kurukin-core)

Plugin “control plane” para Kurukin SaaS sobre WordPress.  
Este plugin desacopla **Usuario (WP User)** de su **Infraestructura (Tenant/Stack)** y orquesta dinámicamente:

- **Evolution API** (WhatsApp Baileys)
- **n8n** (webhooks por vertical + tenant, con Router UUID obligatorio)
- **MemberPress** (control de acceso/pago)
- **REST API** para que n8n consuma configuración multi-tenant
- **UI Panel** (Dashboard + Configuración) usando endpoints REST internos

> **Estado UI actual:** se eliminó el UX/CSS que causaba fricción y bugs visuales.  
> Por ahora quedamos con **esqueleto funcional** (mínimo UI) y el foco es **infra + créditos**.

---

## 1) Conceptos clave

### Usuario (WP User) — Identidad estable
- **Fuente de verdad del usuario:** `wp_users`
- Identificadores usados:
  - `user_id` (numérico, estable)
  - `user_login` (string, estable)
- **No dependemos de `post_id`** para identidad del usuario (eso varía y rompe).

### Tenant (CPT `saas_instance`)
Cada usuario puede tener un CPT `saas_instance` (un “tenant record”) donde se guarda el ruteo y configuración:

- `instance_id` (nombre de la instancia en Evolution)
- endpoint/apikey por tenant (multi-tenant)
- vertical de negocio
- base de webhook hacia n8n (base-only)
- evento permitido por stack (Evolution enum)
- Router UUID obligatorio de n8n (para rutas dinámicas)

**Fuente de verdad del ruteo:** `wp_postmeta` del `saas_instance`.

### Stack (Infra Registry)
Los stacks de infraestructura viven en `wp_options` como JSON:

- `kurukin_infra_stacks` (lista de stacks)
- `kurukin_infra_rr_pointer` (puntero round-robin por vertical)

**Fuente de verdad global:** `wp_options`.

---

## 2) Flujo de Provisioning (alto nivel)

1. Al login o provisioning, el plugin asegura que exista un `saas_instance` para el usuario.
2. Se asigna un stack por vertical (round-robin) y se persisten metadatos críticos.
3. `Evolution_Service` hace el “birth protocol”:
   - asegura que la instancia existe (check → create si hace falta)
   - configura webhook en Evolution hacia n8n (**payload v2** con wrapper `webhook`)
   - pide el QR (base64)

---

## 3) Infra Registry (`kurukin_infra_stacks`)

### Estructura recomendada

> **IMPORTANTE:** en n8n con rutas dinámicas `/:vertical/:instance_id` se requiere un **Router ID (UUID)** en la URL para que el flujo enrute correctamente:
>
> `/webhook/{ROUTER_UUID}/{vertical}/{instance_id}`

Ejemplo (Stack Alpha):

```json
[
  {
    "stack_id": "evo-alpha-01",
    "active": true,

    "evolution_endpoint": "http://evolution_api_v2:8080",
    "evolution_apikey": "YOUR_STACK_KEY",

    "n8n_webhook_base": "http://n8n-v2_n8n_v2_webhook:5678",
    "n8n_router_id": "e699da51-5467-4e2c-989e-de0d82fffc23",

    "webhook_event_type": "MESSAGES_UPSERT",

    "supported_verticals": ["multinivel", "general"]
  }
]
````

### Campos

* `stack_id` *(string, requerido)*: identificador humano del stack.
* `active` *(bool)*: si el stack puede asignar tenants.
* `evolution_endpoint` *(string)*: URL interna de Evolution API.
* `evolution_apikey` *(string)*: apikey para ese Evolution.
* `n8n_webhook_base` *(string)*: host interno de n8n (**base**; sin variables).
* `n8n_router_id` *(string, requerido para wildcards)*: UUID del flujo router en n8n.
* `webhook_event_type` *(string)*: evento permitido por esa versión de Evolution (ej: `MESSAGES_UPSERT`).
* `supported_verticals` *(array)*: verticales soportadas por stack (siempre se incluye fallback `general`).
* `capacity` *(int, opcional)*: reservado para lógica futura.

---

## 4) Metadatos del Tenant (CPT `saas_instance`)

Metas críticas:

* `_kurukin_evolution_instance_id` → `javierquiroz`
* `_kurukin_business_vertical` → `multinivel`
* `_kurukin_stack_id` → `evo-alpha-01`

Routing Evolution (multi-tenant):

* `_kurukin_evolution_endpoint` → `http://evolution_api_v2:8080`
* `_kurukin_evolution_apikey` → `...`

Routing n8n:

* `_kurukin_n8n_webhook_url` → **base only**, ej: `http://n8n-v2...:5678`
* `_kurukin_n8n_router_id` → `e699da51-...`
* `_kurukin_evolution_webhook_event` → `MESSAGES_UPSERT`

**Notas importantes:**

* El plugin asegura que `*_webhook_event` exista aunque el tenant esté “pinned” (endpoint/apikey/n8n_base ya definidos).
  Esto no cambia ruteo, solo completa la config necesaria para Evolution v2.
* Si un tenant legacy trae `_kurukin_n8n_webhook_url` con `/webhook/...`, el builder lo **normaliza** recortando desde `/webhook/` para evitar duplicados.

---

## 5) Construcción de Webhook URL (Contrato n8n)

### URL externa (referencia)

Ejemplo:
`https://webhookv2.kurukin.com/webhook/e699da51-5467-4e2c-989e-de0d82fffc23/:vertical/:instance_id`

### URL interna (la que Evolution usa)

Formato **obligatorio**:

```
{n8n_webhook_base}/webhook/{n8n_router_id}/{vertical}/{instance_id}
```

Ejemplo real:

```
http://n8n-v2_n8n_v2_webhook:5678/webhook/e699da51-5467-4e2c-989e-de0d82fffc23/multinivel/javierquiroz
```

### Hardening (legacy base)

Si `_kurukin_n8n_webhook_url` trae algo como:

`http://n8n-v2...:5678/webhook/multinivel`

El builder recorta desde `/webhook/` para dejarlo como base:

`http://n8n-v2...:5678`

---

## 6) Evolution Webhook Payload (v2.x) — Base64 Blindado

Evolution v2.x requiere wrapper `webhook` y eventos en enum válido.

Para evitar regresiones por variaciones de schema (camelCase vs snake_case), el payload envía **AMBOS**:

```json
{
  "webhook": {
    "enabled": true,
    "url": "http://n8n.../webhook/<router_uuid>/<vertical>/<instance_id>",
    "webhookByEvents": false,
    "events": ["MESSAGES_UPSERT"],
    "webhookBase64": true,
    "webhook_base64": true
  }
}
```

---

## 6.1 INCIDENTE: Evolution API v2.3.7 ignora `webhookBase64=true` (Caja negra)

### Síntoma

Aunque se envíe `webhookBase64: true` (y hasta `webhook_base64: true`), Evolution responde y persiste:

* `"webhookBase64": false`

Confirmación: `GET /webhook/find/:instance` devuelve `false` y la tabla Postgres `public."Webhook"` queda en `false`.

### Causa (inferida)

En **Evolution API v2.3.7** (NestJS/Prisma), el backend “sanea”/normaliza el DTO y **fuerza false** al persistir, ignorando el input del cliente.
No se pudo modificar ni recompilar la API (caja negra).

### Solución definitiva (DB-level)

Se implementó un **TRIGGER en Postgres** que intercepta cualquier `INSERT` o `UPDATE` en `public."Webhook"` y fuerza:

* `NEW."webhookBase64" := true;`

Con esto, aunque la app intente grabar `false`, la DB lo guarda en `true` de forma persistente y automática.

> **Nota:** Esta solución es intencionalmente “enforcement”: aplica a todas las instancias del cluster `evolution2`.

---

## 6.2 Runbook: Trigger Postgres para forzar Base64 (Producción)

### Paso 1: Entrar a psql en el contenedor Postgres (Swarm)

```bash
export PG_CONT="$(docker ps --format '{{.Names}}' | grep -E 'postgres_pgvector_postgres' | head -n 1)"
echo "PG_CONT=$PG_CONT"

docker exec -it "$PG_CONT" sh -lc 'psql -U postgres -d evolution2'
```

### Paso 2: Crear función `force_webhook_base64`

```sql
CREATE OR REPLACE FUNCTION public.force_webhook_base64()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  -- Forzar siempre Base64 ON sin importar lo que mande la app
  NEW."webhookBase64" := true;
  RETURN NEW;
END;
$$;
```

### Paso 3: Crear trigger `trg_enforce_base64` en `"Webhook"`

```sql
DROP TRIGGER IF EXISTS trg_enforce_base64 ON public."Webhook";

CREATE TRIGGER trg_enforce_base64
BEFORE INSERT OR UPDATE ON public."Webhook"
FOR EACH ROW
EXECUTE FUNCTION public.force_webhook_base64();
```

### Confirmar trigger instalado

```sql
SELECT
  t.tgname AS trigger_name,
  pg_get_triggerdef(t.oid) AS definition
FROM pg_trigger t
JOIN pg_class c ON c.oid = t.tgrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relname = 'Webhook'
  AND n.nspname = 'public'
  AND NOT t.tgisinternal;
```

### Rollback (si se requiere)

```sql
DROP TRIGGER IF EXISTS trg_enforce_base64 ON public."Webhook";
DROP FUNCTION IF EXISTS public.force_webhook_base64();
```

---

## 7) REST API

### 7.1 `GET /wp-json/kurukin/v1/config?instance_id=...`

**Propósito:** n8n consume configuración por tenant para ejecutar el cerebro (AI + negocio) y saber a qué Evolution responder.

**Seguridad:** Header requerido:

* `x-kurukin-secret: <KURUKIN_API_SECRET>`

**Respuesta incluye (multi-tenant):**

* `evolution_connection.endpoint` (meta del tenant, fallback a constantes)
* `evolution_connection.apikey` (meta del tenant, fallback a constantes)

Ejemplo (fragmento):

```json
{
  "status": "success",
  "instance_id": "javierquiroz",
  "evolution_connection": {
    "endpoint": "http://evolution_api_v2:8080",
    "apikey": "sk_tenant_specific_or_stack_key"
  }
}
```

### 7.2 UI / Settings API (panel del usuario)

La UI del panel consume endpoints internos:

* `GET /wp-json/kurukin/v1/settings`
* `POST /wp-json/kurukin/v1/settings`
* `POST /wp-json/kurukin/v1/validate-credential`
* `GET /wp-json/kurukin/v1/connection/status`
* `GET /wp-json/kurukin/v1/connection/qr`
* `POST /wp-json/kurukin/v1/connection/reset`

**Importante:** estos endpoints requieren `is_user_logged_in()` (sesión WP).
En CLI pueden devolver 401 si no se simula correctamente el contexto de autenticación.

---

## 8) Créditos (Prioridad del producto)

### 8.1 Fuente de verdad (OFICIAL)

Los créditos se almacenan en **User Meta**:

* Meta key: `_kurukin_credits_balance`
* Tabla: `wp_usermeta`
* Usuario: `user_id` / `user_login`

✅ **Fuente oficial:** `wp_usermeta`
❌ No usar `post_id` para créditos (eso fue un enfoque viejo/incorrecto y causaba “saldo 0”).

### 8.2 Wallet endpoint (usuario logueado)

**GET**:

`/wp-json/kurukin/v1/wallet`

Retorna algo como:

```json
{
  "credits_balance": 150,
  "can_process": true,
  "min_required": 1,
  "source": "usermeta",
  "user_id": 1,
  "user_login": "javierquiroz"
}
```

> Nota: requiere sesión WP. Si no hay login → 401.

### 8.3 Admin endpoint (server-to-server) para cargar créditos

Pensado para Hotmart, QR, recargas, integraciones externas.

**POST**:

`/wp-json/kurukin/v1/admin/credits/set`

Headers:

* `Content-Type: application/json`
* `x-kurukin-secret: <KURUKIN_API_SECRET>`

Body:

```json
{
  "user_login": "javierquiroz",
  "amount": 50,
  "mode": "add",
  "transaction_id": "hotmart:order:ABC123",
  "note": "Hotmart ABC123"
}
```

Ejemplo real (docker -> localhost):

```bash
export SAAS_WP="kurukin_saas_wordpress.1.xxxxx"
export KURUKIN_API_SECRET="token_compartido_seguro_n8n_wp_2026"

docker exec -it "$SAAS_WP" curl -sS -X POST "http://localhost/wp-json/kurukin/v1/admin/credits/set" \
  -H "Content-Type: application/json" \
  -H "x-kurukin-secret: $KURUKIN_API_SECRET" \
  -d '{"user_login":"javierquiroz","amount":50,"mode":"add","transaction_id":"ping:saas","note":"ping"}'
```

Respuesta:

```json
{
  "success": true,
  "message": "Credits updated.",
  "user_id": 1,
  "user_login": "javierquiroz",
  "mode": "add",
  "amount": 50,
  "previous_balance": 100,
  "new_balance": 150,
  "transaction_id": "ping:saas"
}
```

**Idempotencia recomendada:**

* Usar `transaction_id` único por compra/recarga (Hotmart order id, etc).
* (Roadmap) Guardar ledger/transacciones para evitar doble carga.

---

## 9) Dependencias / Integraciones

* **MemberPress**: si está activo, se valida que el usuario esté activo antes de entregar `/config`.
* **Evolution API**: servicio externo. Se usa `apikey` por request.
* **n8n**: recibe webhooks con router UUID + variables.
* **UI (connection-app.js)**: consume `/settings` y `/connection/*` (actualmente UI mínima/esqueleto).

---

## 10) Configuración de constantes (fallback / legacy)

> El diseño actual prioriza **meta del tenant**.
> Las constantes existen solo como fallback para usuarios legacy.

En `WORDPRESS_CONFIG_EXTRA` o `wp-config.php`:

* `KURUKIN_API_SECRET` *(requerida para /config y admin credits API)*
* `KURUKIN_ENCRYPTION_KEY` *(recomendado para decrypt de keys)*
* `KURUKIN_EVOLUTION_URL` *(fallback legacy)*
* `KURUKIN_EVOLUTION_GLOBAL_KEY` *(fallback legacy)*
* `KURUKIN_N8N_WEBHOOK_BASE` *(fallback legacy, si no hay stack)*

---

## 11) Operación / Comandos útiles

> Ejecutar siempre **dentro del contenedor** WordPress.

### 11.1 Reset OPcache (si aplica)

```bash
docker exec -it "$SAAS_WP" sh -lc 'php -r "function_exists(\"opcache_reset\") ? (opcache_reset() && print(\"OPCACHE_RESET\n\")) : print(\"NO_OPCACHE\n\");"'
```

### 11.2 Probar wallet en contexto autenticado (CLI)

```bash
docker exec -it "$SAAS_WP" sh -lc 'php -r '\''require_once "/var/www/html/wp-load.php";
$u=get_user_by("login","javierquiroz"); if(!$u){echo "NO USER\n"; exit;}
wp_set_current_user($u->ID);
$req=new WP_REST_Request("GET","/kurukin/v1/wallet");
$res=rest_do_request($req);
echo wp_json_encode($res->get_data(), JSON_PRETTY_PRINT)."\n";'\'''
```

---

## 12) Troubleshooting

### 12.1 Wallet muestra 0 pero usermeta tiene saldo

Causas típicas:

* UI no autenticada (401) → se queda en 0
* JS cacheado/versión vieja
* Endpoint leyendo una fuente antigua (postmeta) en legacy

Solución:

* Confirmar que `/wallet` devuelve `source: "usermeta"` y el saldo correcto
* Asegurar cache-busting del JS (usar `filemtime()` al encolar)
* Verificar en el navegador Network → request a `/wallet` status 200/401

### 12.2 Admin credits endpoint da 403/401

* 401: falta header `x-kurukin-secret`
* 403: secret incorrecto (no coincide con `KURUKIN_API_SECRET` definido en el server)

---

## 13) Changelog (resumen de cambios recientes)

* Multi-tenant real: Evolution endpoint/apikey salen del meta del tenant (no de constantes).
* REST `/config`:

  * agrega `evolution_connection` (endpoint/apikey) dinámico por tenant (fallback legacy)
* `Evolution_Service`:

  * no crea a ciegas: verifica existencia antes de crear
  * payload correcto para `webhook/set` en Evolution v2 (wrapper + base64)
  * base64 blindado: `webhookBase64` + `webhook_base64`
  * evento permitido configurable por stack (`webhook_event_type`)
  * URL n8n corregida: `/webhook/{router_uuid}/{vertical}/{instance_id}`
  * hardening: si n8n_base trae `/webhook/...`, se recorta automáticamente
* `Infrastructure_Registry`:

  * soporta stacks guardados como array/JSON/serialized
  * normaliza y valida `webhook_event_type` y `n8n_router_id`
  * normaliza verticales (incluye `general`)
* `Tenant_Service`:

  * persiste `_kurukin_evolution_webhook_event`
  * persiste `_kurukin_n8n_router_id`
  * `_kurukin_n8n_webhook_url` se trata como **base only**
* **Créditos (fix crítico):**

  * **Fuente oficial:** `wp_usermeta` meta `_kurukin_credits_balance`
  * `GET /kurukin/v1/wallet` devuelve saldo desde usermeta (y ya no depende de post_id)
  * `POST /kurukin/v1/admin/credits/set` permite recargas server-to-server (Hotmart/QR/etc) usando `x-kurukin-secret`
* **UI/UX:**

  * se eliminó el UX/CSS que estaba rompiendo la experiencia → queda **esqueleto funcional** por ahora
* **SRE/DB Fix (Evolution v2.3.7)**:

  * trigger Postgres `trg_enforce_base64` + función `force_webhook_base64()` para forzar `"webhookBase64"=true` en toda escritura

---

## 14) Seguridad (nota operativa)

* `/config` y admin credits API requieren `x-kurukin-secret`.
* API keys de OpenAI/ElevenLabs se guardan cifradas (AES-256-CBC) y se desencriptan al servir config.
* Las credenciales de Evolution se entregan a n8n porque n8n actúa como “worker/orchestrator” por tenant.

---

## 15) Roadmap corto

* Ledger de transacciones de créditos (idempotencia real por `transaction_id`).
* UI admin para editar `kurukin_infra_stacks` en vez de WP-CLI.
* Cache de `/config` por tenant con invalidación por meta update.
* Upgrade de Evolution API cuando upstream corrija el saneamiento de `webhookBase64`.
