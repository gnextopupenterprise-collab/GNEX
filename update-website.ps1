$ErrorActionPreference = "Stop"

$RepoPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $RepoPath

Write-Host "Updating GNEX website code..." -ForegroundColor Cyan
Write-Host "Folder: $RepoPath"

if (-not (Test-Path ".git")) {
    throw "This folder is not a Git repository."
}

$currentBranch = git branch --show-current
if ($currentBranch -ne "main") {
    throw "Current branch is '$currentBranch'. Switch to 'main' before updating."
}

$localChanges = git status --porcelain |
    Where-Object { $_ -notmatch " update-website\.(bat|ps1)$" }
if ($localChanges) {
    Write-Host ""
    Write-Host "Update stopped because there are local changes:" -ForegroundColor Yellow
    git status --short
    Write-Host ""
    throw "Commit, stash, or remove local changes before updating."
}

git fetch origin main
git pull --ff-only origin main

Write-Host ""
Write-Host "Website code is up to date." -ForegroundColor Green
