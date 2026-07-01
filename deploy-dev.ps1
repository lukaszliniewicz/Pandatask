# deploy-dev.ps1 - Deploy pandatask to the dev WordPress site.

param(
    [string] $HostName = 'iarf',
    [string] $RemotePluginDir = '/home/iarf-dev/htdocs/dev.iarf.net/wp-content/plugins/pandatask',
    [string] $RemoteOwner = 'iarf-dev:iarf-dev',
    [switch] $SkipBuild,
    [switch] $DryRun,
    [switch] $KeepPackage
)

$ErrorActionPreference = 'Stop'

$PluginSlug = 'pandatask'
$Timestamp = Get-Date -Format 'yyyyMMddHHmmss'
$RootPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$TempBase = [System.IO.Path]::GetFullPath($env:TEMP)
$TempRoot = Join-Path $TempBase "$PluginSlug-deploy-$Timestamp"
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

    if (-not $SkipBuild) {
        Write-Host '1. Building local PandaTask assets...' -ForegroundColor Cyan
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

    Write-Host '2. Creating clean plugin package...' -ForegroundColor Cyan
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
        Write-Host "Dry run requested; package retained at $PackagePath" -ForegroundColor Yellow
        return
    }

    $RemotePluginDir = $RemotePluginDir.TrimEnd('/')
    $RemotePluginParent = $RemotePluginDir.Substring(0, $RemotePluginDir.LastIndexOf('/'))
    $RemotePackagePath = "$RemotePluginParent/$PluginSlug.deploy-$Timestamp.tgz"
    $RemoteStagingDir = "$RemotePluginParent/.$PluginSlug.deploy-new"
    $RemoteBackupDir = "$RemotePluginParent/.$PluginSlug.backup-$Timestamp"

    Write-Host '3. Uploading plugin package to dev...' -ForegroundColor Cyan
    scp $PackagePath "${HostName}:$RemotePackagePath"
    if ($LASTEXITCODE -ne 0) {
        throw "Upload failed (exit code $LASTEXITCODE)"
    }

    $RemotePluginDirQ = Get-ShQuoted $RemotePluginDir
    $RemoteStagingDirQ = Get-ShQuoted $RemoteStagingDir
    $RemoteBackupDirQ = Get-ShQuoted $RemoteBackupDir
    $RemotePackagePathQ = Get-ShQuoted $RemotePackagePath
    $RemoteOwnerQ = Get-ShQuoted $RemoteOwner

    Write-Host '4. Swapping package into the dev site...' -ForegroundColor Cyan
    $RemoteSwap = @"
set -e
rm -rf $RemoteStagingDirQ $RemoteBackupDirQ
mkdir -p $RemoteStagingDirQ
tar -xzf $RemotePackagePathQ -C $RemoteStagingDirQ
test -f $RemoteStagingDirQ/pandatask.php
test -f $RemoteStagingDirQ/build/main.asset.php
if [ -f $RemoteStagingDirQ/composer.json ] && command -v composer >/dev/null 2>&1; then
    cd $RemoteStagingDirQ
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi
if [ -d $RemotePluginDirQ ]; then
    mv $RemotePluginDirQ $RemoteBackupDirQ
fi
if mv $RemoteStagingDirQ $RemotePluginDirQ; then
    chown -R $RemoteOwnerQ $RemotePluginDirQ
    find $RemotePluginDirQ -type d -exec chmod 755 {} +
    find $RemotePluginDirQ -type f -exec chmod 644 {} +
    rm -rf $RemoteBackupDirQ
    rm -f $RemotePackagePathQ
else
    if [ -d $RemoteBackupDirQ ]; then
        mv $RemoteBackupDirQ $RemotePluginDirQ
    fi
    exit 1
fi
"@
    ssh $HostName $RemoteSwap
    if ($LASTEXITCODE -ne 0) {
        throw "Remote swap failed (exit code $LASTEXITCODE)"
    }

    Write-Host '5. Verifying deployed dev files...' -ForegroundColor Cyan
    $RemoteVerify = @(
        "test -f $RemotePluginDir/pandatask.php",
        "test -f $RemotePluginDir/build/main.js",
        "test -f $RemotePluginDir/build/main.css"
    ) -join ' && '
    ssh $HostName $RemoteVerify
    if ($LASTEXITCODE -ne 0) {
        throw "Remote verification failed (exit code $LASTEXITCODE)"
    }

    Write-Host 'Dev deployment completed successfully!' -ForegroundColor Green
} finally {
    if ((Test-Path $PackagePath) -and $KeepPackage) {
        Write-Host "Package retained at $PackagePath" -ForegroundColor Yellow
    } elseif (-not $DryRun) {
        Remove-TempRoot
    }
}
