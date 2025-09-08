# SurvosLinguaBundle

A Symfony bundle for lingua functionality.

## Features

- Twig extension with custom filters and functions
- Main service class for business logic
- Controller with example routes
- Console command for CLI operations
- Configurable via environment variables

## Installation

Install the bundle using Composer:

```bash
composer require survos/lingua-bundle
```

If you're using Symfony Flex, the bundle will be automatically registered. Otherwise, add it to your `config/bundles.php`:

```php
return [
    // ...
    Survos\LinguaBundle\SurvosLinguaBundle::class => ['all' => true],
];
```

## Configuration

Configure the bundle in `config/packages/lingua.yaml`:

```yaml
survos_lingua:
  api_key: '%env(SURVOS_LINGUA_API_KEY)%'
```

Or set environment variables:

```bash
SURVOS_LINGUA_API_KEY=your_value_here
```

## Usage

This bundle provides various components depending on your configuration. Check the generated service classes and controllers for specific usage examples.

## Testing

Run the test suite:

```bash
./vendor/bin/phpunit
```

## License

This bundle is released under the MIT license. See the [LICENSE](LICENSE) file for details.
