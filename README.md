# icomm AI Marketing Cloud

Conector de PrestaShop para **icomm AI Marketing Cloud**.

El módulo expone una **API de lectura** para que icomm consulte el catálogo, los pedidos y los clientes de la tienda. No escribe nada en PrestaShop.

> El identificador técnico sigue siendo `icommktconnector`: es el nombre de la carpeta, el prefijo de las clases de los controladores y el de las claves de configuración. No debe cambiarse; solo el nombre visible en el Back Office es "icomm AI Marketing Cloud".

**Versión actual: 1.4.4**

## Requisitos y compatibilidad

- El módulo se escribió originalmente para PrestaShop 1.6 / 1.7.
- El endpoint de catálogo está **verificado en producción sobre PrestaShop 9.1.1 con PHP 8.4**.
- Los endpoints de pedidos y clientes proceden de la versión original y **no han sido verificados** sobre PrestaShop 8.x / 9.x.

## Instalación

1. Subir el zip desde el Back Office (Módulos → Subir un módulo) o descomprimirlo en `<prestashop>/modules/`. La carpeta debe llamarse `icommktconnector`.
2. Instalar el módulo.
3. **Limpiar la caché** de PrestaShop (Parámetros avanzados → Rendimiento → Borrar caché). Es necesario para que se registren las URLs amigables del módulo; sin ello los endpoints responden 404 en su forma amigable.

Una instalación nueva **no crea ninguna tabla ni modifica ninguna tabla de PrestaShop**.

Para comprobar qué versión está sirviendo la tienda, abrir la configuración del módulo y buscar en el código fuente de la página el comentario `<!-- icommktconnector v1.4.4 -->`.

## Configuración

La pantalla de configuración contiene únicamente:

- **App KEY**: identificador que icomm envía en cada petición.
- **App TOKEN**: token que acompaña al App KEY.

Ambos valores los define el integrador y deben coincidir con los configurados en icomm.

> En las tiendas que conservan alguna de las funcionalidades retiradas aparecen además los campos correspondientes a esas funcionalidades (ver el final del documento).

---

# API de lectura

## Autenticación

Todas las peticiones requieren estas dos cabeceras:

- `X-VTEX-API-AppKey`: valor del campo **App KEY**.
- `X-VTEX-API-AppToken`: valor del campo **App TOKEN**.

Sin cabeceras o con credenciales incorrectas la respuesta es `403`.

Todos los endpoints admiten dos formas de URL. Si la amigable devuelve 404, hay que limpiar la caché de PrestaShop; la forma con `index.php?fc=module` funciona siempre.

---

## Catálogo de productos

Devuelve el catálogo completo, paginado y con búsqueda.

Cada fila es un **SKU real**: los productos con combinaciones devuelven una fila por combinación, con su propia referencia, precio y stock; los productos sin combinaciones devuelven una única fila con `idProductAttribute` a `0`.

Se devuelven tanto los productos activos como los inactivos, cada uno con su campo `active`, para que sea el consumidor quien decida.

### URL

```
GET /icommkt/catalog/pvt/products
GET /icommkt/catalog/pvt/products/[id_product]
GET /index.php?fc=module&controller=catalog&module=icommktconnector
```

### Parámetros

| Parámetro | Descripción |
| --- | --- |
| `page` | Página, empezando en 1. Por defecto `1`. |
| `per_page` | Filas por página. Por defecto `50`, máximo `200`. |
| `id_product` | Id exacto de producto. |
| `sku` | Búsqueda parcial por referencia, EAN13 o UPC, tanto del producto como de la combinación. |
| `name` | Búsqueda parcial por nombre de producto. |
| `search` | Búsqueda libre: nombre, referencia, EAN13 e id de producto. |
| `active` | `1` solo activos, `0` solo inactivos. Si se omite, se devuelven todos. |
| `updated_since` | Devuelve solo los productos modificados desde esa fecha. Útil para sincronizaciones incrementales. |
| `orderBy` | `<campo>,<asc\|desc>` con campo en `id`, `reference`, `name`, `price`, `quantity`, `dateUpdated`. Por defecto `id,asc`. |
| `with_tax` | `1` (por defecto) precios con impuestos, `0` sin impuestos. |
| `id_lang` | Idioma en el que se devuelven nombres y URLs. Por defecto el de la tienda. |
| `debug` | `1` devuelve el diagnóstico del error en JSON en lugar de un 500 genérico. |
| `ping` | `1` responde sin consultar el catálogo, para comprobar ruta y credenciales. |

### Respuesta

El total de resultados se devuelve tanto en la cabecera `Total-Records` como en el bloque `paging`.

```json
{
  "products": [
    {
      "sku": "1024-3312",
      "idProduct": 1024,
      "idProductAttribute": 3312,
      "reference": "CAM-ROJ-M",
      "ean13": "8412345678905",
      "upc": "",
      "name": "Camiseta básica",
      "combinationName": "Talla: M, Color: Rojo",
      "fullName": "Camiseta básica - Talla: M, Color: Rojo",
      "descriptionShort": "Camiseta de algodón orgánico 180 g.",
      "price": 19.9,
      "listPrice": 24.9,
      "basePriceTaxExcl": 20.578512,
      "priceIncludesTax": true,
      "currency": "EUR",
      "quantity": 14,
      "available": true,
      "active": true,
      "visibility": "both",
      "manufacturer": "Acme",
      "category": "Camisetas",
      "weight": 0.22,
      "url": "https://tienda.com/es/camisetas/1024-3312-camiseta-basica.html",
      "imageUrl": "https://tienda.com/1024-3312/camiseta-basica.jpg",
      "dateAdd": "2021-03-04T10:22:11+00:00",
      "dateUpd": "2026-09-12T08:01:00+00:00"
    }
  ],
  "paging": {
    "total": 4821,
    "pages": 97,
    "currentPage": 1,
    "perPage": 50
  }
}
```

El campo `sku` (`id_product`, o `id_product-id_product_attribute` cuando hay combinación) es el identificador estable que debe usarse como clave del catálogo.

### Notas sobre los precios

`price` y `listPrice` se calculan con el motor de precios de PrestaShop, de modo que incluyen impuestos, precios específicos y el impacto de la combinación. `basePriceTaxExcl` es el precio base sin impuestos leído directamente de la base de datos, y sirve de contraste.

Si el cálculo falla para un producto concreto, esa fila devuelve el precio base como respaldo y el incidente queda registrado en Parámetros avanzados → Logs, en lugar de tumbar la página entera del catálogo.

---

## Pedidos y estados de pedido

```
GET /icommkt/oms/pvt/orders            (listado)
GET /icommkt/oms/pvt/orders/[id_order] (un pedido)
GET /icommkt/oms/pvt/status_list       (estados de pedido de la tienda)
```

Parámetros del listado: `page`, `per_page`, `current_state[]` (array de ids de estado), `orderBy` (`orderId`, `totalValue` o `creationDate`, seguido de `,asc` o `,desc`), `f_creationDate` y `f_updateDate` con el formato `[<fecha> TO <fecha>]`.

La respuesta imita el formato de VTEX: un bloque `list` con los pedidos y bloques `paging`, `facets` y `stats`.

## Clientes

```
GET /icommkt/dataentities/cl/search
```

Solo se admite la entidad `cl` (clientes). Requiere el parámetro `_where` con la sintaxis de VTEX, que debe incluir `lastInteractionIn` o `createdIn`; sin él la petición se rechaza. Admite `page` y `per_page`, y devuelve el total en la cabecera `Total-Records`.

La respuesta es un array plano de clientes en formato VTEX, sin envoltorio ni metadatos de paginación.

---

# Diagnóstico de errores

Si un endpoint devuelve `500 Internal Server Error`, PrestaShop oculta el motivo tras una página genérica. Añadiendo `&debug=1` al endpoint de catálogo, el error se devuelve en el cuerpo de la respuesta, en JSON, sin necesidad de activar el modo debug de PrestaShop (que expondría errores a todos los visitantes de la tienda).

Captura tres cosas que de otro modo quedan ocultas:

- **Excepciones** de PHP y de PrestaShop, con fichero, línea y traza.
- **Errores fatales no capturables**, como agotar la memoria o el tiempo máximo de ejecución.
- **Errores de SQL**, que en producción no lanzan excepción: la consulta devuelve `false` y el listado saldría vacío sin ninguna señal.

La respuesta incluye la última consulta ejecutada, el último producto que se estaba procesando (útil cuando el problema es un producto concreto), las versiones de PrestaShop y PHP, y los límites de memoria y tiempo:

```json
{
  "error": {
    "type": "PrestaShopException",
    "message": "If no employee is assigned in the context, cart ID must be provided to this method.",
    "file": "/bitnami/prestashop/classes/Product.php:3383",
    "trace": ["#0 ...", "#1 ..."],
    "lastSql": "SELECT p.`id_product`, ...",
    "lastRow": "id_product=1 id_product_attribute=1",
    "psVersion": "9.1.1",
    "phpVersion": "8.4.20",
    "memoryLimit": "256M",
    "memoryPeak": "12 MB",
    "maxExecutionTime": "30"
  }
}
```

Si `lastRow` viene vacío, el fallo está en la consulta o antes de ella; si trae un producto, está en el tratamiento de esa fila.

Con `&ping=1` el endpoint responde tras autenticar, sin llegar a consultar el catálogo: sirve para distinguir un problema de ruta o credenciales de uno de la consulta.

Los errores quedan además registrados en **Parámetros avanzados → Logs**, buscando `ICOMMKTCONNECTOR - CATALOG`, aunque la respuesta HTTP no llegue a mostrarse.

> El parámetro solo es alcanzable con credenciales válidas, porque la autenticación se ejecuta antes. Aun así, la traza revela rutas del servidor: conviene usarlo para diagnosticar y no dejarlo fijo en una integración.

---

# Funcionalidades retiradas

Las dos funcionalidades siguientes **ya no se ofrecen en instalaciones nuevas**, pero siguen operativas, sin cambios, en las tiendas que ya las estaban usando antes de actualizar. En esas tiendas los campos correspondientes siguen visibles en la configuración del módulo y los crones siguen funcionando igual.

En una instalación nueva no se muestran los campos, no se registran las rutas y los controladores no son accesibles.

## Carritos abandonados (retirada en 1.3.0)

Se considera que una tienda la estaba usando si tiene el campo **Profile Key Cart Abandon** configurado, o si la tabla `commktconnector_abandomentcarts` contiene registros.

### Definición

Envía a icomm los carritos abandonados de la tienda para poder mandar un correo a sus propietarios. El correo incluye una URL que recupera el carrito con los productos que contenía.

Al obtener los carritos se tiene en cuenta que:

- Solo se obtienen los carritos que tengan un email asociado, es decir, los de usuarios registrados.
- Solo se obtienen los carritos que no tengan un pedido asociado.

### Campos configurables

- **Api key**: código de la cuenta de icomm.
- **Profile key Abandon**: código del perfil de icomm al que se envían los carritos.
- **Secure Token**: token de seguridad; debe coincidir con el de la URL o la acción no se ejecuta.
- **Days to abandon**: días para considerar abandonado un carrito. Por defecto, 1.
- **Friendly URL**: activa o desactiva la URL amigable de esta funcionalidad.

### Acciones disponibles

- **load_cart**: recupera el carrito abandonado.
  - `http://prueba.net/abandomentcart/load_cart/[secure_token]/[id_cart]`
  - `http://prueba.net/index.php?fc=module&controller=abandomentcart&module=icommktconnector&action=load_cart&secure_token=[secure_token]&id_cart=[id_cart]`
- **sendAbandomentcarts**: envía a icomm los carritos abandonados. Se usa como cron diario.
  - `http://prueba.net/abandomentcart/sendAbandomentcarts/[secure_token]`
  - `http://prueba.net/index.php?fc=module&controller=abandomentcart&module=icommktconnector&action=sendAbandomentcarts&secure_token=[secure_token]`

### Tablas

Estas tablas existen únicamente en las tiendas que ya usaban la funcionalidad; **una instalación nueva no las crea**:

- **commktconnector_abandomentcarts**: carritos enviados correctamente a icomm.
- **commktconnector_abandomentcarts_error**: carritos que no se pudieron enviar, con el error en la columna `error`.

### Importante tener en cuenta

Un mismo cliente puede tener varios carritos que encajen en el periodo configurado en "Days to abandon". Como solo se envía uno por cliente, al volver a ejecutar el cron puede enviarse otro de sus carritos.

## Envío de suscriptores de la newsletter (retirada en 1.4.0)

Se considera que una tienda la estaba usando si tiene el campo **Profile Key** configurado, o si el módulo ya había añadido sus columnas a la tabla de suscriptores.

### Definición

Envía a icomm los usuarios registrados en la newsletter de la tienda.

### Campos configurables

- **Api key**: código de la cuenta de icomm.
- **Profile key**: código del perfil de icomm al que se envían los usuarios.
- **Secure Token**: token de seguridad; debe coincidir con el de la URL o la acción no se ejecuta.

### Acciones disponibles

- Envío de los usuarios, normalmente como cron:
  - `http://prueba.net/index.php?fc=module&controller=sendtoicommkt&module=icommktconnector&action=sendtoicommktuser&secure_token=[secure_token]`

### Tablas modificadas

En las tiendas que ya usaban la funcionalidad, el módulo añadió dos columnas (`is_send_icommkt` y `date_send_icommkt`) a la tabla de suscriptores de PrestaShop: `emailsubscription` en 1.7 y posteriores, `newsletter` en versiones anteriores.

**Una instalación nueva ya no modifica esa tabla.**

### Importante tener en cuenta

El cron envía todos los usuarios pendientes. Cuando uno se registra correctamente en icomm, su columna `is_send_icommkt` pasa a 1, evitando que se vuelva a enviar.

---

# Historial de versiones

| Versión | Cambios |
| --- | --- |
| 1.4.4 | El módulo se clasifica en la categoría `advertising_marketing` de PrestaShop. |
| 1.4.3 | Cabecera de la configuración en dos líneas y marcador de versión en el código fuente de la página. |
| 1.4.2 | Corrección de codificación del fichero principal (BOM y acentos) y recompilación automática de la plantilla. |
| 1.4.1 | Pantalla de configuración reducida al logo y los ajustes. |
| 1.4.0 | Nombre e imagen de icomm AI Marketing Cloud. Envío de suscriptores de la newsletter retirado de instalaciones nuevas. |
| 1.3.0 | Nuevo endpoint de catálogo de productos. Carritos abandonados retirados de instalaciones nuevas. |
