# OneHealth API

Laravel 13 backend for the OneHealth patient app (patient-owned medical records, Nigeria).

Start with **docs/BACKEND-GUIDE.md**: how to run it, the response/error standard every module follows, how to add a module, the endpoint list and the security notes.

```powershell
composer install
copy .env.example .env
php artisan key:generate
# set NIN_HASH_KEY in .env (see the guide), then:
php artisan migrate --seed
php artisan serve
php artisan test
```
