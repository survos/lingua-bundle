<?php
declare(strict_types=1);

namespace Survos\LinguaBundle\Controller;

use Survos\LinguaBundle\Dto\BatchRequest;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LinguaSandboxController extends AbstractController
{
    public function __construct(private readonly LinguaClient $client) {}

    #[Route('/_lingua/sandbox', name: 'lingua_sandbox', methods: ['GET','POST'])]
    public function sandbox(Request $request): Response
    {
        // defaults
        $defaults = [
            'source'        => (string)$request->get('source', 'en'),
            'target'        => (string)$request->get('target', 'es'),
            'engine'        => (string)$request->get('engine', 'libre'),
            'textsRaw'      => (string)$request->get('texts', "hello\nSave\nFile"),
            'html'          => (bool)$request->get('html', false),
            'enqueue'       => (bool)$request->get('enqueue', false),
            'force'         => (bool)$request->get('force', false),
            'noTranslate'   => (bool)$request->get('noTranslate', false), // lookup-only
            'now'           => (bool)$request->get('now', false),         // bypass queue
        ];

        $result = null;

        if ($request->isMethod('POST')) {
            $texts = array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', $defaults['textsRaw']) ?: []),
                fn($x) => $x !== ''
            ));
            $targets = str_contains($defaults['target'], ',')
                ? array_map('trim', explode(',', $defaults['target']))
                : $defaults['target'];

            if ($defaults['now']) {
                // one-shot translate/lookup for first line
                $item = $this->client->translateNow(
                    $texts[0] ?? '',
                    $defaults['target'],
                    $defaults['source'],
                    ['engine' => $defaults['engine']],
                    $defaults['noTranslate'] // if true, lookup only (no insert)
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
                $res = $this->client->requestBatch($req);
                $result = [
                    'status' => $res->status,
                    'jobId'  => $res->jobId,
                    'items'  => array_map(static fn($i) => [
                        'source' => $i->source,
                        'target' => $i->target,
                        'text'   => $i->text,
                        'cached' => $i->cached,
                        'engine' => $i->engine,
                    ], $res->items),
                ];
            }
        }

        return $this->render('@SurvosLingua/lingua/sandbox.html.twig', [
            'defaults' => $defaults,
            'result'   => $result,
        ]);
    }
}
