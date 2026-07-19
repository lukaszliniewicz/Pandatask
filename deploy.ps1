# deploy.ps1 - Build, back up, and atomically deploy Pandatask to production.

param(
    [string] $HostName = 'iarf',
    [string] $RemotePluginDir = '/home/iarf/htdocs/iarf.net/wp-content/plugins/pandatask',
    [string] $RemoteOwner = 'iarf:iarf',
    [string] $RemoteBackupDir = '/home/iarf/deploy-backups',
    [switch] $SkipBuild,
    [switch] $DryRun,
    [switch] $KeepPackage
)

$ErrorActionPreference = 'Stop'

$PluginSlug = 'pandatask'
$ExpectedProductionDir = '/home/iarf/htdocs/iarf.net/wp-content/plugins/pandatask'
$Timestamp = Get-Date -Format 'yyyyMMddHHmmss'
$RootPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$TempBase = [System.IO.Path]::GetFullPath($env:TEMP)
$TempRoot = Join-Path $TempBase "$PluginSlug-production-deploy-$Timestamp"
$PackageRoot = Join-Path $TempRoot $PluginSlug
$PackagePath = Join-Path $TempRoot "$PluginSlug.tgz"

function Get-ShQuoted {
    param([string] $Value)
    return "'" + ($Value -replace "'", "'\''") + "'"
}

function Remove-TempRoot {
    if (-not (Test-Path $TempRoot)) {
        return
    }

    $ResolvedTempRoot = [System.IO.Path]::GetFullPath($TempRoot)
    if (-not $ResolvedTempRoot.StartsWith($TempBase, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to remove unexpected temp path: $ResolvedTempRoot"
    }

    Remove-Item -LiteralPath $ResolvedTempRoot -Recurse -Force
}

try {
    Set-Location $RootPath

    if ($RemotePluginDir.TrimEnd('/') -ne $ExpectedProductionDir) {
        throw "Refusing unexpected production target: $RemotePluginDir"
    }

    if (-not $SkipBuild) {
        Write-Host '1. Building local Pandatask assets...' -ForegroundColor Cyan
        npm run build
        if ($LASTEXITCODE -ne 0) {
            throw "Build failed (exit code $LASTEXITCODE)"
        }
    } else {
        Write-Host '1. Skipping local build.' -ForegroundColor Yellow
    }

    if (-not (Test-Path 'build/main.asset.php' -PathType Leaf)) {
        throw 'Build output is missing: build/main.asset.php'
    }

    Write-Host '2. Creating a clean production plugin package...' -ForegroundColor Cyan
    Remove-TempRoot
    New-Item -ItemType Directory -Path $PackageRoot -Force | Out-Null

    $RuntimeItems = @(
        'assets',
        'build',
        'includes',
        'src',
        'stubs',
        'templates',
        'composer.json',
        'composer.lock',
        'pandatask.php',
        'README.txt'
    )

    foreach ($Item in $RuntimeItems) {
        $Source = Join-Path $RootPath $Item
        if (Test-Path $Source) {
            Copy-Item -LiteralPath $Source -Destination $PackageRoot -Recurse -Force
        }
    }

    tar -czf $PackagePath -C $PackageRoot .
    if ($LASTEXITCODE -ne 0) {
        throw "Package creation failed (exit code $LASTEXITCODE)"
    }

    if ($DryRun) {
        Write-Host "Dry run complete; production package retained at $PackagePath" -ForegroundColor Yellow
        return
    }

    $RemotePluginDir = $RemotePluginDir.TrimEnd('/')
    $RemotePluginParent = $RemotePluginDir.Substring(0, $RemotePluginDir.LastIndexOf('/'))
    $RemotePackagePath = "$RemotePluginParent/$PluginSlug.deploy-$Timestamp.tgz"
    $RemoteStagingDir = "$RemotePluginParent/.$PluginSlug.deploy-new-$Timestamp"
    $RemotePreviousDir = "$RemotePluginParent/.$PluginSlug.previous-$Timestamp"
    $RemoteBackupPath = "$($RemoteBackupDir.TrimEnd('/'))/$PluginSlug-$Timestamp.tgz"

    Write-Host '3. Uploading package to production...' -ForegroundColor Cyan
    scp $PackagePath "${HostName}:$RemotePackagePath"
    if ($LASTEXITCODE -ne 0) {
        throw "Upload failed (exit code $LASTEXITCODE)"
    }

    $RemotePluginDirQ = Get-ShQuoted $RemotePluginDir
    $ExpectedProductionDirQ = Get-ShQuoted $ExpectedProductionDir
    $RemoteStagingDirQ = Get-ShQuoted $RemoteStagingDir
    $RemotePreviousDirQ = Get-ShQuoted $RemotePreviousDir
    $RemotePackagePathQ = Get-ShQuoted $RemotePackagePath
    $RemoteBackupDirQ = Get-ShQuoted $RemoteBackupDir
    $RemoteBackupPathQ = Get-ShQuoted $RemoteBackupPath
    $RemoteOwnerQ = Get-ShQuoted $RemoteOwner

    Write-Host '4. Backing up and atomically swapping the production plugin...' -ForegroundColor Cyan
    $RemoteSwap = @"
set -euo pipefail
test $RemotePluginDirQ = $ExpectedProductionDirQ
mkdir -p $RemoteBackupDirQ
rm -rf $RemoteStagingDirQ $RemotePreviousDirQ
mkdir -p $RemoteStagingDirQ
tar -xzf $RemotePackagePathQ -C $RemoteStagingDirQ
test -f $RemoteStagingDirQ/pandatask.php
test -f $RemoteStagingDirQ/build/main.asset.php
php -l $RemoteStagingDirQ/pandatask.php >/dev/null
if [ -f $RemoteStagingDirQ/composer.json ] && command -v composer >/dev/null 2>&1; then
    cd $RemoteStagingDirQ
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi
if [ -d $RemotePluginDirQ ]; then
    tar -czf $RemoteBackupPathQ -C $RemotePluginDirQ .
    mv $RemotePluginDirQ $RemotePreviousDirQ
fi
if mv $RemoteStagingDirQ $RemotePluginDirQ; then
    chown -R $RemoteOwnerQ $RemotePluginDirQ
    find $RemotePluginDirQ -type d -exec chmod 755 {} +
    find $RemotePluginDirQ -type f -exec chmod 644 {} +
    rm -rf $RemotePreviousDirQ
    rm -f $RemotePackagePathQ
else
    if [ -d $RemotePreviousDirQ ]; then
        mv $RemotePreviousDirQ $RemotePluginDirQ
    fi
    exit 1
fi
"@
    $RemoteSwap = $RemoteSwap -replace "`r`n?", "`n"
    $RemoteEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($RemoteSwap))
    ssh $HostName "echo '$RemoteEncoded' | base64 -d | bash"
    if ($LASTEXITCODE -ne 0) {
        throw "Production swap failed (exit code $LASTEXITCODE)"
    }

    Write-Host '5. Verifying production files and WordPress state...' -ForegroundColor Cyan
    $RemoteVerify = @"
set -e
test -f $RemotePluginDirQ/pandatask.php
test -f $RemotePluginDirQ/build/main.js
test -f $RemotePluginDirQ/build/main.css
php -l $RemotePluginDirQ/pandatask.php >/dev/null
if command -v wp >/dev/null 2>&1; then
    sudo -u iarf -- wp plugin is-active pandatask --path=/home/iarf/htdocs/iarf.net
    sudo -u iarf -- wp cache flush --path=/home/iarf/htdocs/iarf.net >/dev/null
fi
"@
    $RemoteVerify = $RemoteVerify -replace "`r`n?", "`n"
    $RemoteVerifyEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($RemoteVerify))
    ssh $HostName "echo '$RemoteVerifyEncoded' | base64 -d | bash"
    if ($LASTEXITCODE -ne 0) {
        throw "Production verification failed (exit code $LASTEXITCODE)"
    }

    Write-Host "Production deployment completed. Backup: ${HostName}:$RemoteBackupPath" -ForegroundColor Green
} finally {
    if ((Test-Path $PackagePath) -and $KeepPackage) {
        Write-Host "Package retained at $PackagePath" -ForegroundColor Yellow
    } elseif (-not $DryRun) {
        Remove-TempRoot
    }
}
