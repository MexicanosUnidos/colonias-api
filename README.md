# ColoniasAPI

API REST/JSON que expone el catálogo completo de colonias de México, con dos capacidades principales:

- **Búsqueda por texto** — autocompletar por nombre de colonia, código postal, municipio o estado.
- **Geolocalización** — dado un punto GPS, devuelve la colonia exacta usando polígonos geográficos (con fallback aproximado por distancia).

Ver [`docs/PLAN.md`](docs/PLAN.md) para el plan completo de desarrollo, arquitectura y milestones.

## Instalación

1. Clonar el repo en el servidor (PHP 8.x + MySQL 8.x).
2. Crear la base de datos y correr el schema:
   ```bash
   mysql -u user -p colonias_mx < schema.sql
   ```
3. Copiar la configuración de ejemplo y llenarla:
   ```bash
   cp config/env.example.php config/env.php
   ```
4. Apuntar el web root del servidor a la carpeta del proyecto (usa `.htaccess` para reescribir todo a `index.php`).
5. Importar los datos (ver sección siguiente).
6. Crear la primera API key:
   ```bash
   php import/0_crear_key.php "NombreDelProyecto"
   ```

## Importación de datos

```bash
# 1. Colonias (SEPOMEX) — coloca el CSV en import/data/sepomex.csv
php import/1_sepomex.php
php import/1c_verificar_conteos.php

# 2. Polígonos (INEGI) — coloca los GeoJSON en import/data/inegi/*.geojson
php import/2_inegi_geo.php
php import/1b_centroide_provisional.php

# 3. Verificación de salud general
php import/3_verificar.php

# 4. Secciones electorales (INE) — coloca el catálogo en import/data/ine_secciones.csv
php import/5_ine_secciones.php
php import/5b_verificar_secciones.php
```

## Autenticación

Todas las rutas (excepto `/health`) requieren una API key:

```
Authorization: Bearer col_xxxxx...
```

o como parámetro de URL (solo para pruebas):

```
?api_key=col_xxxxx...
```

## Endpoints

### `GET /estados`

Catálogo de los 32 estados.

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/estados
```

### `GET /municipios?estado_id=9`

Municipios de un estado.

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/municipios?estado_id=9"
```

### `GET /buscar?q=polanco`

Búsqueda por texto libre o código postal (si `q` son 5 dígitos).

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/buscar?q=polanco&limit=5"
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/buscar?q=11550"
```

### `GET /colonia/{id}`

Detalle completo de una colonia.

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/colonia/12834
```

### `GET /geolocate?lat=&lng=`

Colonia exacta a partir de coordenadas GPS. El campo `metodo` indica si el resultado vino de `poligono` (exacto) o `centroide` (aproximado).

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/geolocate?lat=19.43261&lng=-99.13321"
```

### `GET /distrito?seccion=&estado_id=`

Distrito federal y local de una sección electoral (dato impreso en la credencial del INE). Es una búsqueda directa en el catálogo del INE, sin geocodificación.

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/distrito?seccion=0001&estado_id=9"
```

Requiere haber importado el catálogo del INE (ver sección "Importación de datos" y `docs/PLAN.md` milestone M8).

### `GET /health`

Estado del servicio, sin autenticación.

```bash
curl https://tu-dominio.com/health
```

### `POST /keys/crear` *(uso interno)*

Crea una nueva API key, protegido por `ADMIN_SECRET`.

```bash
curl -X POST https://tu-dominio.com/keys/crear \
  -H "Content-Type: application/json" \
  -d '{"proyecto":"MeUnoColonia","admin_secret":"..."}'
```

## Formato de respuesta

**Éxito:**
```json
{ "ok": true, "data": [...], "total": 10, "tiempo_ms": 14 }
```

**Error:**
```json
{ "ok": false, "error": "Descripción del error", "codigo": 401 }
```

## Herramientas internas (protegidas por clave de administrador)

`test.php` y `admin/` **no son parte del API pública** — piden la `ADMIN_SECRET` de `config/env.php` antes de mostrar nada (formulario de login, sesión de PHP). No necesitan una API key de proyecto para acceder al panel en sí, solo para las llamadas que el panel de pruebas hace al API.

### Panel de pruebas — `test.php`

Formularios para probar cada endpoint a mano y un botón "Probar todo" que corre un smoke test. Ábrelo en `https://tu-dominio.com/test.php`, entra con la clave de administrador, configura la Base URL y una API key real (se guardan en el navegador) y prueba.

### Panel de administración — `admin/`

Para poblar la base de datos sin necesitar SSH ni Cron Jobs: `https://tu-dominio.com/admin/`. Muestra los conteos actuales de cada tabla, y por cada paso de importación (SEPOMEX, centroide provisional, INEGI/DCAH, secciones INE) indica si ya se corrió, si falta el archivo fuente, y ofrece un botón para ejecutarlo ahí mismo. El paso de SEPOMEX no es idempotente — si ya se corrió, pide marcar "forzar" a propósito antes de dejarlo repetirse, para no duplicar colonias por accidente. También incluye un formulario para crear API keys sin usar `curl`.

**Cómo corre cada paso:** el script de `import/` se incluye (`include`) dentro del mismo proceso PHP del panel — no usa `exec()`/`shell_exec()` ni depende de que el hosting tenga Cron Jobs disponibles (encontramos en producción real un hosting con `exec()` totalmente deshabilitado y sin Cron Jobs en el plan; este enfoque funciona ahí igual). El precio es que el import comparte el límite de tiempo del propio request web: el panel intenta subirlo (`set_time_limit(0)`) pero algunos hostings imponen un tope duro a nivel de servidor que PHP no puede cambiar. Los pasos más pesados (SEPOMEX ~13s, INEGI/DCAH ~45s en una prueba real con datos completos) deberían entrar sin problema en la mayoría de hostings compartidos; si tu hosting corta requests muy agresivamente y un paso se queda a medias, la salida parcial te dice hasta dónde llegó — los pasos con `ON DUPLICATE KEY UPDATE` (todos menos SEPOMEX) son seguros de volver a intentar.

Si tu plan sí incluye Cron Jobs y prefieres correr los scripts como procesos aparte de todos modos, se puede: `php import/1_sepomex.php` (y los demás, en el mismo orden que muestra el panel) funcionan igual por CLI normal, sin cambios.

⚠️ Ambas páginas son accesibles por cualquiera que conozca la URL — la clave de administrador es lo único que las protege. No compartas esa clave, y considera borrar `test.php`/`admin/` del servidor una vez que termines de poblar los datos, si el sitio va a quedar público permanentemente.

## Caché

Respuestas de `/estados`, `/buscar` y `/geolocate` se cachean en archivos planos en `cache/responses/` (TTL configurable, default 24h). No requiere Redis.
