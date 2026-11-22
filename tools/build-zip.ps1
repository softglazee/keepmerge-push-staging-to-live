<#
    Builds the Keepmerge release zip.

    Copies only what may ship (one function decides, used by the copy and the audit stage),
    applies .distignore, rewrites text files to LF without a byte order mark, writes the archive
    entry by entry with forward-slash names (never Compress-Archive), audits the result, then
    unzips it to dist\unzipped\ for Plugin Check.

    It also refuses to build when the plugin header and readme.txt disagree: Plugin Name and the
    readme title byte for byte, Version = Stable tag = SOFTGLAZE_SP_VERSION, License, Requires at
    least, Requires PHP, Text Domain = slug, Domain Path only when languages\ ships. The two names
    are read from the files as UTF-8; this script never types the en dash itself, so it can stay
    pure ASCII.

    Usage:
        powershell -File tools/build-zip.ps1 -Version 1.0.0
#>

[CmdletBinding()]
param(
	[Parameter(Mandatory = $true)]
	[string] $Version
)

$ErrorActionPreference = 'Stop'

$Slug    = 'keepmerge-push-staging-to-live'
$root    = Split-Path -Parent $PSScriptRoot
$source  = Join-Path $root $Slug
$outDir  = Join-Path $root 'dist'
$stage   = Join-Path $outDir "stage-tmp\$Slug"
$zipPath = Join-Path $outDir "$Slug.zip"
$unzip   = Join-Path $outDir 'unzipped'

if (-not (Test-Path $source)) { throw "Plugin not found at $source" }

$blockedDirectories = @('node_modules', 'tests', 'test', 'docs', 'env', 'dev', 'tools', 'release', 'vendor', '__macosx', '.git', '.github')
$blockedFileNames   = @('thumbs.db', 'desktop.ini', 'php.ini', 'error_log', 'web.config', 'phpunit.xml.dist', 'phpunit.xml', 'phpcs.xml', 'phpcs.xml.dist', 'package-lock.json', 'yarn.lock', 'composer.lock')
$blockedExtensions  = @('.zip', '.rar', '.7z', '.gz', '.tgz', '.tar', '.bz2', '.phar', '.sh', '.bat', '.cmd', '.ps1', '.exe', '.dll', '.so', '.sql', '.log', '.map', '.bak', '.orig', '.rej', '.swp', '.tmp', '.dist', '.md')
$textExtensions     = @('.php', '.css', '.js', '.txt', '.json', '.pot', '.po', '.html', '.htm', '.svg', '.xml')

$distPatterns = @()
$distFile = Join-Path $source '.distignore'
if (Test-Path $distFile) {
	foreach ($line in [System.IO.File]::ReadAllLines($distFile)) {
		$t = $line.Trim()
		if ('' -ne $t -and -not $t.StartsWith('#')) { $distPatterns += ($t -replace '\*\*/', '*') }
	}
}

function Get-RejectReason {
	param([Parameter(Mandatory = $true)][string] $RelativePath)
	$rel      = $RelativePath -replace '\\', '/'
	$segments = @($rel -split '/' | Where-Object { '' -ne $_ })
	if (0 -eq $segments.Count) { return 'empty path' }
	foreach ($segment in $segments) {
		if ($segment.StartsWith('.')) { return "hidden file or folder: $segment" }
		if ($blockedDirectories -contains $segment.ToLowerInvariant()) { return "excluded folder: $segment" }
	}
	$leaf = $segments[-1].ToLowerInvariant()
	if ($blockedFileNames -contains $leaf) { return "excluded file name: $leaf" }
	$ext = [System.IO.Path]::GetExtension($leaf)
	if ($blockedExtensions -contains $ext) { return "excluded extension: $ext" }
	foreach ($p in $distPatterns) {
		if ($rel -like $p -or $segments[-1] -like $p -or ($segments -contains $p)) { return ".distignore: $p" }
	}
	return ''
}

function Get-HeaderValue {
	param([string] $Text, [string] $Name)
	if ($Text -match "(?im)^[\s*#]*$([regex]::Escape($Name))\s*:\s*(.+?)\s*$") { return $Matches[1].Trim() }
	return ''
}

foreach ($p in @($stage, $zipPath)) { if (Test-Path $p) { Remove-Item $p -Recurse -Force } }
# The unzipped copy is emptied, not removed: a process whose working directory is inside it (a shell,
# an editor) keeps Windows from removing the folder itself. Every file in it is still deleted.
if (Test-Path $unzip) {
	Get-ChildItem -LiteralPath $unzip -Recurse -Force -File | Remove-Item -Force
	Get-ChildItem -LiteralPath $unzip -Recurse -Force -Directory | Sort-Object { $_.FullName.Length } -Descending | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force -ErrorAction SilentlyContinue }
	if (@(Get-ChildItem -LiteralPath $unzip -Recurse -Force -File).Count -gt 0) { throw "Could not empty $unzip" }
}
New-Item -ItemType Directory -Path $stage -Force | Out-Null

$prefixLength = $source.Length + 1
$included = @(); $excluded = @()
foreach ($file in (Get-ChildItem $source -Recurse -File -Force | Sort-Object FullName)) {
	$relative = $file.FullName.Substring($prefixLength)
	if ($relative -like '.git\*' -or $relative -like 'node_modules\*') { continue }
	$reason = Get-RejectReason -RelativePath $relative
	if ($reason) { $excluded += [pscustomobject]@{ Path = $relative; Reason = $reason }; continue }
	$target = Join-Path $stage $relative
	$parent = Split-Path -Parent $target
	if (-not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
	Copy-Item -LiteralPath $file.FullName -Destination $target -Force
	$included += $relative
}
Write-Host "Source: $source"
Write-Host ("Included {0} file(s); excluded {1}." -f $included.Count, $excluded.Count)
foreach ($item in $excluded) { Write-Host ("  excluded {0}  [{1}]" -f $item.Path, $item.Reason) }

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
foreach ($file in (Get-ChildItem $stage -Recurse -File)) {
	if ($textExtensions -notcontains $file.Extension.ToLowerInvariant()) { continue }
	$original = [System.IO.File]::ReadAllText($file.FullName, [System.Text.Encoding]::UTF8)
	$rewrite  = $original -replace "`r`n", "`n" -replace "`r", "`n"
	if ($rewrite.Length -gt 0 -and [int][char]$rewrite[0] -eq 0xFEFF) { $rewrite = $rewrite.Substring(1) }
	[System.IO.File]::WriteAllText($file.FullName, $rewrite, $utf8NoBom)
}

$failures = @()
$main   = [System.IO.File]::ReadAllText((Join-Path $stage "$Slug.php"), [System.Text.Encoding]::UTF8)
$readme = [System.IO.File]::ReadAllText((Join-Path $stage 'readme.txt'), [System.Text.Encoding]::UTF8)

$pluginName  = Get-HeaderValue -Text $main -Name 'Plugin Name'
$readmeTitle = ''
if ($readme -match '(?m)^===\s*(.+?)\s*===\s*$') { $readmeTitle = $Matches[1] }
if ($pluginName -cne $readmeTitle) { $failures += "Plugin Name and readme title differ: '$pluginName' vs '$readmeTitle'" }

$headerVersion = Get-HeaderValue -Text $main -Name 'Version'
$stableTag     = Get-HeaderValue -Text $readme -Name 'Stable tag'
$constVersion  = ''
if ($main -match "define\(\s*'SOFTGLAZE_SP_VERSION',\s*'([^']+)'\s*\)") { $constVersion = $Matches[1] }
foreach ($pair in @(@('Version', $headerVersion), @('Stable tag', $stableTag), @('SOFTGLAZE_SP_VERSION', $constVersion))) {
	if ($pair[1] -ne $Version) { $failures += ("{0} is '{1}', expected '{2}'" -f $pair[0], $pair[1], $Version) }
}
foreach ($name in @('License', 'Requires at least', 'Requires PHP')) {
	$h = Get-HeaderValue -Text $main -Name $name
	$r = Get-HeaderValue -Text $readme -Name $name
	if ('' -eq $h -or $h -ne $r) { $failures += "$name differs or is missing: header '$h', readme '$r'" }
}
if ((Get-HeaderValue -Text $main -Name 'Text Domain') -ne $Slug) { $failures += 'Text Domain does not equal the slug.' }
$domainPath = Get-HeaderValue -Text $main -Name 'Domain Path'
$hasLanguages = Test-Path (Join-Path $stage 'languages')
if ($domainPath -and -not $hasLanguages) { $failures += 'Domain Path is set but languages/ does not ship.' }
if ($hasLanguages -and -not $domainPath) { $failures += 'languages/ ships but the header has no Domain Path.' }
foreach ($forbidden in @('Plugin URI', 'Update URI', 'Requires Plugins', 'Network')) {
	if (Get-HeaderValue -Text $main -Name $forbidden) { $failures += "Header must not carry '$forbidden' in 1.0.0." }
}
if ($failures.Count -gt 0) {
	foreach ($f in $failures) { Write-Host "FAIL  $f" -ForegroundColor Red }
	Remove-Item (Split-Path -Parent $stage) -Recurse -Force
	throw "Header and readme checks failed: $($failures.Count) problem(s)."
}

Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null
$archive = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
	foreach ($file in (Get-ChildItem $stage -Recurse -File | Sort-Object FullName)) {
		$entryName = $Slug + '/' + ($file.FullName.Substring($stage.Length + 1) -replace '\\', '/')
		[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
	}
} finally {
	$archive.Dispose()
}
Remove-Item (Split-Path -Parent $stage) -Recurse -Force

$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$count = 0; $bytes = 0
try {
	foreach ($entry in $archive.Entries) {
		$count++; $bytes += $entry.Length
		if ($entry.FullName.Contains('\')) { $failures += "Backslash in entry name: $($entry.FullName)" }
		if (-not $entry.FullName.StartsWith("$Slug/")) { $failures += "Entry outside $Slug/: $($entry.FullName)" }
		$reason = Get-RejectReason -RelativePath $entry.FullName.Substring($Slug.Length + 1)
		if ($reason) { $failures += "Disallowed entry: $($entry.FullName) [$reason]" }
	}
} finally {
	$archive.Dispose()
}
if ($failures.Count -gt 0) {
	foreach ($f in $failures) { Write-Host "FAIL  $f" -ForegroundColor Red }
	Remove-Item $zipPath -Force
	throw "Audit found $($failures.Count) problem(s). The zip was deleted."
}

[System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $unzip)
$zipBytes = (Get-Item $zipPath).Length
Write-Host ("Entries: {0}   Uncompressed: {1:N0} bytes   Archive: {2:N0} bytes" -f $count, $bytes, $zipBytes)
Write-Host "Plugin Name = readme title, Version = Stable tag = SOFTGLAZE_SP_VERSION = $Version" -ForegroundColor Green
Write-Host "Built $zipPath" -ForegroundColor Green
Write-Host "Unzipped to $unzip"
