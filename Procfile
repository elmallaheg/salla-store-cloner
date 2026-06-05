web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --queue=salla-sync --tries=5 --sleep=3 --timeout=120
