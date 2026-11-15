$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$build = Join-Path $root 'build'
$stage = Join-Path $build 'stage'
$plugin = Join-Path $stage 'jocky-media-offloader'
$zip = Join-Path $build 'jocky-media-offloader-0.1.0.zip'

if (-not $build.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
	throw 'Build directory escaped the project root.'
}
if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
New-Item -ItemType Directory -Path $plugin -Force | Out-Null
foreach ($file in @('jocky-media-offloader.php', 'uninstall.php', 'readme.txt', 'composer.json', 'composer.lock')) {
	Copy-Item -LiteralPath (Join-Path $root $file) -Destination $plugin
}
foreach ($directory in @('src', 'assets')) {
	Copy-Item -LiteralPath (Join-Path $root $directory) -Destination $plugin -Recurse
}
composer install --working-dir=$plugin --no-dev --no-interaction --prefer-dist --optimize-autoloader
if ($LASTEXITCODE -ne 0) { throw 'Production Composer install failed.' }
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$archive = [System.IO.Compression.ZipFile]::Open(
	$zip,
	[System.IO.Compression.ZipArchiveMode]::Create
)
try {
	$null = $archive.CreateEntry('jocky-media-offloader/')
	Get-ChildItem -LiteralPath $plugin -File -Recurse | ForEach-Object {
		$relative = $_.FullName.Substring($plugin.Length + 1).Replace('\', '/')
		$entry = $archive.CreateEntry(
			"jocky-media-offloader/$relative",
			[System.IO.Compression.CompressionLevel]::Optimal
		)
		$source = [System.IO.File]::OpenRead($_.FullName)
		$destination = $entry.Open()
		try { $source.CopyTo($destination) }
		finally { $destination.Dispose(); $source.Dispose() }
	}
}
finally {
	$archive.Dispose()
}
Write-Output $zip
