param([string]$RemoteUrl="")
if (-not (Test-Path .git)) { git init; git branch -M main }
git add .
git commit -m "Initial Billing RTRW Net"
if ($RemoteUrl -ne "") { git remote remove origin 2>$null; git remote add origin $RemoteUrl }
git push -u origin main
