<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\BabelBundle\Entity\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\Extractor\ExtractorInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBagInterface;

use function Symfony\Component\String\u;

/**
 * Harvests translatable content into babel Str storage (never writes yaml files
 * with placeholder/untranslated entries -- lingua:pull only ever fills in text
 * that's actually known, so anything still missing simply falls through to
 * Symfony's normal missing-translation handling, visible in the debug toolbar).
 */
final class LinguaHarvestCommand
{
    /**
     * Domains harvested by default. Deliberately an allowlist, not a blocklist:
     * a translator's catalogue also includes every third-party bundle's own
     * shipped domains (EasyAdmin, Netgen Layouts, Meili, ...), each already
     * professionally translated -- those must never be swept up and overwritten
     * by our own machine-translation pipeline.
     */
    private const array DEFAULT_DOMAINS = ['messages', 'system'];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly ExtractorInterface $extractor,
        #[Autowire(service: 'translator')] private readonly TranslatorBagInterface $translator,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[AsCommand(
        'lingua:harvest:routes',
        'Harvest route names into babel Str storage (context "routing"), humanized for translation.',
    )]
    public function harvestRoutes(SymfonyStyle $io): int
    {
        [$created, $updated, $unchanged] = [0, 0, 0];

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if ($this->isInternalRoute($name)) {
                continue;
            }

            $label = str_replace('app_', '', $name);
            $label = u($label)->snake()->replace('_', ' ')->title()->toString();

            [$created, $updated, $unchanged] = $this->upsertStr($name, 'routing', $label, $created, $updated, $unchanged);
        }

        $this->em->flush();

        $io->success(sprintf('Routes harvested: %d created, %d updated, %d unchanged.', $created, $updated, $unchanged));

        return Command::SUCCESS;
    }

    #[AsCommand(
        'lingua:harvest:messages',
        'Harvest |trans keys used in templates into babel Str storage, one context per domain.',
    )]
    public function harvestMessages(
        SymfonyStyle $io,
        #[Option('Comma-separated domains to harvest (allowlist -- default: messages,system). Never point this at a vendor bundle\'s own domain.')]
        ?string $domains = null,
    ): int {
        $allowedDomains = $domains !== null
            ? array_values(array_filter(array_map('trim', explode(',', $domains))))
            : self::DEFAULT_DOMAINS;

        $existing = $this->translator->getCatalogue('en');

        $catalogue = new MessageCatalogue('en');
        foreach ($allowedDomains as $domain) {
            $catalogue->add($existing->all($domain), $domain);
        }

        $this->extractor->setPrefix('');
        $this->extractor->extract($this->projectDir . '/templates', $catalogue);

        [$created, $updated, $unchanged] = [0, 0, 0];
        $newKeys = 0;

        foreach ($allowedDomains as $domain) {
            foreach ($catalogue->all($domain) as $key => $extractedText) {
                // TwigExtractor unconditionally overwrites any key it finds used in a
                // template with the raw key itself (that's how it flags brand-new keys
                // for translators) -- so a key that already had real text defined would
                // get clobbered here unless we prefer the pre-extraction value for it.
                $isNew = !$existing->defines($key, $domain);
                $text = $isNew ? $extractedText : $existing->get($key, $domain);
                if ($isNew) {
                    $newKeys++;
                }

                // Str.code is VARCHAR(64) but |trans keys can be arbitrarily long (some
                // apps use full English sentences as msgids) -- hash long ones and keep
                // the real key in meta so BabelTranslationLoader can reconstruct it.
                $code = $domain . ':' . $key;
                if (\strlen($code) > 64) {
                    $code = $domain . ':' . hash('xxh3', $key);
                }
                [$created, $updated, $unchanged] = $this->upsertStr($code, $domain, $text, $created, $updated, $unchanged, $key);
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Messages harvested: %d created, %d updated, %d unchanged (%d never seen before -- their English source is just the humanized key, review it).',
            $created,
            $updated,
            $unchanged,
            $newKeys,
        ));

        return Command::SUCCESS;
    }

    /** @return array{int, int, int} */
    private function upsertStr(string $code, string $context, string $source, int $created, int $updated, int $unchanged, ?string $realKey = null): array
    {
        $repo = $this->em->getRepository(Str::class);
        $meta = $realKey !== null ? ['key' => $realKey] : [];

        $str = $repo->findOneBy(['code' => $code]);
        if (!$str) {
            $str = new Str();
            $str->code = $code;
            $str->sourceLocale = 'en';
            $str->context = $context;
            $str->source = $source;
            $str->meta = $meta;
            $this->em->persist($str);
            $created++;
        } elseif ($str->source !== $source) {
            $str->source = $source;
            $str->meta = $meta;
            $updated++;
        } else {
            $unchanged++;
        }

        return [$created, $updated, $unchanged];
    }

    /**
     * Heuristic filter for routes that are never surfaced as UI nav/menu labels:
     * framework-internal routes, API-Platform's per-entity CRUD endpoints, and
     * EasyAdmin's generated CRUD routes. Not a substitute for walking the actual
     * rendered KnpMenu tree (which would be exact), but cuts the bulk of noise
     * cheaply -- api-platform alone generates ~4 routes per resource.
     */
    private function isInternalRoute(string $name): bool
    {
        return str_starts_with($name, '_')
            || str_starts_with($name, 'api_')
            || str_contains($name, '_api_')
            || str_starts_with($name, 'easyadmin');
    }
}
