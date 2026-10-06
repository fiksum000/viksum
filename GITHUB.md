# Push ke GitHub

Buat repository kosong di GitHub, misalnya `billing-rtrwnet`.

Di PowerShell pada folder project:

```powershell
git init
git branch -M main
git add .
git commit -m "Initial Billing RTRW Net"
git remote add origin https://github.com/USERNAME/billing-rtrwnet.git
git push -u origin main
```

Atau gunakan:

```powershell
.\scripts\push-github.ps1 -RemoteUrl "https://github.com/USERNAME/billing-rtrwnet.git"
```

Jangan commit `.env`, API key, private key, password MikroTik, token Fonnte, atau secret database.
