# Kurukin Core (kurukin-core)

Plugin “control plane” para Kurukin SaaS sobre WordPress.  
Este plugin desacopla **Usuario (WP User)** de su **Infraestructura (Tenant/Stack)** y orquesta dinámicamente:

- **Evolution API** (WhatsApp Baileys)
- **n8n** (webhooks por vertical + tenant, con Router UUID obligatorio)
- **MemberPress** (control de acceso/pago)
- **REST API** para que n8n consuma configuración multi-tenant
- **UI Panel** (Dashboard + Configuración) usando endpoints REST internos

---

## 1) Conceptos clave

### Tenant (CPT `saas_instance`)
Cada usuario tiene un CPT `saas_instance` (un “tenant record”) donde se guarda el ruteo y configuración:

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

### Paso 4: Verificación (UPDATE e INSERT/UPSERT)

**a) Obtener el UUID interno de la instancia (Instance.id) por nombre**

```sql
SELECT id, name
FROM public."Instance"
WHERE name = 'javierquiroz'
LIMIT 1;
```

Copia el `id` (uuid) y úsalo abajo como `INSTANCE_UUID`.

**b) UPDATE test (intenta poner false → debe quedar true)**

```sql
UPDATE public."Webhook"
SET "webhookBase64" = false,
    "updatedAt" = NOW()
WHERE "instanceId" = 'INSTANCE_UUID';

SELECT id, "instanceId", "webhookBase64", "updatedAt"
FROM public."Webhook"
WHERE "instanceId" = 'INSTANCE_UUID';
```

**c) UPSERT test (intenta insertar/actualizar false → debe quedar true)**

```sql
INSERT INTO public."Webhook"
  (id, url, enabled, events, "webhookByEvents", "webhookBase64", "createdAt", "updatedAt", "instanceId", headers)
VALUES
  ('trg_test_' || replace(gen_random_uuid()::text,'-',''),
   'http://example.local/webhook/test',
   true,
   '["MESSAGES_UPSERT"]'::jsonb,
   false,
   false,
   NOW(),
   NOW(),
   'INSTANCE_UUID',
   NULL)
ON CONFLICT ("instanceId") DO UPDATE
SET url = EXCLUDED.url,
    "webhookBase64" = false,
    "updatedAt" = NOW();

SELECT id, "instanceId", url, "webhookBase64", "updatedAt"
FROM public."Webhook"
WHERE "instanceId" = 'INSTANCE_UUID';
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

## 8) Dependencias / Integraciones

* **MemberPress**: si está activo, se valida que el usuario esté activo antes de entregar `/config`.
* **Evolution API**: servicio externo. Se usa `apikey` por request.
* **n8n**: recibe webhooks con router UUID + variables.
* **UI (connection-app.js)**: consume `/settings` y `/connection/*`.

---

## 9) Configuración de constantes (fallback / legacy)

> El diseño actual prioriza **meta del tenant**.
> Las constantes existen solo como fallback para usuarios legacy.

En `WORDPRESS_CONFIG_EXTRA` o `wp-config.php`:

* `KURUKIN_API_SECRET` *(requerida para /config)*
* `KURUKIN_ENCRYPTION_KEY` *(recomendado para decrypt de keys)*
* `KURUKIN_EVOLUTION_URL` *(fallback legacy)*
* `KURUKIN_EVOLUTION_GLOBAL_KEY` *(fallback legacy)*
* `KURUKIN_N8N_WEBHOOK_BASE` *(fallback legacy, si no hay stack)*

---

## 10) Operación / Comandos útiles (WP-CLI)

> Ejecutar siempre **dentro del contenedor** WordPress.

### 10.1 Instalar WP-CLI (si el contenedor no lo trae)

```bash
export WP_CONT="$(docker ps --format '{{.Names}}' | grep -E '^kurukin_saas_wordpress\.1\.' | head -n 1)"

docker exec -it "$WP_CONT" sh -lc '
command -v wp >/dev/null 2>&1 && wp --info || (
  curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar &&
  chmod +x /usr/local/bin/wp &&
  wp --info
)'
```

### 10.2 Setear infra registry (stack) — ejemplo completo

```bash
export WP_CONT="$(docker ps --format '{{.Names}}' | grep -E '^kurukin_saas_wordpress\.1\.' | head -n 1)"

docker exec -it "$WP_CONT" sh -lc \
'wp --allow-root option update kurukin_infra_stacks '\''[
  {
    "stack_id":"evo-alpha-01",
    "active":true,
    "evolution_endpoint":"http://evolution_api_v2:8080",
    "evolution_apikey":"XXX",
    "n8n_webhook_base":"http://n8n-v2_n8n_v2_webhook:5678",
    "n8n_router_id":"e699da51-5467-4e2c-989e-de0d82fffc23",
    "supported_verticals":["multinivel","general"],
    "webhook_event_type":"MESSAGES_UPSERT"
  }
]'\'''
```

### 10.3 Ver metas críticas del tenant (ej: post_id=18)

```bash
docker exec -it "$WP_CONT" sh -lc \
'wp --allow-root eval '\''$id=18;
echo "VERT:".get_post_meta($id,"_kurukin_business_vertical",true)."\n";
echo "INSTANCE:".get_post_meta($id,"_kurukin_evolution_instance_id",true)."\n";
echo "EVO:".get_post_meta($id,"_kurukin_evolution_endpoint",true)."\n";
echo "EVENT:".get_post_meta($id,"_kurukin_evolution_webhook_event",true)."\n";
echo "ROUTER:".get_post_meta($id,"_kurukin_n8n_router_id",true)."\n";
echo "N8N_BASE:".get_post_meta($id,"_kurukin_n8n_webhook_url",true)."\n";'\'''
```

### 10.4 Probar QR end-to-end (fuerza set_webhook)

```bash
docker exec -it "$WP_CONT" sh -lc \
'wp --allow-root eval '\''$svc=new \Kurukin\Core\Services\Evolution_Service();
$r=$svc->connect_and_get_qr(1);
if(is_wp_error($r)) echo "ERR: ".$r->get_error_message()."\n";
else echo "OK base64_len=".strlen($r["base64"]??"")."\n";'\'''
```

### 10.5 Probar `/config` por REST dentro de WP

```bash
docker exec -it "$WP_CONT" sh -lc "wp --allow-root eval '
\$req = new WP_REST_Request(\"GET\", \"/kurukin/v1/config\");
\$req->set_param(\"instance_id\", \"javierquiroz\");
\$req->set_header(\"x-kurukin-secret\", defined(\"KURUKIN_API_SECRET\") ? KURUKIN_API_SECRET : \"\");
\$res = rest_do_request(\$req);
echo wp_json_encode(\$res->get_data(), JSON_PRETTY_PRINT).PHP_EOL;
'"
```

---

## 11) Troubleshooting

### 11.1 DNS interno (Docker Swarm / overlay)

Si ves `cURL error 6: Could not resolve host`, revisa:

* que WordPress y Evolution estén en la **misma network overlay**
* usa el **alias correcto** del servicio (ej: `evolution_api_v2`, no `evolution_evolution_api`)

Desde el contenedor WordPress:

```bash
docker exec -it "$WP_CONT" sh -lc '
getent hosts evolution_api_v2 || true
curl -sS -D- http://evolution_api_v2:8080/ -o /dev/null | head -n 12 || true
'
```

### 11.2 Error 400 en webhook/set (Evolution)

Causas típicas:

* Falta wrapper `webhook` (Evolution v2 lo exige)
* Evento inválido (debe ser enum permitido por esa versión)
* Falta `webhookBase64` / incompatibilidad camelCase vs snake_case

Solución:

* configurar `webhook_event_type` por stack
* payload blindado (Sección 6)
* si Evolution sigue “saneando” a false → activar enforcement DB (Sección 6.2)

### 11.3 404 en n8n con rutas dinámicas

Causa:

* Falta el router UUID. n8n exige:
  `/webhook/{ROUTER_UUID}/:vertical/:instance_id`

Solución:

* agregar `n8n_router_id` al stack
* construir URL final con router UUID

---

## 12) Changelog (resumen de cambios recientes)

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
* **SRE/DB Fix (Evolution v2.3.7)**:

  * trigger Postgres `trg_enforce_base64` + función `force_webhook_base64()` para forzar `"webhookBase64"=true` en toda escritura

---

## 13) Seguridad (nota operativa)

* `/config` requiere `x-kurukin-secret`.
* API keys de OpenAI/ElevenLabs se guardan cifradas (AES-256-CBC) y se desencriptan al servir config.
* Las credenciales de Evolution se entregan a n8n porque n8n actúa como “worker/orchestrator” por tenant.

---

## 14) Próximos pasos sugeridos (roadmap corto)

* Validación automática de “stack health” (ping Evolution/n8n) antes de asignar.
* UI Admin para editar `kurukin_infra_stacks` en vez de WP-CLI.
* Capacidad/quotas por stack + métricas (round-robin ponderado).
* Cache de config `/config` por tenant (con invalidación por meta update).
* Evaluar upgrade de Evolution API cuando upstream corrija el saneamiento de `webhookBase64`.

