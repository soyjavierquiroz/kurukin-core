# Kurukin Core — SaaS Control Plane (WordPress)

Kurukin Core convierte WordPress en el **Control Plane** del SaaS: desacopla al usuario (WP User) de su infraestructura (Tenant) y orquesta el aprovisionamiento hacia **Evolution API** (WhatsApp) y **n8n** (automatizaciones) de forma **tenant-aware** y **multi-stack**.

---

## TL;DR

- Cada usuario tiene (o se le crea) un **Tenant** como CPT: `saas_instance`.
- La infraestructura **no se hardcodea**: se asigna desde un **Registry global** en `wp_options` (`kurukin_infra_stacks`) y se persiste por tenant en `post_meta`.
- Flujo de conexión WhatsApp:
  1) Verifica si la instancia ya existe (no crea a ciegas)
  2) Configura webhook en Evolution con el evento correcto (por stack/version)
  3) Solicita QR y retorna `base64` para mostrar en UI.

---

## Arquitectura (conceptos)

### User vs Tenant
- **User**: Usuario de WordPress (`wp_users`)
- **Tenant**: CPT `saas_instance` + metadatos (`wp_postmeta`)
  - Este tenant es la “unidad de infraestructura” y enrutamiento.

### Source of Truth de Infraestructura
1) **Registry global** (DevOps / Infra): `wp_options.kurukin_infra_stacks`
2) **Pinning por tenant** (runtime): `post_meta` en `saas_instance`

Regla:
- Si un tenant ya tiene endpoint/apikey/webhook, queda “pinneado” (no se sobreescribe).
- El campo de evento de webhook se **asegura** si falta (no cambia routing).

---

## Requisitos

- WordPress corriendo en Docker Swarm (u otro entorno con networking interno consistente)
- Evolution API v2.3.x (probado con `evoapicloud/evolution-api:v2.3.7`)
- n8n v2 (webhook service accesible desde Evolution y WordPress por red interna)

---

## Redes / DNS (Swarm) — MUY IMPORTANTE

En Docker Swarm, el hostname “largo” del servicio a veces no es resoluble entre stacks.  
El enfoque correcto es usar el **Alias de red**.

✅ Recomendado:
- `evolution_api_v2` (alias en la red overlay compartida)

Ejemplo verificación dentro del contenedor WordPress:
```bash
getent hosts evolution_api_v2
curl -I http://evolution_api_v2:8080/
````

Síntoma de DNS roto:

* `cURL error 6: Could not resolve host`

---

## Configuración: Registry de Infraestructura

La opción `kurukin_infra_stacks` define los stacks disponibles, verticales soportadas y el tipo de evento válido para la versión de Evolution.

### Ejemplo

```json
[
  {
    "stack_id": "evo-alpha-01",
    "active": true,
    "evolution_endpoint": "http://evolution_api_v2:8080",
    "evolution_apikey": "XXX",
    "n8n_webhook_base": "http://n8n-v2_n8n_v2_webhook:5678",
    "supported_verticals": ["multinivel", "general"],
    "webhook_event_type": "MESSAGES_UPSERT"
  }
]
```

### Notas clave

* `supported_verticals`: lista de verticales que puede atender este stack.
  Siempre se fuerza `general` como fallback.
* `webhook_event_type`:

  * Para Evolution v2.3.7+ el valor típico es: `MESSAGES_UPSERT`
  * En versiones legacy puede ser `messages.upsert` (si el stack lo soporta)
* Esta estrategia corresponde a la decisión **PRO**:

  * **Configuración explícita por stack (Registry)** para evitar heurísticas frágiles.

### Set / Get (wp-cli)

> Ejecutar dentro del contenedor WordPress con WP-CLI disponible

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

wp --allow-root option get kurukin_infra_stacks --format=json
```

---

## Tenant Meta (CPT `saas_instance`)

Metas relevantes por tenant (post_id = tenant id):

* `_kurukin_stack_id`
* `_kurukin_business_vertical` (ej: `multinivel`, `general`)
* `_kurukin_evolution_instance_id` (ej: `javierquiroz`)
* `_kurukin_evolution_endpoint` (ej: `http://evolution_api_v2:8080`)
* `_kurukin_evolution_apikey`
* `_kurukin_n8n_webhook_url` (ej: `http://n8n-v2_n8n_v2_webhook:5678/webhook/multinivel`)
* `_kurukin_evolution_webhook_event` (ej: `MESSAGES_UPSERT`) ✅

### Validación rápida

```bash
wp --allow-root eval '
$id=18;
echo "TENANT:$id\n";
echo "VERT:".get_post_meta($id,"_kurukin_business_vertical",true)."\n";
echo "N8N:".get_post_meta($id,"_kurukin_n8n_webhook_url",true)."\n";
echo "EVO:".get_post_meta($id,"_kurukin_evolution_endpoint",true)."\n";
echo "EVENT:".get_post_meta($id,"_kurukin_evolution_webhook_event",true)."\n";
'
```

### Fix de endpoint (si quedó un host viejo)

```bash
wp --allow-root post meta update 18 _kurukin_evolution_endpoint "http://evolution_api_v2:8080"
wp --allow-root post meta get 18 _kurukin_evolution_endpoint
```

---

## Flujo de Orquestación (Evolution_Service)

### Método principal

* `Kurukin\Core\Services\Evolution_Service::connect_and_get_qr($user_id)`

### Cadena de pasos

0. `ensure_instance_exists()`

   * consulta `/instance/connectionState/{instance}`
   * si existe → OK
   * si no existe → crea `/instance/create`

A) `set_webhook()` (crítico)

* Evolution v2.3.7 requiere wrapper:

  ```json
  { "webhook": { ... } }
  ```
* `events` debe coincidir con enum permitido (por stack/version):

  * `MESSAGES_UPSERT` (v2.3.7)

B) `instance/connect/{instance}`

* retorna QR base64 cuando esté listo (con reintentos)

---

## Endpoints REST (UI)

Archivo: `includes/api/class-kurukin-connection-controller.php`

Rutas:

* `GET /wp-json/kurukin/v1/connection/status`
* `GET /wp-json/kurukin/v1/connection/qr`
* `POST /wp-json/kurukin/v1/connection/reset`

Permisos:

* requiere `is_user_logged_in()`

---

## Operación / Runbook (soporte)

### Checklist (orden recomendado)

1. DNS interno:

```bash
getent hosts evolution_api_v2
curl -I http://evolution_api_v2:8080/
```

2. Registry:

```bash
wp --allow-root option get kurukin_infra_stacks --format=json
```

3. Tenant meta:

* endpoint / apikey / webhook_url / webhook_event

4. Probar estado y QR:

```bash
wp --allow-root eval '
$svc=new \Kurukin\Core\Services\Evolution_Service();
var_export($svc->get_connection_state(1)); echo PHP_EOL;
$r=$svc->connect_and_get_qr(1);
if(is_wp_error($r)) echo "ERR: ".$r->get_error_message().PHP_EOL;
else echo "OK base64_len=".strlen($r["base64"]??"").PHP_EOL;
'
```

### Síntomas → causa común

* `cURL error 6 could not resolve host` → hostname equivocado / redes no compartidas
* `403 name already in use` → instancia ya existe (no es fatal si se hace existence-check)
* `400 requires property "webhook"` → payload incorrecto (falta wrapper)
* `400 events enum` → `webhook_event_type` incorrecto para esa versión de Evolution

---

## Estructura de archivos relevantes

* `includes/services/class-infrastructure-registry.php`

  * Lee/normaliza stacks, valida `webhook_event_type`
* `includes/services/class-tenant-service.php`

  * Crea tenant, persiste routing + `_kurukin_evolution_webhook_event`
* `includes/services/class-evolution-service.php`

  * Orquesta instance existence, webhook set, connect QR
* `includes/api/class-kurukin-connection-controller.php`

  * Endpoints REST para UI

---

## Notas de Seguridad

* `evolution_apikey` y cualquier secreto debe tratarse como **secreto**.
* Evitar loggear apikey completa. Si se imprime, truncar.

---

## Roadmap (siguiente paso recomendado)

* UI que consuma `GET /connection/qr` y renderice QR base64
* Observabilidad:

  * logs por tenant/stack
  * métricas de errores de webhook/events
* Herramienta DevOps opcional:

  * “Stack capability test” para cachear allowed events (si Evolution expone introspección)
  * fallback siempre a `webhook_event_type` del registry

---

## Licencia

Privado / Interno Kurukin
