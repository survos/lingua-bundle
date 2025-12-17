<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use Survos\Lingua\Contracts\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Developer sandbox for exercising Lingua requests with clear error reporting.
 *
 * Direct mode:
 * - If checked, MUST run in-process via \App\Service\TranslationIntakeService
 * - If the service is not available (this app is not the server), fail hard (no HTTP fallback)
 *
 * Note: This controller lives in lingua-bundle, so the intake service is optional.
 */
final class LinguaSandboxController extends AbstractController
{
    public function __construct(
        private readonly LinguaClient $client,
        // Optional: only present when this bundle is installed in the lingua-server app.
        private readonly ?\App\Service\TranslationIntakeService $intake = null,
    ) {}

    #[Route('/_lingua/sandbox', name: 'lingua_sandbox', methods: ['GET', 'POST'])]
    public function sandbox(Request $request): Response
    {
        $params = array_merge(
            $request->query->all(),
            $request->request->all()
        );

        $defaults = [
            'source'           => (string) ($params['source'] ?? 'en'),
            'target'           => (string) ($params['target'] ?? 'es'),
            'engine'           => (string) ($params['engine'] ?? 'libre'),
            'textsRaw'         => (string) ($params['texts'] ?? "hello\nSave\nFile"),

            'insertNewStrings' => filter_var($params['insertNewStrings'] ?? true, FILTER_VALIDATE_BOOL),
            'forceDispatch'    => filter_var($params['forceDispatch'] ?? false, FILTER_VALIDATE_BOOL),
            'transport'        => (string) ($params['transport'] ?? ''),
            'direct'           => filter_var($params['direct'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        $result = null;

        if ($request->isMethod('POST')) {
            $texts = array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', $defaults['textsRaw']) ?: []),
                static fn(string $x) => $x !== ''
            ));

            $targets = array_values(array_filter(
                array_map('trim', preg_split('/[,\s]+/', $defaults['target']) ?: []),
                static fn(string $x) => $x !== ''
            ));

            $transport = trim($defaults['transport']) ?: null;

            $req = new BatchRequest(
                source: $defaults['source'],
                target: $targets,
                texts: $texts,
                engine: ($defaults['engine'] !== '' ? $defaults['engine'] : null),
                insertNewStrings: (bool) $defaults['insertNewStrings'],
                forceDispatch: (bool) $defaults['forceDispatch'],
                transport: $transport,
            );

            if ($defaults['direct']) {
                if ($this->intake === null) {
                    throw new \LogicException(
                        'Direct mode requested, but the host application does not provide App\\Service\\TranslationIntakeService. ' .
                        'Disable direct mode, or run this sandbox inside the lingua-server application.'
                    );
                }

                // Let exceptions bubble for precise file/line in dev.
                $res = $this->intake->handle($req);

                $result = [
                    'mode'     => 'direct',
                    'status'   => 'ok',
                    'response' => $res,
                ];
            } else {
                $raw = $this->client->requestBatch($req, $request);

                $result = [
                    'mode'     => 'client',
                    'status'   => is_array($raw) ? (string)($raw['status'] ?? 'ok') : 'ok',
                    'response' => $raw,
                ];
            }
        }

        return $this->render('@SurvosLingua/lingua/sandbox.html.twig', [
            'defaults' => $defaults,
            'result'   => $result,
        ]);
    }
}
