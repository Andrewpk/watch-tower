# Client App Install Process

This guide covers installing the client integration in a Laravel application using `laravel/nightwatch`.

New to Watch Tower? Step 4 of the [quick start](quick-start.md) covers the same setup with checks after each step.

Official docs: https://nightwatch.laravel.com/docs/start-guide

## 1. Install Nightwatch

Follow the official Laravel Nightwatch start guide exactly as documented:
https://nightwatch.laravel.com/docs/start-guide

## 2. Set the Nightwatch Base URL

In addition to the standard Nightwatch setup, you must point the client at your Watch Tower instance.

Open `app/Providers/AppServiceProvider.php` and add the following line inside the `boot()` method:

```php
public function boot(): void
{
    $_SERVER['NIGHTWATCH_BASE_URL'] = 'http://127.0.0.1:8000'; // your hosted url
}
```

Replace `http://127.0.0.1:8000` with the URL where your Watch Tower instance is hosted.

## 3. Continue with the Standard Process

The rest of the installation and configuration follows the official Nightwatch documentation — no further customization is required.
