# Kurukin Core (kurukin-core)

Plugin “control plane” para Kurukin SaaS sobre WordPress.  
Este plugin desacopla **Usuario (WP User)** de su **Infraestructura (Tenant/Stack)** y orquesta dinámicamente:

- **Evolution API** (WhatsApp Baileys)
- **n8n** (webhooks por vertical + tenant)
- **MemberPress** (control de acceso/pago)
- **REST API** para que n8n consuma configuración multi-tenant

---

## 1) Conceptos clave

### Tenant (saas_instance)
Cada usuario tiene un CPT `saas_instance` (un “tenant record”) donde se guarda el ruteo y configuración:

- `instance_id` (nombre de la instancia en Evolution)
- endpoint/apikey por tenant (multi-tenant)
- vertical de negocio
- webhook URL hacia n8n
- evento de Evolution permitido por stack
- router UUID obligatorio de n8n (para rutas dinámicas)

**Fuente de verdad del ruteo:** `wp_postmeta` del `saas_instance`.

### Stack (Infra Registry)
Los stacks de infraestructura viven en `wp_options` como JSON:

- `kurukin_infra_stacks` (lista de stacks)
- `kurukin_infra_rr_pointer` (puntero round-robin por vertical)

**Fuente de verdad global:** `wp_options`.

---

## 2) Flujo de Provisioning (alto nivel)

1. Al login o evento de provisioning, el plugin asegura que exista un `saas_instance` para el usuario.
2. Se asigna un stack por vertical (round-robin) y se persisten metadatos críticos.
3. `Evolution_Service` hace el “birth protocol”:
   - asegura que la instancia existe (check → create si hace falta)
   - configura webhook en Evolution hacia n8n (payload v2 con wrapper `webhook`)
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
    "evolution_apikey": "YOUR_GLOBAL_OR_STACK_KEY",

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
* `n8n_webhook_base` *(string)*: host interno de n8n (sin path o con path legacy; el plugin lo normaliza).
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

* `_kurukin_n8n_webhook_url` → (puede venir legacy con `/webhook/<vertical>`, el servicio la limpia al construir URL final)
* `_kurukin_n8n_router_id` → `e699da51-...`
* `_kurukin_evolution_webhook_event` → `MESSAGES_UPSERT`

**Nota:**
El plugin asegura que `*_webhook_event` exista aunque el tenant esté “pinned” (endpoint/apikey/webhook ya definidos). Esto no cambia ruteo, solo completa la config necesaria para Evolution v2.

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

### Nota sobre “base legacy”

Si `_kurukin_n8n_webhook_url` trae algo como:

`http://n8n-v2...:5678/webhook/multinivel`

El código normaliza recortando todo lo que esté a partir de `/webhook/` para evitar duplicados.

---

## 6) Evolution Webhook Payload (v2.x)

Evolution v2.3.7 requiere wrapper `webhook` y eventos en enum válido.

Payload:

```json
{
  "webhook": {
    "enabled": true,
    "url": "http://n8n.../webhook/<router_uuid>/<vertical>/<instance_id>",
    "webhookByEvents": false,
    "events": ["MESSAGES_UPSERT"],
    "webhookBase64": true
  }
}
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

### 7.2 Connection API (QR y estado)

Existe controlador dedicado para:

* estado de conexión
* obtener QR “smart”
* reset

(Ver: `includes/api/class-kurukin-connection-controller.php`)

---

## 8) Dependencias / Integraciones

* **MemberPress**: si está activo, se valida que el usuario esté activo antes de entregar `/config`.
* **Evolution API**: servicio externo (contenedor/stack). Se usa `apikey` por request.
* **n8n**: recibe webhooks con router UUID + variables.

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

### 10.2 Ver tenant actual (user_id=1)

```bash
docker exec -it "$WP_CONT" sh -lc \
'wp --allow-root eval '\''$q=new WP_Query(["post_type"=>"saas_instance","author"=>1,"posts_per_page"=>1,"fields"=>"ids"]); $id=$q->posts[0]??0; echo "TENANT_ID:$id\n";'\'''
```

### 10.3 Ver metas críticas del tenant

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

### 10.4 Probar QR end-to-end (debe devolver base64)

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
* Falta `webhookBase64: true`

Solución:

* configurar `webhook_event_type` por stack
* usar payload v2 (ver sección 6)

### 11.3 404 en n8n con rutas dinámicas

Causa:

* Falta el router UUID. n8n exige:
  `/webhook/{ROUTER_UUID}/:vertical/:instance_id`

Solución:

* agregar `n8n_router_id` al stack
* construir URL final con router UUID

---

## 12) Changelog (resumen de los últimos cambios)

* Multi-tenant real: Evolution endpoint/apikey salen del meta del tenant (no de constantes).
* `Evolution_Service`:

  * no crea a ciegas: verifica existencia antes de crear
  * payload correcto para `webhook/set` en Evolution v2 (wrapper + base64)
  * evento permitido configurable por stack (`webhook_event_type`)
  * URL n8n corregida para router UUID (`n8n_router_id`) + vertical + instance_id
  * robustez: extracción de error messages sin warnings (arrays anidados)
* `Infrastructure_Registry`:

  * soporta stacks guardados como array/JSON/serialized
  * normaliza y valida `webhook_event_type` (+ defaults)
  * normaliza verticals (incluye `general`)
* `Tenant_Service`:

  * persiste `_kurukin_evolution_webhook_event`
  * persiste `_kurukin_n8n_router_id` (cuando está disponible en stack)
* REST `/config`:

  * agrega `evolution_connection` (endpoint/apikey) dinámico por tenant (fallback legacy)

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

