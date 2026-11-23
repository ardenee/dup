# Derive Synology Package Center and DSM Main Menu icons from the exact
# original 120x120 DUP image. No replacement artwork is generated.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$root = Split-Path -Parent $PSScriptRoot
$dsm = Join-Path $root 'packaging\dsm7'
$master = Join-Path $dsm 'PACKAGE_ICON_120.PNG'
$source = [System.Drawing.Bitmap]::FromFile($master)
try {
    if ($source.Width -ne 120 -or $source.Height -ne 120) {
        throw "Original DUP artwork must be 120x120 pixels"
    }
    foreach ($size in @(16, 24, 32, 48, 64, 72, 256)) {
        $dest = [System.Drawing.Bitmap]::new($size, $size)
        $gfx = [System.Drawing.Graphics]::FromImage($dest)
        try {
            $gfx.Clear([System.Drawing.Color]::Transparent)
            $gfx.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $gfx.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
            $gfx.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $gfx.DrawImage($source, 0, 0, $size, $size)
        } finally { $gfx.Dispose() }
        try {
            $dest.Save((Join-Path $dsm ("ui\images\icon_{0}.png" -f $size)), [System.Drawing.Imaging.ImageFormat]::Png)
            if ($size -eq 64) {
                $dest.Save((Join-Path $dsm 'PACKAGE_ICON.PNG'), [System.Drawing.Imaging.ImageFormat]::Png)
            }
            if ($size -eq 256) {
                $dest.Save((Join-Path $dsm 'PACKAGE_ICON_256.PNG'), [System.Drawing.Imaging.ImageFormat]::Png)
            }
        } finally { $dest.Dispose() }
    }
} finally { $source.Dispose() }
Write-Host 'PASS: DSM icons all resized from original 120x120 DUP icon.'
