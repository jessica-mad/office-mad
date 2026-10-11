# Bloque 1 · Base: cómo ponerlo en la web

## Qué incluye

- **Plugin MAD Oficina**, con la misma estructura que MAD Suite: una clase principal, módulos en `modules/{slug}/Module.php` y un gestor para activarlos o apagarlos.
- **Tablas** `wp_mad_of_*`: clientes, usuarios de cliente, contactos, emails, equipo, reuniones, tareas, temas pendientes, ideas, procesos, tareas de agentes, aprobaciones, registro y mensajes. Se crean o actualizan solas en cada despliegue, sin necesidad de reactivar el plugin.
- **Roles**:
  - `mad_team` (Equipo MAD),
  - `mad_client` (Cliente MAD),
  - capacidad `mad_manage_office` para las administradoras.
- **Módulo Clientes**:
  - ficha del cliente: código, dominios, carpeta de Drive y notas internas,
  - contactos con alias y emails de trabajo y personales,
  - acceso al portal (crear usuarios no envía emails),
  - borrado con confirmación,
  - pantalla de Equipo,
  - datos demo.
- **Módulo Conexiones**:
  - OAuth con Google (app Interna, solo cuentas de tu dominio, tokens cifrados),
  - estado de la clave de Claude,
  - estado de la clave de cifrado.
- **Action Scheduler** incluido en `lib/`. Si WooCommerce está activo, se usa la versión más nueva de las dos.
- **39 tests (PHPUnit)** de permisos, aislamiento entre clientes, visibilidad, cifrado, OAuth y datos demo.

## Pasos en la web

1. **Despliega la rama** con tu deployer. La carpeta del plugin puede llamarse como quieras, porque el archivo principal es `mad-oficina.php`.
2. **Añade a `wp-config.php`**, antes de `/* That's all, stop editing! */`:
   ```php
   define('MAD_OFICINA_SECRET', 'pega-aquí-64-caracteres-aleatorios'); // la pantalla Conexiones te genera uno
   define('MAD_OFICINA_ANTHROPIC_KEY', 'sk-ant-…');                    // se usará desde el bloque 2
   ```
   Pon la clave de cifrado **antes** de conectar Google. Si la cambias más tarde, tendrás que volver a conectar.
3. **Activa el plugin** en Plugins.
4. En **MAD Oficina → Módulos**, enciende **Clientes** y **Conexiones**.
5. **Google** (opcional en este bloque, imprescindible a partir del bloque 5). En **MAD Oficina → Conexiones**:
   1. En Google Cloud Console, crea un proyecto.
   2. En la pantalla de consentimiento OAuth, elige el tipo **Interna**.
   3. Crea un ID de cliente de tipo **Aplicación web** y pega como URI de redirección el que te muestra la pantalla.
   4. Guarda el Client ID y el Client secret en el plugin y pulsa **Conectar con Google**.

   Por ahora solo se piden los permisos `openid` y `email`.
6. **Datos demo** (opcional):
   - Créalos en **Clientes → Crear datos demo**: 3 clientes ficticios, cada uno con un usuario `demo_xxx@demo.invalid`.
   - Bórralos con **Borrar datos demo**. Tus datos reales no se tocan.
   - También hay comandos de WP-CLI: `wp mad-oficina seed` y `wp mad-oficina purge-demo`.

## Tests (para desarrollo)

```bash
composer install            # solo dev: phpunit + polyfills
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/office-mad vendor/bin/phpunit
```

## Notas

- **Requisitos**: el plugin pide WordPress 6.9 o superior, porque la versión incluida de Action Scheduler (4.2) lo necesita. Se ha probado con WordPress 7.1.3. Las pruebas locales se hicieron con PHP 8.3; tu servidor usa 8.4.
- **Al desinstalar**: borrar el plugin **no** borra los datos, salvo que actives la opción `madoficina_delete_data_on_uninstall`.
- **Archivos que no hacen falta en producción**: `tests/`, `docs/` y `game/`. Si tu deployer lee `.distignore`, no los subirá.
