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
$RemoteWordPressDir = '/home/iarf/htdocs/iarf.net'
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

    foreach ($BuildFile in @('build/main.asset.php', 'build/main.js', 'build/main.css')) {
        if (-not (Test-Path $BuildFile -PathType Leaf)) {
            throw "Build output is missing: $BuildFile"
        }
    }

    $PluginSource = Get-Content -LiteralPath 'pandatask.php' -Raw
    $PluginVersionMatch = [regex]::Match($PluginSource, '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$')
    if (-not $PluginVersionMatch.Success) {
        throw 'Unable to read the Pandatask version from pandatask.php'
    }
    $ExpectedVersion = $PluginVersionMatch.Groups[1].Value
    $ExpectedPluginHash = (Get-FileHash -LiteralPath 'pandatask.php' -Algorithm SHA256).Hash.ToLowerInvariant()
    $ExpectedMainJsHash = (Get-FileHash -LiteralPath 'build/main.js' -Algorithm SHA256).Hash.ToLowerInvariant()
    $ExpectedMainCssHash = (Get-FileHash -LiteralPath 'build/main.css' -Algorithm SHA256).Hash.ToLowerInvariant()

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
    $RemoteWordPressDirQ = Get-ShQuoted $RemoteWordPressDir
    $ExpectedVersionQ = Get-ShQuoted $ExpectedVersion

    Write-Host '4. Validating, backing up, atomically swapping, and verifying production...' -ForegroundColor Cyan
    $RemoteSwap = @"
set -Eeuo pipefail
test $RemotePluginDirQ = $ExpectedProductionDirQ
had_previous=0
deployed=0
rollback() {
    status=`$?
    trap - ERR
    set +e
    if [ "`$deployed" -eq 1 ] && [ -d $RemotePluginDirQ ]; then
        rm -rf $RemotePluginDirQ
    fi
    if [ "`$had_previous" -eq 1 ] && [ -d $RemotePreviousDirQ ]; then
        mv $RemotePreviousDirQ $RemotePluginDirQ
    fi
    rm -rf $RemoteStagingDirQ
    rm -f $RemotePackagePathQ
    exit "`$status"
}
trap rollback ERR
mkdir -p $RemoteBackupDirQ
rm -rf $RemoteStagingDirQ $RemotePreviousDirQ
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
    tar -czf $RemoteBackupPathQ -C $RemotePluginDirQ .
    mv $RemotePluginDirQ $RemotePreviousDirQ
    had_previous=1
fi
mv $RemoteStagingDirQ $RemotePluginDirQ
deployed=1
chown -R $RemoteOwnerQ $RemotePluginDirQ
find $RemotePluginDirQ -type d -exec chmod 755 {} +
find $RemotePluginDirQ -type f -exec chmod 644 {} +
test -s $RemotePluginDirQ/build/main.js
test -s $RemotePluginDirQ/build/main.css
while IFS= read -r -d '' php_file; do
    php -l "`$php_file" >/dev/null
done < <(find $RemotePluginDirQ -type f -name '*.php' -print0)
printf '%s  %s\n' '$ExpectedPluginHash' $RemotePluginDirQ/pandatask.php | sha256sum -c -
printf '%s  %s\n' '$ExpectedMainJsHash' $RemotePluginDirQ/build/main.js | sha256sum -c -
printf '%s  %s\n' '$ExpectedMainCssHash' $RemotePluginDirQ/build/main.css | sha256sum -c -
command -v wp >/dev/null 2>&1
sudo -u iarf -- wp plugin is-active pandatask --path=$RemoteWordPressDirQ
actual_version=`$(sudo -u iarf -- wp plugin get pandatask --field=version --path=$RemoteWordPressDirQ)
test "`$actual_version" = $ExpectedVersionQ
if ! sudo -u iarf -- wp cache flush --path=$RemoteWordPressDirQ >/dev/null; then
    echo 'Warning: WordPress object cache could not be flushed; deployment files and plugin activation were verified.' >&2
fi
trap - ERR
if ! rm -rf $RemotePreviousDirQ; then
    echo 'Warning: the temporary previous-release directory could not be removed.' >&2
fi
if ! rm -f $RemotePackagePathQ; then
    echo 'Warning: the uploaded deployment package could not be removed.' >&2
fi
"@
    $RemoteSwap = $RemoteSwap -replace "`r`n?", "`n"
    $RemoteEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($RemoteSwap))
    ssh $HostName "echo '$RemoteEncoded' | base64 -d | bash"
    if ($LASTEXITCODE -ne 0) {
        throw "Production swap failed (exit code $LASTEXITCODE)"
    }

    Write-Host '5. Confirming production release metadata...' -ForegroundColor Cyan
    $RemoteVerify = @"
set -e
printf '%s  %s\n' '$ExpectedPluginHash' $RemotePluginDirQ/pandatask.php | sha256sum -c -
printf '%s  %s\n' '$ExpectedMainJsHash' $RemotePluginDirQ/build/main.js | sha256sum -c -
printf '%s  %s\n' '$ExpectedMainCssHash' $RemotePluginDirQ/build/main.css | sha256sum -c -
sudo -u iarf -- wp plugin is-active pandatask --path=$RemoteWordPressDirQ
actual_version=`$(sudo -u iarf -- wp plugin get pandatask --field=version --path=$RemoteWordPressDirQ)
test "`$actual_version" = $ExpectedVersionQ
printf 'Pandatask production version: %s\n' "`$actual_version"
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
