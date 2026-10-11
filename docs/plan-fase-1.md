# Plan · Fase 1 (MVP) · v2

> Propuesta para revisar antes de escribir código.
> v2: incorpora tus respuestas (convenciones de MAD Suite, Apps Script para Meet, bot con nombre propio, despliegue directo en la web y modelos aprobados).

---

## 0. Decisiones tomadas

| Tema | Decisión |
|---|---|
| **Convenciones** | Las mismas que en [`jessica-mad/plugin-mad`](https://github.com/jessica-mad/plugin-mad). Detalle en la sección 2. |
| **Despliegue** | Sin staging: trabajamos directamente en la web. Por eso: (a) todos los módulos vienen **desactivados** al instalar, como en MAD Suite, y los activas uno a uno; (b) **sin paso de build en el servidor**: el juego compilado (`assets/game/`) y cualquier librería van incluidos en el repo; (c) migraciones idempotentes, que nunca borran datos al actualizar. |
| **Reuniones de Meet** | Tu idea: un **Apps Script** vigila la carpeta de grabaciones, mueve lo nuevo, apunta una fila en un Sheet y avisa a la web. Detalle en la sección 4. |
| **Grabaciones para clientes** | Se comparten en Drive (lector) con los emails de los contactos del cliente. La **transcripción** solo se comparte si lo marcas al aprobar, porque puede contener comentarios internos. |
| **Chat** | Un **bot propio (app de Chat)** con nombre y avatar propios ("MAD Oficina"). Cada mensaje lleva en la cabecera de la tarjeta el personaje que habla (Secretaria, Coordinadora…). Queda pendiente verificar los **espacios con externos** antes del bloque 4. |
| **Modelos** | Recepcionista: Haiku 5.5. Secretaria de actas: Sonnet 5.5. Configurables por agente. |
| **Endpoint público** | Como la web ya tiene HTTPS público, Google Chat y Apps Script pueden llamarla directamente. No hace falta túnel. |

---

## 1. Limitaciones de Google (actualizado)

1. **App OAuth "Interna"** en el plugin: la usaremos solo para **Calendar** (disponibilidad en tiempo real y crear reuniones con Meet, bloque 5). Drive y Meet van por Apps Script, que funciona como tú, así que el plugin **no necesita permisos de Drive** en la Fase 1.
2. **Transcripciones**: solo existen si la transcripción o las notas de Gemini estaban activadas en esa reunión. Si una grabación llega sin transcripción, el script la registra igual y la web te avisa ("Reunión sin transcripción: ¿la pegas a mano?").
3. **Compartir con clientes**: tu Workspace debe permitir compartir fuera del dominio, y el cliente tiene que tener una cuenta de Google con ese email. Si no la tiene, la grabación se ve desde el portal con un enlace, sin acceso al archivo.
4. **Bot en espacios externos**: es lo único que todavía no doy por hecho. Lo verifico con la documentación actual antes del bloque 4. Si el bot no pudiera entrar en espacios de clientes de otros dominios, la alternativa es: **bot para ti y para Liz**, y para los clientes, mensajes publicados **como tú** (autenticación de usuario) o por email.
5. **Bot por DM**: tienes que añadir al bot una vez desde Chat, o instalarlo como administradora, para que pueda escribirte.

---

## 2. Convenciones (copiadas de MAD Suite)

- **Archivo principal** `mad-oficina.php` con `final class MAD_Oficina_Core`, singleton con `instance()`, constantes `CAPABILITY`, `MENU_SLUG_ROOT = 'mad-oficina'` y `ENABLED_MODULES_OPTION = 'madoficina_enabled_modules'`.
- **Interfaz `MAD_Oficina_Module`**, que replica `MAD_Suite_Module` (`slug()`, `title()`, `menu_label()`, `menu_slug()`, `init()`, `admin_init()`, `render_settings_page()`, y opcionalmente `description()` y `required_plugins()`). Añade tres métodos opcionales:
  - `install()`, para las tablas del módulo,
  - `rest_routes()`,
  - `dependencies()`, porque `meetings` depende de `tasks`.
- **Módulos** en `modules/{slug}/Module.php`, cada uno con `return new class($core) implements MAD_Oficina_Module`. Cada módulo tiene:
  - un autoloader propio con namespace `MAD_Oficina\{Modulo}\`,
  - las carpetas `includes/`, `views/` y `assets/`.
- **Código compartido** en `core/` (namespace `MAD_Oficina\Core\`): tablas, roles, cifrado, aprobaciones, cliente de Claude y agentes.
- **Opciones** en `madoficina_{slug}_settings`. Página raíz "MAD Oficina" con el gestor de módulos (activar y desactivar), igual que "MAD Plugins".
- **Mejoras respecto a MAD Suite** (te las propongo, no las impongo):
  - El cifrado de tokens usa `sodium` con una clave propia (`MAD_OFICINA_SECRET` en `wp-config.php`). El de MAD Suite tiene una clave de reserva fija en el código que conviene evitar.
  - No hay archivos de depuración accesibles por URL (como `debug-mcs.php`). La depuración va dentro del admin.

```
office-mad/                       # = carpeta del plugin en wp-content/plugins/
├── mad-oficina.php                # Core singleton + interfaz + bootstrap
├── uninstall.php
├── core/                          # Installer, Roles, Crypto, Visibility, Rest, Approvals, Agents, Claude
├── modules/
│   ├── portal/   tasks/   meetings/   chat/   scheduling/   google/   admin/
│   └── suppliers/   billing/   ads/  # Fases 2 y 3 (aún no)
├── views/modules-manager.php
├── assets/                        # mad.css, fuentes, iconos pixel, game/ (compilado)
├── lib/action-scheduler/          # Incluida (si WooCommerce está activo, se usa la suya)
├── game/                          # Fuente Phaser + Vite + Tiled (no se ejecuta en el servidor)
├── apps-script/                   # Código del Apps Script "cartero" (con clasp)
├── languages/
└── tests/
```

---

## 3. Tablas, roles y tests

Sin cambios respecto a v1: prefijo `mad_of_`, tablas `clients`, `client_users`, `client_contacts`, `contact_emails`, `team_members`, `meetings`, `action_items`, `pending_topics`, `ideas`, `processes`, `tasks`, `approvals`, `agent_log` y `messages`. Cada módulo crea las suyas al activarse.

Cambios en `meetings`:
- nuevos campos `sheet_row` (fila del Sheet de reuniones), `drive_folder_id` y `shared_with` (JSON),
- el estado pasa a ser `received → identified → extracted → approved → archived`.

Roles: `mad_manage_office` (Jessica), `mad_team` (Liz), `mad_client`.

Tests de aislamiento entre clientes, visibilidad y aprobaciones: igual que en v1.

---

## 4. Flujo de reuniones con Apps Script ("el cartero")

```
Meet Recordings (tu Drive)
      │  Apps Script, disparador cada 10 min
      ▼
1. Detecta grabación / transcripción / notas Gemini nuevas
2. Las mueve a "MAD Oficina/Reuniones/_Entrada/AAAA-MM-DD – Título/"
3. Busca el evento de Calendar (por hora y título): invitados, título, códigos
4. Añade fila al Sheet "Reuniones MAD": fecha, título, enlaces, estado = recibida
5. POST firmado (HMAC) → /wp-json/mad-oficina/v1/hooks/meetings
      con: metadatos + invitados + TEXTO de la transcripción
      ▼
WordPress (la web)
6. Identifica cliente (código → emails → nombres → te pregunta y aprende)
7. Secretaria de actas extrae JSON → borrador en la bandeja
8. Tú apruebas (por partes, y marcas qué compartir con el cliente)
9. Deja una "orden" pendiente para el script
      ▼
Apps Script (siguiente pasada, o al instante si lo llamas)
10. Mueve la carpeta a  Cliente/Reuniones/AAAA-MM-DD – Título/
11. Comparte grabación (y transcripción si la marcaste) con los contactos
12. Actualiza la fila del Sheet: cliente, resumen rápido, estado = procesada
13. Confirma a la web → la reunión aparece en el portal del cliente
```

**Por qué así:**
- Usas un lenguaje que dominas.
- El plugin no guarda permisos sobre todo tu Drive.
- El Sheet te queda como registro aunque la web falle.
- Si algún día cambias de web, el registro sigue ahí.

**Seguridad:**
- Secreto compartido y firma HMAC con marca de tiempo (contra repeticiones).
- La web solo acepta del script datos y confirmaciones, nunca instrucciones.
- El script solo ejecuta órdenes que la web ha emitido tras una aprobación tuya.

**Alternativa manual (siempre disponible):** "Importar reunión", para pegar o subir una transcripción, y el botón "Procesar esta reunión".

---

## 5. Endpoints REST (`/wp-json/mad-oficina/v1`)

Los de v1, más estos:

| Método | Ruta | Permiso |
|---|---|---|
| POST | `/hooks/meetings` | Apps Script, firma HMAC |
| GET | `/hooks/orders` | Apps Script, firma HMAC: órdenes pendientes (mover, compartir, actualizar fila) |
| POST | `/hooks/orders/{id}/done` | Apps Script, firma HMAC |
| POST | `/chat/events` | Google Chat, JWT verificado |

---

## 6. Scopes

| Dónde | Bloque | Permisos |
|---|---|---|
| Apps Script | 3 | Drive, Calendar (lectura), Sheets, Docs (lectura). Se autoriza como tú en tu cuenta. |
| Plugin (OAuth Interna) | 5 | `calendar.freebusy`, `calendar.events`, `calendar.calendarlist.readonly` |
| App de Chat | 4 | `chat.bot` (cuenta de servicio) |
| Plugin | Fase 2 | `gmail.readonly`, etc. |

El bloque 1 incluye la pantalla **Conexiones**, con OAuth listo, aunque Calendar no se use hasta el bloque 5.

---

## 7. Oficina (Phaser 3)

Sin cambios respecto a v1:
- Escenas `Boot`, `Preload`, `Office` y `UI`.
- Mapa de Tiled con tiles de 16 px.
- Spritesheet de 128 × 192: 4 columnas × 6 filas de 32 × 32. Filas: caminar ↓ ← → ↑, idle y sentado.
- Paneles HTML encima del canvas.
- Vista clásica por defecto en móvil y con `prefers-reduced-motion`.

---

## 8. Bloques y entregables

| Bloque | Entregable |
|---|---|
| **1 · Base** | Core y gestor de módulos al estilo MAD Suite, tablas, roles, menú, pantalla Conexiones (OAuth y secretos), Clientes y Equipo, `wp mad-oficina seed`, tests. |
| **2 · Oficina y portal** | `/oficina`, escena, personajes, recepcionista con chat, línea de tiempo, documentos, reuniones, tareas e ideas. Vista clásica. |
| **3 · Secretaria de actas** | Apps Script "cartero", endpoints firmados, identificación, extracción, bandeja, aprobación por partes, órdenes de vuelta y Sheet de reuniones. |
| **4 · Google Chat** | Informe de 5.7 verificado y bot "MAD Oficina": DM contigo con tarjetas, tareas a Liz y resumen al cliente. |
| **5 · Agenda y seguimiento** | Freebusy en tiempo real, reuniones con Meet tras aprobación, recordatorios, banco de ideas y resumen de los lunes. |
