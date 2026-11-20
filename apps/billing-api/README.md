# Mfaro Billing API

Laravel 13 session BFF and REST API. See the repository README and docs/ for setup and the exact supported feature set. Use PHP 8.4+ and Composer 2 with this lockfile. The production document root must be public/.

Local commands: php artisan migrate --seed; php artisan test --compact; php artisan billing:doctor. Production identity and gateway credentials must be configured separately. Never run the local password convenience in production.
