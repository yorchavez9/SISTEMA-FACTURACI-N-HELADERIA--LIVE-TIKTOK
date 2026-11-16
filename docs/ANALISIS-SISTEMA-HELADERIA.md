# Sistema de Heladería con Facturación Electrónica – Documento de Análisis (v1)

> Stack: Laravel · Inertia.js · React (TypeScript) · MySQL · Tailwind CSS · shadcn/ui
> Fuente analizada: `3. API FACTURACION SUNAT PERU ✅✅✅✅✅.postman_collection.json`
> Fecha: 2026-11-16

---

## 1. Resumen de la solución propuesta

Un **punto de venta (POS) web** para una heladería pequeña o mediana, centrado en que un cajero registre una venta en pocos segundos, con emisión opcional de **boleta o factura electrónica** a través de un proveedor externo (API REST `https://sunat.kodevo.es/api/v1`).

Principios:

1. **La venta nunca depende de SUNAT.** La venta se guarda primero en la base de datos local. El comprobante electrónico es un proceso aparte que puede reintentarse.
2. **Un solo local, una caja y pocos roles** (administrador y cajero). Sin multiempresa ni almacenes.
3. **Los reportes salen de la base de datos local**, porque incluyen las ventas sin comprobante. Los endpoints de reportes del proveedor no se usan.
4. **La integración de facturación es un módulo aislado** (`app/Integrations/Facturacion`) con un cliente HTTP, un generador de payloads y un servicio. Si mañana se cambia de proveedor, se reemplaza solo ese módulo.
5. **Configuración inicial única.** El alta de la empresa en el proveedor (RUC, certificado digital, clave SOL) se hace una sola vez desde Postman o el panel del proveedor. El sistema solo guarda la API Key, el API Secret y las series.

---

## 2. Análisis de la colección de Postman

### 2.1 Datos generales

| Aspecto | Detalle |
|---|---|
| Base URL | `{{base_url}}` = `https://sunat.kodevo.es/api/v1` (proveedor intermediario, no SUNAT directo) |
| Autenticación | Cabeceras **`X-Api-Key`** y **`X-Api-Secret`** en cada petición. No hay token ni OAuth, y la colección no define auth global. |
| Formato | JSON (`Accept: application/json`, `Content-Type: application/json`). El registro de empresa y la subida de logo y certificado usan `multipart/form-data`. |
| Entornos SUNAT | Campo `entorno`: `beta` (pruebas, usuario SOL `MODDATOS`) o `production`. Se cambia con `PUT /empresa`. |
| Paginación | Query `por_pagina` (algunos módulos usan `per_page`). |
| Ejemplos de respuesta | **La colección no incluye ninguna respuesta de ejemplo.** No se conoce la forma exacta del JSON de respuesta (nombre del campo `id`, estados, hash, QR, enlaces). Ver Riesgos. |
| Estados del comprobante | `pendiente` → `enviado` → `aceptado` / `rechazado` (según la carpeta 12 y los filtros `sunat_status=`). |
| Envío | Automático por defecto. Con `enviar_automatico: false` el comprobante queda `pendiente` hasta llamar a `POST /{tipo}/{id}/enviar`. El envío se encola: el estado final llega después. |
| Webhook | `PUT /empresa` acepta `webhook_url`, pero la colección no documenta el formato de la notificación. |

### 2.2 Inventario de endpoints por carpeta

| # | Carpeta | Endpoints | ¿Se usa en v1? |
|---|---|---|---|
| 01 | Configuración inicial | `POST /registro` (público, multipart: ruc, razon_social, direccion, ubigeo, sol_user, sol_pass, certificado .pfx, contrasena_certificado, tax_regime, plan, entorno…) · `GET/PUT /empresa` · `POST /empresa/logo` · `POST /empresa/certificado` · `GET /empresa/credenciales` · `POST /empresa/credenciales/regenerar` | **Parcial**: `GET /empresa` (probar conexión y leer el régimen) y `PUT /empresa` (opcional). El registro se hace una sola vez fuera del sistema. |
| 01 | Sucursales (CRUD) | `POST/GET /sucursales`, `GET/PUT/DELETE /sucursales/{id}` (nombre, cod_local, direccion, ubigeo, es_principal, telefono, email) | **Lectura**: `GET /sucursales` para guardar el `sucursal_id`. Se crea una vez. |
| 01 | Series (CRUD) | `POST /series` (alta en lote: tipo, serie, sucursal_id, correlativo_inicial) · `GET /series` · `GET /series/{id}` · `PUT /series/{id}` (activar/desactivar). **No hay DELETE.** | **Sí**: `GET /series` para elegir las series en Configuración. |
| 01 | Clientes (CRUD) | `POST/GET /clientes`, `?buscar=`, `GET/PUT/DELETE /clientes/{id}` | **No.** El cliente viaja dentro de cada comprobante. Los clientes se guardan localmente. |
| 02 | Régimen tributario | `PUT /empresa` con `tax_regime` = `general`, `rer`, `mype` o `rus` · `igv_rate_override` (tasa reducida Ley 31556: 10.5 % en 2026) · `GET /empresa` (`tax_regime`, `tasa_igv_vigente`) | **Lectura** del régimen y la tasa vigente. Se configura una vez. |
| 03 | Suscripción y planes | `GET /planes`, `GET/POST /suscripcion`, cambiar plan, cancelar, pagos, `GET /suscripcion/uso` | **Solo** `GET /suscripcion/uso` (mostrar el consumo del mes en Configuración). |
| 04 | Facturas (01) | `POST /facturas` (19 variantes) · `GET /facturas` (filtros `sucursal_id`, `sunat_status`, `serie`, `correlativo`, `con=items,payments`) · `GET /facturas/{id}` · `PUT /facturas/{id}` (corregir y reenviar) · `POST /{id}/enviar` y `/reenviar` · `GET /{id}/xml`, `/cdr`, `/pdf?format=a4\|a5\|ticket-80\|ticket-58` · pagos `GET/POST/DELETE /{id}/pagos` | **Sí**: solo la **factura básica al contado** (`tipo_operacion 0101`, `tip_afe_igv 10`), ver, PDF, XML, CDR, reenviar y corregir. |
| 05 | Boletas (03) | `POST /boletas` (básica, consumidor final `tipo_doc 0`, NRUS, tasa restaurante, `solo_registro`, manual, anticipo) · `GET /boletas` · `GET/PUT/DELETE /boletas/{id}` (DELETE solo si no está aceptada) · xml, cdr, pdf · enviar/reenviar · pagos | **Sí**: boleta con DNI, boleta a consumidor final, ver, PDF, XML, CDR, reenviar y corregir. |
| 06 | Notas de crédito (07) | `POST /notas-credito` (doc_afectado_tipo/serie/correlativo, cod_motivo, des_motivo, cliente, items) · listar, ver, PUT, enviar/reenviar, xml, cdr, pdf | **Sí, mínimo**: solo **motivo 01 (anulación de la operación)**, para anular ventas con comprobante aceptado. |
| 07 | Notas de débito (08) | CRUD + envío | No |
| 08 | Resumen diario (RC) | `POST /resumenes` (`fecha_resumen`), anulación por resumen, `GET /resumenes`, `/{id}/estado`, xml, cdr, enviar | No en v1 (las boletas se envían una por una). Queda como alternativa si el proveedor obliga a usarlo. |
| 09 | Comunicación de baja (RA) | `POST /anulaciones` (detalles: tipo_documento, serie, correlativo, motivo), listar, ver, estado, xml, cdr, enviar | No en v1: se anula con nota de crédito, un solo mecanismo para boletas y facturas. |
| 10–11 | Guías de remisión (09 / 31) | — | No |
| 12 | Envío manual | `POST /{tipo}/{id}/enviar` | Se usa el `enviar`/`reenviar` de boletas, facturas y NC. |
| 13 | Actualizar documentos | `PUT` sobre comprobantes `pendiente` o `rechazado` (se reenvían solos; no aplica a aceptados) | **Sí**: corregir datos del cliente en un comprobante rechazado. |
| 14 | Consultar CPE / CDR | `GET /consultar-cpe?ruc_emisor&tipo_doc&serie&correlativo&fecha_emision&monto_total` · `POST /consultar-cdr` · `GET /comprobantes/exportar-zip` | **Sí**: `consultar-cpe` como verificación manual directa en SUNAT. `exportar-zip` queda como mejora futura. |
| 15 | Panel de control | 11 endpoints de KPIs | No (el dashboard es local) |
| 16 | Reportes | Registro de ventas, consolidado, etc. | No (los reportes son locales) |
| 17 | SIRE (compras) | 25 endpoints | No |
| 18–20 | Retenciones, percepciones, reversiones | — | No (solo para agentes designados por SUNAT) |
| 21–22 | Cotizaciones y notas de venta (internas) | — | No: las ventas sin comprobante se registran localmente, sin consumir el plan. |
| 23 | Catálogos | Ubigeo, códigos de producto SUNAT | No (el ubigeo solo se necesita en el alta) |

### 2.3 Estructura de los payloads que se usarán

**Boleta (`POST /boletas`)**
```json
{
  "serie": "B001",
  "fecha_emision": "2026-11-16",
  "tipo_moneda": "PEN",
  "forma_pago": "Contado",
  "cliente": { "tipo_doc": "1", "num_doc": "72243561", "razon_social": "NOMBRE" },
  "items": [
    { "codigo": "P001", "descripcion": "Helado 2 bolas", "unidad": "NIU",
      "cantidad": 2, "precio_unitario": 8.00, "tip_afe_igv": "10" }
  ]
}
```
- Consumidor final sin DNI: `"cliente": { "tipo_doc": "0", "num_doc": "99999999", "razon_social": "CONSUMIDOR FINAL" }`. Según la colección, SUNAT lo acepta en boletas menores a S/ 700.
- En la boleta **no se envía `correlativo`**: lo asigna el proveedor.
- `solo_registro: true` deja la boleta pendiente para enviarla en el resumen diario. **No se usa en v1.**

**Factura (`POST /facturas`)**: igual, más `"tipo_operacion": "0101"` y un cliente con `tipo_doc "6"` (RUC) y `direccion`. El ejemplo envía `correlativo`; hay que confirmar que puede omitirse, igual que en la boleta.

**Nota de crédito de anulación (`POST /notas-credito`)**
```json
{
  "serie": "BC01",
  "fecha_emision": "2026-11-16",
  "doc_afectado_tipo": "03", "doc_afectado_serie": "B001", "doc_afectado_correlativo": "4",
  "cod_motivo": "01", "des_motivo": "Anulación de la operación",
  "cliente": { ... }, "items": [ ... ]
}
```
Serie `BC01` para notas sobre boletas y `FC01` para notas sobre facturas. El ejemplo de creación de series solo crea `FC01`, así que **hay que crear también `BC01`**.

**Precio unitario**: los ejemplos (`2360.00` = 2000 × 1.18; `118`; `2.36`) indican que `precio_unitario` **incluye IGV** y que el proveedor calcula la base y el impuesto. Eso encaja con una heladería, donde el precio de lista es el precio final. **Hay que confirmarlo en beta.**

**Códigos útiles**: `tipo_doc` 0 = sin documento, 1 = DNI, 4 = carné de extranjería, 6 = RUC, 7 = pasaporte. `tip_afe_igv` 10 = gravado, 20 = exonerado, 30 = inafecto. `unidad` NIU = unidad, KGM = kilogramo.

### 2.4 Régimen tributario e IGV (impacto en el diseño)

- **`rus` (Nuevo RUS)**: solo boletas, IGV 0, `tipo_operacion 0113` automático. Si la heladería es NRUS, **la opción Factura debe ocultarse.** El sistema lee `tax_regime` desde `GET /empresa`.
- **`igv_rate_override`**: tasa reducida para restaurantes, hoteles y servicios de alimentación (Ley 31556; 10.5 % en 2026). Si aplica a la heladería, **lo decide el contador**. El sistema solo muestra la tasa vigente; no la calcula.
- El POS no calcula impuestos para SUNAT. Localmente guarda el total y muestra un desglose aproximado (`base = total / (1 + tasa)`) con la tasa leída del proveedor.

### 2.5 Hallazgos e inconsistencias de la colección

1. **No existe un endpoint de consulta de DNI/RUC** (RENIEC/SUNAT). La descripción de `GET /empresa/credenciales` menciona "Consulta local + SUNAT/RENIEC… Params: tipo + numero", pero la URL no tiene parámetros y el nombre dice "API key actual". Parece una descripción copiada por error. **No se asume que exista**: en v1 los datos del cliente se escriben a mano y la consulta automática queda como mejora condicionada a que el proveedor la confirme o a usar otro servicio.
2. **No hay respuestas de ejemplo**: el mapeo de respuestas debe construirse a partir de llamadas reales en beta (Fase 1).
3. El PDF de factura A4 usa `?formato=a4` y los demás `?format=`. Se usará `format`.
4. La descripción de sucursales dice que el establecimiento principal debe ser `cod_local "0000"`, pero el ejemplo usa `"0002"` con `es_principal: true`. Usar **`0000`**.
5. El ejemplo "Boleta básica (DNI, contado)" incluye `solo_registro: true` (igual que el ejemplo de resumen). Hay que verificar cómo se comporta una boleta sin ese campo: si se envía sola o si queda pendiente.
6. Los endpoints de PDF, XML y CDR llevan `Accept: application/json`. No se sabe si devuelven binario, base64 o una URL. Se confirmará en beta.
7. `client_id` y `client_secret` en el registro y en `PUT /empresa` parecen credenciales de la API de SUNAT para guías de remisión. **No son necesarios en v1.**

---

## 3. Módulos de la primera versión

| # | Módulo | Alcance v1 |
|---|---|---|
| 1 | **Acceso** | Inicio de sesión. Dos roles: `admin` y `cajero`. |
| 2 | **Dashboard** | Ventas de hoy (cantidad, total, ticket promedio), total por método de pago, top 5 productos del día, alertas (comprobantes rechazados o pendientes, productos con stock bajo). |
| 3 | **Ventas (POS)** | Cuadrícula de productos por categoría con buscador, carrito con +/− cantidad, total, método de pago (efectivo con vuelto, Yape, Plin, tarjeta) y tipo de comprobante (Ninguno, Boleta o Factura). Historial de ventas con su detalle y la opción de anular. |
| 4 | **Productos** | CRUD de productos y categorías: nombre, descripción, precio (con IGV), estado activo, categoría, "controla stock" (sí/no), stock actual y stock mínimo. Ajuste manual de stock con motivo. |
| 5 | **Clientes** | CRUD básico (tipo y número de documento, nombre o razón social, dirección, teléfono, email). Búsqueda por DNI/RUC o nombre. Alta rápida desde el POS. |
| 6 | **Comprobantes electrónicos** | Emitir boleta o factura desde la venta, consultar y refrescar el estado, reintentar, corregir un rechazado (PUT), descargar PDF (ticket 80 mm o A4), XML y CDR, anular con nota de crédito, e historial con filtros (fecha, tipo, estado). |
| 7 | **Reportes** | Ventas por día, ventas por rango de fechas, ventas por método de pago y productos más vendidos. Todo con filtro de fechas. |
| 8 | **Configuración** | Datos de la heladería (para el ticket interno). API: URL, API Key y API Secret (cifrados), botón "Probar conexión", entorno y régimen (solo lectura). Sucursal y series elegidas (boleta, factura, NC de boleta y NC de factura). Parámetros: formato de PDF, comprobante por defecto y si se permite vender sin stock. Usuarios (alta y baja de cajeros). |

---

## 4. Funcionalidades excluidas de la v1 (mejoras futuras)

**Excluidas por no aportar a una heladería:** notas de débito, guías de remisión, retenciones, percepciones, reversiones, SIRE, detracciones, exportación, anticipos, ISC, IVAP, cotizaciones, facturas a crédito con cuotas, multimoneda (USD), multiempresa y multisucursal.

**Mejoras futuras (con valor real, pero no para la v1):**

| Mejora | Motivo para posponer |
|---|---|
| Consulta automática de DNI/RUC | La colección no tiene ese endpoint; requiere confirmar un servicio. |
| Apertura y cierre de caja con arqueo | En v1 el reporte del día por método de pago cumple esa función. |
| Pagos mixtos (parte en efectivo y parte con Yape) | Complica el POS; en v1 hay un método por venta. |
| Productos por peso (KGM) y variantes o toppings | En v1 cada presentación es un producto ("Helado 1 bola", "Helado 2 bolas", "Litro"). |
| Resumen diario (RC) y comunicación de baja (RA) | En v1 basta con el envío individual y la nota de crédito. |
| Impresión directa en ticketera (ESC/POS, QZ Tray) | En v1 se abre el PDF ticket-80 del proveedor o un ticket HTML interno. |
| Webhook del proveedor | En v1 el estado se consulta periódicamente (polling); falta documentar el formato del webhook. |
| Exportar a Excel/CSV y `exportar-zip` mensual para el contador | Útil, pero secundario. |
| Notas de crédito parciales (devoluciones o descuentos) | En v1 solo hay anulación total. |
| ICBPER (bolsas plásticas) | Solo si la heladería entrega bolsas; el contador debe evaluarlo. |
| Inventario de insumos o recetas, compras y proveedores | Esto ya sería un ERP. |
| Modo sin conexión (PWA) | Complejidad alta. |

---

## 5. Arquitectura recomendada

### 5.1 Base

- **Laravel con el starter kit oficial de React** (Inertia + React + TypeScript + Tailwind + shadcn/ui ya integrados, con autenticación incluida). Se usa la versión estable vigente del framework.
- MySQL 8. **Cola con driver `database`** y **scheduler** de Laravel (un solo cron en el servidor). No hace falta Redis.
- Arquitectura sencilla: **controladores delgados, Form Requests para validar, Services para la lógica y un módulo de integración separado.** Sin repositorios ni DDD.

### 5.2 Estructura de carpetas

```
app/
├── Enums/                      PaymentMethod, SaleStatus, DocumentType (ninguno|boleta|factura),
│                               SunatStatus, IdentityDocType, UserRole
├── Http/
│   ├── Controllers/            DashboardController, PosController, SaleController,
│   │                           ProductController, CategoryController, StockAdjustmentController,
│   │                           CustomerController, ElectronicDocumentController,
│   │                           ReportController, Settings/{Business,Billing,User}Controller
│   ├── Requests/               StoreSaleRequest, StoreProductRequest, StoreCustomerRequest, ...
│   └── Middleware/             EnsureUserIsAdmin
├── Models/                     User, Setting, Category, Product, StockMovement, Customer,
│                               Sale, SaleItem, ElectronicDocument
├── Services/
│   ├── SaleService.php         crea y anula ventas (transacción, stock, movimientos)
│   ├── StockService.php
│   ├── DashboardService.php
│   └── ReportService.php
├── Integrations/Facturacion/   ← módulo desacoplado
│   ├── Contracts/InvoicingProvider.php   interfaz: issue(), refresh(), resend(), update(),
│   │                                     download(), voidWithCreditNote(), ping()
│   ├── Kodevo/KodevoClient.php           wrapper de Http:: (baseUrl, cabeceras, timeout, retry)
│   ├── Kodevo/KodevoProvider.php         implementa la interfaz
│   ├── Kodevo/PayloadBuilder.php         Sale/ElectronicDocument → JSON del API
│   ├── Kodevo/ResponseMapper.php         JSON del API → DTO local (estado, número, códigos)
│   ├── DTO/IssuedDocumentData.php
│   ├── Exceptions/ (ProviderUnavailableException, ProviderValidationException,
│   │                ProviderAuthException)
│   └── ElectronicDocumentService.php     orquesta: crea el registro local, llama al proveedor, guarda estado
├── Jobs/
│   ├── IssueElectronicDocumentJob.php    reintento de emisiones fallidas
│   └── RefreshDocumentStatusJob.php      consulta los pendientes o enviados
└── Providers/                  bind InvoicingProvider → KodevoProvider

resources/js/
├── layouts/AppLayout.tsx       menú lateral colapsable + barra superior
├── pages/
│   ├── dashboard.tsx
│   ├── pos/index.tsx           pantalla de venta
│   ├── sales/{index,show}.tsx
│   ├── products/{index,form}.tsx, categories/index.tsx
│   ├── customers/{index,form}.tsx
│   ├── documents/{index,show}.tsx
│   ├── reports/index.tsx
│   └── settings/{business,billing,users}.tsx
├── components/
│   ├── ui/                     shadcn (button, input, dialog, sheet, table, select, badge,
│   │                           tabs, toast/sonner, command, dropdown-menu, card)
│   ├── pos/                    ProductGrid, CategoryTabs, Cart, CartLine, PaymentPanel,
│   │                           DocumentTypeToggle, CustomerPicker, SaleSuccessDialog
│   ├── data-table.tsx          tabla genérica con paginación y filtros del servidor
│   ├── money.tsx, status-badge.tsx, date-range-filter.tsx, stat.tsx
├── hooks/useCart.ts            estado del carrito (useReducer)
└── lib/format.ts               moneda (S/), fechas
```

### 5.3 Desacoplamiento Ventas ↔ Facturación

- `SaleService` **no conoce el API.** Solo crea la venta.
- `PosController@store` llama a `SaleService::create()` y, si se pidió comprobante, a `ElectronicDocumentService::issueForSale($sale, $type)`.
- `ElectronicDocumentService` depende de la interfaz `InvoicingProvider`, no de Kodevo. Cambiar de proveedor equivale a escribir otra implementación.
- Todo el request y el response del proveedor se guardan en `electronic_documents`, para auditoría y soporte.

### 5.4 Rutas web (Inertia)

```
GET  /                         → redirect /pos
GET  /dashboard                DashboardController@index            [admin]
GET  /pos                      PosController@index                  [auth]
POST /pos                      PosController@store                  [auth]
GET  /sales                    SaleController@index                 [auth; cajero: solo hoy]
GET  /sales/{sale}             SaleController@show
POST /sales/{sale}/void        SaleController@void                  [admin]
GET  /sales/{sale}/ticket      SaleController@ticket   (ticket interno HTML para venta sin comprobante)

resource /categories           CategoryController       (except show)  [admin]
resource /products             ProductController        (except show)  [admin]
POST /products/{p}/stock       StockAdjustmentController@store         [admin]
resource /customers            CustomerController       (except show)  [auth]
GET  /customers/search?q=      CustomerController@search (JSON para el POS)

GET  /documents                ElectronicDocumentController@index
GET  /documents/{doc}          ElectronicDocumentController@show
POST /sales/{sale}/documents   ElectronicDocumentController@store   (emitir después de la venta)
POST /documents/{doc}/refresh  ...@refresh
POST /documents/{doc}/resend   ...@resend
PUT  /documents/{doc}          ...@update   (corregir cliente si fue rechazado)
GET  /documents/{doc}/pdf|xml|cdr  ...@download   (proxy al API, sin exponer credenciales)

GET  /reports                  ReportController@index               [admin]
GET|PUT /settings/business     [admin]
GET|PUT /settings/billing      [admin]   POST /settings/billing/test
resource /settings/users       [admin]
```

### 5.5 Validaciones principales

| Request | Reglas |
|---|---|
| `StoreSaleRequest` | `items` array, mínimo 1; `items.*.product_id` existe y está activo; `items.*.quantity` numérico > 0 y ≤ 999; `payment_method` dentro del enum; `amount_received` ≥ total si es efectivo; `document_type` dentro del enum (factura no permitida si el régimen es `rus`). **Factura**: `customer_id` obligatorio con RUC de 11 dígitos que empiece por 10 o 20. **Boleta ≥ S/ 700**: cliente con DNI obligatorio. **Stock**: si el producto controla stock y no se permite vender sin stock, la cantidad debe ser ≤ al stock (se revalida dentro de la transacción con `lockForUpdate`). **Los precios no se reciben del frontend**: se toman de la base de datos. |
| `StoreProductRequest` | nombre requerido (máx. 120) y único por categoría; precio > 0, máximo 2 decimales; `category_id` existe; stock ≥ 0; `stock_min` ≥ 0. |
| `StoreCustomerRequest` | `doc_type` en (1, 4, 6, 7); DNI de 8 dígitos; RUC de 11 dígitos; número único; nombre requerido; dirección requerida si es RUC. |
| `BillingSettingsRequest` | URL https; key y secret requeridos; series con el patrón correcto (`^B\w{3}$`, `^F\w{3}$`, `^BC\d{2}$`, `^FC\d{2}$`). |

### 5.6 Manejo de errores

| Caso | Comportamiento |
|---|---|
| Timeout o error 5xx del proveedor | La venta queda guardada. El comprobante queda en estado local `error_envio`, se encola `IssueElectronicDocumentJob` con backoff (1, 5 y 15 minutos) y el POS muestra el aviso "Venta registrada, comprobante pendiente". |
| 401/403 | `ProviderAuthException`: alerta "Revise la API Key" en el dashboard y en Configuración. No se reintenta. |
| 422 (validación del proveedor) | Se guarda el mensaje y el estado `error`. El usuario corrige (por ejemplo, datos del cliente) y reintenta. |
| SUNAT `rechazado` | Se guardan el código y el mensaje de SUNAT. Opciones: corregir y reenviar (PUT) o anular la venta. |
| Timeout ambiguo (no se sabe si el proveedor creó el documento) | Antes de reintentar se busca en `GET /boletas` o `GET /facturas` (fecha, serie, total) para no duplicar. Si no se puede determinar, queda en "Revisión manual". |
| Errores de dominio (sin stock, factura sin RUC) | `ValidationException`: errores en línea en el formulario (Inertia). |
| Errores inesperados | Log en `storage/logs` + toast genérico. Las credenciales nunca se escriben en el log. |

---

## 6. Diseño de base de datos

### 6.1 Diagrama de relaciones

```
users 1───* sales *───1 customers (nullable)
               │
               ├──* sale_items *───1 products *───1 categories
               │                         │
               │                         └──* stock_movements
               └──* electronic_documents (boleta/factura y su NC)
                         └── self: credit_note.related_document_id → documento original
settings (fila única clave/valor)
```

### 6.2 Tablas

**users**: id, name, email (unique), password, `role` enum('admin','cajero'), `active` bool, timestamps.

**settings**: id, `key` (unique), `value` (text, `encrypted` cast para la API Key y el Secret), timestamps.
Claves: `business_name`, `business_ruc`, `business_address`, `business_phone`, `ticket_footer`, `api_base_url`, `api_key`, `api_secret`, `api_branch_id`, `series_boleta`, `series_factura`, `series_nc_boleta`, `series_nc_factura`, `pdf_format` (ticket-80/a4), `default_document_type`, `allow_negative_stock`, `tax_regime` (cache), `igv_rate` (cache), `environment` (cache).

**categories**: id, name (unique), sort_order, active, timestamps.

**products**: id, category_id (FK), `code` (unique, para `items.codigo`), name, description (nullable), `price` decimal(10,2) con IGV incluido, `unit` default 'NIU', `tax_affectation` default '10', `track_stock` bool, `stock` decimal(10,2) default 0, `stock_min` decimal(10,2) default 0, active, timestamps, softDeletes.
Índices: (category_id, active), name.

**stock_movements**: id, product_id (FK), `type` enum('sale','void','adjustment','purchase'), `quantity` decimal (+/−), `stock_after`, sale_id (nullable FK), user_id (FK), note, created_at.

**customers**: id, `doc_type` char(1), `doc_number` (unique con doc_type), name, address (nullable), phone, email, timestamps.

**sales**: id, `number` (correlativo interno, unique), user_id (FK), customer_id (nullable FK), `sold_at` datetime, `total` decimal(10,2), `payment_method` enum('efectivo','yape','plin','tarjeta'), `amount_received` decimal nullable, `change` decimal nullable, `document_type` enum('ninguno','boleta','factura'), `status` enum('completada','anulada'), `voided_at`, `voided_by`, `void_reason`, notes, timestamps.
Índices: (sold_at), (status, sold_at), (payment_method, sold_at).

**sale_items**: id, sale_id (FK, cascade), product_id (FK), `product_name` (copia del nombre al momento de la venta), `unit_price` decimal(10,2), `quantity` decimal(10,2), `total` decimal(10,2).
Índice: (product_id) para el ranking de productos.

**electronic_documents**: id, sale_id (FK), `type` enum('01','03','07'), `series`, `number` (nullable hasta que el proveedor responda), `provider_id` (id en el API, nullable), `related_document_id` (nullable, self FK, para la NC), `customer_snapshot` json, `total` decimal, `status` enum('borrador','error_envio','pendiente','enviado','aceptado','rechazado','anulado','revision_manual'), `sunat_code`, `sunat_message` text, `hash` nullable, `request_payload` json, `response_payload` json, `attempts` tinyint, `issued_at`, `last_checked_at`, timestamps.
Índices: unique (type, series, number), (status), (sale_id).

Notas:
- El PDF, el XML y el CDR **no se guardan**: se descargan del proveedor al pedirlos, con el controlador como proxy. Más adelante se puede añadir caché en `storage/`.
- Una venta puede tener más de un documento: la boleta y, si se anula, su NC.

### 6.3 Modelos Eloquent y relaciones

- `User` hasMany `Sale`
- `Category` hasMany `Product`
- `Product` belongsTo `Category`; hasMany `StockMovement`, `SaleItem`
- `Customer` hasMany `Sale`
- `Sale` belongsTo `User`, `Customer`; hasMany `SaleItem`, `ElectronicDocument`; hasOne `invoice()` (el documento 01/03 activo)
- `SaleItem` belongsTo `Sale`, `Product`
- `ElectronicDocument` belongsTo `Sale`; belongsTo `related` (self); hasOne `creditNote`
- Casts con Enums PHP; `Setting::get('key')` con caché.

---

## 7. Flujos principales

### 7.1 Venta rápida (flujo base, objetivo: menos de 10 segundos)

1. El cajero inicia sesión y entra directo a **/pos**.
2. Toca los productos en la cuadrícula; cada toque suma 1 al carrito. Ajusta con +/−.
3. Elige el método de pago (efectivo por defecto). Si es efectivo, ingresa el monto recibido y ve el vuelto.
4. Elige el comprobante: **Ninguno**, **Boleta** (por defecto según la configuración) o **Factura**.
   - Boleta: sin cliente se emite a "Consumidor final"; también puede buscar o crear un cliente por DNI.
   - Factura: debe elegir o crear un cliente con RUC.
5. Pulsa **Cobrar**. En el backend, dentro de una transacción: se crea la venta, sus ítems y los movimientos de stock, y se descuenta el stock.
6. Si hay comprobante, `ElectronicDocumentService` llama a `POST /boletas` o `POST /facturas` (timeout de unos 10 s).
   - Si responde: se guardan `provider_id`, número y estado (`pendiente` o `enviado`).
   - Si falla: estado `error_envio` y el reintento queda en cola.
7. Se abre el diálogo **Venta registrada** con: total, vuelto, número de comprobante, estado, y los botones **Imprimir ticket** (PDF ticket-80) y **Nueva venta**.
8. El carrito se limpia y el cajero sigue atendiendo.

### 7.2 Actualización del estado SUNAT

- Cada minuto, el scheduler despacha `RefreshDocumentStatusJob` para los documentos en `pendiente` o `enviado` con menos de 72 h. Llama a `GET /boletas/{id}` o `GET /facturas/{id}` y actualiza el estado, el código y el mensaje.
- Cada 5 minutos se reintentan los documentos en `error_envio` (máximo 5 intentos; después pasan a `revision_manual`).
- El dashboard muestra los rechazados y los pendientes con más de 1 hora.
- En el detalle del documento, el botón **Verificar en SUNAT** usa `GET /consultar-cpe`.

### 7.3 Emitir comprobante después de la venta

Desde el historial, en una venta "sin comprobante" del mismo día: **Emitir boleta/factura**, que sigue el mismo servicio del paso 6.

### 7.4 Comprobante rechazado

En el detalle aparece el mensaje de SUNAT. Si el problema son los datos del cliente: **Corregir y reenviar** (`PUT /boletas/{id}` o `PUT /facturas/{id}`). Si no tiene solución: anular la venta.

### 7.5 Anular una venta (solo admin)

1. Sin comprobante: la venta pasa a `anulada` y se repone el stock (movimiento `void`).
2. Con comprobante aceptado: se emite una NC con motivo 01 (`POST /notas-credito`, serie BC01 o FC01, con los mismos ítems y el mismo cliente). Cuando se crea la NC, la venta queda anulada y se repone el stock. El documento original pasa a `anulado`.
3. Con comprobante `rechazado` o `error_envio`: el comprobante nunca fue válido. La venta se anula localmente y el documento se marca `anulado`. Si quedó como borrador en el proveedor, se elimina la boleta con `DELETE /boletas/{id}`.

### 7.6 Configuración inicial (una sola vez)

1. **Fuera del sistema** (Postman o panel del proveedor): `POST /registro` con el RUC, el certificado .pfx y la clave SOL. Se guardan la `api_key` y el `api_secret` (el secret no se puede recuperar). Luego `POST /sucursales` (cod_local `0000`), `POST /series` (B001, F001, BC01, FC01) y el régimen (`PUT /empresa`).
2. **En el sistema**, en Configuración > Facturación: se pegan la key y el secret y se pulsa **Probar conexión** (`GET /empresa`), que muestra la razón social, el entorno, el régimen y la tasa de IGV. Después se eligen la sucursal y las series (`GET /sucursales`, `GET /series`).
3. Se hacen pruebas en `beta` y luego se cambia a `production`.

---

## 8. Plan de implementación por fases

### Fase 1: Arquitectura y configuración inicial
- **Objetivo**: proyecto base funcionando con autenticación y layout, y validar el API en beta.
- **Funcionalidades**: login, layout con menú lateral y roles.
- **Tareas técnicas**: instalar Laravel con el starter kit de React; configurar MySQL en Laragon; añadir `role` y `active` a users con un seeder de admin; middleware `admin`; instalar los componentes shadcn necesarios; definir la cola `database` y el scheduler; configurar ESLint, Prettier y Pint. **Prueba del API (spike)**: ejecutar en beta la colección (empresa, sucursal, series, boleta, factura, NC, PDF/XML/CDR, consultar-cpe) y **guardar las respuestas reales como fixtures JSON**.
- **Tablas**: users, sessions, jobs, cache.
- **Frontend**: AppLayout, Sidebar, página de login, toasts.
- **Dependencias**: ninguna.
- **Terminada cuando**: el admin entra y ve el menú; el cajero solo ve sus opciones; existen fixtures reales de cada respuesta del API y están resueltas las dudas de la sección 2.5.

### Fase 2: Base de datos y módulos básicos
- **Objetivo**: esquema completo y módulos de soporte.
- **Funcionalidades**: categorías (CRUD), configuración del negocio y gestión de usuarios.
- **Tareas técnicas**: todas las migraciones de la sección 6, modelos, enums, casts y relaciones; factories y seeders con datos de heladería de ejemplo; modelo `Setting` con caché y cifrado; DataTable genérica.
- **Tablas**: todas.
- **Frontend**: `categories/index` (tabla con diálogo de edición), `settings/business`, `settings/users`, DataTable, componentes de formulario.
- **Dependencias**: Fase 1.
- **Terminada cuando**: `migrate:fresh --seed` corre sin errores; se crean y editan categorías, usuarios y datos del negocio; hay pruebas de feature de autorización por rol.

### Fase 3: Productos, clientes y ventas
- **Objetivo**: vender sin comprobante electrónico de punta a punta.
- **Funcionalidades**: CRUD de productos con stock y ajustes; CRUD y búsqueda de clientes; POS completo; historial y detalle de ventas; anulación sin comprobante; ticket interno HTML.
- **Tareas técnicas**: `SaleService` (transacción, `lockForUpdate`, precios desde la base de datos, movimientos de stock); `StockService`; `StoreSaleRequest` con reglas de negocio; endpoint JSON de búsqueda de clientes; hook `useCart`; atajos de teclado en el POS (buscar, cobrar).
- **Tablas**: products, stock_movements, customers, sales, sale_items.
- **Frontend**: `products/index` y `form`, `customers/index` y `form`, `pos/index` (ProductGrid, CategoryTabs, Cart, PaymentPanel, CustomerPicker, SaleSuccessDialog), `sales/index` y `show`.
- **Dependencias**: Fase 2.
- **Terminada cuando**: una venta de 3 productos con vuelto se registra en menos de 10 s en tablet; el stock se descuenta y se repone al anular; no se puede vender por encima del stock si está deshabilitado; hay pruebas de `SaleService` (totales, stock, concurrencia básica).

### Fase 4: Integración con el API de facturación
- **Objetivo**: emitir, consultar, descargar y anular comprobantes.
- **Funcionalidades**: boleta (con DNI y consumidor final), factura, estado automático, reintento, corrección de rechazados, PDF/XML/CDR, NC de anulación, historial de comprobantes, configuración de facturación con "Probar conexión".
- **Tareas técnicas**: módulo `Integrations/Facturacion` (interfaz, KodevoClient con `Http::withHeaders([...])->timeout(10)->retry(2)`, PayloadBuilder, ResponseMapper con los fixtures de la Fase 1), `ElectronicDocumentService`, jobs `IssueElectronicDocumentJob` y `RefreshDocumentStatusJob`, scheduler, proxy de descargas, ocultar la factura si el régimen es `rus`, pruebas con `Http::fake()` usando los fixtures reales.
- **Tablas**: electronic_documents, settings (claves del API).
- **Frontend**: DocumentTypeToggle y estado en el POS, `documents/index` y `show`, StatusBadge, `settings/billing`, alertas en el dashboard.
- **Dependencias**: Fase 3 y fixtures de la Fase 1.
- **Terminada cuando**, en entorno beta: se emite una boleta a consumidor final, una boleta con DNI y una factura con RUC, todas llegan a `aceptado`; se anula una boleta aceptada con NC y queda aceptada; se descargan PDF ticket-80, A4, XML y CDR; si se corta la red, la venta se guarda y el comprobante se emite después; los tests con `Http::fake` pasan.

### Fase 5: Reportes y dashboard
- **Objetivo**: información del negocio.
- **Funcionalidades**: dashboard del día y los 4 reportes con filtro de fechas.
- **Tareas técnicas**: `DashboardService` y `ReportService` con consultas agregadas (`SUM`, `GROUP BY DATE(sold_at)`, `payment_method`, `product_id`) que excluyen las ventas anuladas; índices revisados; gráficos simples con Recharts (el componente Chart de shadcn).
- **Tablas**: sales, sale_items (solo lectura).
- **Frontend**: `dashboard` (4 indicadores, gráfico por hora o método de pago, top 5, alertas), `reports/index` (pestañas: por día, por método, top productos; DateRangeFilter).
- **Dependencias**: Fase 3 (la Fase 4 solo para las alertas de comprobantes).
- **Terminada cuando**: los totales del reporte coinciden con la suma de las ventas no anuladas en las pruebas; los reportes de un mes responden en menos de 1 s con unas 20 000 ventas de seed.

### Fase 6: Pruebas, optimización y producción
- **Objetivo**: sistema estable en producción.
- **Tareas técnicas**: pruebas de feature de todos los flujos de la sección 7; prueba en tablet y celular; eager loading y revisión de N+1; `config:cache`, `route:cache` y `npm run build`; servidor (VPS pequeño o hosting con SSH y cron): HTTPS, cron del scheduler y worker de cola (supervisor o `queue:work --stop-when-empty` desde el cron); backup diario de MySQL; cambiar `entorno` a `production` en el proveedor; capacitación de 30 minutos al personal.
- **Dependencias**: Fases 1 a 5.
- **Terminada cuando**: primer día de ventas reales con comprobantes aceptados en producción, backups verificados y el cajero vendiendo sin ayuda.

---

## 9. Riesgos y recomendaciones técnicas

| Riesgo | Impacto | Mitigación |
|---|---|---|
| La colección no tiene respuestas de ejemplo | Alto | Prueba del API en beta en la Fase 1; `ResponseMapper` aislado y probado con fixtures reales. |
| Dependencia de un proveedor externo (caídas, cambios, fin del servicio) | Alto | La venta nunca se bloquea; cola de reintentos; la interfaz `InvoicingProvider` permite cambiar de proveedor; se guardan request y response. |
| Límites del plan (documentos por mes) | Medio | Mostrar `GET /suscripcion/uso` en Configuración y avisar en el dashboard al llegar al 80 %. |
| Comprobantes duplicados por timeout | Medio | Verificar en el listado del proveedor antes de reintentar; estado `revision_manual`. |
| Obligaciones tributarias de las ventas sin comprobante | Medio | La normativa permite no entregar comprobante en ventas pequeñas (hasta S/ 5), pero exige reglas de sustento (por ejemplo, una boleta resumen diaria). **El contador debe validar** cuándo se usa "Ninguno". Recomendación: configurar Boleta como opción por defecto. |
| Régimen (NRUS / tasa reducida Ley 31556) | Medio | No calcular el IGV localmente; leer `tax_regime` y `tasa_igv_vigente` del proveedor y que el contador lo configure. |
| Credenciales expuestas | Alto | Cast `encrypted` (APP_KEY), nunca enviarlas al frontend ni a los logs; las descargas pasan por un proxy del backend. |
| Stock inconsistente por ventas simultáneas | Bajo | Transacción con `lockForUpdate`; los helados en tina no controlan stock (`track_stock = false`). |
| Hosting sin cron ni worker | Medio | Elegir un hosting con cron; el scheduler procesa la cola con `--stop-when-empty`. |
| Exceso de alcance | Medio | Respetar la sección 4; cualquier pedido nuevo va a "mejoras futuras". |

**Recomendaciones adicionales**

- Montos con `decimal(10,2)` en MySQL y cálculos en el backend; en PHP se redondea solo al final.
- Pantalla de POS pensada para tablet: botones grandes, sin paginación (scroll por categoría) y búsqueda con foco automático.
- Interfaz sobria: colores neutros, un color de acento, tablas sin bordes pesados, sin sombras decorativas y modo claro por defecto.
- Logs del canal `facturacion` por separado para dar soporte.
- Antes de pasar a producción: emitir en beta al menos 20 comprobantes variados.

---

## 10. Orden recomendado para comenzar

1. **Hoy**: confirmar con el contador el régimen de la heladería (¿NRUS? ¿tasa reducida?) y la política de ventas sin comprobante.
2. **Prueba del API en beta** (se puede hacer en paralelo con el paso 3): alta de la empresa de prueba, sucursal `0000`, series B001, F001, BC01 y FC01; emitir una boleta, una factura y una NC; descargar PDF, XML y CDR; consultar el estado. Guardar las respuestas como fixtures y aclarar las dudas de la sección 2.5 (consulta de DNI/RUC, `correlativo` en factura, comportamiento de la boleta sin `solo_registro`, formato de las descargas).
3. Fase 1: proyecto base, autenticación, roles y layout.
4. Fase 2: migraciones, seeders, categorías, configuración y usuarios.
5. Fase 3: productos, luego clientes, luego POS y por último el historial de ventas. **Primer hito usable: vender sin comprobante.**
6. Fase 4: facturación, con la boleta primero, luego la factura y después la NC.
7. Fase 5: dashboard y reportes.
8. Fase 6: pruebas, despliegue, paso a producción y capacitación.
