# Guía de integración — ColoniasAPI

> Este documento es para el equipo de **otro sitio** que quiere consumir ColoniasAPI (ej. MeUnoColonia, Espejo Electoral Ciudadano). No necesitas saber cómo se instaló, se pobló ni se administra el servicio — solo cómo llamarlo desde tu aplicación.
>
> Si buscas cómo desplegar o administrar el propio servicio, ve al [`README.md`](../README.md) del proyecto.

---

## 1. Qué hace la API

ColoniasAPI expone, por HTTP/JSON, el catálogo geográfico y electoral de México:

| Capacidad | Para qué sirve | Endpoint principal |
|---|---|---|
| Catálogo | Estados, municipios | `/estados`, `/municipios` |
| Búsqueda por texto | Autocompletar colonia por nombre o código postal | `/buscar` |
| Detalle de colonia | Datos completos de una colonia por ID | `/colonia/{id}` |
| Geolocalización | Colonia exacta a partir de coordenadas GPS | `/geolocate` |
| Distrito electoral | Distrito federal/local a partir de la Sección de la credencial del INE | `/distrito` |

Todas las respuestas son JSON. No hay versión SOAP, XML, ni GraphQL.

---

## 2. Cómo conseguir acceso

La API requiere una **API key** por proyecto consumidor. No hay registro automático: pídesela al equipo que administra ColoniasAPI (ellos la generan desde su panel interno). Vas a recibir algo como:

```
col_a1b2c3d4e5f67890...
```

Guárdala como si fuera una contraseña — no se puede recuperar después de creada, solo se puede pedir una nueva.

⚠️ **No la pongas en código JavaScript que corra en el navegador del usuario final** (no es secreta ahí — cualquiera puede verla con "Ver código fuente" o las herramientas de desarrollador). Si tu sitio es un frontend puro, haz las llamadas a ColoniasAPI **desde tu propio backend**, y que el navegador del usuario hable con tu backend, no directo con ColoniasAPI.

---

## 3. Autenticación

Todas las rutas, excepto `/health`, requieren la key en cada petición. Dos formas — usa la primera:

**Header (recomendado):**
```
Authorization: Bearer col_a1b2c3d4e5f67890...
```

**Parámetro de URL (solo para pruebas rápidas, ej. pegar una URL en el navegador):**
```
?api_key=col_a1b2c3d4e5f67890...
```

Una key inválida o inactiva responde `401`.

---

## 4. Formato de respuesta

Toda respuesta trae un campo `ok` booleano. Revísalo siempre antes de leer `data`.

**Éxito:**
```json
{ "ok": true, "data": { ... } , "tiempo_ms": 14 }
```
Si `data` es una lista, además viene `"total": <n>`.

**Error:**
```json
{ "ok": false, "error": "Descripción del error en español", "codigo": 404 }
```
El `codigo` dentro del JSON siempre coincide con el status HTTP de la respuesta.

---

## 5. Endpoints

### `GET /estados`

Catálogo completo de los 32 estados. Sin parámetros.

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/estados
```
```json
{
  "ok": true,
  "data": [
    { "id": 9, "clave": "09", "nombre": "Ciudad de México" },
    { "id": 15, "clave": "15", "nombre": "Estado de México" }
  ],
  "total": 32,
  "tiempo_ms": 3
}
```

---

### `GET /municipios?estado_id=`

| Parámetro | Tipo | Requerido | Descripción |
|---|---|---|---|
| `estado_id` | int | ✓ | `id` de un estado (el que devuelve `/estados`) |

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/municipios?estado_id=9"
```
```json
{
  "ok": true,
  "data": [
    { "id": 11, "nombre": "Álvaro Obregón", "clave_inegi": "09010" }
  ],
  "total": 16,
  "tiempo_ms": 4
}
```

---

### `GET /buscar?q=`

Autocompletar. Detecta automáticamente si `q` es un código postal (5 dígitos exactos) o texto libre.

| Parámetro | Tipo | Requerido | Descripción |
|---|---|---|---|
| `q` | string | ✓ | Texto a buscar, mínimo 2 caracteres, o CP de 5 dígitos |
| `estado_id` | int | — | Filtrar resultados por estado |
| `municipio_id` | int | — | Filtrar resultados por municipio |
| `limit` | int | — | Máximo de resultados (default 10, máx 50) |

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/buscar?q=polanco&limit=5"
```
```json
{
  "ok": true,
  "data": [
    {
      "id": 1060, "nombre": "Polanco I Sección", "tipo": "Colonia",
      "codigo_postal": "11510", "municipio_id": 11, "municipio": "Miguel Hidalgo",
      "estado_id": 9, "estado": "Ciudad de México"
    }
  ],
  "total": 1,
  "tiempo_ms": 8
}
```

**Errores esperables:** `q` con menos de 2 caracteres → `400`.

---

### `GET /colonia/{id}`

Detalle completo de una colonia por su `id` (el que devuelven `/buscar` o `/geolocate`).

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/colonia/1
```
```json
{
  "ok": true,
  "data": {
    "id": 1, "nombre": "San Ángel", "tipo": "Colonia", "codigo_postal": "01000",
    "municipio_id": 1, "municipio": "Álvaro Obregón",
    "estado_id": 9, "estado": "Ciudad de México",
    "lat": 19.346067, "lng": -99.193239,
    "tiene_poligono": true
  },
  "tiempo_ms": 5
}
```
`tiene_poligono` indica si esa colonia tiene polígono geográfico exacto (afecta la precisión de `/geolocate`, no la de este endpoint).

**Errores esperables:** ID inexistente → `404` ("Colonia no encontrada").

---

### `GET /geolocate?lat=&lng=`

Dado un punto GPS, devuelve la colonia a la que pertenece.

| Parámetro | Tipo | Requerido | Descripción |
|---|---|---|---|
| `lat` | float | ✓ | Latitud decimal |
| `lng` | float | ✓ | Longitud decimal |

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/geolocate?lat=19.43261&lng=-99.13321"
```
```json
{
  "ok": true,
  "data": {
    "id": 1060, "nombre": "Polanco I Sección", "tipo": "Colonia", "codigo_postal": "11510",
    "municipio_id": 11, "municipio": "Miguel Hidalgo",
    "estado_id": 9, "estado": "Ciudad de México",
    "lat": 19.4320, "lng": -99.1934,
    "metodo": "poligono"
  },
  "tiempo_ms": 22
}
```

El campo **`metodo`** indica la precisión del resultado:
- `"poligono"` — exacto, el punto cayó dentro del polígono real de la colonia.
- `"centroide"` — aproximado (la colonia más cercana por distancia), cuando no hay polígono para esa zona. Actualmente cubre ~53% de las colonias del país — fuera de esa cobertura, `/geolocate` sigue funcionando pero con este método aproximado.

**Errores esperables:** coordenadas fuera de México → `400`. Ninguna colonia cerca (rarísimo) → `404`.

---

### `GET /distrito?seccion=&estado_id=`

Distrito electoral federal y local a partir del número de **Sección** (dato impreso en el frente de la credencial del INE). No requiere geocodificar nada — es un catálogo directo.

| Parámetro | Tipo | Requerido | Descripción |
|---|---|---|---|
| `seccion` | string | ✓ | Número de sección, 1-4 dígitos (se normaliza solo, `1` y `0001` son lo mismo) |
| `estado_id` | int | ✓ | `id` del estado — obligatorio porque el número de sección se repite entre estados |

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/distrito?seccion=1&estado_id=9"
```
```json
{
  "ok": true,
  "data": {
    "seccion": "0001", "estado_id": 9, "estado": "Ciudad de México",
    "distrito_federal": 3, "distrito_federal_cabecera": null,
    "distrito_local": 5, "distrito_local_cabecera": null,
    "municipio_id": 2, "municipio": "Azcapotzalco"
  },
  "tiempo_ms": 4
}
```

**Errores esperables:** falta `seccion` o `estado_id` → `400`. Sección inexistente para ese estado → `404`.

---

### `GET /health`

Estado del servicio. **No requiere API key.** Útil para monitoreo (uptime checks) — no cuenta contra tu límite de peticiones.

```bash
curl https://tu-dominio.com/health
```
```json
{ "ok": true, "data": { "status": "ok", "colonias": 159201, "latencia_bd_ms": 4 }, "tiempo_ms": 6 }
```

---

## 6. Códigos de error — referencia rápida

| HTTP | Cuándo pasa | Qué hacer |
|---|---|---|
| `400` | Parámetro faltante o inválido | Revisa el mensaje en `error`, corrige el parámetro |
| `401` | API key faltante, inválida o desactivada | Verifica el header `Authorization`, pide una key nueva si sigue fallando |
| `404` | El recurso no existe (colonia, sección) | Trátalo como "sin resultado", no como error de tu integración |
| `429` | Superaste el límite de peticiones por minuto | Espera y reintenta — ver sección de límites abajo |
| `500` | Error interno del servicio | No es tu integración; avisa al equipo que administra la API con el `tiempo_ms` y la hora exacta |

---

## 7. Límites y buenas prácticas

- **Rate limit:** 100 peticiones por minuto por API key (puede variar según lo que te configuren). Si lo superas, recibes `429` — implementa un reintento con espera (ej. 1-2 segundos) en vez de reintentar inmediatamente en bucle.
- **Autocompletar (`/buscar`):** aplica un *debounce* de ~300ms en tu input de texto antes de llamar al endpoint, para no disparar una petición por cada tecla.
- **Cacheo del lado del servidor:** `/estados`, `/buscar` y `/geolocate` ya se cachean en el servidor de ColoniasAPI (hasta 24h) — no necesitas armar tu propio caché para reducir carga en ellos, aunque igual puedes cachear del lado del cliente si tu UI lo amerita.
- **Geolocalización desde el navegador:** usa `navigator.geolocation.getCurrentPosition()` para obtener `lat`/`lng` del dispositivo, y maneja el caso de que el usuario niegue el permiso (ofrece búsqueda manual por texto como alternativa).

---

## 8. Ejemplos de integración

### JavaScript (fetch, desde tu propio backend o un script de servidor)

```js
const BASE_URL = 'https://tu-dominio.com';
const API_KEY = process.env.COLONIAS_API_KEY; // nunca hardcodeado, nunca en el navegador

async function buscarColonia(texto) {
  const resp = await fetch(`${BASE_URL}/buscar?q=${encodeURIComponent(texto)}&limit=5`, {
    headers: { Authorization: `Bearer ${API_KEY}` },
  });
  const json = await resp.json();
  if (!json.ok) {
    throw new Error(json.error);
  }
  return json.data;
}

async function distritoPorSeccion(seccion, estadoId) {
  const resp = await fetch(`${BASE_URL}/distrito?seccion=${seccion}&estado_id=${estadoId}`, {
    headers: { Authorization: `Bearer ${API_KEY}` },
  });
  const json = await resp.json();
  return json.ok ? json.data : null; // null si la sección no existe (404)
}
```

### PHP (cURL)

```php
<?php
define('COLONIAS_BASE_URL', 'https://tu-dominio.com');
define('COLONIAS_API_KEY', getenv('COLONIAS_API_KEY')); // nunca hardcodeado

function coloniasApiGet(string $path): array
{
    $ch = curl_init(COLONIAS_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . COLONIAS_API_KEY],
        CURLOPT_TIMEOUT => 5,
    ]);
    $respuesta = curl_exec($ch);
    curl_close($ch);
    return json_decode($respuesta, true);
}

$resultado = coloniasApiGet('/geolocate?lat=19.43261&lng=-99.13321');
if ($resultado['ok']) {
    echo $resultado['data']['nombre'];
}
```

---

## 9. ¿Algo no cuadra?

Si una respuesta no coincide con lo documentado aquí, o te da `500` de forma consistente, contacta al equipo que administra ColoniasAPI con:
- El endpoint y parámetros exactos que usaste
- La respuesta completa que recibiste
- Hora aproximada de la petición

No intentes depurarlo tú mismo desde el lado del servidor — no tienes acceso a la base de datos ni a los logs de ColoniasAPI desde tu integración.
