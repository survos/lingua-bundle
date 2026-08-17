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

## Being told when a string is translated

Translation on the lingua server is asynchronous, and the historical way to collect results was
to poll with `lingua:pull` — a job that usually finds nothing, and that nobody schedules until a
locale turns up empty in production.

Set a callback URL and lingua announces them instead:

```bash
LINGUA_CALLBACK_URL=https://your-app.example/webhook/lingua
LINGUA_WEBHOOK_SECRET=<same value the lingua server signs with>
```

`lingua:push` then sends each string's `Str.code` as a `ref`, the server records a subscription
per (string × target locale), and POSTs a signed `translation.completed` carrying up to 500
finished translations inline. `TranslationUpdateApplier` writes them to `StrTranslation` and
dispatches `TranslationUpdatedEvent` for app-specific work (reindexing, cache busting).

In the app:

```yaml
# config/routes/webhook.yaml
webhook:
    resource: '@FrameworkBundle/Resources/config/routing/webhook.php'
    prefix: /webhook

# config/packages/webhook.yaml
framework:
    webhook:
        routing:
            lingua:
                service: Survos\LinguaBundle\Webhook\LinguaWebhookRequestParser
                secret: '%env(default::LINGUA_WEBHOOK_SECRET)%'

# config/packages/messenger.yaml — REQUIRED, or the endpoint's 202 is a lie
framework:
    messenger:
        routing:
            'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': lingua_callback
```

Leave `LINGUA_CALLBACK_URL` unset and nothing changes — no subscriptions are created and
`lingua:pull` remains the way to collect results.

Full contract: [kit-bundle/docs/webhooks.md](../kit-bundle/docs/webhooks.md).

> Replaced `LinguaWebhookController`, which checked an `X-Api-Key` by hand and whose route was
> never registered. See survos-sites/mediary#8.

## Testing

Run the test suite:

```bash
./vendor/bin/phpunit
```

## License

This bundle is released under the MIT license. See the [LICENSE](LICENSE) file for details.
