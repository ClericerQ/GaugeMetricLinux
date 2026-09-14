<?PHP
  class cImg{

    public ?string $lastError = null;

    private const ALGOS = ['ahash', 'dhash', 'phash'];

    // Zielformate fuer convert(): Kuerzel => [Imagick-Name, Alpha moeglich, MIME, gd-Funktion]
    private const OUT = [
        'png'  => ['PNG',  true,  'image/png',    'imagepng'],
        'jpg'  => ['JPEG', false, 'image/jpeg',   'imagejpeg'],
        'jpeg' => ['JPEG', false, 'image/jpeg',   'imagejpeg'],
        'gif'  => ['GIF',  true,  'image/gif',    'imagegif'],
        'webp' => ['WEBP', true,  'image/webp',   'imagewebp'],
        'avif' => ['AVIF', true,  'image/avif',   'imageavif'],
        'bmp'  => ['BMP',  false, 'image/bmp',    'imagebmp'],
        'tif'  => ['TIFF', true,  'image/tiff',   null],
        'tiff' => ['TIFF', true,  'image/tiff',   null],
        'ico'  => ['ICO',  true,  'image/x-icon', null],
    ];

    // Standardqualitaet der verlustbehafteten Formate, 0 laesst die Vorgabe des Backends stehen
    private const QUALITY = ['jpg' => 88, 'jpeg' => 88, 'webp' => 82, 'avif' => 55];

    public function encode_base64_img($img){
      $encodedImage = str_replace('data:image/png;base64,', '', $img);
      $encodedImage = str_replace(' ', '+', $encodedImage);
      $encodedImage = base64_encode($encodedImage);
      return $encodedImage;
    }

    public function decode_base64_img($img){
      $encodedImage = str_replace(' ', '+', $img);
      $encodedImage = base64_decode($encodedImage);
      return "data:image/png;base64," . $encodedImage;
    }

    // Welche Bilderweiterung steht zur Verfuegung: 'imagick', 'gd' oder '' (keine).
    public function backend(): string {
        if (extension_loaded('imagick')) return 'imagick';
        if (extension_loaded('gd'))      return 'gd';
        return '';
    }

    // Ein Bild in ein anderes Format umwandeln - Breite und Hoehe bleiben unveraendert.
    // $img darf Pfad, Rohdaten, Base64 oder Data-URI sein, $target ist ein optionaler Ausgabepfad.
    // $options: ['background' => 'white', 'quality' => 0..100, 'strip' => false]
    // Rueckgabe sind immer die Bilddaten als String, bei Fehler false.
    public function convert(string $img, string $format = 'png', ?string $target = null, array $options = []): string|false {
        $format = strtolower(ltrim(trim($format), '.'));
        if (!isset(self::OUT[$format])) {
            return $this->fail("convert: unbekanntes Zielformat '{$format}' (erlaubt: " . implode(', ', array_keys(self::OUT)) . ')');
        }

        $blob = $this->blob($img);
        if ($blob === false) return false;

        $out = match($this->backend()) {
            'imagick' => $this->convertImagick($blob, $format, $options),
            'gd'      => $this->convertGd($blob, $format, $options),
            default   => $this->fail('convert: weder imagick noch gd geladen'),
        };
        if ($out === false) return false;

        if ($target !== null && $target !== '') {
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return $this->fail("convert: Verzeichnis '{$dir}' laesst sich nicht anlegen");
            }
            if (@file_put_contents($target, $out) === false) {
                return $this->fail("convert: '{$target}' nicht schreibbar");
            }
        }

        return $out;
    }

    // Umwandeln und gleich als Data-URI zurueckgeben, fertig fuer <img src="...">.
    public function dataUri(string $img, string $format = 'png', array $options = []): string|false {
        $out = $this->convert($img, $format, null, $options);
        if ($out === false) return false;

        return 'data:' . self::OUT[strtolower(ltrim(trim($format), '.'))][2] . ';base64,' . base64_encode($out);
    }

    // Welche Zielformate kann das geladene Backend hier tatsaechlich schreiben?
    // Imagick::queryFormats() zaehlt auch Formate auf, die es nur lesen kann (z. B. ICO in
    // ImageMagick 6) - deshalb wird einmal ein 2x2-Bild probeweise geschrieben.
    public function formats(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $backend = $this->backend();
        if ($backend === '') return $cache = [];

        $probe = '';
        if ($backend === 'imagick') {
            $im = new Imagick();
            $im->newImage(2, 2, new ImagickPixel('white'));
            $im->setImageFormat('PNG');
            $probe = $im->getImageBlob();
            $im->clear();
        } else {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagepng($im);
            $probe = (string) ob_get_clean();
            imagedestroy($im);
        }

        $error = $this->lastError;
        $out   = [];
        foreach (array_keys(self::OUT) as $ext) {
            if ($this->convert($probe, $ext) !== false) $out[] = $ext;
        }
        $this->lastError = $error;   // die Probe ist kein Fehler des Aufrufers

        return $cache = $out;
    }

    // Was steckt in der Datei? Format, Groesse, Alphakanal, Anzahl Einzelbilder.
    public function info(string $img): array|false {
        $blob = $this->blob($img);
        if ($blob === false) return false;

        return match($this->backend()) {
            'imagick' => $this->infoImagick($blob),
            'gd'      => $this->infoGd($blob),
            default   => $this->fail('info: weder imagick noch gd geladen'),
        };
    }

    // Wahrnehmungs-Hash eines Bildes als Hex-String (64 Bit = 16 Zeichen).
    public function hash(string $img, string $algo = 'dhash'): string|false {
        $algo = strtolower($algo);
        if (!in_array($algo, self::ALGOS, true)) {
            return $this->fail("hash: unbekannter Algorithmus '{$algo}' (erlaubt: " . implode(', ', self::ALGOS) . ')');
        }

        // dhash braucht eine Spalte mehr fuer den Nachbarvergleich, phash rechnet auf 32x32
        [$w, $h] = match($algo) {
            'dhash' => [9, 8],
            'phash' => [32, 32],
            default => [8, 8],
        };

        $gray = $this->grayscale($img, $w, $h);
        if ($gray === false) return false;

        return match($algo) {
            'ahash' => $this->bitsToHex($this->bitsAverage($gray)),
            'dhash' => $this->bitsToHex($this->bitsDifference($gray, $w, $h)),
            'phash' => $this->bitsToHex($this->bitsDct($gray, $w)),
        };
    }

    // Hamming-Abstand zweier Hex-Hashes: Zahl der unterschiedlichen Bits.
    public function distance(string $hashA, string $hashB): int|false {
        $a = strtolower(trim($hashA));
        $b = strtolower(trim($hashB));

        if ($a === '' || strlen($a) !== strlen($b)) {
            return $this->fail('distance: Hashes muessen gleich lang sein');
        }
        if (!ctype_xdigit($a) || !ctype_xdigit($b)) {
            return $this->fail('distance: Hashes muessen hexadezimal sein');
        }

        $dist = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $dist += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }
        return $dist;
    }

    // Zwei Bilder vergleichen: Details inklusive Prozentwert.
    public function compare(string $imgA, string $imgB, string $algo = 'dhash', float $threshold = 90.0): array|false {
        $hashA = $this->hash($imgA, $algo);
        if ($hashA === false) return false;

        $hashB = $this->hash($imgB, $algo);
        if ($hashB === false) return false;

        $dist = $this->distance($hashA, $hashB);
        if ($dist === false) return false;

        $bits    = strlen($hashA) * 4;
        $percent = round((1 - $dist / $bits) * 100, 2);

        return [
            'algo'      => strtolower($algo),
            'backend'   => $this->backend(),
            'hash_a'    => $hashA,
            'hash_b'    => $hashB,
            'bits'      => $bits,
            'distance'  => $dist,
            'percent'   => $percent,
            'threshold' => $threshold,
            'similar'   => $percent >= $threshold,
        ];
    }

    // Nur der Prozentwert der Uebereinstimmung.
    public function similarity(string $imgA, string $imgB, string $algo = 'dhash'): float|false {
        $result = $this->compare($imgA, $imgB, $algo);
        return $result === false ? false : $result['percent'];
    }

    // Schwellenwert-Entscheidung, bei Fehler false.
    public function isSimilar(string $imgA, string $imgB, float $threshold = 90.0, string $algo = 'dhash'): bool {
        $result = $this->compare($imgA, $imgB, $algo, $threshold);
        return $result === false ? false : $result['similar'];
    }

    // Ein Bild gegen ein Verzeichnis oder eine Dateiliste pruefen, beste Treffer zuerst.
    public function findSimilar(string $img, array|string $candidates, float $threshold = 90.0, string $algo = 'dhash'): array|false {
        $ref = $this->hash($img, $algo);
        if ($ref === false) return false;

        $list = $this->imageList($candidates);
        if ($list === false) return false;

        $bits = strlen($ref) * 4;
        $hits = [];

        foreach ($list as $file) {
            $hash = $this->hash($file, $algo);
            if ($hash === false) continue;   // unlesbare Kandidaten ueberspringen

            $dist = $this->distance($ref, $hash);
            if ($dist === false) continue;

            $percent = round((1 - $dist / $bits) * 100, 2);
            if ($percent < $threshold) continue;

            $hits[] = ['file' => $file, 'hash' => $hash, 'distance' => $dist, 'percent' => $percent];
        }

        usort($hits, static fn(array $a, array $b): int => $b['percent'] <=> $a['percent']);
        return $hits;
    }

    // Ganzen Bestand nach aehnlichen Bildern gruppieren, nur Gruppen ab zwei Bildern.
    public function groupSimilar(array|string $images, float $threshold = 90.0, string $algo = 'dhash'): array|false {
        $list = $this->imageList($images);
        if ($list === false) return false;

        $hashes = [];
        foreach ($list as $file) {
            $hash = $this->hash($file, $algo);
            if ($hash !== false) $hashes[$file] = $hash;
        }

        $groups = [];
        $taken  = [];

        foreach ($hashes as $file => $hash) {
            if (isset($taken[$file])) continue;

            $taken[$file] = true;
            $group = [$file];
            $bits  = strlen($hash) * 4;

            foreach ($hashes as $other => $otherHash) {
                if (isset($taken[$other])) continue;

                $dist = $this->distance($hash, $otherHash);
                if ($dist === false) continue;

                if ((1 - $dist / $bits) * 100 >= $threshold) {
                    $taken[$other] = true;
                    $group[] = $other;
                }
            }

            if (count($group) > 1) $groups[] = $group;
        }

        return $groups;
    }

    // Pixelgenauer Vergleich ueber Imagick statt Hash - streng, erkennt auch kleine Aenderungen.
    public function pixelDiff(string $imgA, string $imgB, int $size = 256): array|false {
        if (!extension_loaded('imagick')) {
            return $this->fail('pixelDiff: benoetigt die imagick-Erweiterung');
        }

        $blobA = $this->blob($imgA);
        if ($blobA === false) return false;

        $blobB = $this->blob($imgB);
        if ($blobB === false) return false;

        try {
            $a = $this->imagickFrom($blobA);
            $b = $this->imagickFrom($blobB);

            $a->resizeImage($size, $size, Imagick::FILTER_TRIANGLE, 1, false);
            $b->resizeImage($size, $size, Imagick::FILTER_TRIANGLE, 1, false);

            $result     = $a->compareImages($b, Imagick::METRIC_ROOTMEANSQUAREDERROR);
            $distortion = (float) $result[1];

            if ($result[0] instanceof Imagick) $result[0]->clear();
            $a->clear();
            $b->clear();
        } catch (Throwable $e) {
            return $this->fail('pixelDiff: ' . $e->getMessage());
        }

        return [
            'metric'     => 'rmse',
            'distortion' => round($distortion, 6),
            'percent'    => round((1 - $distortion) * 100, 2),
        ];
    }

    private function fail(string $message): false {
        $this->lastError = $message;
        return false;
    }

    // Verzeichnis, Einzelpfad oder Liste zu einem Array von Bilddateien machen.
    private function imageList(array|string $images): array|false {
        if (is_array($images)) return array_values($images);

        if (is_dir($images)) {
            $files = glob(rtrim($images, '/') . '/*.{png,jpg,jpeg,gif,bmp,webp,tif,tiff,avif,PNG,JPG,JPEG,GIF,BMP,WEBP}', GLOB_BRACE) ?: [];
            if ($files === []) return $this->fail("imageList: keine Bilder in '{$images}'");
            return $files;
        }

        if (is_file($images)) return [$images];

        return $this->fail("imageList: '{$images}' ist weder Verzeichnis noch Datei");
    }

    // Bildquelle einlesen: Dateipfad, Data-URI, Base64 oder Rohdaten.
    private function blob(string $img): string|false {
        if ($img === '') return $this->fail('blob: leere Bilddaten');

        if (strlen($img) < 4096 && !str_contains($img, "\0")) {
            if (is_file($img)) {
                $data = @file_get_contents($img);
                return $data === false ? $this->fail("blob: '{$img}' nicht lesbar") : $data;
            }
            // Sieht aus wie ein Pfad, ist aber keiner - sonst landet der Aufrufer in einer Decoder-Meldung
            if (preg_match('~^[\w./\\\\:@ +-]+\.(png|jpe?g|gif|bmp|webp|tiff?|avif|ico|svg)$~i', $img)) {
                return $this->fail("blob: '{$img}' existiert nicht");
            }
        }

        if (preg_match('~^data:image/[\w.+-]+;base64,~i', $img)) {
            $img = substr($img, strpos($img, ',') + 1);
        }

        if (strlen($img) > 32 && preg_match('~^[A-Za-z0-9+/=\s]+$~', $img)) {
            $decoded = base64_decode(str_replace(' ', '+', $img), true);
            if ($decoded !== false && $decoded !== '') return $decoded;
        }

        return $img;   // Rohdaten
    }


    private function convertImagick(string $blob, string $format, array $options): string|false {
        [$name, $alpha] = self::OUT[$format];

        try {
            $im = new Imagick();
            $im->readImageBlob($blob);
            $im->setFirstIterator();

            // Nur das erste Einzelbild: animierte GIF/WebP und mehrbildige AVIF/HEIC
            // wuerden sonst uebereinandergelegt statt umgewandelt.
            $frame = $im->getImage();
            $im->clear();

            // AVIF/HEIC dekodieren nach YCbCr, CMYK kommt aus der Druckvorstufe. Beides
            // schreiben die meisten Coder falsch bis gar nicht - vorher nach sRGB drehen.
            $colorspace = $frame->getImageColorspace();
            if (!in_array($colorspace, [Imagick::COLORSPACE_SRGB, Imagick::COLORSPACE_RGB, Imagick::COLORSPACE_GRAY], true)) {
                $frame->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            if (!$alpha) {
                // Zielformat kann keine Transparenz: auf einen Hintergrund legen,
                // ueber ein gleich grosses Blatt bleibt die Groesse exakt erhalten.
                $flat = new Imagick();
                $flat->newImage(
                    $frame->getImageWidth(),
                    $frame->getImageHeight(),
                    new ImagickPixel((string) ($options['background'] ?? 'white'))
                );
                $flat->compositeImage($frame, Imagick::COMPOSITE_OVER, 0, 0);
                $flat->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $frame->clear();
                $frame = $flat;
            }

            // ICO ist auf 256 Pixel Kantenlaenge begrenzt, sonst nur eine kryptische Coder-Meldung
            if ($format === 'ico' && ($frame->getImageWidth() > 256 || $frame->getImageHeight() > 256)) {
                $size = $frame->getImageWidth() . 'x' . $frame->getImageHeight();
                $frame->clear();
                return $this->fail("convert: ico fasst hoechstens 256x256 Pixel, das Bild ist {$size}");
            }

            $frame->setImageFormat($name);

            $quality = (int) ($options['quality'] ?? self::QUALITY[$format] ?? 0);
            if ($quality > 0) $frame->setImageCompressionQuality($quality);

            if (!empty($options['strip'])) $frame->stripImage();

            $out = $frame->getImageBlob();
            $frame->clear();
        } catch (Throwable $e) {
            return $this->fail('convert: imagick - ' . $e->getMessage());
        }

        return $out === '' ? $this->fail('convert: imagick lieferte keine Daten') : $out;
    }

    private function convertGd(string $blob, string $format, array $options): string|false {
        [, $alpha, , $writer] = self::OUT[$format];

        if ($writer === null || !function_exists($writer)) {
            return $this->fail("convert: gd kann '{$format}' nicht schreiben");
        }

        $src = @imagecreatefromstring($blob);
        if ($src === false) return $this->fail('convert: gd konnte das Bild nicht dekodieren');

        $w   = imagesx($src);
        $h   = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);

        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, true);
        } else {
            [$r, $g, $b] = $this->rgb((string) ($options['background'] ?? 'white'));
            imagealphablending($dst, true);
            imagefilledrectangle($dst, 0, 0, $w - 1, $h - 1, imagecolorallocate($dst, $r, $g, $b));
        }

        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $quality = (int) ($options['quality'] ?? self::QUALITY[$format] ?? 80);

        ob_start();
        $ok  = match($format) {
            'png'  => imagepng($dst, null, 6),
            'gif'  => imagegif($dst),
            'bmp'  => imagebmp($dst, null, true),
            default => $writer($dst, null, $quality),
        };
        $out = (string) ob_get_clean();
        imagedestroy($dst);

        return $ok && $out !== '' ? $out : $this->fail("convert: gd konnte '{$format}' nicht schreiben");
    }

    private function infoImagick(string $blob): array|false {
        try {
            $im = new Imagick();
            $im->readImageBlob($blob);
            $im->setFirstIterator();

            $info = [
                'backend' => 'imagick',
                'format'  => strtolower($im->getImageFormat()),
                'mime'    => $im->getImageMimeType(),
                'width'   => $im->getImageWidth(),
                'height'  => $im->getImageHeight(),
                'alpha'   => (bool) $im->getImageAlphaChannel(),
                'frames'  => $im->getNumberImages(),
                'bytes'   => strlen($blob),
            ];
            $im->clear();
        } catch (Throwable $e) {
            return $this->fail('info: imagick - ' . $e->getMessage());
        }

        return $info;
    }

    private function infoGd(string $blob): array|false {
        $im = @imagecreatefromstring($blob);
        if ($im === false) return $this->fail('info: gd konnte das Bild nicht dekodieren');

        $size = @getimagesizefromstring($blob) ?: [];

        $info = [
            'backend' => 'gd',
            'format'  => isset($size[2]) ? ltrim((string) image_type_to_extension($size[2], false), '.') : '',
            'mime'    => $size['mime'] ?? '',
            'width'   => imagesx($im),
            'height'  => imagesy($im),
            'alpha'   => imagecolortransparent($im) >= 0,
            'frames'  => 1,
            'bytes'   => strlen($blob),
        ];
        imagedestroy($im);

        return $info;
    }

    // Farbangabe zu [r, g, b] - '#rgb', '#rrggbb', 'rgb(r,g,b)' oder ein paar Namen. Nur fuer gd noetig.
    private function rgb(string $color): array {
        $names = [
            'white' => [255, 255, 255], 'black'  => [0, 0, 0],       'red'    => [255, 0, 0],
            'green' => [0, 128, 0],     'blue'   => [0, 0, 255],     'yellow' => [255, 255, 0],
            'gray'  => [128, 128, 128], 'grey'   => [128, 128, 128], 'silver' => [192, 192, 192],
        ];

        $c = strtolower(trim($color));
        if (isset($names[$c])) return $names[$c];

        if (preg_match('~^#?([0-9a-f]{3})$~', $c, $m)) {
            [$r, $g, $b] = str_split($m[1]);
            return [(int) hexdec($r . $r), (int) hexdec($g . $g), (int) hexdec($b . $b)];
        }
        if (preg_match('~^#?([0-9a-f]{6})$~', $c, $m)) {
            return array_map(static fn(string $hex): int => (int) hexdec($hex), str_split($m[1], 2));
        }
        if (preg_match('~^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)~', $c, $m)) {
            return [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
        }

        return [255, 255, 255];   // unbekannt: weiss
    }

    // Bild auf $w x $h herunterrechnen und als Graustufen 0..255 zurueckgeben.
    private function grayscale(string $img, int $w, int $h): array|false {
        $blob = $this->blob($img);
        if ($blob === false) return false;

        return match($this->backend()) {
            'imagick' => $this->grayscaleImagick($blob, $w, $h),
            'gd'      => $this->grayscaleGd($blob, $w, $h),
            default   => $this->fail('grayscale: weder imagick noch gd geladen'),
        };
    }

    // Erstes Bild aus dem Blob, auf weissem Grund flachgelegt.
    private function imagickFrom(string $blob): Imagick {
        $im = new Imagick();
        $im->readImageBlob($blob);
        $im->setFirstIterator();
        $im->setImageBackgroundColor('white');

        $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $im->clear();

        return $flat;
    }

    private function grayscaleImagick(string $blob, int $w, int $h): array|false {
        try {
            $im = $this->imagickFrom($blob);
            $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            $im->resizeImage($w, $h, Imagick::FILTER_TRIANGLE, 1, false);

            $pixels = $im->exportImagePixels(0, 0, $w, $h, 'I', Imagick::PIXEL_CHAR);
            $im->clear();
        } catch (Throwable $e) {
            return $this->fail('grayscale: imagick - ' . $e->getMessage());
        }

        if (count($pixels) !== $w * $h) {
            return $this->fail('grayscale: imagick lieferte ' . count($pixels) . ' statt ' . ($w * $h) . ' Pixel');
        }

        return array_map('intval', $pixels);
    }

    private function grayscaleGd(string $blob, int $w, int $h): array|false {
        $src = @imagecreatefromstring($blob);
        if ($src === false) return $this->fail('grayscale: gd konnte das Bild nicht dekodieren');

        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, true);
        imagefilledrectangle($dst, 0, 0, $w - 1, $h - 1, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

        $gray = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $gray[] = (int) round(
                    0.299 * (($rgb >> 16) & 0xFF) +
                    0.587 * (($rgb >> 8) & 0xFF) +
                    0.114 * ($rgb & 0xFF)
                );
            }
        }

        imagedestroy($src);
        imagedestroy($dst);
        return $gray;
    }

    // ahash: heller als der Bilddurchschnitt?
    private function bitsAverage(array $gray): array {
        $avg  = array_sum($gray) / count($gray);
        $bits = [];
        foreach ($gray as $value) $bits[] = $value > $avg ? 1 : 0;
        return $bits;
    }

    // dhash: heller als der rechte Nachbar? Unempfindlich gegen Helligkeit und Gamma.
    private function bitsDifference(array $gray, int $w, int $h): array {
        $bits = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w - 1; $x++) {
                $bits[] = $gray[$y * $w + $x] > $gray[$y * $w + $x + 1] ? 1 : 0;
            }
        }
        return $bits;
    }

    // phash: 8x8 niedrigste DCT-Frequenzen gegen ihren Median. Robust gegen Skalierung und Rauschen.
    private function bitsDct(array $gray, int $size): array {
        static $tables = [];

        if (!isset($tables[$size])) {
            $table = [];
            for ($u = 0; $u < $size; $u++) {
                for ($x = 0; $x < $size; $x++) {
                    $table[$u][$x] = cos((2 * $x + 1) * $u * M_PI / (2 * $size));
                }
            }
            $tables[$size] = $table;
        }

        $cos  = $tables[$size];
        $keep = 8;

        // Zeilen-DCT, aber nur die ersten 8 waagerechten Frequenzen
        $rows = [];
        for ($y = 0; $y < $size; $y++) {
            for ($v = 0; $v < $keep; $v++) {
                $sum = 0.0;
                for ($x = 0; $x < $size; $x++) $sum += $gray[$y * $size + $x] * $cos[$v][$x];
                $rows[$y][$v] = $sum;
            }
        }

        // Spalten-DCT ueber dieselben 8 Frequenzen
        $dct = [];
        for ($u = 0; $u < $keep; $u++) {
            for ($v = 0; $v < $keep; $v++) {
                $sum = 0.0;
                for ($y = 0; $y < $size; $y++) $sum += $rows[$y][$v] * $cos[$u][$y];
                $dct[] = $sum;
            }
        }

        // Median ohne den Gleichanteil, der nur die Gesamthelligkeit traegt
        $rest = array_slice($dct, 1);
        sort($rest);
        $n      = count($rest);
        $median = $n % 2 === 1 ? $rest[intdiv($n, 2)] : ($rest[$n / 2 - 1] + $rest[$n / 2]) / 2;

        $bits = [];
        foreach ($dct as $value) $bits[] = $value > $median ? 1 : 0;
        return $bits;
    }

    private function bitsToHex(array $bits): string {
        $hex = '';
        for ($i = 0, $n = count($bits); $i < $n; $i += 4) {
            $nibble = 0;
            for ($j = 0; $j < 4; $j++) $nibble = ($nibble << 1) | ($bits[$i + $j] ?? 0);
            $hex .= dechex($nibble);
        }
        return $hex;
    }
  }

?>
