# VD Social Pipeline

Plugin de WordPress para **Vermouth Deportivo**. Al publicarse una nota, genera con la **Gemini API
(Google AI)** los posteos adaptados a **X (Twitter)**, **Facebook** e **Instagram**, los deja en una cola
de aprobación en el admin y los publica en cada red (manual o automático por red).

Es un plugin **independiente**: se activa y desactiva sin afectar el resto del sitio. No requiere ni
modifica el plugin de SEO existente.

---

## Cómo funciona (flujo)

```
Publicar nota (post type "post", primera publicación)
  → job asíncrono (Action Scheduler si está; si no, WP-Cron)
    → Gemini genera JSON con las 3 variantes
      → se guardan como CPT "vd_social_post" (estado: pendiente)
        → modo revisión: aparecen en "Cola de redes" para aprobar/editar/descartar
        → modo automático (por red): se publican solas
          → publicador por red (X API v2 / Meta Graph API)
            → log del resultado + reintentos con backoff
```

La generación y la publicación **nunca** corren dentro del request de publicación de la nota: siempre en
background. Si Gemini falla, la nota se publica igual en el sitio; el error queda visible en la cola.

---

## Instalación

1. Subí el ZIP en **Plugins → Añadir nuevo → Subir plugin** (o copiá la carpeta `vd-social-pipeline/`
   a `wp-content/plugins/`).
2. Activá **VD Social Pipeline**. En la activación se crean la opción de config y la tabla de log.
3. Andá a **Social Pipeline → Ajustes**, cargá credenciales y activá el pipeline.
4. (Recomendado) Instalá el plugin **Action Scheduler** o cualquier plugin que lo incluya (WooCommerce,
   por ejemplo) para un procesamiento de background más robusto. Si no está, se usa WP-Cron
   automáticamente — que depende de que el sitio reciba visitas o de un cron real del sistema.

---

## Configuración

**Social Pipeline → Ajustes**:

- **Pipeline**: toggle global + categorías excluidas.
- **Gemini**: API key + modelo. Botón *Probar conexión*.
- **Redes activas**: activar/desactivar X, Facebook, Instagram. Si desactivás una red, no se
  genera su variante ni se publica por API. Útil para **apagar X** y evitar el costo de su API
  (US$ por publicación); podés seguir usando Facebook e Instagram.
- **Auto-publicación por red**: X / Facebook / Instagram (default: apagado en las tres).
- **X (OAuth 1.0a)**: Consumer Key/Secret + Access Token/Secret. *Probar conexión*.
- **Meta**: Page ID (Facebook), IG User ID (Instagram), Access Token de larga duración, versión de la
  Graph API. *Probar conexión* para Facebook e Instagram por separado.

### Modelo de Gemini (dónde cambiarlo)

El modelo por defecto es **`gemini-3.5-flash`**. Google da de baja versiones seguido; si empieza a
fallar la generación, cambialo en **Ajustes → Gemini → Modelo**. También podés fijarlo por código en el
default de [`includes/class-options.php`](includes/class-options.php) (clave `gemini_model`).

Endpoint usado:
`POST https://generativelanguage.googleapis.com/v1beta/models/{modelo}:generateContent`, con la API key
en el header `x-goog-api-key` y **salida estructurada nativa** (`responseMimeType: application/json` +
`responseSchema`).

---

## Cómo obtener las credenciales

### Gemini (Google AI Studio) — gratis

1. Entrá a https://aistudio.google.com → **Get API key**.
2. Creá una API key y pegala en Ajustes (o mejor, como constante — ver *Seguridad*).
3. El nivel gratuito tiene **cuotas por proyecto**; en días de mucha publicación puede dar **429**. El
   plugin lo maneja con backoff y reintentos diferidos.

### X (Twitter) — API v2, OAuth 1.0a user context

1. Entrá al **Developer Portal** de X (https://developer.x.com) y creá un **Project + App**.
2. En la App, activá **User authentication** con permisos **Read and Write**.
3. Generá las 4 credenciales: **API Key** (consumer key), **API Key Secret** (consumer secret),
   **Access Token** y **Access Token Secret** (con permisos de escritura).
4. Cargalas en Ajustes → X.
   - > ⚠️ Los límites y el precio de la API de X cambian seguido. Verificá tu plan vigente: el tier
     > gratuito/básico puede limitar fuerte el volumen de tweets. El plugin maneja el **429** con backoff,
     > pero si tu plan no permite escribir, la publicación fallará. Ajustá el volumen en consecuencia.

*(Alternativa OAuth 2.0 con PKCE: es válida pero requiere un flujo de refresh de tokens; este plugin
está implementado con OAuth 1.0a, que no necesita refresh y encaja con las 4 credenciales de arriba.)*

### Facebook (página) — Meta Graph API

1. Creá una app en **Meta for Developers** (https://developers.facebook.com).
2. Pedí los permisos **`pages_manage_posts`** y **`pages_read_engagement`**.
3. Obtené un **Page Access Token de larga duración** de la página donde vas a publicar (Graph API
   Explorer → intercambiar el token de usuario por uno de página de larga duración).
4. Cargá el **Page ID** y el **Access Token** en Ajustes → Meta.

Se publica con `POST /{page-id}/feed` (`message` + `link`).

### Instagram (cuenta profesional vinculada a la página)

1. La cuenta de IG debe ser **profesional** y estar **vinculada** a la página de Facebook.
2. Permiso adicional: **`instagram_content_publish`**.
3. Obtené el **IG User ID** (Graph API: `/{page-id}?fields=instagram_business_account`).
4. Se usa el **mismo Access Token de Meta**. Cargá el **IG User ID** en Ajustes → Meta.

Publicación en dos pasos: `POST /{ig-user-id}/media` (con `image_url` = imagen destacada tamaño *large*
+ `caption`) y luego `POST /{ig-user-id}/media_publish`. Antes de publicar se consulta
`content_publishing_limit`; si se alcanzó el límite diario, se reintenta más tarde automáticamente.

> Requisitos de imagen: la imagen destacada debe ser una **URL pública**. Si no cumple la relación de
> aspecto de IG, la creación del media puede fallar y queda registrado en el historial.

---

## Seguridad — credenciales por constante (recomendado)

Cualquier credencial puede definirse en `wp-config.php`. **La constante tiene prioridad** sobre lo
guardado en la base de datos, y en Ajustes el campo se muestra como *"Definido por constante"*.

```php
// wp-config.php
define( 'VD_GEMINI_API_KEY',          '...' );
define( 'VD_X_CONSUMER_KEY',          '...' );
define( 'VD_X_CONSUMER_SECRET',       '...' );
define( 'VD_X_ACCESS_TOKEN',          '...' );
define( 'VD_X_ACCESS_TOKEN_SECRET',   '...' );
define( 'VD_META_PAGE_ID',            '...' );
define( 'VD_META_IG_USER_ID',         '...' );
define( 'VD_META_ACCESS_TOKEN',       '...' );
```

Otras medidas ya implementadas: nonces + checks de capability en todas las acciones del admin, sanitizado
del texto editado antes de enviarlo a las APIs, escape en toda la salida, y **nunca** se imprimen ni se
loguean las credenciales (los tokens en mensajes de error se redactan).

---

## UTM

Toda URL publicada lleva:

```
?utm_source={red}&utm_medium=social&utm_campaign=pipeline&utm_content={post_id}
```

`{red}` = `x`, `facebook` o `instagram`. Se arma en [`includes/class-utm.php`](includes/class-utm.php).

> **Nota de diseño:** el link **no** lo escribe Gemini (los LLM suelen romper las URLs y perder UTM). El
> texto se genera sin URL y el plugin **agrega el link con los UTM correctos al publicar** en X y
> Facebook. En Instagram no va link en el caption (cierra con "Nota completa en el link de la bio").

---

## Reintentos y logging

- Publicación fallida: hasta **2 reintentos** con backoff (1 min, 5 min). Si falla definitivamente, la
  variante queda en estado **error** en la cola con el mensaje de la API.
- **Historial**: pestaña con log rotativo (tabla `{prefix}vd_social_log`, se conservan las últimas 1000
  filas) — timestamp, red, nota, resultado, código y mensaje.
- **Anti-duplicados**: si ya existe una variante `publicado` para esa nota y esa red, no se vuelve a
  publicar aunque la nota se actualice. Y una nota ya procesada no regenera posteos.

---

## Estructura del código

```
vd-social-pipeline/
├── vd-social-pipeline.php        Bootstrap (constantes, autoloader, hooks de activación)
├── uninstall.php                 Limpieza total al desinstalar
├── includes/
│   ├── class-autoloader.php      Autoloader por mapa explícito
│   ├── class-plugin.php          Orquestador (enrutado por contexto)
│   ├── class-activator.php       Activación (defaults + tabla de log)
│   ├── class-options.php         Config en una sola option
│   ├── class-credentials.php     Constante > option para secretos
│   ├── class-cpt.php             CPT vd_social_post (no público)
│   ├── class-variant.php         Modelo/CRUD de variantes
│   ├── class-utm.php             Armado de URLs con UTM
│   ├── class-logger.php          Log rotativo en tabla propia
│   ├── class-scheduler.php       Action Scheduler / WP-Cron
│   ├── class-pipeline.php        Trigger al publicar la nota
│   ├── class-generator.php       Gemini → variantes (background)
│   ├── class-gemini-client.php   Cliente Gemini (salida estructurada, 429)
│   ├── class-oauth1.php          Firma OAuth 1.0a para X
│   └── publishers/
│       ├── class-meta-error.php
│       ├── class-publish-manager.php   Dispatch + reintentos + anti-duplicados
│       ├── class-x-publisher.php
│       ├── class-facebook-publisher.php
│       └── class-instagram-publisher.php
└── admin/
    ├── class-admin-menu.php      Menú + assets condicionados
    ├── class-settings.php        Settings API (sanitize global)
    ├── class-queue.php           Acciones de la cola (aprobar/descartar)
    ├── class-connection-test.php "Probar conexión" (admin-ajax)
    ├── views/                    queue.php, settings.php, history.php
    ├── css/admin.css
    └── js/admin.js               Contador de caracteres + tests
```

---

## Módulo Placas (imágenes para Instagram)

Genera automáticamente, para cada nota, dos placas verticales (imagen destacada + título + marca):

- **Feed** 1080×1350 (4:5) → `placa-{post_id}.jpg`
- **Historia** 1080×1920 (9:16) → `placa-{post_id}-story.jpg`

Se guardan en `wp-content/uploads/vd-placas/{año}/{mes}/`, en JPG calidad 90.

**Cómo se generan:**
- Automático: al publicar una nota (dentro del job asíncrono del pipeline), si aún no existen.
- Manual desde el editor: metabox **"Placas de Instagram" → Generar placas** (con vista previa y descarga).
- Desde la cola: junto a la variante de Instagram, botones **Descargar** (feed/historia) y **Regenerar placas**.

**Motor:** usa **Imagick** si está disponible (mejor texto/degradados) y cae a **GD** si no. El motor
usado queda registrado en la meta y en el Historial. Requiere las fuentes **Anton** y **Bebas Neue**
(bundleadas en `assets/fonts/`, licencia OFL).

**Marcador de partido (opcional):** en el editor, metabox **"Placa de Instagram — Marcador"** con dos
campos: *Marcador* (ej. `2-1`) y *Subtítulo* (ej. `ARGENTINA – SUIZA`). Si quedan vacíos, no se dibuja
el marcador. Con títulos de 4 líneas el marcador se reduce/sube automáticamente para no colisionar.

**Ajustes (sección "Placas de Instagram"):** logo PNG opcional (si no hay, se usa el wordmark
tipográfico), color de acento, handle·dominio de la franja inferior, y mostrar/ocultar la fecha.

**Casos borde:** nota sin imagen destacada → fondo de marca + advertencia; imagen de origen < 1080 px de
ancho → se escala igual y se marca "baja resolución".

**Publicación en Instagram con la placa (preparada, apagada por default):** en Ajustes, el toggle
*"Usar la placa (feed) como imagen al publicar en Instagram"*. Con el toggle apagado, el publicador de IG
usa la imagen destacada como siempre. Con el toggle activo y credenciales de Meta cargadas, usa la URL
pública de la placa 1080×1350 (que ya cumple la relación de aspecto de la API de IG).

> **Fuentes:** si actualizás el plugin y las placas salen sin texto, verificá que existan
> `assets/fonts/Anton-Regular.ttf` y `assets/fonts/BebasNeue-Regular.ttf`.

## Fase 3 (no implementada — código preparado)

- Adjuntar imagen en X (media upload).
- Hilos de X para notas largas.
- Canales de WhatsApp y Telegram.

---

## Requisitos

- WordPress 6.0+
- PHP 7.4+
- (Opcional pero recomendado) Action Scheduler para el background.
