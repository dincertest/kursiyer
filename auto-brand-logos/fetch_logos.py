#!/usr/bin/env python3
import io
import re
import sys
import urllib.parse
from pathlib import Path
from typing import Optional, Tuple, List

import requests
from PIL import Image, ImageOps

BRANDS_FILE = Path(__file__).parent / "brands_tr.txt"
OUTPUT_DIR = Path(__file__).parent / "logos"
USER_AGENT = "auto-brand-logos/1.0 (contact: local script)"
SESSION = requests.Session()
SESSION.headers.update({"User-Agent": USER_AGENT})

WIKIDATA_API = "https://www.wikidata.org/w/api.php"
COMMONS_THUMB = "https://commons.wikimedia.org/w/thumb.php?f={filename}&w={width}"
COMMONS_FILEPATH = "https://commons.wikimedia.org/wiki/Special:FilePath/{filename}"

SAFE_FILENAME_PATTERN = re.compile(r"[\\/:*?\"<>|]")


def sanitize_filename(name: str) -> str:
	return SAFE_FILENAME_PATTERN.sub("_", name).strip()


def read_brands(path: Path) -> list[str]:
	with path.open("r", encoding="utf-8") as f:
		return [line.strip() for line in f if line.strip() and not line.strip().startswith("#")]


def wikidata_get_logo_filename(qid: str) -> Optional[str]:
	params = {
		"action": "wbgetclaims",
		"entity": qid,
		"property": "P154",
		"format": "json",
	}
	r = SESSION.get(WIKIDATA_API, params=params, timeout=20)
	r.raise_for_status()
	claims = r.json().get("claims", {})
	if "P154" not in claims:
		return None
	try:
		mainsnak = claims["P154"][0]["mainsnak"]
		filename = mainsnak["datavalue"]["value"]
		return filename
	except Exception:
		return None


def wikidata_search_candidates(name: str, language: str, limit: int = 10) -> List[str]:
	params = {
		"action": "wbsearchentities",
		"search": name,
		"language": language,
		"type": "item",
		"limit": limit,
		"format": "json",
	}
	r = SESSION.get(WIKIDATA_API, params=params, timeout=20)
	r.raise_for_status()
	return [entry.get("id") for entry in r.json().get("search", []) if entry.get("id")]


def wikidata_search_entity(name: str) -> Optional[str]:
	# Try Turkish first, then English; prefer candidates that already have a logo (P154)
	for lang in ("tr", "en"):
		candidates = wikidata_search_candidates(name, lang, limit=15)
		# First pass: find first with a logo
		for qid in candidates:
			if wikidata_get_logo_filename(qid):
				return qid
		# Fallback: return first candidate if any
		if candidates:
			return candidates[0]
	return None


def download_commons_raster(filename: str) -> Optional[bytes]:
	quoted = urllib.parse.quote(filename)
	thumb_url = COMMONS_THUMB.format(filename=quoted, width=1024)
	try:
		r = SESSION.get(thumb_url, timeout=30)
		r.raise_for_status()
		return r.content
	except Exception:
		pass
	file_url = COMMONS_FILEPATH.format(filename=quoted)
	try:
		r = SESSION.get(file_url, timeout=30)
		r.raise_for_status()
		return r.content
	except Exception:
		return None


def to_png_128(img_bytes: bytes) -> Optional[Image.Image]:
	try:
		img = Image.open(io.BytesIO(img_bytes)).convert("RGBA")
	except Exception:
		return None
	canvas_size = (128, 128)
	padding = 8
	max_w = canvas_size[0] - 2 * padding
	max_h = canvas_size[1] - 2 * padding
	img = ImageOps.contain(img, (max_w, max_h), method=Image.LANCZOS)
	canvas = Image.new("RGBA", canvas_size, (0, 0, 0, 0))
	offset = ((canvas_size[0] - img.width) // 2, (canvas_size[1] - img.height) // 2)
	canvas.paste(img, offset, img)
	return canvas


def process_brand(brand: str) -> Tuple[str, bool, str]:
	try:
		qid = wikidata_search_entity(brand)
		if not qid:
			return brand, False, "No Wikidata item found"
		filename = wikidata_get_logo_filename(qid)
		if not filename:
			return brand, False, f"No logo (P154) for {qid}"
		img_bytes = download_commons_raster(filename)
		if not img_bytes:
			return brand, False, "Download failed"
		img = to_png_128(img_bytes)
		if img is None:
			return brand, False, "Conversion failed"
		out_name = sanitize_filename(brand) + ".png"
		out_path = OUTPUT_DIR / out_name
		img.save(out_path, format="PNG")
		return brand, True, "OK"
	except Exception as e:
		return brand, False, f"Error: {e}"


def main() -> int:
	OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
	brands = read_brands(BRANDS_FILE)
	ok = 0
	fail = 0
	for brand in brands:
		b, success, msg = process_brand(brand)
		status = "SUCCESS" if success else "FAIL"
		print(f"[{status}] {b}: {msg}")
		if success:
			ok += 1
		else:
			fail += 1
	print(f"Done. Success: {ok}, Fail: {fail}")
	return 0 if ok > 0 else 1


if __name__ == "__main__":
	sys.exit(main())