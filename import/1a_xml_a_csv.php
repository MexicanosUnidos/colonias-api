<?php
/**
 * Convierte el XML nacional de SEPOMEX ("CPdescarga.xml", el que entrega
 * https://www.correosdemexico.gob.mx/SSLServicios/ConsultaCP/Descarga.aspx)
 * al CSV que espera import/1_sepomex.php.
 *
 * OJO con las columnas del XML — es fácil confundirlas:
 *   - "d_codigo" es el código postal REAL de cada colonia
 *     (~32,000 valores distintos en todo el país).
 *   - "d_CP" es la clave de la OFICINA postal, mucho más genérica
 *     (~1,200 valores en todo el país) — NO es el CP de la colonia,
 *     aunque el nombre invite a confusión. Este script usa "d_codigo".
 *
 * No depende de la extensión XMLReader/SimpleXML (no todos los hostings
 * la traen) — el archivo es grande (~65MB) pero de estructura simple y
 * repetitiva (un <table>...</table> por colonia), así que se procesa
 * con un parser manual por streaming, sin cargarlo completo en memoria.
 *
 * Uso: php import/1a_xml_a_csv.php ["ruta/al/CPdescarga.xml"]
 * Por default busca en ../"Codigos Postales"/CPdescarga.xml
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$xmlPath = $argv[1] ?? __DIR__ . '/../Codigos Postales/CPdescarga.xml';
if (!is_file($xmlPath)) {
    fwrite(STDERR, "No se encontró $xmlPath\n");
    exit(1);
}

function extraerCampo(string $bloque, string $campo): string
{
    if (preg_match('/<' . preg_quote($campo, '/') . '(?:\s[^>]*)?>(.*?)<\/' . preg_quote($campo, '/') . '>/is', $bloque, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    return '';
}

if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0775, true);
}
$csvPath = __DIR__ . '/data/sepomex.csv';
$out = fopen($csvPath, 'w');
fputcsv($out, ['d_codigo', 'd_asenta', 'd_tipo_asenta', 'd_mnpio', 'd_estado', 'c_estado', 'c_mnpio']);

$handle = fopen($xmlPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "No se pudo abrir $xmlPath\n");
    exit(1);
}

$buffer = '';
$total = 0;
$tamanioChunk = 1024 * 1024; // 1MB por lectura

while (!feof($handle)) {
    $buffer .= fread($handle, $tamanioChunk);

    while (($inicio = strpos($buffer, '<table')) !== false) {
        $finTag = strpos($buffer, '>', $inicio);
        if ($finTag === false) {
            break; // tag de apertura incompleto, esperar más datos del archivo
        }
        $finBloque = strpos($buffer, '</table>', $finTag);
        if ($finBloque === false) {
            break; // bloque incompleto, esperar más datos del archivo
        }
        $finBloque += strlen('</table>');
        $bloque = substr($buffer, $inicio, $finBloque - $inicio);
        $buffer = substr($buffer, $finBloque);

        fputcsv($out, [
            extraerCampo($bloque, 'd_codigo'),
            extraerCampo($bloque, 'd_asenta'),
            extraerCampo($bloque, 'd_tipo_asenta'),
            extraerCampo($bloque, 'D_mnpio'),
            extraerCampo($bloque, 'd_estado'),
            extraerCampo($bloque, 'c_estado'),
            extraerCampo($bloque, 'c_mnpio'),
        ]);
        $total++;

        if ($total % 20000 === 0) {
            echo "Procesadas: $total\n";
        }
    }
}

fclose($handle);
fclose($out);

echo "Conversión completa. Colonias escritas: $total\n";
echo "CSV generado en: $csvPath\n";
