<?php

class cData {
    private ?string $lastError = null;

    private const CSV_DELIMITERS = [',', ';', "\t", '|'];

    private const BOM_UTF8 = "\xEF\xBB\xBF";

    private const HTML_NON_CONTENT = ['script', 'style', 'noscript', 'template'];

    public function error(): ?string {
        return $this->lastError;
    }

    private function fail(string $message): false {
        $this->lastError = $message;
        return false;
    }

    private function ok(): void {
        $this->lastError = null;
    }

    public function csvRead(string $file, array $options = []): array|false {
        if (!is_file($file) || !is_readable($file)) {
            return $this->fail("CSV nicht lesbar: $file");
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return $this->fail("CSV laesst sich nicht oeffnen: $file");
        }

        $rows = $this->csvFromStream($handle, $options);
        fclose($handle);

        return $rows;
    }

    public function csvParse(string $content, array $options = []): array|false {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return $this->fail('Temporaerer Stream nicht verfuegbar');
        }

        fwrite($handle, $content);
        rewind($handle);

        $rows = $this->csvFromStream($handle, $options);
        fclose($handle);

        return $rows;
    }

    private function csvFromStream($handle, array $options): array|false {
        $enclosure = $options['enclosure'] ?? '"';
        $escape    = $options['escape']    ?? '';
        $header    = $options['header']    ?? true;
        $skipEmpty = $options['skipEmpty'] ?? true;
        $limit     = (int) ($options['limit'] ?? 0);

        $delimiter = $options['delimiter'] ?? null;
        if ($delimiter === null) {
            $delimiter = $this->csvDetectDelimiter($handle, $enclosure);
        }

        $this->stripBom($handle);

        $columns = null;
        $rows    = [];

        while (($fields = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            $isEmpty = $fields === [null]
                || ($fields === [''] )
                || (count($fields) === 1 && trim((string) $fields[0]) === '');

            if ($isEmpty && $skipEmpty) {
                continue;
            }

            if ($header && $columns === null) {
                $columns = $this->csvHeader($fields);
                continue;
            }

            if ($columns !== null) {
                $rows[] = $this->csvCombine($columns, $fields);
            } else {
                $rows[] = $fields;
            }

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        $this->ok();
        return $rows;
    }

    private function csvHeader(array $fields): array {
        $columns = [];
        $seen    = [];

        foreach ($fields as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                $name = 'column_' . ($i + 1);
            }
            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_' . $seen[$name];
            } else {
                $seen[$name] = 1;
            }
            $columns[] = $name;
        }

        return $columns;
    }

    private function csvCombine(array $columns, array $fields): array {
        $row = [];
        foreach ($columns as $i => $name) {
            $row[$name] = $fields[$i] ?? null;
        }
        for ($i = count($columns), $n = count($fields); $i < $n; $i++) {
            $row[$i] = $fields[$i];
        }
        return $row;
    }

    private function csvDetectDelimiter($handle, string $enclosure): string {
        $line = fgets($handle);
        rewind($handle);

        if ($line === false || $line === '') {
            return ',';
        }

        $best  = ',';
        $count = 0;

        foreach (self::CSV_DELIMITERS as $candidate) {
            $n = $this->countOutsideQuotes($line, $candidate, $enclosure);
            if ($n > $count) {
                $best  = $candidate;
                $count = $n;
            }
        }

        return $best;
    }

    private function countOutsideQuotes(string $line, string $needle, string $enclosure): int {
        $count  = 0;
        $inside = false;
        $len    = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($enclosure !== '' && $char === $enclosure) {
                $inside = !$inside;
            } elseif (!$inside && $char === $needle) {
                $count++;
            }
        }

        return $count;
    }

    private function stripBom($handle): void {
        $start = ftell($handle);
        $head  = fread($handle, 3);

        if ($head !== self::BOM_UTF8) {
            fseek($handle, $start);
        }
    }

    public function csvBuild(array $rows, array $options = []): string|false {
        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $escape    = $options['escape']    ?? '';
        $eol       = $options['eol']       ?? "\r\n";
        $header    = $options['header']    ?? true;
        $bom       = $options['bom']       ?? false;

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return $this->fail('Temporaerer Stream nicht verfuegbar');
        }

        $columns = null;
        if ($header && $rows !== []) {
            $first = reset($rows);

            if (is_array($first) && array_keys($first) !== range(0, count($first) - 1)) {
                $columns = array_keys($first);
                fputcsv($handle, $columns, $delimiter, $enclosure, $escape, $eol);
            }
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = [$row];
            }

            if ($columns !== null) {
                $line = [];
                foreach ($columns as $name) {
                    $line[] = $row[$name] ?? '';
                }
            } else {
                $line = array_values($row);
            }
            fputcsv($handle, $line, $delimiter, $enclosure, $escape, $eol);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            return $this->fail('CSV konnte nicht erzeugt werden');
        }

        $this->ok();
        return ($bom ? self::BOM_UTF8 : '') . $csv;
    }

    public function csvWrite(string $file, array $rows, array $options = []): bool {
        $csv = $this->csvBuild($rows, $options);
        if ($csv === false) {
            return false;
        }

        if (@file_put_contents($file, $csv) === false) {
            return $this->fail("CSV nicht schreibbar: $file");
        }

        $this->ok();
        return true;
    }

    public function xmlParse(string $xml, bool $alwaysList = false): array|false {
        $xml = trim($xml);
        if ($xml === '') {
            return $this->fail('XML ist leer');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            $first = $errors[0] ?? null;
            return $this->fail($first
                ? 'XML ungueltig: ' . trim($first->message) . ' (Zeile ' . $first->line . ')'
                : 'XML ungueltig');
        }

        $this->ok();
        return [$doc->getName() => $this->xmlNode($doc, $alwaysList)];
    }

    public function xmlRead(string $file, bool $alwaysList = false): array|false {
        if (!is_file($file) || !is_readable($file)) {
            return $this->fail("XML nicht lesbar: $file");
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $this->fail("XML laesst sich nicht lesen: $file");
        }

        return $this->xmlParse($content, $alwaysList);
    }

    private function xmlNode(SimpleXMLElement $node, bool $alwaysList): mixed {
        $result = [];

        foreach ($node->attributes() as $name => $value) {
            $result['@attributes'][$name] = (string) $value;
        }

        foreach ($node->children() as $name => $child) {
            $value = $this->xmlNode($child, $alwaysList);

            if (array_key_exists($name, $result)) {
                if (!is_array($result[$name]) || !array_is_list($result[$name])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } elseif ($alwaysList) {
                $result[$name] = [$value];
            } else {
                $result[$name] = $value;
            }
        }

        $text = trim((string) $node);

        if ($result === []) {
            return $text;
        }
        if ($text !== '') {
            $result['#text'] = $text;
        }

        return $result;
    }

    public function xmlBuild(array $data, ?string $root = null, bool $pretty = true): string|false {
        if ($root === null) {
            $root = 'root';
            if (count($data) === 1) {
                $key = array_key_first($data);
                if (is_string($key) && $this->isXmlName($key) && is_array($data[$key])) {
                    $root = $key;
                    $data = $data[$key];
                }
            }
        }

        if (!$this->isXmlName($root)) {
            return $this->fail("Ungueltiger Wurzelname: $root");
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = $pretty;

        $element = $doc->createElement($root);
        $doc->appendChild($element);

        try {
            $this->xmlAppend($doc, $element, $data);
        } catch (DOMException $e) {
            return $this->fail('XML konnte nicht gebaut werden: ' . $e->getMessage());
        }

        $xml = $doc->saveXML();
        if ($xml === false) {
            return $this->fail('XML konnte nicht serialisiert werden');
        }

        $this->ok();
        return $xml;
    }

    private function xmlAppend(DOMDocument $doc, DOMElement $parent, array $data): void {
        foreach ($data as $key => $value) {
            if ($key === '@attributes' && is_array($value)) {
                foreach ($value as $name => $attr) {
                    if ($this->isXmlName((string) $name)) {
                        $parent->setAttribute((string) $name, $this->xmlScalar($attr));
                    }
                }
                continue;
            }

            if ($key === '#text') {
                $parent->appendChild($doc->createTextNode($this->xmlScalar($value)));
                continue;
            }

            if (is_int($key)) {
                $this->xmlChild($doc, $parent, $parent->nodeName, $value);
                continue;
            }

            $name = $this->isXmlName((string) $key) ? (string) $key : 'item';

            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $this->xmlChild($doc, $parent, $name, $item);
                }
                continue;
            }

            $this->xmlChild($doc, $parent, $name, $value);
        }
    }

    private function xmlChild(DOMDocument $doc, DOMElement $parent, string $name, mixed $value): void {
        $child = $doc->createElement($name);
        $parent->appendChild($child);

        if (is_array($value)) {
            $this->xmlAppend($doc, $child, $value);
        } else {
            $text = $this->xmlScalar($value);
            if ($text !== '') {
                $child->appendChild($doc->createTextNode($text));
            }
        }
    }

    private function xmlScalar(mixed $value): string {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function isXmlName(string $name): bool {
        return $name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9._:-]*$/', $name) === 1;
    }

    public function jsonParse(string $json, bool $assoc = true): mixed {
        $data = json_decode($json, $assoc);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fail('JSON ungueltig: ' . json_last_error_msg());
        }

        $this->ok();
        return $data;
    }

    public function jsonRead(string $file, bool $assoc = true): mixed {
        if (!is_file($file) || !is_readable($file)) {
            return $this->fail("JSON nicht lesbar: $file");
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $this->fail("JSON laesst sich nicht lesen: $file");
        }

        return $this->jsonParse($content, $assoc);
    }

    public function jsonBuild(mixed $data, bool $pretty = true): string|false {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);
        if ($json === false) {
            return $this->fail('JSON konnte nicht erzeugt werden: ' . json_last_error_msg());
        }

        $this->ok();
        return $json;
    }

    public function jsonWrite(string $file, mixed $data, bool $pretty = true): bool {
        $json = $this->jsonBuild($data, $pretty);
        if ($json === false) {
            return false;
        }

        if (@file_put_contents($file, $json) === false) {
            return $this->fail("JSON nicht schreibbar: $file");
        }

        $this->ok();
        return true;
    }

    public function htmlTags(string $html, string $tag = '*', array $options = []): array {
        $doc = $this->htmlDocument($html);
        if ($doc === false) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $limit = (int) ($options['limit'] ?? 0);

        $name  = $tag === '*' ? '*' : strtolower($tag);
        $query = '//' . $name;

        if (isset($options['id'])) {
            $query .= '[@id=' . $this->xpathLiteral((string) $options['id']) . ']';
        }

        if (isset($options['class'])) {
            $class  = $this->xpathLiteral(' ' . (string) $options['class'] . ' ');
            $query .= '[contains(concat(" ", normalize-space(@class), " "), ' . $class . ')]';
        }

        foreach (($options['attr'] ?? []) as $attr => $value) {
            $query .= $value === null
                ? '[@' . $attr . ']'
                : '[@' . $attr . '=' . $this->xpathLiteral((string) $value) . ']';
        }

        $nodes = $xpath->query($query);
        if ($nodes === false) {
            $this->fail('Ungueltige Tag-Abfrage: ' . $query);
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $result[] = [
                'tag'        => $node->nodeName,
                'attributes' => $this->htmlAttributes($node),
                'text'       => $this->normalizeSpace($node->textContent),
                'html'       => $doc->saveHTML($node) ?: '',
            ];

            if ($limit > 0 && count($result) >= $limit) {
                break;
            }
        }

        $this->ok();
        return $result;
    }

    public function htmlLinks(string $html, string $baseUrl = ''): array {
        $links = [];

        foreach ($this->htmlTags($html, 'a') as $tag) {
            $href = trim($tag['attributes']['href'] ?? '');
            if ($href === '' || preg_match('/^(mailto|tel|javascript):/i', $href)) {
                continue;
            }

            $links[] = [
                'href'   => $baseUrl !== '' ? $this->absoluteUrl($href, $baseUrl) : $href,
                'text'   => $tag['text'],
                'title'  => $tag['attributes']['title']  ?? '',
                'rel'    => $tag['attributes']['rel']    ?? '',
                'target' => $tag['attributes']['target'] ?? '',
            ];
        }

        return $links;
    }

    public function htmlImages(string $html, string $baseUrl = ''): array {
        $images = [];

        foreach ($this->htmlTags($html, 'img') as $tag) {
            $src = trim($tag['attributes']['src'] ?? '');
            if ($src === '') {
                continue;
            }

            $images[] = [
                'src'    => $baseUrl !== '' ? $this->absoluteUrl($src, $baseUrl) : $src,
                'alt'    => $tag['attributes']['alt']    ?? '',
                'title'  => $tag['attributes']['title']  ?? '',
                'width'  => $tag['attributes']['width']  ?? '',
                'height' => $tag['attributes']['height'] ?? '',
            ];
        }

        return $images;
    }

    public function htmlMeta(string $html): array {
        $doc = $this->htmlDocument($html);
        if ($doc === false) {
            return ['title' => '', 'meta' => [], 'og' => []];
        }

        $title = '';
        $nodes = $doc->getElementsByTagName('title');
        if ($nodes->length > 0) {
            $title = $this->normalizeSpace($nodes->item(0)->textContent);
        }

        $meta = [];
        $og   = [];

        foreach ($doc->getElementsByTagName('meta') as $node) {
            $content = $node->getAttribute('content');
            $key     = $node->getAttribute('name')
                    ?: $node->getAttribute('property')
                    ?: $node->getAttribute('http-equiv');

            if ($key === '') {
                if ($node->hasAttribute('charset')) {
                    $meta['charset'] = $node->getAttribute('charset');
                }
                continue;
            }

            $key = strtolower($key);
            $meta[$key] = $content;

            if (str_starts_with($key, 'og:')) {
                $og[substr($key, 3)] = $content;
            }
        }

        $this->ok();
        return ['title' => $title, 'meta' => $meta, 'og' => $og];
    }

    public function htmlTables(string $html, bool $header = true): array {
        $doc = $this->htmlDocument($html);
        if ($doc === false) {
            return [];
        }

        $xpath  = new DOMXPath($doc);
        $tables = [];

        foreach ($doc->getElementsByTagName('table') as $table) {
            $rows = [];

            foreach ($xpath->query('.//tr', $table) as $tr) {
                if ($xpath->query('ancestor::table[1]', $tr)->item(0) !== $table) {
                    continue;
                }

                $cells = [];
                foreach ($xpath->query('./th|./td', $tr) as $cell) {
                    $cells[] = $this->normalizeSpace($cell->textContent);
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }

            if ($rows === []) {
                continue;
            }

            $tables[] = $header ? $this->tableWithHeader($rows) : $rows;
        }

        $this->ok();
        return $tables;
    }

    private function tableWithHeader(array $rows): array {
        $columns = $this->csvHeader(array_shift($rows));

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->csvCombine($columns, $row);
        }

        return $result;
    }

    public function htmlText(string $html): string {
        $doc = $this->htmlDocument($html);
        if ($doc === false) {
            return '';
        }

        $xpath = new DOMXPath($doc);

        foreach (self::HTML_NON_CONTENT as $tag) {
            $nodes = $xpath->query('//' . $tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node->parentNode?->removeChild($node);
            }
        }

        $text = $doc->textContent ?? '';

        $lines = array_map(
            fn(string $line): string => $this->normalizeSpace($line),
            explode("\n", $text)
        );

        $this->ok();
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private function htmlAttributes(DOMElement $node): array {
        $attributes = [];
        foreach ($node->attributes ?? [] as $attr) {
            $attributes[$attr->nodeName] = $attr->nodeValue;
        }
        return $attributes;
    }

    private function htmlDocument(string $html): DOMDocument|false {
        if (trim($html) === '') {
            return $this->fail('HTML ist leer');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();

        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->fail('HTML konnte nicht geparst werden');
        }

        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $doc->removeChild($node);
            }
        }

        $this->ok();
        return $doc;
    }

    public function absoluteUrl(string $url, string $baseUrl): string {
        $url = trim($url);

        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['host'])) {
            return $url;
        }

        $scheme    = $base['scheme'] ?? 'http';
        $authority = $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $authority . $url;
        }

        $basePath = $base['path'] ?? '/';
        if (str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return $scheme . '://' . $authority . $basePath . $url;
        }

        $dir  = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        $path = $this->normalizePath($dir . $url);

        return $scheme . '://' . $authority . $path;
    }

    private function normalizePath(string $path): string {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
    }

    private function normalizeSpace(string $text): string {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    private function xpathLiteral(string $value): string {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        return 'concat(' . implode(", \"'\", ", array_map(
            fn(string $part): string => "'" . $part . "'",
            explode("'", $value)
        )) . ')';
    }
}
