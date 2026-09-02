-- ColoniasAPI — Esquema de base de datos
-- Ejecutar: mysql -u user -p colonias_mx < schema.sql

CREATE TABLE IF NOT EXISTS api_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto VARCHAR(100) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_uso DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave CHAR(2) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS municipios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_id INT UNSIGNED NOT NULL,
    clave_inegi CHAR(5) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_clave_inegi (clave_inegi),
    KEY idx_estado (estado_id),
    CONSTRAINT fk_municipios_estado FOREIGN KEY (estado_id) REFERENCES estados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colonias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    municipio_id INT UNSIGNED NOT NULL,
    codigo_postal CHAR(5) NOT NULL,
    tipo VARCHAR(50) NULL,
    centroide POINT SRID 0 NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_municipio (municipio_id),
    KEY idx_cp (codigo_postal),
    SPATIAL KEY sp_centroide (centroide),
    FULLTEXT KEY ft_nombre (nombre),
    CONSTRAINT fk_colonias_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_rate_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT UNSIGNED NOT NULL,
    minuto DATETIME NOT NULL COMMENT 'timestamp truncado al minuto',
    conteo INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_key_minuto (api_key_id, minuto),
    CONSTRAINT fk_rate_log_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colonia_poligonos (
    colonia_id INT UNSIGNED NOT NULL PRIMARY KEY,
    poligono GEOMETRY SRID 0 NOT NULL,
    SPATIAL KEY sp_poligono (poligono),
    CONSTRAINT fk_poligonos_colonia FOREIGN KEY (colonia_id) REFERENCES colonias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de distritos electorales federales (INE), por estado
CREATE TABLE IF NOT EXISTS distritos_federales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_id INT UNSIGNED NOT NULL,
    numero TINYINT UNSIGNED NOT NULL,
    cabecera VARCHAR(150) NULL,
    UNIQUE KEY uq_distrito_federal (estado_id, numero),
    CONSTRAINT fk_distritos_federales_estado FOREIGN KEY (estado_id) REFERENCES estados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de distritos electorales locales (INE), por estado
CREATE TABLE IF NOT EXISTS distritos_locales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estado_id INT UNSIGNED NOT NULL,
    numero TINYINT UNSIGNED NOT NULL,
    cabecera VARCHAR(150) NULL,
    UNIQUE KEY uq_distrito_local (estado_id, numero),
    CONSTRAINT fk_distritos_locales_estado FOREIGN KEY (estado_id) REFERENCES estados(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de secciones electorales (INE). La sección se repite entre
-- estados, por eso la llave primaria es compuesta (seccion + estado_id).
CREATE TABLE IF NOT EXISTS secciones_electorales (
    seccion CHAR(4) NOT NULL,
    estado_id INT UNSIGNED NOT NULL,
    distrito_federal_id INT UNSIGNED NOT NULL,
    distrito_local_id INT UNSIGNED NULL,
    municipio_id INT UNSIGNED NULL,
    PRIMARY KEY (seccion, estado_id),
    KEY idx_distrito_federal (distrito_federal_id),
    KEY idx_distrito_local (distrito_local_id),
    KEY idx_municipio (municipio_id),
    CONSTRAINT fk_secciones_estado FOREIGN KEY (estado_id) REFERENCES estados(id),
    CONSTRAINT fk_secciones_distrito_federal FOREIGN KEY (distrito_federal_id) REFERENCES distritos_federales(id),
    CONSTRAINT fk_secciones_distrito_local FOREIGN KEY (distrito_local_id) REFERENCES distritos_locales(id),
    CONSTRAINT fk_secciones_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de los 32 estados de México
INSERT INTO estados (clave, nombre) VALUES
('01', 'Aguascalientes'),
('02', 'Baja California'),
('03', 'Baja California Sur'),
('04', 'Campeche'),
('05', 'Coahuila'),
('06', 'Colima'),
('07', 'Chiapas'),
('08', 'Chihuahua'),
('09', 'Ciudad de México'),
('10', 'Durango'),
('11', 'Guanajuato'),
('12', 'Guerrero'),
('13', 'Hidalgo'),
('14', 'Jalisco'),
('15', 'Estado de México'),
('16', 'Michoacán'),
('17', 'Morelos'),
('18', 'Nayarit'),
('19', 'Nuevo León'),
('20', 'Oaxaca'),
('21', 'Puebla'),
('22', 'Querétaro'),
('23', 'Quintana Roo'),
('24', 'San Luis Potosí'),
('25', 'Sinaloa'),
('26', 'Sonora'),
('27', 'Tabasco'),
('28', 'Tamaulipas'),
('29', 'Tlaxcala'),
('30', 'Veracruz'),
('31', 'Yucatán'),
('32', 'Zacatecas')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
