#!/usr/bin/env python3
"""Build DUP v2 artwork from the original cropped photograph.

The master PNG is the user's photograph, cropped without regeneration.
Pillow is needed for development packaging only, not at runtime.
"""
from __future__ import annotations
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
MASTER = ROOT / "packaging" / "dup-original-crop.png"
OUTPUTS = (
    ("public/assets/dup.png", 256),
    ("packaging/dsm7/PACKAGE_ICON.PNG", 64),
    ("packaging/dsm7/PACKAGE_ICON_120.PNG", 120),
    ("packaging/dsm7/PACKAGE_ICON_256.PNG", 256),
)

def main() -> None:
    with Image.open(MASTER) as image:
        photo = image.convert("RGB")
        if photo.size != (383, 383):
            raise RuntimeError(f"Unexpected original photograph crop: {photo.size}")
        for name, pixels in OUTPUTS:
            target = ROOT / name
            target.parent.mkdir(parents=True, exist_ok=True)
            resized = photo.resize((pixels, pixels), Image.Resampling.LANCZOS)
            resized.save(target, format="PNG", optimize=True)
            with Image.open(target) as verify:
                if verify.size != (pixels, pixels) or verify.format != "PNG":
                    raise RuntimeError(f"Invalid generated icon: {name}")
            print(f"PASS {name}: {pixels}x{pixels}, {target.stat().st_size} bytes")

if __name__ == "__main__":
    main()
