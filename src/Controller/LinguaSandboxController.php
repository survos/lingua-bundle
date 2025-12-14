<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use App\Controller\ApiController;
use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LinguaSandboxController extends AbstractController
{
    public function __construct(
        private readonly LinguaClient $client,
        private ?ApiController $apiController,
    ) {}

    #[Route('/_lingua/sandbox', name: 'lingua_sandbox', methods: ['GET','POST'])]
    public function sandbox(Request $request): Response
    {
        $params = array_merge(
            $request->query->all(),   // fallback
            $request->request->all()  // overrides if POST
        );

        $defaults = [
            'source'      => (string) ($params['source'] ?? 'en'),
            'target'      => (string) ($params['target'] ?? 'es'),
            'engine'      => (string) ($params['engine'] ?? 'libre'),
            'textsRaw'    => (string) ($params['texts'] ?? "hello\nSave\nFile"),
            'html'        => filter_var($params['html'] ?? false, FILTER_VALIDATE_BOOL),
            'enqueue'     => filter_var($params['enqueue'] ?? false, FILTER_VALIDATE_BOOL),
            'force'       => filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOL),
            'noTranslate' => filter_var($params['noTranslate'] ?? false, FILTER_VALIDATE_BOOL),
            'now'         => filter_var($params['now'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        $result = null;

        if ($request->isMethod('POST')) {
            $texts = array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', $defaults['textsRaw']) ?: []),
                fn($x) => $x !== ''
            ));
            $targets = preg_split('/[,\s]+/', (string)$defaults['target']) ?: [];

            if ($defaults['now']) {
                $item = $this->client->translateNow(
                    $texts[0] ?? '',
                    $targets[0] ?? 'es',
                    $defaults['source'],
                    ['engine' => $defaults['engine']],
                    $defaults['noTranslate']
                );
                $result = [
                    'status' => 'ok',
                    'items' => [[
                        'source' => $item->source,
                        'target' => $item->target,
                        'text'   => $item->text,
                        'cached' => $item->cached,
                        'engine' => $item->engine,
                    ]],
                ];
            } else {
                $req = new BatchRequest(
                    texts: $texts,
                    source: $defaults['source'],
                    target: $targets,
                    html: $defaults['html'],
                    insertNewStrings: !$defaults['noTranslate'],
                    extra: ['engine' => $defaults['engine']],
                    enqueue: $defaults['enqueue'],
                    force: $defaults['force'],
                    engine: $defaults['engine']
                );
                if (class_exists(ApiController::class)) {
                    $res = $this->apiController->batchRequest($req);
                }

                $res = $this->client->requestBatch($req, $request);
                $result = [
                    'status' => $res->status ?? 'ok',
                    'jobId'  => $res->jobId,
                    'queued' => $res->queued,
                    'error'  => $res->error,
                    'items'  => $res->items,
//                    array_map(static fn($i) => [
//                        'source' => $i->source,
//                        'target' => $i->target,
//                        'text'   => $i->text,
//                        'cached' => $i->cached,
//                        'engine' => $i->engine,
//                    ], $res->items),
                ];
            }
        }

        return $this->render('@SurvosLingua/lingua/sandbox.html.twig', [
            'defaults' => $defaults,
            'result'   => $result,
        ]);
    }
}
