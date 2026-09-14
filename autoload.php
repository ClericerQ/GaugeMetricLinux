<?PHP

	// __DIR__ statt festem Pfad: autoload.php liegt im Projekt-Root und findet sich
	// damit unabhaengig von CWD und aufrufendem Skript selbst.
	if( !isset($dir) ) $dir = __DIR__ . '/';

	$dir = rtrim($dir, '/') . '/';

	date_default_timezone_set('Europe/Berlin');

	// Reihenfolge ist Rangfolge: 'classes/' ist der eingefrorene Framework-Kern,
	// 'app/' der Projektcode. Bei gleichem Klassennamen gewinnt der Kern, damit
	// eine neue Projektdatei eine Basisklasse nie unbemerkt verdeckt.
	$autoloadDirs = ['classes/', 'app/'];

	spl_autoload_register(function ($class_name) use ($dir, $autoloadDirs) {
	    foreach ($autoloadDirs as $directory) {
		$file = $dir . $directory . $class_name . '.php';

		if (file_exists($file)) {
		    include $file;
		    return;
		}
	    }
	});

	if (!is_dir($dir . 'classes/')) {
	    throw new RuntimeException(
	        "autoload: '{$dir}classes/' existiert nicht - \$dir zeigt auf das falsche Projektverzeichnis."
	    );
	}

	cConfig::load($dir . 'config.json');

	// Nicht automatisch instanziieren: cConfig ist statisch, cDatabase baut im
	// Konstruktor sofort eine PDO-Verbindung auf, cThread belegt Worker.
	$autoloadSkip = ['cConfig', 'cDatabase', 'cThread'];

	// cLog zuerst, damit der Error-Handler steht, bevor andere Klassen laden.
	$autoloadFirst = ['cLog'];

	// Je Verzeichnis sortieren statt global: so stehen die Kernklassen geschlossen
	// vor den Projektklassen, und eine Projektklasse darf im Konstruktor bereits
	// auf $cFile & Co. zugreifen.
	$autoloadNames = [];

	foreach ($autoloadDirs as $autoloadDir) {
	    $autoloadFiles = glob($dir . $autoloadDir . '*.php') ?: [];
	    $autoloadFound = array_map(static fn(string $f): string => basename($f, '.php'), $autoloadFiles);
	    sort($autoloadFound);

	    // Namen aus einem frueheren Verzeichnis bleiben stehen (Kern vor Projekt).
	    $autoloadNames = array_merge($autoloadNames, array_diff($autoloadFound, $autoloadNames));
	}

	$autoloadNames = array_merge(
	    array_values(array_intersect($autoloadFirst, $autoloadNames)),
	    array_values(array_diff($autoloadNames, $autoloadFirst))
	);

	$cClasses = [];

	foreach ($autoloadNames as $autoloadName) {
	    if (in_array($autoloadName, $autoloadSkip, true)) continue;

	    if (!class_exists($autoloadName)) continue;

	    $autoloadRef = new ReflectionClass($autoloadName);
	    if (!$autoloadRef->isInstantiable()) continue;

	    $autoloadCtor = $autoloadRef->getConstructor();
	    if ($autoloadCtor !== null && $autoloadCtor->getNumberOfRequiredParameters() > 0) continue;

	    $cClasses[$autoloadName] = $autoloadRef->newInstance();
	    $GLOBALS[$autoloadName]  = $cClasses[$autoloadName];
	}

	unset($autoloadFiles, $autoloadFound, $autoloadNames, $autoloadName, $autoloadDir,
	      $autoloadRef, $autoloadCtor, $autoloadSkip, $autoloadFirst);
