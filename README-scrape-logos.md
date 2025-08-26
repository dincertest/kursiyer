# Car Logos Scraper

This repository includes a PHP script to download car brand logos from `https://www.carlogos.org/car-brands/` into a local `brands/` directory.

## Requirements
- PHP 8.0+
- Extensions: curl, dom (php-xml)

On Debian/Ubuntu:
```bash
sudo apt-get update && sudo apt-get install -y php php-curl php-xml
```

## Usage
```bash
php scrape_car_logos.php
```

Logos will be saved under `brands/`.

Note: Website structure may change; adjust XPath in `parseBrandCards` if needed.