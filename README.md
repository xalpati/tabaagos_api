<div align="center">

# ⚙️ Tabapagos API

### Backend de identidad, cuentas bancarias, cobros y eventos

**Laravel · PHP · MySQL · Webhooks · Entrega push**

</div>

> 🚧 **Implementación inicial.** Laravel 13 con migraciones, sesiones por dispositivo, cuentas bancarias, intentos CoDi e inbox/outbox durable. Google, Pey y FCM requieren configuración y validación real antes de producción.

## 🧭 Explorar

[Propósito](#-propósito) · [Responsabilidades](#-responsabilidades-previstas) · [Pagos](#-confirmación-de-pagos) · [Datos](#-datos-y-límites) · [Tecnología](#-tecnología) · [Desarrollo](#-desarrollo)

## 🎯 Propósito

Este servicio proporcionará las funciones remotas necesarias para [Tabapagos Android](https://github.com/xalpati/tabapagos), el punto de venta para teléfonos y tabletas.

Este repositorio se dedica al backend Laravel/PHP y a su base de datos MySQL.

## 🧩 Responsabilidades previstas

| Módulo | Responsabilidad |
|---|---|
| 🔐 Identidad | Validar el acceso Google y emitir sesiones propias |
| 📱 Dispositivos | Asociar instalaciones autorizadas y destinos push al propietario |
| 🏦 Cuentas bancarias | Registrar una cuenta por usuario y conservar su estado de activación |
| 💳 Intentos de cobro | Crear solicitudes CoDi QR/push y relacionarlas con usuario, dispositivo y venta local |
| 📩 Webhook Pey | Recibir, autenticar y persistir eventos del proveedor |
| 🔔 Entrega de eventos | Enviar confirmaciones firmadas con acuse y reenvío durable |
| 🧾 Auditoría | Conservar trazabilidad mínima y diagnósticos protegidos |

Estos módulos están implementados y cubiertos por pruebas locales. Los servicios externos aún no se han validado con credenciales reales.

## 💳 Confirmación de pagos

El flujo acordado separa la solicitud de cobro de la confirmación del dinero recibido:

1. Android solicita crear un cobro para su venta local o cobro directo.
2. Laravel valida propietario, cuenta activa e importe, y registra el intento antes de enviarlo a Pey.
3. Pey notifica el resultado mediante webhook.
4. Laravel valida el evento, lo guarda y genera un evento propio firmado para el dispositivo de origen.
5. La app verifica y aplica el evento localmente; después envía el acuse.
6. Los eventos sin acuse se conservan para reenvío cuando el dispositivo vuelva a estar disponible.

**Sin consultas periódicas de verificación de pagos.** La reproducción de eventos pendientes recupera hechos ya recibidos por el webhook; no obtiene confirmaciones nuevas consultando al proveedor.

### Confiabilidad que debe implementarse

| Situación | Tratamiento previsto |
|---|---|
| Reintento de la misma solicitud | Identidad estable e idempotencia propia |
| Webhook o evento duplicado | Aplicación una sola vez |
| Respuesta perdida al crear un cobro | Conservar estado incierto y evitar repetir automáticamente el cobro |
| Dispositivo desconectado | Persistencia de eventos y reenvío posterior |
| Acuse perdido | Reenvío tolerado sin duplicar el ingreso |
| Pago tardío o adicional | Conservar la transacción y llevarla a revisión cuando corresponda |

La idempotencia propia no implica que Pey garantice idempotencia al crear cobros. Cancelar una venta local tampoco ejecuta una devolución ni invalida automáticamente un QR del proveedor.

## 🏦 Activación bancaria

Se contempla una cuenta bancaria por usuario. Solo una cuenta **ACTIVE** habilita crear cobros CoDi.

La activación se notificará por webhook y evento push firmado. Al abrir la app podrá consultarse el estado bancario únicamente mientras exista una cuenta registrada sin validar/activar. Después de persistir ACTIVE, se omitirán las consultas de apertura posteriores.

Esta consulta bancaria es independiente de la confirmación de pagos.

## 🗃️ Datos y límites

| En el backend | En el dispositivo Android |
|---|---|
| Identidad y dispositivos autorizados | Catálogo y precios |
| Cuenta bancaria y estado | Existencias y movimientos de inventario |
| Intentos, transacciones y referencias mínimas de cobro | Detalle completo de las ventas y ventas en efectivo |
| Eventos, entregas y acuses | Perfil del negocio, impresoras e historial local de cobros |

La API no sincronizará el catálogo ni las ventas completas entre dispositivos. Tampoco proporcionará restauración de la base comercial local.

## 🔒 Seguridad prevista

- Secretos de Pey y claves privadas únicamente en el servidor.
- Validación de identidad Google y autorización por usuario/dispositivo.
- Verificación de firma sobre el cuerpo original del webhook.
- Persistencia y deduplicación de eventos y transacciones.
- Eventos propios firmados para la app, con destinatario verificado.
- Datos bancarios protegidos, errores sanitizados y registros sin credenciales.
- Credenciales, archivos de entorno y material privado de firma excluidos de Git.

Los controles del servidor se comprueban con pruebas locales. Falta validación real de las integraciones y una revisión de seguridad antes de producción.

## 🧱 Tecnología

| Componente | Elección |
|---|---|
| Framework | Laravel |
| Lenguaje | PHP |
| Base de datos | MySQL |
| Integración CoDi | Pey |
| Transporte push previsto | Firebase Cloud Messaging |
| Alojamiento objetivo | Hostinger; recursos y configuración por verificar |

PHP 8.3 o posterior, Composer 2 y MySQL. Dependencias fijadas en `composer.lock`. Inbox/outbox procesados por scheduler, con reintentos persistidos en MySQL.

## 🛠️ Desarrollo

```bash
git clone https://github.com/xalpati/tabapagos_api.git
cd tabapagos_api
```

Instalación local con PowerShell, sobre una base exclusiva del proyecto:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configurar DB_* en .env antes de migrar.
php artisan migrate
php artisan serve
```

Ejecutar `php artisan schedule:work` en otra terminal. En el servidor, configurar cron para `php artisan schedule:run` cada minuto. `php artisan events:dispatch` procesa un lote manualmente; no consulta pagos en Pey.

### Configuración externa

- `GOOGLE_CLIENT_ID`: cliente autorizado para validar ID tokens; la identidad se obtiene de `sub` verificado.
- `PEY_ENABLED=false` por defecto. Activar solo después de configurar `PEY_API_KEY`, `PEY_API_KEY_ID` y `PEY_WEBHOOK_SECRET` y autorizar el uso real. Webhook: `POST /api/webhooks/pey`.
- `EVENT_SIGNING_KEY_PATH`: ruta absoluta de clave privada RSA de al menos 2048 bits fuera de Git y `public/`. `EVENT_SIGNING_KID` identifica la clave pública confiable distribuida en Android. Eventos firmados con RS256; definir rotación antes de operar.
- `FCM_PROJECT_ID` y `GOOGLE_APPLICATION_CREDENTIALS`: proyecto Firebase y ruta absoluta de la cuenta de servicio autorizada para FCM HTTP v1. Mantener el archivo fuera de Git y `public/`.
- Comprobar HTTPS, reloj sincronizado, MySQL, cron y backups en el alojamiento. Conservar `APP_KEY`, pues cifra datos persistidos. `APP_DEBUG=false`.

### Contrato móvil

Prefijo `/api/v1`. `POST /auth/google` recibe `id_token` e `installation_id` UUID y devuelve `access_token`, `user_id`, `device_id`. Las demás rutas requieren bearer token. Duración configurable con `SESSION_TOKEN_DAYS` (30 por defecto); un nuevo login revoca el token previo de esa instalación. El cambio de dueño de una instalación queda bloqueado hasta definir un flujo explícito.

| Operación | Ruta relativa |
|---|---|
| Identidad y salida | `GET /me`, `POST /auth/logout` |
| Destino FCM | `PUT/DELETE /devices/current/push-token` |
| Cuenta bancaria | `GET/POST /bank-account`, `POST /bank-account/refresh` |
| Crear o abandonar cobro | `POST /payment-attempts`, `POST /payment-attempts/{id}/abandon` |
| Acuse y reproducción | `POST /events/ack`, `POST /events/resume` |

Alta bancaria: `alias`, `name`, `clabe` con verificador válido y `entity` (`PERSON`/`BUSINESS`). Respuestas con últimos cuatro dígitos, sin CLABE completa. Consulta al proveedor solo antes de ACTIVE.

Alta bancaria y cobros exigen `Idempotency-Key` de 8–128 caracteres alfanuméricos, guion o guion bajo. Misma clave/contenido recupera el recurso; contenido distinto devuelve 409. Una respuesta externa incierta se conserva sin repetir el POST. Abandonar no cancela el recurso Pey.

Cobros: `local_sale_id` UUID, `origin` (`CATALOG`/`DIRECT`), `amount_minor` entero en centavos MXN, `concept` y `method` (`CODI_QR`/`CODI_PUSH`). QR exige `qr_mode=per_sale`; push exige `phone` de diez dígitos. Caducidad externa de 600 segundos. QR fijo pendiente de definición. No enviar artículos ni ventas completas. Crear un intento no confirma dinero recibido.

El push lleva el evento completo como JWS RS256 en `message.data.event`. ACK: `event_id`, `delivery_id`, `result` (`APPLIED`/`STORED_FOR_REVIEW`), después de persistir en Android. Resume devuelve solo aceptación. FCM aceptado no equivale a ACK. Se conserva el evento y se reenvía hasta su acuse; eventos sin relación quedan pendientes para revisión.

### Verificación

```powershell
composer test
php vendor/bin/pint --test
composer validate --strict
```

24 pruebas / 81 comprobaciones verificadas con SQLite efímero y MySQL local. Para probar MySQL, definir las variables `DB_*` del proceso sobre una base **exclusiva de pruebas**: `RefreshDatabase` ejecuta migraciones. Las respuestas externas son simuladas; no se realizan cobros reales.

Pendientes: validación real Google/Pey/FCM, integración Android, alojamiento, operación/retención y concurrencia con múltiples procesos. Sin despliegue de producción.
