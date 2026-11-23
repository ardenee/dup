#!/usr/bin/env python3
"""Refresh DSM artwork from the generic DUP icon (requires Pillow)."""
from pathlib import Path
from PIL import Image
root = Path(__file__).resolve().parent.parent
source = Image.open(root / 'images/dup.png').convert('RGBA')
for size, filename in [(64, 'PACKAGE_ICON.PNG'), (256, 'PACKAGE_ICON_256.PNG')]:
    source.resize((size, size), Image.Resampling.NEAREST).save(
        root / 'packaging/dsm7' / filename)
    print(f'Created {filename}: {size}x{size}')
