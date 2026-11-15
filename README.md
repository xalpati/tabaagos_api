<div align="center">

# ⚙️ Tabapagos API

### Backend de identidad, cuentas bancarias, cobros CoDi y eventos push

**Laravel 13 · PHP 8.3+ · MySQL · Pey CoDi · Firebase Cloud Messaging**

</div>

> ✅ **API funcional en producción.** Servicio backend para la gestión de sesiones por dispositivo, vinculación de cuentas bancarias CLABE, emisión de cobros CoDi (QR y notificación push celular) vía Pey/Banxico, procesamiento autenticado de webhooks y entrega inmediata de eventos firmados con RS256 hacia dispositivos Android mediante Firebase Cloud Messaging (FCM).

## 🧭 Explorar

[Propósito](#-propósito) · [Repositorio Móvil](#-aplicación-móvil-android) · [Módulos](#-módulos-y-responsabilidades) · [Confirmación de Pagos](#-confirmación-de-pagos) · [Datos y Límites](#-datos-y-límites) · [Seguridad](#-seguridad) · [Tecnología](#-tecnología) · [Instalación y Contrato](#-instalación-y-contrato-móvil)

## 🎯 Propósito

Este servicio proporciona las funciones remotas de identidad, integración interbancaria CoDi y mensajería push para **[Tabapagos Android (`xalpati/tabapagos`)](https://github.com/xalpati/tabapagos)**, el punto de venta móvil para teléfonos y tabletas.

Este repositorio se dedica exclusivamente al backend en Laravel/PHP y a su base de datos MySQL.

## 📱 Aplicación Móvil Android

La aplicación cliente (POS Android en Flutter y Dart) se encuentra en su propio repositorio público:

- **Repositorio de la App Móvil**: [https://github.com/xalpati/tabapagos](https://github.com/xalpati/tabapagos)

La aplicación móvil opera de manera autónoma en cada dispositivo para el catálogo, inventario, ventas en efectivo e impresión térmica Bluetooth (58 mm y 80 mm), consumiendo esta API para la autenticación del comerciante, el registro bancario y el ciclo completo de cobros digitales CoDi.

## 🧩 Módulos y responsabilidades

| Módulo | Responsabilidad |
|---|---|
| 🔐 Identidad | Registro e inicio de sesión por correo/contraseña y Google, con emisión de tokens Sanctum por instalación |
| 📱 Dispositivos | Asociación de instalaciones autorizadas (`installation_id`) y actualización de tokens FCM al propietario |
| 🏦 Cuentas bancarias | Registro de cuenta CLABE por usuario, alta en Pey y seguimiento de su estado de activación en Banxico |
| 💳 Intentos de cobro | Creación de solicitudes CoDi QR (`CODI_QR`) y notificación push telefónica (`CODI_PUSH`) vinculadas a la venta local |
| 📩 Webhook Pey | Recepción, verificación criptográfica (Standard Webhooks) y persistencia idempotente de eventos del proveedor |
| 🔔 Entrega de eventos | Firma JWS (RS256) y despacho push inmediato vía FCM HTTP v1 con acuse (`ACK`) y reenvío durable |
| 🧾 Auditoría | Trazabilidad de intentos, transacciones del proveedor e historial de entregas |

## 💳 Confirmación de pagos

El flujo implementado desacopla la solicitud del cobro de la confirmación de acreditación bancaria:

1. La app [Tabapagos Android](https://github.com/xalpati/tabapagos) solicita crear un cobro para su venta de catálogo o cobro directo (`POST /v1/payment-attempts`).
2. Laravel valida propietario, cuenta bancaria, idempotencia e importe, y registra el intento antes de solicitar el QR o el requerimiento push a Pey (`POST /codi/qr` o `POST /codi/pn`).
3. Cuando el comprador autoriza el pago en su aplicación bancaria, Pey notifica el resultado mediante webhook (`POST /webhooks/pey`).
4. Laravel verifica la firma del webhook, persiste el evento en `webhook_inboxes`, correlaciona la transacción y despacha de inmediato un sobre JWS firmado con RS256 hacia el dispositivo de origen vía Firebase Cloud Messaging.
5. La aplicación Android recibe el push en tiempo real, reproduce la alerta auditiva, registra la venta pagada, imprime el ticket térmico y emite el acuse (`POST /v1/events/ack`).
6. Los eventos sin acuse permanecen en cola (`event_deliveries`) para su reenvío automático o al reanudar sesión (`POST /v1/events/resume`).

**Sin consultas periódicas (polling) de verificación de pagos.** La confirmación proviene de los eventos firmados originados por el webhook oficial.

### Confiabilidad implementada

| Situación | Tratamiento |
|---|---|
| Reintento de la misma solicitud | Idempotencia por dispositivo mediante cabecera `Idempotency-Key` y hash de solicitud |
| Webhook o evento duplicado | Deduplicación estricta por `webhook-id` y `transactionId` |
| Error del proveedor al crear cobro | Registro del estado y devolución de diagnóstico claro al dispositivo |
| Dispositivo temporalmente sin red | Persistencia en `event_deliveries` y reenvío al reconectar (`resume` o tarea programada) |
| Acuse repetido | Idempotencia en el endpoint `ack` sin duplicar efectos |
| Pago fuera de ventana o monto distinto | Registro de la transacción con estado `REVIEW_REQUIRED` |

## 🏦 Activación bancaria

Se gestiona una cuenta bancaria por comerciante. La validación interbancaria ante Banxico transita de `PENDING` a `ACTIVE`.

La transición a `ACTIVE` se procesa mediante el webhook `bank.account.updated` (con envío de notificación push firmada `bank_account.activated` al dispositivo) o mediante la sincronización explícita del estado bancario mientras la cuenta se encuentre pendiente de activación.

## 🗃️ Datos y límites

| En el backend (`tabapagos_api`) | En el dispositivo Android ([`tabapagos`](https://github.com/xalpati/tabapagos)) |
|---|---|
| Identidad del comerciante y dispositivos autorizados | Catálogo de productos, categorías y precios |
| Cuenta bancaria vinculada y estado CoDi | Existencias y movimientos de inventario local |
| Intentos, transacciones CoDi y referencias mínimas | Detalle completo de ventas y cobros en efectivo |
| Bandeja de webhooks, entregas JWS y acuses | Perfil del negocio, configuración de impresoras Bluetooth y cortes |

La API no almacena ni sincroniza el catálogo de productos ni el detalle de artículos vendidos entre dispositivos.

## 🔒 Seguridad

- Credenciales de Pey, secreto de webhook, cuenta de servicio de Firebase y llave privada RSA resguardados exclusivamente en el servidor.
- Autenticación mediante tokens Bearer de Laravel Sanctum vinculados al `device_id` e `installation_id`.
- Verificación de firma Standard Webhooks (`webhook-id`, `webhook-timestamp`, `webhook-signature`) sobre el cuerpo crudo de la petición.
- Eventos hacia la app firmados criptográficamente con JWS `RS256` (`kid: tabapagos-v1`).
- Cifrado en reposo para payloads de webhooks, detalles bancarios y tokens push en la base de datos.
- Respuestas de cuenta bancaria ofuscadas exponiendo únicamente los últimos 4 dígitos de la CLABE.

## 🧱 Tecnología

| Componente | Especificación |
|---|---|
| Framework | Laravel 13 |
| Lenguaje | PHP 8.3+ |
| Base de datos | MySQL |
| Proveedor CoDi | Pey (`https://api.pey.mx`) |
| Mensajería Push | Firebase Cloud Messaging (HTTP v1 API) |
| Firma de Eventos | OpenSSL RSA-SHA256 (JWS) |

## 🛠️ Instalación y Contrato Móvil

```bash
git clone https://github.com/xalpati/tabapagos_api.git
cd tabapagos_api
```

Instalación en entorno local:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# Configurar variables DB_*, PEY_* y FCM_* en .env
php artisan migrate
php artisan serve
```

### Variables de entorno principales

- `PEY_ENABLED=true`, `PEY_API_KEY` y `PEY_WEBHOOK_SECRET`: credenciales de integración con Pey. Endpoint receptor: `POST /webhooks/pey`.
- `EVENT_SIGNING_KEY_PATH` y `EVENT_SIGNING_KID`: ruta de la llave privada RSA (2048 bits) para firmar los sobres JWS enviados a la aplicación móvil.
- `FCM_PROJECT_ID` y `GOOGLE_APPLICATION_CREDENTIALS`: identificador del proyecto de Firebase y ruta al JSON de la cuenta de servicio para FCM HTTP v1.

### Rutas principales (`/v1`)

| Operación | Método y Ruta |
|---|---|
| Registro e inicio de sesión | `POST /v1/auth/register`, `POST /v1/auth/login` |
| Perfil de sesión y cierre | `GET /v1/me`, `POST /v1/auth/logout` |
| Token push FCM del dispositivo | `PUT /v1/devices/current/push-token`, `DELETE /v1/devices/current/push-token` |
| Cuenta bancaria CoDi | `GET /v1/bank-account`, `POST /v1/bank-account`, `POST /v1/bank-account/refresh` |
| Intentos de cobro CoDi (QR y Push) | `POST /v1/payment-attempts`, `POST /v1/payment-attempts/{id}/abandon` |
| Acuse y reproducción de eventos | `POST /v1/events/ack`, `POST /v1/events/resume` |
| Recepción de Webhooks Pey | `POST /webhooks/pey` |

### Verificación y calidad de código

```powershell
composer test
php vendor/bin/pint --test
composer validate --strict
```
