# Sistema de Heladería

Punto de venta para una heladería, con facturación electrónica (boletas, facturas y notas de crédito) mediante el API de la colección `3. API FACTURACION SUNAT PERU…postman_collection.json`.

Laravel 12 · Inertia 2 · React 19 · TypeScript · Tailwind 4 · shadcn/ui · MySQL

El análisis y el plan completos están en [`docs/ANALISIS-SISTEMA-HELADERIA.md`](docs/ANALISIS-SISTEMA-HELADERIA.md).

## Puesta en marcha (Laragon)

```bash
composer install
npm install
cp .env.example .env        # ya configurado para MySQL root sin clave, BD "heladeria"
php artisan key:generate
php artisan migrate --seed
npm run build               # o "npm run dev" mientras se desarrolla
php artisan serve           # http://127.0.0.1:8000
```

Usuarios de prueba creados por el seeder (cámbielos antes de producción):

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | admin@heladeria.test | password |
| Cajero | cajero@heladeria.test | password |

## Facturación electrónica

1. Registrar la empresa, la sucursal (`cod_local 0000`) y las series **B001, F001, BC01 y FC01** en el proveedor, una sola vez desde Postman (carpeta 01 de la colección).
2. En **Configuración > Facturación**: pegar la API Key y el API Secret, guardar y pulsar **Probar conexión**. Se leen el régimen, la tasa de IGV y el entorno.
3. Programar el scheduler. Consulta el estado SUNAT y reintenta los envíos fallidos cada minuto. No requiere un worker de colas.

```
* * * * * php /ruta/al/proyecto/artisan schedule:run >> /dev/null 2>&1
```

En desarrollo: `php artisan schedule:work`, o ejecutarlo a mano con `php artisan facturacion:sincronizar`.

Al guardar las credenciales, el sistema se conecta solo: lee la empresa, el régimen y el entorno (`GET /empresa`) y verifica las series (`GET /series`). Si alguna serie configurada no existe, elige una activa. Desde ese momento el punto de venta emite directamente.

> **Formato verificado en beta (2026-11-16):** `{"estado","mensaje","datos"}`; el estado SUNAT del comprobante viene en `datos.sunat.{estado,codigo,descripcion,hash_cpe}`. Una respuesta HTTP 200 con `"estado":"error"` se trata como error. Antes de reintentar un envío, el sistema busca en el proveedor si el comprobante ya existe, para no duplicarlo.

## Estructura

```
app/Services/                    Ventas, stock y reportes (no conocen el API)
app/Integrations/Facturacion/    Módulo desacoplado del proveedor
  Contracts/InvoicingProvider    Interfaz: cambiar de proveedor = otra implementación
  Kodevo/                        Cliente HTTP, implementación y mapeo de respuestas
  PayloadBuilder                 Venta → JSON del API
  ElectronicDocumentService      Emitir, reintentar, consultar, corregir, anular con NC
app/Console/Commands/SyncElectronicDocuments   facturacion:sincronizar
resources/js/pages/              pos, sales, products, categories, customers,
                                 documents, reports, dashboard, settings
```

## Tests

```bash
php artisan test    # SQLite en memoria; el API se simula con Http::fake
```
