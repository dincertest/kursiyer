<?php

declare(strict_types=1);

// Simple scraper to download car brand logos from carlogos.org/car-brands/
// Usage: php scrape_car_logos.php

$baseUrl = 'https://www.carlogos.org';
$brandsUrl = $baseUrl . '/car-brands/';
$targetDir = __DIR__ . '/brands';

function assertExtensions(): void {
	$required = ['curl', 'dom'];
	$missing = [];
	foreach ($required as $ext) {
		if (!extension_loaded($ext)) {
			$missing[] = $ext;
		}
	}
	if ($missing) {
		fwrite(STDERR, "Missing PHP extensions: " . implode(', ', $missing) . "\n".
			"Install them (e.g., apt-get install php-curl php-xml) and retry.\n");
		exit(1);
	}
}

function httpGet(string $url): string {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_CONNECTTIMEOUT => 20,
		CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CarLogosScraper/1.0)'
	]);
	$body = curl_exec($ch);
	if ($body === false) {
		$err = curl_error($ch);
		$code = curl_errno($ch);
		curl_close($ch);
		throw new RuntimeException("cURL error ($code): $err while requesting $url");
	}
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($httpCode >= 400) {
		throw new RuntimeException("HTTP $httpCode for $url");
	}
	return $body;
}

function sanitizeFilename(string $name): string {
	$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name));
	$name = preg_replace('/-+/', '-', $name);
	return trim($name, '-');
}

function ensureDir(string $dir): void {
	if (!is_dir($dir)) {
		if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new RuntimeException("Failed to create directory: $dir");
		}
	}
}

function parseBrandCards(string $html, string $baseUrl): array {
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	// Cards hold brand anchor and image. Adjust XPath if site changes.
	$nodes = $xpath->query("//a[contains(@class,'item')]//img | //div[contains(@class,'item')]//img | //a[contains(@class,'brands')]//img");
	$results = [];
	foreach ($nodes as $img) {
		$alt = $img->getAttribute('alt');
		$src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
		if (!$src) { continue; }
		if (strpos($src, '//') === 0) {
			$src = 'https:' . $src;
		} elseif (strpos($src, 'http') !== 0) {
			$src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
		}
		$results[] = [
			'name' => $alt ?: basename(parse_url($src, PHP_URL_PATH) ?: ''),
			'url' => $src,
		];
	}

	// Deduplicate by URL
	$unique = [];
	foreach ($results as $r) {
		$unique[$r['url']] = $r;
	}
	return array_values($unique);
}

function chooseExtensionFromHeaders(array $headers, string $fallbackPath): string {
	$contentType = '';
	foreach ($headers as $h) {
		if (stripos($h, 'Content-Type:') === 0) {
			$contentType = trim(substr($h, strlen('Content-Type:')));
			break;
		}
	}
	$ext = '';
	if (stripos($contentType, 'image/png') !== false) $ext = '.png';
	elseif (stripos($contentType, 'image/jpeg') !== false) $ext = '.jpg';
	elseif (stripos($contentType, 'image/svg') !== false) $ext = '.svg';
	elseif (stripos($contentType, 'image/webp') !== false) $ext = '.webp';
	if ($ext) return $ext;
	$path = parse_url($fallbackPath, PHP_URL_PATH) ?: '';
	if (preg_match('/\.(png|jpe?g|svg|webp)(?:$|\?)/i', $path, $m)) {
		return '.' . strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
	}
	return '.img';
}

function downloadImage(string $url, string $savePath): void {
	$ch = curl_init($url);
	$tmp = fopen('php://temp', 'w+');
	$headers = [];
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 120,
		CURLOPT_CONNECTTIMEOUT => 20,
		CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CarLogosScraper/1.0)',
		CURLOPT_FILE => $tmp,
		CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$headers) {
			$headers[] = rtrim($header, "\r\n");
			return strlen($header);
		}
	]);
	$ok = curl_exec($ch);
	if ($ok === false) {
		$err = curl_error($ch);
		$code = curl_errno($ch);
		curl_close($ch);
		throw new RuntimeException("cURL error ($code): $err while downloading $url");
	}
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($httpCode >= 400) {
		throw new RuntimeException("HTTP $httpCode for $url");
	}
	rewind($tmp);
	$data = stream_get_contents($tmp);
	fclose($tmp);
	if ($data === false || strlen($data) === 0) {
		throw new RuntimeException("Empty body for $url");
	}
	if (file_put_contents($savePath, $data) === false) {
		throw new RuntimeException("Failed to write $savePath");
	}
}

function main(): void {
	global $brandsUrl, $baseUrl, $targetDir;
	assertExtensions();
	ensureDir($targetDir);
	fwrite(STDOUT, "Fetching brand list...\n");
	$html = httpGet($brandsUrl);
	$cards = parseBrandCards($html, $baseUrl);
	if (empty($cards)) {
		fwrite(STDERR, "No logos found. The page structure might have changed.\n");
		exit(2);
	}
	fwrite(STDOUT, "Found " . count($cards) . " candidate images. Downloading...\n");
	$counter = 0;
	foreach ($cards as $card) {
		$name = $card['name'] ?: 'brand-' . ($counter + 1);
		$name = sanitizeFilename(strtolower($name));
		$ext = pathinfo(parse_url($card['url'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
		$ext = $ext ? ('.' . strtolower($ext)) : '';
		$filenameBase = $name ?: ('brand-' . ($counter + 1));
		$filename = $filenameBase . ($ext ?: '');
		$savePath = $targetDir . '/' . $filename;

		// Avoid overwrite: append numeric suffix if needed
		$suffix = 1;
		while (file_exists($savePath)) {
			$savePath = $targetDir . '/' . $filenameBase . '-' . $suffix . ($ext ?: '');
			$suffix++;
		}

		try {
			// If no extension, detect from content-type after a header-only request
			if ($ext === '') {
				$head = @get_headers($card['url']);
				$detectedExt = is_array($head) ? chooseExtensionFromHeaders($head, $card['url']) : '.img';
				$savePath = preg_replace('/(\.[^.]+)?$/', $detectedExt, $savePath);
			}
			downloadImage($card['url'], $savePath);
			$counter++;
			fwrite(STDOUT, "Saved: " . basename($savePath) . "\n");
		} catch (Throwable $e) {
			fwrite(STDERR, "Failed: {$card['url']} => " . $e->getMessage() . "\n");
		}
	}
	fwrite(STDOUT, "Done. Saved $counter files into $targetDir\n");
}

main();