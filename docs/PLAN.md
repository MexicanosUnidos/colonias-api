# ColoniasAPI — Plan completo de proyecto

> Documento de planificación para el equipo de desarrollo y agentes de IA.
> Leer completo antes de implementar cualquier tarea.

---

## Índice

1. [Qué es ColoniasAPI](#1-qué-es-coloniasapi)
2. [Fuentes de datos](#2-fuentes-de-datos)
3. [Arquitectura técnica](#3-arquitectura-técnica)
4. [Endpoints del API](#4-endpoints-del-api)
5. [Milestones y tareas](#5-milestones-y-tareas)
6. [Verificación técnica por milestone](#6-verificación-técnica-por-milestone)
7. [Verificación funcional — checklist para el usuario](#7-verificación-funcional--checklist-para-el-usuario)
8. [Referencia de tamaños de tarea](#8-referencia-de-tamaños-de-tarea)

---

## 1. Qué es ColoniasAPI

ColoniasAPI es un servicio web independiente (REST/JSON) que expone el catálogo completo de colonias de México con dos capacidades principales:

**A. Búsqueda por texto**
Dado un texto parcial, devuelve colonias que coincidan con el nombre de la colonia, código postal, municipio o estado. Diseñado para autocompletar en formularios.

**B. Geolocalización**
Dado un par de coordenadas GPS (latitud, longitud), devuelve la colonia exacta a la que pertenece ese punto, usando el polígono geográfico de cada colonia.

**C. Distrito electoral (por Sección)**
Dado el número de Sección electoral (dato impreso en la credencial del INE), devuelve el distrito federal y el distrito local a los que pertenece. A diferencia de A y B, esto **no requiere geocodificación ni polígonos**: es una búsqueda directa contra el catálogo oficial del INE, porque cada Sección pertenece a un único distrito (relación fija N:1). Ver sección 2.4 y milestones M8-M9.

Las tres capacidades son consumibles por cualquier proyecto mediante API key. Los proyectos actuales que lo usarán:
- **MeUnoColonia** — autocompletar colonia en el registro de colonos; detectar colonia por GPS del celular
- **Otros proyectos futuros** — cualquier sistema que necesite datos de colonias mexicanas

### Lo que NO es este proyecto
- No es un mapa ni visualizador
- No tiene panel de administración web (la administración es por scripts de importación)
- No guarda datos de usuarios ni peticiones
- No tiene autenticación de usuarios — solo autenticación de proyectos (API keys)

---

## 2. Fuentes de datos

El catálogo de colonias en México proviene de dos fuentes oficiales gratuitas que se deben combinar:

### 2.1 SEPOMEX — Datos de texto
- **URL de descarga:** https://www.correosdemexico.gob.mx/SSLServicios/ConsultaCP/Descarga.aspx
- **Formato real de la descarga nacional: XML** (`CPdescarga.xml`, un `<table>` por colonia con el esquema inline al inicio del archivo) — no CSV. `import/1a_xml_a_csv.php` lo convierte al CSV que espera `import/1_sepomex.php`.
- **Contenido:** nombre de colonia, código postal, tipo de asentamiento, municipio, estado
- **Tamaño real confirmado:** 159,201 registros (colonias), 31,875 códigos postales distintos, 32 estados, 2,478 municipios
- **Actualización:** ocasional (cuando se crean colonias nuevas)
- **Columnas relevantes — ⚠️ fácil de confundir:**
  - `d_asenta` — nombre de la colonia
  - `d_tipo_asenta` — tipo: Colonia, Fraccionamiento, Pueblo, Ejido, etc.
  - `d_codigo` — **el código postal real de la colonia** (5 dígitos; ~32,000 valores distintos en el país)
  - `d_CP` — ⚠️ **NO es el código postal de la colonia** pese al nombre — es la clave de la oficina postal, mucho más genérica (~1,200 valores en todo el país). Usarla como CP asigna el mismo código postal a cientos de colonias distintas.
  - `d_mnpio` — nombre del municipio
  - `d_estado` — nombre del estado
  - `c_estado` — clave de 2 dígitos del estado (para unir con INEGI)
  - `c_mnpio` — clave de municipio

### 2.2 INEGI — Polígonos de colonias (programa DCAH) — confirmado con archivos reales

> ⚠️ El UPC de biblioteca usado antes en este documento (`889463770944`) ya no aparece en el sitio de INEGI. El producto correcto es un programa dedicado, no una edición genérica del Marco Geoestadístico: **DCAH — Delimitación de Colonias y otros Asentamientos Humanos**, https://www.inegi.org.mx/programas/dcah/ (edición 2025, corte cartográfico diciembre 2024).

- **Estructura real del paquete descargado** (carpeta `Poligonos/` del proyecto): 32 carpetas por estado (`01_aguascalientes.zip` … `32_zacatecas.zip`) más **`00_integrado.zip`**, el archivo **nacional ya combinado** — usar este único archivo evita procesar los 32 por separado. Cada carpeta trae 3 subcarpetas: `catalogos/` (CSV/PDF descriptivos), `conjunto_de_datos/` (el Shapefile: `.shp .dbf .shx .prj .cpg .sbn .sbx`), `metadatos/` (XML/TXT).
- **Total nacional confirmado:** 79,775 polígonos en 14,110 localidades (coincide exacto con el archivo real: `00as.dbf` tiene 79,775 registros).
- **Atributos reales del `.dbf`** (confirmados leyendo el archivo — difieren de lo que se asumía antes, no es `NOMGEO`):
  - `cvegeo` — clave geoestadística completa
  - `cve_ent` (2 dígitos) / `cve_mun` (3 dígitos) — para unir con SEPOMEX igual que antes
  - `cve_loc`, `cve_asen` — claves de localidad y de asentamiento
  - `cp` — código postal (bonus no documentado originalmente; el propio INEGI advierte que no está validado contra Correos de México, puede venir en ceros — no usarlo como fuente principal de CP)
  - `nom_asen` — nombre del asentamiento/colonia (la columna para emparejar por nombre)
  - `tipo` — tipo de asentamiento (texto: COLONIA, FRACCIONAMIENTO, etc.)
- **⚠️ Proyección — el hallazgo más importante:** el Shapefile **no viene en WGS84** (lat/lng). El `.prj` declara una **Cónica Conforme de Lambert** (México ITRF2008 LCC): meridiano central -102°, paralelos estándar 17.5°/29.5°, latitud de origen 12°N, falso este 2,500,000, elipsoide GRS80. Hay que reproyectar cada vértice a WGS84 antes de guardar — `import/2_dcah_geo.php` lo hace con una implementación propia de la fórmula inversa de Lambert (Snyder 1987), sin depender de GDAL/ogr2ogr. **Validado**: se corrió contra los 79,775 polígonos reales y los 79,775 resultaron dentro del rango geográfico de México (14°-33°N, -118°/-86°W), cero anomalías.
- **⚠️ Cobertura incompleta por diseño — no es un defecto de la importación:** el DCAH prioriza capitales y localidades de 50,000+ habitantes; no cubre el 100% de las colonias de SEPOMEX. Cruce real hecho entre `nom_asen` (DCAH) y `d_asenta` (SEPOMEX) por nombre normalizado + clave de municipio: **~53% de coincidencia** (42,237 de 79,775). Es más bajo que el ideal >75% que se documentaba antes — normal al cruzar dos catálogos independientes por nombre; candidato a mejorar después con matching más flexible (por CP, por similitud de texto) si hace falta más cobertura. Mientras tanto sigue existiendo el fallback de M3.4 (centroide aproximado) y el campo `metodo` de `/geolocate`.

### 2.3 Estrategia de unión
SEPOMEX tiene los CP y tipos; INEGI tiene los polígonos. Se unen por:
```
clave_estado + clave_municipio + nombre_colonia (normalizado: sin acentos, mayúsculas)
```
El script de importación hace esta unión. Los que no emparejan quedan sin polígono (el campo queda NULL) y solo funcionan en búsqueda por texto, no en geolocalización exacta.

### 2.4 INE — Catálogo de Secciones Electorales

> **Contexto para la IA que implemente esto:** esta fuente es distinta a SEPOMEX/INEGI. La autoridad es el **INE** (Instituto Nacional Electoral), no el INEGI. No confundir los dos organismos ni sus catálogos.

- **Qué es una "Sección electoral":** la unidad territorial más pequeña del sistema electoral mexicano. Es un número de 4 dígitos (ej. `0001`) impreso en el frente de la credencial para votar (INE). **Cada sección pertenece a un único distrito federal y a un único distrito local** — no hay traslape. Por eso, a diferencia de la geolocalización por GPS (sección 2.2/M5), aquí **no se necesita punto-en-polígono ni geocodificar direcciones**: es un `SELECT` directo.
- **URL de descarga confirmada:** https://cartografia.ine.mx/sige8/ → sección "Marco Geográfico Electoral". Ahí hay 3 productos descargables; **el que sirve es "Base Geográfica Digital (BGD)"**:
  - ~~Mapas Digitales~~ — son PDF de mapas (visual), no sirve.
  - ~~Plano de Sección Individual (PSI)~~ — un PDF por cada sección (73,268 archivos), no sirve como catálogo.
  - **Base Geográfica Digital (BGD)** ✓ — trae el Marco Geográfico Seccional en Shapefile y catálogos en **MDB, XLS y TXT**. Preferir el catálogo XLS/TXT (evitar MDB salvo tener Access/mdbtools) — es la vía más rápida a un CSV.
  - La descarga es por estado (32 paquetes), igual que el Marco Geográfico de INEGI en M4.
  - Totales nacionales confirmados en la propia página (referencia para verificar la importación completa): 300 distritos federales, 679 distritos locales, 2,477 municipios, **73,268 secciones electorales**.
- **Formato:** CSV/XLS/TXT (convertir XLS a CSV si es necesario), normalmente un archivo por entidad.
- **Contenido esperado (columnas típicas, verificar contra el archivo real antes de programar el import):**
  - `seccion` — número de sección (4 dígitos, con ceros a la izquierda, ej. `"0001"`)
  - `entidad` / `clave_entidad` — clave de 2 dígitos del estado (para unir con la tabla `estados` ya existente)
  - `distrito_federal` — número del distrito federal (1-N, N varía por estado)
  - `distrito_local` — número del distrito local (puede no existir para todas las entidades)
  - `municipio` / `clave_municipio` — para unir con la tabla `municipios` ya existente
  - `cabecera_distrital` — nombre del municipio/ciudad sede del distrito (opcional, solo informativo)
- **Advertencia importante:** el número de Sección **se repite entre estados** (ej. puede haber una sección "0001" en Jalisco y otra "0001" en Sonora). Por eso la llave para buscar siempre debe ser **sección + estado**, nunca sección sola. El usuario final normalmente ya sabe su estado (por dirección o porque lo captura en el mismo formulario).

---

## 3. Arquitectura técnica

### 3.1 Infraestructura
```
Servidor compartido (mismo que meunocolonia.org)
├── MySQL 8.x
│   ├── meunocoonia        ← BD existente de MeUnoColonia (no tocar)
│   └── colonias_mx        ← BD nueva de ColoniasAPI
└── PHP 8.x
    └── colonias-api/      ← carpeta raíz del proyecto
        ├── index.php      ← router (web root apunta aquí)
        ├── .htaccess
        ├── config/
        ├── middleware/
        ├── handlers/
        ├── helpers/
        ├── cache/
        └── import/
```

### 3.2 Base de datos — Diagrama de tablas

```
api_keys                    estados (32 filas)
  id                          id
  proyecto                    clave CHAR(2)
  key_hash CHAR(64)           nombre
  activa
  ultimo_uso                municipios (~2,500 filas)
                              id
                              estado_id → estados.id
colonias (~145,000 filas)     clave_inegi
  id                          nombre
  nombre
  municipio_id → municipios.id
  codigo_postal             colonia_poligonos (~145,000 filas)
  tipo                        colonia_id → colonias.id  [PK]
  centroide  POINT            poligono   GEOMETRY NOT NULL
  creado_en                   SPATIAL INDEX sp_poligono

distritos_federales            distritos_locales
  id                            id
  estado_id → estados.id        estado_id → estados.id
  numero                        numero
  cabecera (opcional)           cabecera (opcional)

secciones_electorales (~68,000 filas, catálogo INE)
  seccion CHAR(4)
  estado_id → estados.id
  distrito_federal_id → distritos_federales.id
  distrito_local_id → distritos_locales.id  [NULL permitido]
  municipio_id → municipios.id  [NULL permitido]
  PK compuesta (seccion, estado_id)  ← la sección se repite entre estados
```

**Por qué dos tablas para colonias y polígonos:**
- Los polígonos se importan en una segunda fase (primero SEPOMEX, luego INEGI); mientras tanto `colonias.centroide` queda `NULL`
- `colonia_poligonos.poligono` sí es `NOT NULL` y sí tiene `SPATIAL INDEX` (`sp_poligono`) — todo `ST_Within()` de `/geolocate` pasa por ahí
- `colonias.centroide` **no** lleva índice espacial: el fallback Haversine de `/geolocate` filtra con `ST_X()/ST_Y() BETWEEN`, que un índice espacial no acelera, y un `SPATIAL INDEX` exige que la columna sea `NOT NULL` — incompatible con dejarla vacía entre M3 y M4. Un `KEY` normal ahí tampoco ayudaría a ese filtro, así que se dejó sin índice (colonias es candidata a bounding box, no hay tantas filas por consulta como para notarlo)
- Las consultas de texto no necesitan cargar los polígonos (más rápido)
- Permite que el sistema funcione parcialmente desde el día 1

### 3.3 Índices críticos

| Tabla | Índice | Tipo | Para qué sirve |
|-------|--------|------|----------------|
| `colonias` | `ft_nombre` | FULLTEXT | Búsqueda por texto libre |
| `colonias` | `idx_cp` | B-Tree | Búsqueda por código postal |
| `colonias` | `idx_municipio` | B-Tree | Filtrar por municipio |
| `colonia_poligonos` | `sp_poligono` | SPATIAL (R-Tree) | `ST_Within()` para GPS |

### 3.4 Sistema de caché

Sin Redis en el hosting compartido, se usa caché de archivos PHP:
- **Ubicación:** `cache/responses/`
- **Nombre de archivo:** `md5(endpoint + params).json`
- **TTL:** 24 horas (el catálogo de colonias cambia muy poco)
- **Invalidar:** borrar archivos en `cache/responses/` manualmente o por script

```
cache/
  responses/
    a3f8c2...json    ← /buscar?q=polanco
    9d12e4...json    ← /buscar?q=roma
    c71ab3...json    ← /geolocate?lat=19.43&lng=-99.13
```

### 3.5 Coordenadas — Convención importante

El proyecto usa **SRID 0** (Cartesiano) en lugar de SRID 4326 (WGS84 estándar) para evitar problemas de orden de ejes en MySQL 8 (que invierte lat/lng en SRID 4326). A escala de México la diferencia es < 0.01%.

**Convención de almacenamiento:**
```
POINT(longitud, latitud)   — x = lng, y = lat
ST_X(centroide) = longitud
ST_Y(centroide) = latitud
```

**Coordenadas en respuestas JSON siempre como:**
```json
{ "lat": 19.43261, "lng": -99.13321 }
```

---

## 4. Endpoints del API

### Autenticación
Todas las peticiones requieren la API key en el header:
```
Authorization: Bearer {api_key}
```
O como parámetro de URL (menos seguro, solo para pruebas):
```
?api_key={api_key}
```

### Formato de respuesta estándar

**Éxito:**
```json
{
  "ok": true,
  "data": [...],
  "total": 10,
  "tiempo_ms": 14
}
```

**Error:**
```json
{
  "ok": false,
  "error": "Descripción del error en español",
  "codigo": 401
}
```

---

### `GET /buscar`

Búsqueda por texto. Detecta automáticamente el tipo de query:
- Si son 5 dígitos → busca por código postal exacto
- Si no → FULLTEXT en nombre de colonia + municipio

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `q` | string | ✓ | Texto a buscar (mín. 2 caracteres) |
| `estado_id` | int | — | Filtrar por estado |
| `municipio_id` | int | — | Filtrar por municipio |
| `limit` | int | — | Resultados (default 10, máx 50) |

**Ejemplo:** `GET /buscar?q=polanco&limit=5`
```json
{
  "ok": true,
  "data": [
    {
      "id": 12834,
      "nombre": "Polanco I Sección",
      "tipo": "Colonia",
      "codigo_postal": "11550",
      "municipio": "Miguel Hidalgo",
      "estado": "Ciudad de México",
      "estado_id": 9,
      "municipio_id": 214
    }
  ],
  "total": 1,
  "tiempo_ms": 8
}
```

**Ejemplo CP:** `GET /buscar?q=11550`
```json
{
  "ok": true,
  "data": [
    { "id": 12834, "nombre": "Polanco I Sección", "codigo_postal": "11550", ... },
    { "id": 12835, "nombre": "Polanco II Sección", "codigo_postal": "11550", ... },
    { "id": 12836, "nombre": "Polanco III Sección", "codigo_postal": "11550", ... }
  ],
  "total": 3,
  "tiempo_ms": 4
}
```

---

### `GET /geolocate`

Dado un punto GPS, devuelve la colonia exacta usando polígonos.

**Flujo interno:**
1. Busca con `ST_Within(POINT(lng,lat), poligono)` — exacto, usa SPATIAL INDEX
2. Si no encuentra (punto en frontera o sin polígono), usa Haversine al centroide más cercano — fallback
3. Respuesta incluye campo `metodo` para que el consumidor sepa la precisión

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `lat` | float | ✓ | Latitud decimal (ej. 19.43261) |
| `lng` | float | ✓ | Longitud decimal (ej. -99.13321) |

**Ejemplo:** `GET /geolocate?lat=19.43261&lng=-99.13321`
```json
{
  "ok": true,
  "data": {
    "id": 12834,
    "nombre": "Polanco I Sección",
    "tipo": "Colonia",
    "codigo_postal": "11550",
    "municipio": "Miguel Hidalgo",
    "estado": "Ciudad de México",
    "estado_id": 9,
    "municipio_id": 214,
    "lat": 19.43200,
    "lng": -99.13400,
    "metodo": "poligono"
  },
  "tiempo_ms": 18
}
```

Campo `metodo`:
- `"poligono"` — resultado exacto por ST_Within (máxima precisión)
- `"centroide"` — resultado aproximado por Haversine (sin polígono disponible)

---

### `GET /estados`

Catálogo de los 32 estados. Sin parámetros.

**Ejemplo:** `GET /estados`
```json
{
  "ok": true,
  "data": [
    { "id": 1, "clave": "01", "nombre": "Aguascalientes" },
    { "id": 9, "clave": "09", "nombre": "Ciudad de México" }
  ],
  "total": 32,
  "tiempo_ms": 2
}
```

---

### `GET /municipios`

Municipios de un estado.

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `estado_id` | int | ✓ | ID del estado |

**Ejemplo:** `GET /municipios?estado_id=9`
```json
{
  "ok": true,
  "data": [
    { "id": 210, "nombre": "Álvaro Obregón", "clave_inegi": "09010" },
    { "id": 214, "nombre": "Miguel Hidalgo", "clave_inegi": "09007" }
  ],
  "total": 16,
  "tiempo_ms": 3
}
```

---

### `GET /colonia/{id}`

Detalle completo de una colonia por ID.

**Ejemplo:** `GET /colonia/12834`
```json
{
  "ok": true,
  "data": {
    "id": 12834,
    "nombre": "Polanco I Sección",
    "tipo": "Colonia",
    "codigo_postal": "11550",
    "municipio": "Miguel Hidalgo",
    "municipio_id": 214,
    "estado": "Ciudad de México",
    "estado_id": 9,
    "lat": 19.43200,
    "lng": -99.13400,
    "tiene_poligono": true
  },
  "tiempo_ms": 3
}
```

---

### `GET /distrito`

Dado el número de Sección electoral (dato impreso en la credencial del INE) y el estado, devuelve el distrito federal y el distrito local a los que pertenece. Es una búsqueda directa contra el catálogo del INE — **no** calcula nada geográfico ni necesita coordenadas (ver sección 2.4).

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `seccion` | string | ✓ | Número de sección, 4 dígitos (ej. `0001`). Acepta con o sin ceros a la izquierda. |
| `estado_id` | int | ✓ | ID del estado (el mismo `id` que devuelve `/estados`). Obligatorio porque el número de sección se repite entre estados. |

**Ejemplo:** `GET /distrito?seccion=0001&estado_id=9`
```json
{
  "ok": true,
  "data": {
    "seccion": "0001",
    "estado_id": 9,
    "estado": "Ciudad de México",
    "distrito_federal": 3,
    "distrito_federal_cabecera": "Miguel Hidalgo",
    "distrito_local": 15,
    "distrito_local_cabecera": "Miguel Hidalgo",
    "municipio": "Miguel Hidalgo",
    "municipio_id": 214
  },
  "tiempo_ms": 3
}
```

**Ejemplo de error (sección inexistente):**
```json
{ "ok": false, "error": "Sección electoral no encontrada para ese estado", "codigo": 404 }
```

**Cómo llamarlo desde un formulario típico:**
1. El usuario captura o selecciona su estado (usar `/estados`, ya existente).
2. El usuario captura el número de Sección que aparece en su credencial del INE (campo de texto libre, 4 dígitos).
3. El frontend llama `GET /distrito?seccion={valor}&estado_id={id}` con la misma API key que ya usa para los demás endpoints.
4. Se muestra `distrito_federal` y/o `distrito_local` según lo que el proyecto consumidor necesite.

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/distrito?seccion=0001&estado_id=9"
```

---

### `POST /keys/crear` *(solo uso interno — IP whitelist)*

Crea una nueva API key para un proyecto.

**Body JSON:**
```json
{ "proyecto": "MeUnoColonia", "admin_secret": "****" }
```

**Respuesta:**
```json
{
  "ok": true,
  "data": {
    "proyecto": "MeUnoColonia",
    "api_key": "col_a1b2c3d4e5f6..."
  }
}
```

La `api_key` se muestra una sola vez — el sistema guarda solo el hash.

---

## 5. Milestones y tareas

### Referencia de tamaños de IA

| Etiqueta | Modelo adecuado | Tipo de tarea |
|----------|----------------|---------------|
| 🟢 **pequeña** | Haiku / GPT-3.5 | SELECT simple, respuesta JSON estática, configuración |
| 🟡 **mediana** | Sonnet / GPT-4o-mini | Lógica de negocio, múltiples tablas, caché, validaciones |
| 🔴 **grande** | Opus / GPT-4o | Importación GeoJSON, índices espaciales, Haversine, router complejo |
| ✅ **verificación** | Cualquier modelo | QA, checklists, comparar esperado vs actual |

---

### M0 — Fundación del proyecto
*Objetivo: Repositorio funcional con estructura lista para desarrollar.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M0.1 | Crear repo `colonias-api` en GitHub, estructura de carpetas | 🟢 | Repo con carpetas config/, middleware/, handlers/, helpers/, cache/, import/ |
| M0.2 | Crear `schema.sql` con tablas api_keys, estados, municipios, colonias, colonia_poligonos + índices + INSERT de los 32 estados | 🟡 | Archivo ejecutable en MySQL sin errores |
| M0.3 | Crear `config/env.example.php` con todas las constantes requeridas | 🟢 | Archivo documentado con cada constante explicada |
| M0.4 | Crear `config/db.php` con función `getDB()` singleton PDO y función `jsonResponse()` | 🟢 | Conexión a BD y helper de respuesta JSON |
| M0.5 | Crear `.htaccess` que rewrite todo a `index.php` | 🟢 | Todas las rutas llegan a index.php |
| M0.6 | Crear `index.php` como router básico: parsear URI, devolver 404 JSON si ruta no existe | 🟡 | Router que despacha a handlers/ |
| ✅ M0.V | Verificar: `curl /estados` devuelve 401, `curl /estados?api_key=test` devuelve 401, servidor sin errores 500 | ✅ | Checklist completado |

---

### M1 — Autenticación
*Objetivo: Todas las rutas protegidas por API key.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M1.1 | Crear `middleware/auth.php`: leer header `Authorization: Bearer` o `?api_key=`, calcular SHA-256, buscar en `api_keys`, actualizar `ultimo_uso` | 🟡 | Middleware que bloquea sin key válida |
| M1.2 | Crear `handlers/keys.php`: endpoint `POST /keys/crear` protegido por `ADMIN_SECRET` en env | 🟡 | Genera key aleatoria (32 bytes hex), devuelve una vez |
| M1.3 | Insertar manualmente la primera API key para MeUnoColonia vía script CLI | 🟢 | Key guardada en BD, probada con curl |
| ✅ M1.V | Verificar: key inválida → 401, key válida → pasa, key desactivada → 401, `ultimo_uso` se actualiza | ✅ | |

---

### M2 — Endpoints de catálogo (sin geolocalización)
*Objetivo: /estados, /municipios y /buscar funcionando.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M2.1 | `handlers/estados.php` — SELECT todos los estados, respuesta JSON | 🟢 | GET /estados devuelve los 32 estados |
| M2.2 | `handlers/municipios.php` — SELECT municipios WHERE estado_id, validar parámetro | 🟢 | GET /municipios?estado_id=9 |
| M2.3 | `handlers/colonias.php` — GET /colonia/{id} con JOIN a municipio y estado | 🟢 | Detalle de colonia por ID |
| M2.4 | `handlers/buscar.php` — Detectar si query es CP (5 dígitos) o texto; rama CP: WHERE codigo_postal = ?; rama texto: MATCH AGAINST con JOIN a municipio/estado | 🟡 | /buscar?q=polanco y /buscar?q=11550 |
| M2.5 | Caché de archivos en `helpers/cache.php`: `cacheGet(key)` / `cacheSet(key, data, ttl)` usando serialize/unserialize en `cache/responses/` | 🟡 | Segundo request del mismo query usa archivo, no BD |
| M2.6 | Aplicar caché en /buscar y /estados (TTL 24h) | 🟢 | Verificable con `ls cache/responses/` |
| ✅ M2.V | Verificar: buscar por nombre parcial, por CP, por municipio + estado, query < 2 chars → error claro, limit respetado | ✅ | |

---

### M3 — Importación SEPOMEX (datos de texto)
*Objetivo: Las colonias en la BD con nombre, CP, municipio, estado.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M3.0 | Descargar el XML nacional de SEPOMEX (`CPdescarga.xml`, manual, el usuario lo hace) y colocarlo en cualquier ruta (ej. `Codigos Postales/CPdescarga.xml`) | — | Archivo XML disponible |
| M3.0b | `import/1a_xml_a_csv.php` — convertir el XML a `import/data/sepomex.csv`. **Usa `d_codigo` para el CP, no `d_CP`** (ver advertencia en sección 2.1) | 🟡 | CSV generado, confirmado con 159,201 filas / 31,875 CPs distintos / 32 estados |
| M3.1 | *(alternativa a M3.0/M3.0b)* Si en vez del XML se consigue un CSV ya armado de SEPOMEX, colocarlo directo en `import/data/sepomex.csv` | — | Archivo CSV en carpeta |
| M3.2 | `import/1_sepomex.php` — leer CSV, insertar/actualizar municipios (deduplicar por clave_inegi), insertar colonias con centroide vacío temporal | 🔴 | Script CLI que corre sin errores en ~5 min |
| M3.3 | Verificar conteos post-importación: `SELECT COUNT(*) FROM colonias` ≈ 159,000 | 🟢 | Query de verificación |
| M3.4 | Calcular centroide promedio por colonia a partir de sus vecinos del mismo CP (estimación provisional hasta tener INEGI/DCAH) | 🟡 | Campo centroide con valor aproximado |
| ✅ M3.V | Verificar: /buscar?q=roma → colonias de CDMX y otras ciudades; /buscar?q=06600 → colonias de Juárez CDMX | ✅ | |

---

### M4 — Importación INEGI/DCAH (polígonos geográficos)
*Objetivo: Cada colonia con su polígono exacto en colonia_poligonos.*

> Actualizado tras confirmar el archivo real (ver 2.2): el producto es DCAH, no GeoJSON genérico, y viene en una proyección que hay que convertir a mano. `import/2_dcah_geo.php` ya reemplaza el plan original de `2_inegi_geo.php` (ese script se deja en el repo por si algún día se usa un GeoJSON real, pero no es el camino recomendado).

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M4.1 | Descargar el paquete DCAH del INEGI (manual, ver 2.2) y colocarlo en `Poligonos/` — usar `00_integrado.zip` (nacional, ya combinado) | — | `Poligonos/00_integrados/conjunto_de_datos/00as.shp` + `.dbf` disponibles |
| M4.2 | `import/2_dcah_geo.php` — parser de Shapefile propio (sin GDAL) + reproyección Lambert Conformal Conic → WGS84 (validado contra los 79,775 polígonos reales, 0 fuera de México) + emparejamiento por `nom_asen` normalizado + `cve_ent`+`cve_mun`, inserta en `colonia_poligonos` y actualiza `centroide` | 🔴 | Script CLI, registra sin match en `import/logs/dcah_sin_match.txt` |
| M4.3 | Revisar `dcah_sin_match.txt` — cobertura real medida por cruce de nombres: **~53%** (42,237 de 79,775). Es esperado (cobertura DCAH incompleta + diferencias de nomenclatura entre catálogos), no es un bug | 🟢 | Porcentaje de cobertura documentado |
| M4.4 | Agregar SPATIAL INDEX en `colonia_poligonos.poligono` (ya está en schema, verificar que se creó) | 🟢 | `SHOW INDEX FROM colonia_poligonos` muestra el índice |
| ✅ M4.V | `SELECT COUNT(*) FROM colonia_poligonos` — con el ~53% de match esperado, revisar que la cifra ronde las ~40,000 filas, no que sea 0 o el 100% de las colonias | ✅ | |

---

### M5 — Endpoint de geolocalización
*Objetivo: /geolocate funciona con polígono exacto y fallback Haversine.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M5.1 | `helpers/geo.php` — función `haversineMetros(lat1,lng1,lat2,lng2)` (tomada del doc de Espejo Electoral) | 🟢 | Función probada con coordenadas conocidas |
| M5.2 | `handlers/geolocate.php` — validar lat/lng (float, rango México: lat 14-33, lng -118/-86), buscar con ST_Within en colonia_poligonos | 🔴 | GET /geolocate?lat=19.43&lng=-99.13 devuelve colonia |
| M5.3 | Implementar fallback Haversine: si ST_Within no encuentra resultado, hacer SELECT de las 20 colonias con centroide más cercano por bounding box, calcular Haversine y devolver la más cercana | 🔴 | Campo `metodo: "centroide"` en respuesta |
| M5.4 | Caché para /geolocate: key = `geo_` + round(lat,3) + round(lng,3) (precisión ~100m) | 🟡 | Coordenadas cercanas usan el mismo cache |
| ✅ M5.V | Probar con 5 puntos conocidos de distintas ciudades; verificar que `metodo` indica la fuente correcta | ✅ | |

---

### M6 — Integración con MeUnoColonia
*Objetivo: El formulario de registro de colonos usa ColoniasAPI.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M6.1 | En MeUnoColonia `portal/registro/`: agregar campo "Colonia" con autocompletar que llama a ColoniasAPI /buscar mientras el usuario escribe | 🟡 | Autocompletar funcional con debounce 300ms |
| M6.2 | Al seleccionar colonia, guardar `colonia_api_id` (el ID de ColoniasAPI) en la tabla `colonos` | 🟢 | Campo `colonia_api_id` en tabla colonos |
| M6.3 | Botón "Detectar mi colonia por GPS" — usa `navigator.geolocation` (código del doc Espejo Electoral) y llama a /geolocate | 🟡 | Botón que llena el campo de colonia automáticamente |
| M6.4 | Manejo de errores: GPS denegado → mensaje claro; sin resultado → pedir texto manual | 🟢 | Mensajes en lenguaje cotidiano |
| ✅ M6.V | Probar registro en celular real; probar con GPS; probar escribiendo texto parcial | ✅ | |

---

### M7 — QA final y documentación
*Objetivo: El API es estable, documentado y listo para nuevos consumidores.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M7.1 | `README.md` del repo colonias-api con: qué es, cómo instalar, cómo usar cada endpoint con ejemplos curl | 🟢 | Documento público |
| M7.2 | Script `import/3_verificar.php` que valida: conteos de tablas, % de polígonos, 10 queries de prueba, tiempo de respuesta | 🟡 | Reporte de salud de la BD |
| M7.3 | Rate limiting básico: máximo 100 requests/minuto por API key, usando tabla `api_rate_log` en MySQL | 🟡 | 429 Too Many Requests si se excede |
| M7.4 | Endpoint `GET /health` público (sin auth) que devuelve: status ok/error, conteo de colonias, latencia de BD | 🟢 | Monitoreo básico |
| ✅ M7.V | Checklist completo de usuario (ver Sección 7) | ✅ | |

---

### M8 — Importación de catálogo de Secciones Electorales (INE)
*Objetivo: tener en la BD el catálogo completo de secciones electorales, cada una vinculada a su distrito federal y distrito local.*

> **Contexto general para toda esta milestone:** el INE publica un catálogo (no un mapa) que dice, para cada número de Sección, a qué distrito federal y distrito local pertenece. Es un archivo de texto (CSV/Excel), no un GeoJSON — no hay geometría involucrada aquí. Ver sección 2.4 para el detalle de columnas. Cada tarea de abajo es intencionalmente pequeña: hacer una cosa, verificarla, seguir con la siguiente.

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M8.1 | Descargar en https://cartografia.ine.mx/sige8/ el producto **"Base Geográfica Digital (BGD)"** (no "Mapas Digitales" ni "Plano de Sección Individual") de los 32 estados, extraer el catálogo XLS/TXT de cada uno, convertir a CSV y concatenar en `import/data/ine_secciones.csv` (manual, lo hace el usuario) | — | Archivo CSV único con las 32 entidades en la carpeta |
| M8.2 | Abrir el CSV y anotar en un comentario al inicio de `import/5_ine_secciones.php` cuáles son los nombres reales de las columnas (pueden no llamarse igual que en la sección 2.4 — ej. podría ser `ENTIDAD` en mayúsculas) | 🟢 | Comentario con el mapeo columna real → campo interno |
| M8.3 | Agregar a `schema.sql` la tabla `distritos_federales`: columnas `id`, `estado_id` (FK a `estados.id`), `numero` (TINYINT), `cabecera` (VARCHAR NULL), índice único en (`estado_id`, `numero`) | 🟢 | Tabla nueva en schema.sql, sin tocar las tablas existentes |
| M8.4 | Agregar a `schema.sql` la tabla `distritos_locales`: mismas columnas que M8.3 pero para distrito local | 🟢 | Tabla nueva en schema.sql |
| M8.5 | Agregar a `schema.sql` la tabla `secciones_electorales` con PK compuesta (`seccion`, `estado_id`) y FKs a `estados`, `distritos_federales`, `distritos_locales`, `municipios` (ver diagrama en sección 3.2) | 🟡 | Tabla nueva en schema.sql |
| M8.6 | Ejecutar `schema.sql` actualizado en la BD de desarrollo (`mysql -u user -p colonias_mx < schema.sql`) y confirmar con `SHOW TABLES;` que las 3 tablas nuevas existen | 🟢 | 3 tablas visibles en la BD |
| M8.7 | `import/5_ine_secciones.php` — parte 1: leer el CSV completo a memoria (o por líneas), y solo con un `echo` imprimir cuántas filas tiene y las primeras 3 filas crudas, sin insertar nada todavía | 🟢 | Al correr el script en consola se ve el conteo de filas y un ejemplo |
| M8.8 | `import/5_ine_secciones.php` — parte 2: por cada fila, extraer la clave de estado y buscar el `id` correspondiente en la tabla `estados` ya existente (JOIN por `clave`). Si no encuentra el estado, escribir la fila en `import/logs/secciones_sin_estado.txt` y seguir con la siguiente | 🟡 | Script no se detiene ante filas problemáticas, deja rastro en el log |
| M8.9 | `import/5_ine_secciones.php` — parte 3: recolectar los pares únicos (estado_id, número de distrito federal) de todas las filas e insertarlos en `distritos_federales` con `INSERT IGNORE` (evita duplicados gracias al índice único de M8.3) | 🟡 | `SELECT COUNT(*) FROM distritos_federales` da un número razonable (decenas por estado) |
| M8.10 | `import/5_ine_secciones.php` — parte 4: igual que M8.9 pero para `distritos_locales` | 🟡 | `SELECT COUNT(*) FROM distritos_locales` da un número razonable |
| M8.11 | `import/5_ine_secciones.php` — parte 5: por cada fila del catálogo, resolver `distrito_federal_id` y `distrito_local_id` (buscando en las tablas ya llenadas por M8.9/M8.10) y hacer `INSERT` en `secciones_electorales`. Usar inserción en lotes (ej. 500 filas por `INSERT`) para que no tarde horas | 🔴 | Script corre completo sin errores en unos minutos |
| M8.12 | `import/5b_verificar_secciones.php` — script de verificación: cuenta total de secciones importadas, cuenta de secciones sin `distrito_local_id` (puede ser normal en algunas entidades), cuenta de distritos sin ninguna sección asociada | 🟢 | Reporte impreso en consola, análogo a `import/1c_verificar_conteos.php` |
| ✅ M8.V | Verificar manualmente 5 secciones de al menos 3 estados distintos contra el "Ubicador de Módulos"/consulta oficial del INE (buscar en https://www.ine.mx) y confirmar que el distrito coincide | ✅ | Checklist completado, sin discrepancias |

---

### M9 — Endpoint de Distrito Electoral
*Objetivo: `GET /distrito?seccion=&estado_id=` funcionando, documentado y protegido igual que los demás endpoints.*

> **Contexto:** este endpoint es el más sencillo de todo el API porque es un `SELECT` con JOINs fijos, sin cálculo geográfico ni texto libre. Usar como plantilla `handlers/municipios.php` (también recibe un solo filtro y hace un JOIN simple).

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M9.1 | Crear `handlers/distrito.php` con el esqueleto mínimo: leer `$_GET['seccion']` y `$_GET['estado_id']`, si falta alguno responder error 400 con `jsonResponse()` (igual patrón que `handlers/municipios.php`) | 🟢 | Endpoint responde error claro si faltan parámetros |
| M9.2 | Normalizar el parámetro `seccion`: rellenar con ceros a la izquierda hasta 4 dígitos (ej. `"1"` → `"0001"`) usando `str_pad()` | 🟢 | `?seccion=1` y `?seccion=0001` dan el mismo resultado |
| M9.3 | Escribir el `SELECT` con JOIN de `secciones_electorales` a `distritos_federales`, `distritos_locales`, `municipios`, `estados`, filtrando por `seccion` + `estado_id` (ver PK compuesta de M8.5) | 🟡 | Query devuelve una sola fila para una sección válida |
| M9.4 | Si el `SELECT` no devuelve filas, responder `{"ok":false,"error":"Sección electoral no encontrada para ese estado","codigo":404}` | 🟢 | Caso de sección inexistente probado con curl |
| M9.5 | Armar el JSON de respuesta exitosa con el formato exacto mostrado en la sección 4 (`GET /distrito`) de este documento | 🟢 | Respuesta igual al ejemplo documentado |
| M9.6 | Registrar la ruta `GET /distrito` en `index.php`, apuntando a `handlers/distrito.php` (copiar el mismo patrón usado para `/municipios`) | 🟢 | `curl .../distrito?...` ya no da 404 de ruta |
| M9.7 | Aplicar el middleware existente `middleware/auth.php` y `middleware/rate_limit.php` a esta ruta, igual que a las demás (revisar cómo lo hace `index.php` para `/municipios`) | 🟢 | Sin API key → 401; con key válida → 200 |
| M9.8 | Aplicar caché de archivo (`helpers/cache.php`) a esta ruta con TTL largo (ej. 30 días — el catálogo solo cambia si el INE redistritó) | 🟢 | Segunda llamada idéntica se sirve desde `cache/responses/` |
| M9.9 | Agregar la documentación de `/distrito` a `README.md`, con el mismo formato que los demás endpoints (ya está redactada en la sección 4 de este PLAN — solo copiarla/adaptarla) | 🟢 | README actualizado |
| ✅ M9.V | Probar con secciones reales de al menos 3 estados distintos y comparar contra el resultado oficial del INE; probar sección inexistente; probar sin API key | ✅ | Checklist completado |

---

## 6. Verificación técnica por milestone

### M0 — Verificación de fundación

```bash
# 1. El schema corre sin errores
mysql -u user -p colonias_mx < schema.sql
# Esperado: sin errores, sin warnings

# 2. Los 32 estados están insertados
mysql -u user -p -e "SELECT COUNT(*) FROM colonias_mx.estados;"
# Esperado: 32

# 3. El servidor responde
curl -s https://tu-dominio.com/colonias-api/estados
# Esperado: {"ok":false,"error":"API key requerida","codigo":401}

# 4. Ruta inválida devuelve 404 JSON
curl -s https://tu-dominio.com/colonias-api/inventada
# Esperado: {"ok":false,"error":"Ruta no encontrada","codigo":404}
```

### M1 — Verificación de autenticación

```bash
# Key inválida
curl -H "Authorization: Bearer clave_falsa" https://tu-dominio.com/colonias-api/estados
# Esperado: 401

# Key válida
curl -H "Authorization: Bearer tu_key_real" https://tu-dominio.com/colonias-api/estados
# Esperado: 200 con array de estados

# Verificar que ultimo_uso se actualizó
mysql -u user -p -e "SELECT proyecto, ultimo_uso FROM colonias_mx.api_keys;"
```

### M2 — Verificación de búsqueda

```bash
KEY="tu_api_key"
BASE="https://tu-dominio.com/colonias-api"

# Búsqueda por nombre
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=polanco"
# Esperado: colonias con "Polanco" en el nombre

# Búsqueda por CP
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=11550"
# Esperado: colonias de Polanco CDMX con CP 11550

# Query muy corto
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=a"
# Esperado: {"ok":false,"error":"El texto debe tener al menos 2 caracteres",...}

# Verificar caché
ls -la cache/responses/
# Esperado: archivos .json tras los requests anteriores
```

### M3 — Verificación de importación SEPOMEX

```bash
# Conteos esperados
mysql -u user -p -e "
    SELECT
        (SELECT COUNT(*) FROM colonias_mx.colonias) AS colonias,
        (SELECT COUNT(*) FROM colonias_mx.municipios) AS municipios;
"
# Esperado: colonias > 140000, municipios > 2400

# Verificar que no hay colonias sin municipio
mysql -u user -p -e "
    SELECT COUNT(*) FROM colonias_mx.colonias
    WHERE municipio_id IS NULL;
"
# Esperado: 0
```

### M4 — Verificación de polígonos

```bash
# Cobertura de polígonos
mysql -u user -p -e "
    SELECT
        (SELECT COUNT(*) FROM colonias_mx.colonias) AS total_colonias,
        (SELECT COUNT(*) FROM colonias_mx.colonia_poligonos) AS con_poligono,
        ROUND(
            (SELECT COUNT(*) FROM colonias_mx.colonia_poligonos) * 100.0 /
            (SELECT COUNT(*) FROM colonias_mx.colonias), 1
        ) AS porcentaje;
"
# Esperado: porcentaje > 75%

# Verificar índice espacial
mysql -u user -p -e "SHOW INDEX FROM colonias_mx.colonia_poligonos;"
# Esperado: una fila con Key_name = sp_poligono, Index_type = SPATIAL
```

### M5 — Verificación de geolocalización

```bash
KEY="tu_api_key"
BASE="https://tu-dominio.com/colonias-api"

# CDMX — Polanco
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=19.4326&lng=-99.1940"
# Esperado: Polanco I Sección, Miguel Hidalgo, CDMX

# Monterrey — San Pedro Garza García
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=25.6572&lng=-100.4012"
# Esperado: colonia en San Pedro o Monterrey

# Guadalajara — Zapopan
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=20.7214&lng=-103.3882"
# Esperado: colonia en Zapopan

# Coordenadas fuera de México
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=40.7128&lng=-74.0060"
# Esperado: {"ok":false,"error":"Coordenadas fuera del territorio mexicano",...}

# Verificar campo "metodo" en respuesta
# Si dice "poligono" = exacto con ST_Within
# Si dice "centroide" = aproximado con Haversine
```

### M8 — Verificación de importación de Secciones Electorales

```bash
# Conteos generales
mysql -u user -p -e "
    SELECT
        (SELECT COUNT(*) FROM colonias_mx.secciones_electorales) AS secciones,
        (SELECT COUNT(*) FROM colonias_mx.distritos_federales) AS distritos_federales,
        (SELECT COUNT(*) FROM colonias_mx.distritos_locales) AS distritos_locales;
"
# Esperado: secciones ~68,000 (cifra referencial, verificar contra el catálogo real),
# distritos_federales = 300, distritos_locales según cada entidad

# Verificar que no hay secciones sin distrito federal (ese campo nunca debería ser NULL)
mysql -u user -p -e "
    SELECT COUNT(*) FROM colonias_mx.secciones_electorales
    WHERE distrito_federal_id IS NULL;
"
# Esperado: 0

# Revisar el log de filas que no emparejaron con ningún estado
cat import/logs/secciones_sin_estado.txt
# Esperado: archivo vacío o con muy pocas líneas
```

### M9 — Verificación del endpoint /distrito

```bash
KEY="tu_api_key"
BASE="https://tu-dominio.com/colonias-api"

# Sección válida
curl -s -H "Authorization: Bearer $KEY" "$BASE/distrito?seccion=0001&estado_id=9"
# Esperado: JSON con distrito_federal y distrito_local

# Sección sin ceros a la izquierda (debe normalizar igual)
curl -s -H "Authorization: Bearer $KEY" "$BASE/distrito?seccion=1&estado_id=9"
# Esperado: mismo resultado que el caso anterior

# Sección inexistente
curl -s -H "Authorization: Bearer $KEY" "$BASE/distrito?seccion=9999&estado_id=9"
# Esperado: {"ok":false,"error":"Sección electoral no encontrada para ese estado","codigo":404}

# Falta estado_id
curl -s -H "Authorization: Bearer $KEY" "$BASE/distrito?seccion=0001"
# Esperado: error 400 pidiendo el parámetro faltante

# Sin API key
curl -s "$BASE/distrito?seccion=0001&estado_id=9"
# Esperado: 401
```

---

## 7. Verificación funcional — Checklist para el usuario

Esta sección es para que el usuario (no el desarrollador) pueda confirmar que cada funcionalidad trabaja correctamente. No requiere conocimiento técnico.

### 7.1 Búsqueda por texto — probar en navegador o Postman

Abrir: `https://tu-dominio.com/colonias-api/buscar?api_key=TU_KEY&q=santa+fe`

**¿Qué verificar?**
- [ ] Aparecen resultados con colonias que contienen "Santa Fe"
- [ ] Los resultados incluyen colonia, municipio y estado
- [ ] Incluyen el código postal
- [ ] Si escribo solo "a" aparece un mensaje de error claro (no un error del servidor)
- [ ] Si escribo un CP de 5 dígitos como "06600", aparecen las colonias de ese CP

### 7.2 Búsqueda por estado y municipio

Abrir: `https://tu-dominio.com/colonias-api/estados?api_key=TU_KEY`

- [ ] Aparecen los 32 estados de México con sus nombres correctos
- [ ] Tomar el ID de "Ciudad de México" (debe ser 9) y abrir: `.../municipios?api_key=TU_KEY&estado_id=9`
- [ ] Aparecen las 16 alcaldías de CDMX

### 7.3 Geolocalización — desde el celular

1. Abrir MeUnoColonia en el celular y ir al formulario de registro
2. Tocar el botón "Detectar mi colonia por GPS"
3. Cuando el navegador pregunte permiso de ubicación, dar "Permitir"

**¿Qué verificar?**
- [ ] Aparece un mensaje "Obteniendo ubicación..." mientras procesa
- [ ] En menos de 15 segundos aparece el nombre de la colonia donde estás
- [ ] El municipio y estado son correctos para tu ubicación actual
- [ ] Si rechazas el permiso de GPS, aparece un mensaje claro invitando a escribir tu colonia manualmente

### 7.4 Geolocalización — casos borde

- [ ] Estar en la frontera entre dos colonias: ¿devuelve alguna sin error?
- [ ] Conectado solo a WiFi (sin GPS de satélite): ¿sigue funcionando aunque con menos precisión?
- [ ] En zona rural con colonia poco conocida: ¿devuelve algo o un error claro?

### 7.5 Autocompletar en formulario (MeUnoColonia)

1. Ir al formulario de registro de colono
2. Empezar a escribir en el campo "Colonia"

- [ ] Después de 2 o 3 letras aparecen sugerencias
- [ ] Las sugerencias se actualizan al seguir escribiendo
- [ ] Hacer clic en una sugerencia llena el campo
- [ ] El municipio y estado se llenan automáticamente al seleccionar

### 7.6 Rendimiento

- [ ] La búsqueda responde en menos de 2 segundos
- [ ] La geolocalización responde en menos de 3 segundos
- [ ] Si hago la misma búsqueda dos veces, la segunda es más rápida (caché)

### 7.7 Errores — verificar mensajes amigables

- [ ] Sin internet: aparece mensaje claro (no pantalla en blanco)
- [ ] GPS no disponible en el dispositivo: aparece alternativa para escribir manualmente
- [ ] Colonia no encontrada: mensaje "No encontramos esa colonia, intenta con otro término"

### 7.8 Distrito electoral — probar en navegador o Postman

Abrir: `https://tu-dominio.com/colonias-api/distrito?api_key=TU_KEY&seccion=0001&estado_id=9`

**¿Qué verificar?**
- [ ] Aparece un distrito federal (número) y, si la entidad lo tiene, un distrito local
- [ ] El número de distrito coincide con el que muestra el sitio oficial del INE para esa misma sección
- [ ] Si escribo una sección que no existe, aparece un mensaje claro (no un error del servidor)
- [ ] Si no indico `estado_id`, aparece un mensaje pidiendo ese dato (no un resultado de otro estado)
- [ ] Repetir la prueba con una sección de otro estado distinto a CDMX, para confirmar que la búsqueda respeta el estado indicado

---

## 8. Referencia de tamaños de tarea

Esta tabla es para asignar tareas al agente de IA correcto:

| Tarea | Señales | Modelo |
|-------|---------|--------|
| SELECT simple, INSERT fijo, respuesta JSON de una tabla | Sin joins complejos, sin lógica condicional | 🟢 Haiku |
| Múltiples joins, validaciones, caché de archivos, middleware de auth | Lógica de negocio, varios pasos | 🟡 Sonnet |
| Spatial queries, importación de GeoJSON, Haversine, router con múltiples rutas | Matemáticas, datos geoespaciales, scripts de ETL | 🔴 Opus |
| Checklist de verificación, comparar output esperado vs actual | Lectura y reporte | ✅ Cualquiera |

### Señales de que una tarea es más grande de lo que parece

- Requiere entender datos de INEGI o SEPOMEX (formatos no estándar)
- Involucra coordenadas GPS o geometría
- Necesita manejar errores parciales (algunos registros fallan en importación)
- Modifica tablas con > 10,000 filas existentes
- Requiere entender el CLAUDE.md de este proyecto antes de tocar código
