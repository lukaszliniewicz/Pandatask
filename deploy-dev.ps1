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
$ExpectedDevDir = '/home/iarf-dev/htdocs/dev.iarf.net/wp-content/plugins/pandatask'
$RemoteWordPressDir = '/home/iarf-dev/htdocs/dev.iarf.net'
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

    if ($RemotePluginDir.TrimEnd('/') -ne $ExpectedDevDir) {
        throw "Refusing unexpected dev target: $RemotePluginDir"
    }

    if (-not $SkipBuild) {
        Write-Host '1. Building local PandaTask assets...' -ForegroundColor Cyan
        npm run build
        if ($LASTEXITCODE -ne 0) {
            throw "Build failed (exit code $LASTEXITCODE)"
        }
    } else {
        Write-Host '1. Skipping local build.' -ForegroundColor Yellow
    }

    foreach ($BuildFile in @('build/main.asset.php', 'build/main.js', 'build/main.css')) {
        if (-not (Test-Path $BuildFile -PathType Leaf)) {
            throw "Build output is missing: $BuildFile"
        }
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
    $RemoteStagingDir = "$RemotePluginParent/.$PluginSlug.deploy-new-$Timestamp"
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
    $ExpectedDevDirQ = Get-ShQuoted $ExpectedDevDir
    $RemoteWordPressDirQ = Get-ShQuoted $RemoteWordPressDir

    Write-Host '4. Validating and atomically swapping the package into dev...' -ForegroundColor Cyan
    $RemoteSwap = @"
set -Eeuo pipefail
test $RemotePluginDirQ = $ExpectedDevDirQ
had_previous=0
deployed=0
rollback() {
    status=`$?
    if [ "`$deployed" -eq 1 ]; then
        rm -rf $RemotePluginDirQ
    fi
    if [ "`$had_previous" -eq 1 ] && [ -d $RemoteBackupDirQ ]; then
        mv $RemoteBackupDirQ $RemotePluginDirQ
    fi
    rm -rf $RemoteStagingDirQ
    rm -f $RemotePackagePathQ
    exit "`$status"
}
trap rollback ERR
rm -rf $RemoteStagingDirQ $RemoteBackupDirQ
mkdir -p $RemoteStagingDirQ
tar -xzf $RemotePackagePathQ -C $RemoteStagingDirQ
test -f $RemoteStagingDirQ/pandatask.php
test -f $RemoteStagingDirQ/build/main.asset.php
test -s $RemoteStagingDirQ/build/main.js
test -s $RemoteStagingDirQ/build/main.css
while IFS= read -r -d '' php_file; do
    php -l "`$php_file" >/dev/null
done < <(find $RemoteStagingDirQ -type f -name '*.php' -print0)
if [ -f $RemoteStagingDirQ/composer.json ] && command -v composer >/dev/null 2>&1; then
    cd $RemoteStagingDirQ
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi
if [ -d $RemotePluginDirQ ]; then
    mv $RemotePluginDirQ $RemoteBackupDirQ
    had_previous=1
fi
mv $RemoteStagingDirQ $RemotePluginDirQ
deployed=1
chown -R $RemoteOwnerQ $RemotePluginDirQ
find $RemotePluginDirQ -type d -exec chmod 755 {} +
find $RemotePluginDirQ -type f -exec chmod 644 {} +
test -s $RemotePluginDirQ/build/main.js
test -s $RemotePluginDirQ/build/main.css
php -l $RemotePluginDirQ/pandatask.php >/dev/null
if command -v wp >/dev/null 2>&1; then
    sudo -u iarf-dev -- wp plugin is-active pandatask --path=$RemoteWordPressDirQ
    if ! sudo -u iarf-dev -- wp cache flush --path=$RemoteWordPressDirQ >/dev/null; then
        echo 'Warning: WordPress object cache could not be flushed.' >&2
    fi
fi
rm -rf $RemoteBackupDirQ
rm -f $RemotePackagePathQ
trap - ERR
"@
    $RemoteSwap = $RemoteSwap -replace "`r`n?", "`n"
    $RemoteEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($RemoteSwap))
    ssh $HostName "echo '$RemoteEncoded' | base64 -d | bash"
    if ($LASTEXITCODE -ne 0) {
        throw "Remote swap failed (exit code $LASTEXITCODE)"
    }

    Write-Host '5. Verifying deployed dev version...' -ForegroundColor Cyan
    $RemoteVerify = "test -s $RemotePluginDirQ/build/main.js && test -s $RemotePluginDirQ/build/main.css && grep -q 'Version:' $RemotePluginDirQ/pandatask.php"
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
