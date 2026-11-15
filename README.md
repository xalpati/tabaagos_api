<div align="center">

# ⚙️ Tabapagos API

### Backend de identidad, cuentas bancarias, cobros y eventos

**Laravel · PHP · MySQL · Webhooks · Entrega push**

</div>

> 🚧 **En desarrollo — fase de diseño.** El repositorio público `tabapagos_api` está inicializado. Todavía no contiene una aplicación Laravel ejecutable, migraciones o endpoints disponibles.

## 🧭 Explorar

[Propósito](#-propósito) · [Responsabilidades](#-responsabilidades-previstas) · [Pagos](#-confirmación-de-pagos) · [Datos](#-datos-y-límites) · [Tecnología](#-tecnología) · [Desarrollo](#-desarrollo)

## 🎯 Propósito

Este servicio proporcionará las funciones remotas necesarias para [Tabapagos Android](https://github.com/xalpati/tabapagos), el punto de venta para teléfonos y tabletas, y para su sitio de presentación y descarga.

La API, la aplicación Android y la landing Vue tienen **repositorios independientes**. Este repositorio se dedica al backend Laravel/PHP y a su base de datos MySQL.

## 🧩 Responsabilidades previstas

| Módulo | Responsabilidad |
|---|---|
| 🔐 Identidad | Validar el acceso Google y emitir sesiones propias |
| 📱 Dispositivos | Asociar instalaciones autorizadas y destinos push al propietario |
| 🏦 Cuentas bancarias | Registrar una cuenta por usuario y conservar su estado de activación |
| 💳 Intentos de cobro | Crear solicitudes CoDi QR/push y relacionarlas con usuario, dispositivo y venta local |
| 📩 Webhook Pey | Recibir, autenticar y persistir eventos del proveedor |
| 🔔 Entrega de eventos | Enviar confirmaciones firmadas con acuse y reenvío durable |
| 📥 Distribución Android | Guardar formularios previos a descarga y autorizar acceso a la APK publicada |
| 🧾 Auditoría | Conservar trazabilidad mínima y diagnósticos protegidos |

Los nombres de módulos describen el diseño previsto, no servicios implementados.

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
| Eventos, entregas y acuses | Perfil del negocio e impresoras |
| Registros y permisos de descarga | Historial local de cobros originados en ese equipo |

La API no sincronizará el catálogo ni las ventas completas entre dispositivos. Tampoco proporcionará restauración de la base comercial local.

## 📦 Soporte para descargar la APK

La landing solicitará al backend el guardado de información esencial antes de habilitar la descarga. El registro será independiente del usuario Google y de su cuenta bancaria.

El diseño contempla validación del formulario, protección contra abuso, aviso de privacidad, autorización temporal de descarga y metadatos reales de una APK de release firmada. Los campos definitivos, retención y configuración de orígenes/sesiones para el sitio separado deben cerrarse antes de publicar.

## 🔒 Seguridad prevista

- Secretos de Pey y claves privadas únicamente en el servidor.
- Validación de identidad Google y autorización por usuario/dispositivo.
- Verificación de firma sobre el cuerpo original del webhook.
- Persistencia y deduplicación de eventos y transacciones.
- Eventos propios firmados para la app, con destinatario verificado.
- Datos bancarios protegidos, errores sanitizados y registros sin credenciales.
- Credenciales, archivos de entorno y material privado de firma excluidos de Git.

Estos son requisitos de diseño; aún no hay código ni despliegue cuya seguridad haya sido comprobada.

## 🧱 Tecnología

| Componente | Elección |
|---|---|
| Framework | Laravel |
| Lenguaje | PHP |
| Base de datos | MySQL |
| Integración CoDi | Pey |
| Transporte push previsto | Firebase Cloud Messaging |
| Alojamiento objetivo | Hostinger; recursos y configuración por verificar |

Las versiones, dependencias y configuración de colas se fijarán al inicializar la aplicación.

## 🛠️ Desarrollo

```bash
git clone https://github.com/xalpati/tabapagos_api.git
cd tabapagos_api
```

El repositorio contiene README y exclusiones iniciales. La configuración del entorno, instalación, migraciones, workers y pruebas se documentará al incorporar el proyecto Laravel.

### Próximas entregas

- [ ] Base Laravel/MySQL y configuración por entorno.
- [ ] Identidad Google, sesiones y dispositivos.
- [ ] Cuentas bancarias e intentos de cobro.
- [ ] Webhook, persistencia, firma y entrega durable.
- [ ] Registro de descargas y acceso controlado a APK.
- [ ] Pruebas de integración y preparación de despliegue.
