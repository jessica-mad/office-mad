# Plan · Fase 1 (MVP)

> Propuesta para revisar antes de escribir código. Nada de esto está implementado todavía.
> Cuando des el OK, empiezo por el **Bloque 1** y te lo enseño antes de pasar al siguiente.

---

## 0. Decisiones que necesito de ti

| # | Pregunta | Mi propuesta por defecto |
|---|---|---|
| 1 | **Convenciones de MAD Suite.** No tengo acceso a su código. ¿Me pasas el archivo principal y la interfaz de módulos (o el repo)? | Si no puedes, uso `namespace MAD\Oficina`, PSR-4 con Composer, `Plugin::instance()` como singleton y `ModuleInterface` con `id()`, `register()`, `activate()` y `deactivate()`. Después lo adapto a MAD Suite. |
| 2 | ¿El repo **es** el plugin (raíz = `mad-oficina/`)? | Sí. El código del juego va en `game/` y no entra en el zip de distribución. |
| 3 | **Servidor de pruebas con HTTPS público.** Google Chat solo envía eventos (clics en tarjetas, mensajes al bot) a una URL pública. Con `wp-env` en local no llegan. | Para los bloques 1 a 3, `wp-env` en local. Para el bloque 4, un staging (subdominio de imad.pro) o un túnel (Cloudflare Tunnel). |
| 4 | ¿Tu hosting permite un **cron real** (crontab o panel)? | Sí. Lo dejo documentado: `*/5 * * * * wp cron event run --due-now`. |
| 5 | **Modelos de Claude por agente** (configurables) | Recepcionista: Haiku 5.5, rápido y barato. Secretaria de actas: Sonnet 5.5, extracción fiable. Te enseño el coste real por reunión en el registro. |

---

## 1. Limitaciones de Google que debes conocer ya

1. **App OAuth "Interna"**: es posible porque solo tú (y Liz) os conectáis. Con ella no hace falta la verificación de Google, ni siquiera para scopes restringidos (Drive completo y, en la fase 2, Gmail). Los clientes **nunca** inician sesión con Google; ven los datos a través del plugin.
2. **Meet REST API**: solo devuelve transcripciones si la **transcripción estaba activada** en esa reunión, y solo de las reuniones que **organizaste tú** (o tu dominio). Las notas de Gemini son un Google Doc aparte. Propongo detectar **por las dos vías** (API de Meet y carpeta "Meet Recordings"), con **importación manual** (pegar o subir la transcripción) como alternativa.
3. **Mover archivos de Meet** a la carpeta del cliente requiere el scope `drive` completo (restringido). Con la app Interna no hay problema. La alternativa más prudente sería crear un **acceso directo** en la carpeta del cliente en lugar de mover el original.
4. **Google Chat con usuarios externos**: es el punto más delicado. Antes del bloque 4 haré la investigación del apartado 5.7 (bot vs. publicar como tú vs. webhooks) **verificada con la documentación actual** y te la presentaré. No asumo nada todavía, porque esta política de Google ha cambiado varias veces.
5. **Bot "MAD Oficina" en Chat**: hay que configurar la app de Chat en Google Cloud (nombre, avatar y URL del endpoint) y **publicarla en tu dominio**. Para escribirte por DM, el bot necesita que lo añadas una vez (o que lo instales como administradora).
6. **Disponibilidad (freebusy)**: siempre en el momento, sin caché, como pides. Cada consulta tarda unos 300–600 ms. Es perfectamente asumible.

---

## 2. Estructura de carpetas

```
mad-oficina/
├── mad-oficina.php            # Cabecera, constantes, arranque de Plugin::instance()
├── uninstall.php              # Borra datos solo si está activado "borrar al desinstalar"
├── composer.json              # PSR-4 + woocommerce/action-scheduler + phpunit
├── .wp-env.json
├── includes/
│   ├── Core/
│   │   ├── Plugin.php          # Singleton, registro de módulos
│   │   ├── ModuleInterface.php
│   │   ├── Installer.php       # Migraciones versionadas (dbDelta)
│   │   ├── Roles.php           # mad_team, mad_client, capacidades
│   │   ├── Crypto.php          # Cifrado de tokens (sodium, clave derivada de wp-config)
│   │   ├── Visibility.php      # internal/team/client + filtros de consulta
│   │   └── Rest/               # Controlador base, permisos, rate limit
│   ├── Agents/
│   │   ├── AgentInterface.php
│   │   ├── AbstractAgent.php   # Prompt, tools, triggers, log
│   │   ├── ClaudeClient.php    # Messages API + tool use + validación con JSON Schema
│   │   └── ToolRegistry.php    # client_id siempre inyectado por el servidor
│   ├── Approvals/
│   │   ├── ApprovalService.php # Crea, resuelve (también por partes) y ejecuta
│   │   └── AutonomyPolicy.php  # ask / notify / silent, por tipo de acción
│   ├── Modules/
│   │   ├── Portal/   Google/   Meetings/   Scheduling/   Tasks/
│   │   ├── Chat/     Admin/
│   │   └── Suppliers/  Billing/  Ads/   # Solo esqueleto (fases 2 y 3)
│   └── Cli/SeedCommand.php     # wp mad-oficina seed [--reset]
├── migrations/                 # 0001_initial.php, 0002_…
├── templates/                  # Vista clásica, paneles y correos
├── assets/
│   ├── fonts/                  # Jost, Silkscreen, Playfair Display (OFL, autoalojadas)
│   ├── icons/                  # SVG pixel 12×12, crispEdges
│   ├── css/mad.css             # Tokens --y --k --c --g, puntos, tarjetas
│   └── game/                   # Build de Vite (generado)
├── game/                       # Fuente Phaser 3 + Vite + mapa de Tiled
├── languages/mad-oficina.pot
└── tests/
    ├── php/                    # PHPUnit (wp-env tests-cli)
    └── e2e/                    # Playwright: escritorio y móvil
```

---

## 3. Tablas de la fase 1

Prefijo `{$wpdb->prefix}mad_of_`. Todas llevan `created_at` y `updated_at`. Las que muestran contenido llevan `visibility ENUM('internal','team','client')`.

| Tabla | Columnas clave |
|---|---|
| `clients` | id, name, code (`MOM`, único), domains (JSON), drive_folder_id, chat_targets (JSON: tipo de mensaje → espacio o DM), tracking_sheet_id, status |
| `client_users` | client_id, wp_user_id. *Nueva*: varios usuarios WP por empresa. |
| `client_contacts` | client_id, name, aliases (JSON), role, receives_summaries |
| `contact_emails` | contact_id, email (índice único), kind (work/personal), learned_from. *Nueva*: hace rápido el cruce con los invitados y permite "aprender". |
| `team_members` | wp_user_id, name, workspace_email, chat_dm_space |
| `meetings` | client_id, calendar_event_id, conference_record, title, starts_at, recording_file_id, transcript_file_id, gemini_notes_id, summary, next_meeting, status (detected → identified → extracted → approved), visibility |
| `action_items` | client_id, meeting_id, title, description, owner (cliente/jessica/liz/mad), team_member_id, due_date, priority, status, visibility, process_id, synced_to (JSON) |
| `pending_topics` | client_id, meeting_id, text, investigable, research_note, status |
| `ideas` | client_id, meeting_id, text, resurface_at, status (dormant/due/postponed/discarded/converted), visibility |
| `processes` | client_id, area (web/ads/diseño/contenidos…), title, status, starts_at, ends_at, sort |
| `tasks` | agent, type, payload (JSON), status, attempts, scheduled_at, result. *Tareas de agentes; la cola real es Action Scheduler.* |
| `approvals` | agent, action_type, client_id, title, payload (JSON con partes), status, resolved_by, resolved_at, channel (web/chat), chat_message_name |
| `agent_log` | agent, event, client_id, model, input_tokens, output_tokens, cost_eur, data (JSON) |
| `messages` | client_id, thread, author_type (client/agent/jessica), author_id, body, channel, visibility |

`suppliers`, `expenses`, `billing_schedules`, `invoices` y `quotes` se crean en la fase 2 con su propia migración.

---

## 4. Roles y capacidades

- `mad_manage_office`: se añade a `administrator` (Jessica).
- `mad_team` (Liz): `mad_view_assigned`. Solo ve sus tareas, los briefs y archivos relacionados.
- `mad_client`: `mad_view_portal`. Todas sus consultas van filtradas por un `client_id` que se resuelve **en el servidor** a partir de `client_users`, y por `visibility = 'client'`.

**Tests obligatorios** (PHPUnit):
- Un cliente A nunca obtiene datos de B por ningún endpoint, aunque manipule IDs.
- Ningún contenido `internal` o `team` llega a un cliente.
- Liz no ve márgenes ni notas internas.
- Ninguna acción externa se ejecuta sin una `approval` resuelta (o sin autonomía configurada).

---

## 5. Endpoints REST (`/wp-json/mad-oficina/v1`)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/me` | conectado: perfil, rol, personajes y salas visibles |
| GET | `/office/state` | conectado: estados de los personajes (polling cada 25 s) |
| GET | `/portal/timeline` · `/portal/meetings` · `/portal/tasks` · `/portal/ideas` · `/portal/documents` | cliente (o admin con `?client=`) |
| POST | `/portal/chat` | cliente + rate limit diario |
| GET | `/portal/slots` | cliente: freebusy en el momento |
| POST | `/portal/meeting-requests` · `/portal/briefs` | cliente → crea una approval |
| GET | `/admin/approvals` | `mad_manage_office` |
| POST | `/admin/approvals/{id}` | aprobar, rechazar o editar, también por partes |
| POST | `/admin/meetings/{id}/process` | botón "Procesar esta reunión" |
| POST | `/admin/meetings/import` | subir o pegar una transcripción |
| POST | `/chat/events` | **público**, verificado con el JWT de Google (audiencia = número de proyecto) |

OAuth de Google: callback en una página de admin (`admin.php?page=mad-oficina-connections`) con `state` + nonce.

---

## 6. Scopes de Google (incrementales, por bloque)

| Bloque | Scopes |
|---|---|
| 1 | `openid`, `email` (solo para conectar e identificar) |
| 2 | `drive` (documentos del cliente y estructura de carpetas) |
| 3 | `meetings.space.readonly`, `documents.readonly`, `calendar.events.readonly` |
| 4 | Bot: autenticación de app (`chat.bot`, cuenta de servicio). Opcional: `chat.messages.create` si eliges publicar como tú. |
| 5 | `calendar.freebusy`, `calendar.events`, `calendar.calendarlist.readonly` |
| Fase 2 | `gmail.readonly`, `gmail.settings.basic` (filtros), `spreadsheets` |

---

## 7. Oficina (Phaser 3)

- **Escenas**: `Boot` → `Preload` → `Office` (+ `UI` superpuesta para los bocadillos).
- **Mapa** en Tiled (JSON), tiles de 16 px, zoom entero (×2/×3) con `pixelArt: true`. Ocho salas: recepción, reuniones, proyectos, estudio creativo, archivo, administración, campañas y despacho de gerencia (este último solo se dibuja abierto para la admin).
- **Personajes**: máquina de estados `idle` / `caminando` / `trabajando` / `esperando_gerencia`. Usan pathfinding sobre la capa de colisiones (EasyStar) y van a su mesa o al despacho según `/office/state`.
- **Paneles**: el juego emite `CustomEvent('mad:open-panel', {panel})`. Los paneles son HTML y CSS de MAD encima del canvas, con foco atrapado, `Esc` para cerrar y `aria-live` en el chat.
- **Vista clásica**: el mismo menú y los mismos paneles sin canvas. Es la vista por defecto en móvil y con `prefers-reduced-motion`, y siempre hay un botón para cambiar.

### Formato de spritesheet (para tus sprites)

PNG de **128 × 192 px**: 4 columnas × 6 filas de **32 × 32 px**, fondo transparente.

| Fila | Contenido | Frames |
|---|---|---|
| 0 | Caminar ↓ | 4 |
| 1 | Caminar ← | 4 |
| 2 | Caminar → | 4 |
| 3 | Caminar ↑ | 4 |
| 4 | Idle ↓ ← → ↑ | 1 por dirección |
| 5 | Sentado ↓ ← → ↑ | 1 por dirección |

El punto de apoyo está en el centro de la base (16, 30). Un archivo por personaje: `characters/{slug}.png`. Si un PNG tiene otro tamaño, el plugin lo rechaza con un mensaje claro. Mientras tanto genero sprites provisionales con la paleta MAD.

---

## 8. Bloques y entregables

| Bloque | Entregable que te enseño |
|---|---|
| **1 · Base** | Plugin activable en `wp-env`, tablas, roles, menú "MAD Oficina", conexión con Google (botón Conectar, token cifrado), pantallas Clientes y Equipo, `wp mad-oficina seed` (3 clientes ficticios, contactos, Liz, tareas, ideas y reuniones), tests de permisos y aislamiento. |
| **2 · Oficina y portal** | `/oficina` con escena, personajes, recepcionista con chat (Claude), línea de tiempo, Drive, reuniones, tareas e ideas. Vista clásica. Capturas de escritorio y móvil. |
| **3 · Secretaria de actas** | Detección e importación, identificación del cliente (código → emails → nombres → preguntar y aprender), extracción con JSON Schema, borrador en la bandeja, aprobación por partes, creación de tareas e ideas. |
| **4 · Google Chat** | Primero el informe de 5.7. Después, el bot con DM a ti y tarjetas con botones, las tareas a Liz y el resumen al cliente (o por email como alternativa). |
| **5 · Agenda y seguimiento** | Huecos reales, creación con Meet tras aprobación, interpretación de lenguaje natural, recordatorios, banco de ideas y resumen de los lunes. |

Commits pequeños, uno por pieza, en la rama de trabajo.
