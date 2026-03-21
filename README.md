# Laravel Notify Africa is a lightweight Laravel package for sending SMS via the Notify Africa API. It provides a clean, expressive interface for single and bulk messaging, integrates with Laravel Notifications, and simplifies SMS delivery without dealing with raw HTTP requests.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mg-techlegend/laravel-notify-africa.svg?style=flat-square)](https://packagist.org/packages/mg-techlegend/laravel-notify-africa)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mg-techlegend/laravel-notify-africa/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mg-techlegend/laravel-notify-africa/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mg-techlegend/laravel-notify-africa/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mg-techlegend/laravel-notify-africa/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mg-techlegend/laravel-notify-africa.svg?style=flat-square)](https://packagist.org/packages/mg-techlegend/laravel-notify-africa)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-notify-africa.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-notify-africa)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require mg-techlegend/laravel-notify-africa
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-notify-africa-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-notify-africa-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-notify-africa-views"
```

## Usage

```php
$laravelNotifyAfrica = new TechLegend\LaravelNotifyAfrica();
echo $laravelNotifyAfrica->echoPhrase('Hello, TechLegend!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Thomson Maguru](https://github.com/mg-techlegend)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
