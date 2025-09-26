<?php

declare(strict_types=1);

namespace Survos\LinguaBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiPlatformDataFetcher
{
    public function __construct(private HttpClientInterface $httpClient,
                                private ?string $baseUrl=null)
    {
    }

    /**
     * Fetch all data from API Platform paginated endpoint by IDs
     *
     * @param array $ids Array of IDs to fetch
     * @param string $endpoint The API endpoint (e.g., '/api/targets')
     * @param string $keyParam The query parameter name for IDs (e.g., 'key')
     * @return array All collected data from all pages
     */
    public function fetchAllDataByIds(array $ids, string $endpoint, string $keyParam = 'key'): array
    {
        $allData = [];
        $currentPage = 1;
        $hasMorePages = true;

        while ($hasMorePages) {
            // Build query parameters
            $queryParams = [
                'page' => $currentPage,
            ];

            // Add IDs as array parameters (key[]=abc&key[]=def)
            foreach ($ids as $id) {
                $id = '000HU085ccbbd2d36e-en';
                $queryParams[$keyParam . '[]'] = $id;
            }


            // Make the HTTP request
            $proxy = null;
            if (str_contains($this->baseUrl, '.wip')) {
                $proxy = '127.0.0.1:7080';
            }
            $response = $this->httpClient->request('GET', $this->baseUrl . $endpoint, [
                'query' => $queryParams,
                'proxy' => $proxy,
                'no_proxy' => 'localhost,127.0.0.1',
                'headers' => [
                    'Accept' => 'application/ld+json',
                ],
            ]);

            $data = $response->toArray();

            // Extract the actual data items
            if (isset($data['hydra:member'])) {
                $allData = array_merge($allData, $data['hydra:member']);
            }
            dd($allData, $data, $response, $ids);

            // Check if there are more pages
            $hasMorePages = $this->hasNextPage($data);
            $currentPage++;

            // Safety check to prevent infinite loops
            if ($currentPage > 1000) {
                throw new \RuntimeException('Too many pages, possible infinite loop detected');
            }
        }

        return $allData;
    }

    /**
     * Check if there's a next page based on API Platform response
     *
     * @param array $responseData
     * @return bool
     */
    private function hasNextPage(array $responseData): bool
    {
        // Method 1: Check for hydra:view and hydra:next
        if (isset($responseData['hydra:view']['hydra:next'])) {
            return true;
        }

        // Method 2: Check total items vs current page
        if (isset($responseData['hydra:totalItems'])) {
            $totalItems = $responseData['hydra:totalItems'];
            $currentPageItems = count($responseData['hydra:member'] ?? []);
            $itemsPerPage = 30; // Default API Platform items per page, adjust if needed

            // If we have fewer items than the page size, we're on the last page
            return $currentPageItems >= $itemsPerPage && $totalItems > ($this->getCurrentPageNumber($responseData) * $itemsPerPage);
        }

        // Method 3: Check if current page has items and assume more exist
        // This is less reliable but works as a fallback
        $currentPageItems = count($responseData['hydra:member'] ?? []);
        return $currentPageItems > 0;
    }

    /**
     * Extract current page number from response
     */
    private function getCurrentPageNumber(array $responseData): int
    {
        if (isset($responseData['hydra:view']['@id'])) {
            $viewId = $responseData['hydra:view']['@id'];
            if (preg_match('/page=(\d+)/', $viewId, $matches)) {
                return (int) $matches[1];
            }
        }
        return 1;
    }

    /**
     * Alternative method: Fetch specific page
     */
    public function fetchPageByIds(array $ids, string $endpoint, int $page = 1, string $keyParam = 'key'): array
    {
        $queryParams = [
            'page' => $page,
        ];

        foreach ($ids as $id) {
            $queryParams[$keyParam . '[]'] = $id;
        }

        $response = $this->httpClient->request('GET', $this->baseUrl . $endpoint, [
            'query' => $queryParams,
            'headers' => [
                'Accept' => 'application/ld+json',
            ],
        ]);

        return $response->toArray();
    }
}

// Usage example:
/*
$ids = ['abc', 'def'];
$fetcher = new ApiPlatformDataFetcher($this->httpClient, 'https://trans.wip');

// Get all data across all pages
$allTargets = $fetcher->fetchAllDataByIds($ids, '/api/targets', 'key');

// Or get a specific page
$pageData = $fetcher->fetchPageByIds($ids, '/api/targets', 1, 'key');

// Direct usage without the class:
$ids = ['abc', 'def'];
$allData = [];
$currentPage = 1;
$hasMorePages = true;

while ($hasMorePages) {
    $queryParams = ['page' => $currentPage];
    foreach ($ids as $id) {
        $queryParams['key[]'] = $id;
    }

    $response = $this->httpClient->request('GET', 'https://trans.wip/api/targets', [
        'query' => $queryParams,
        'headers' => ['Accept' => 'application/ld+json'],
    ]);

    $data = $response->toArray();
    $allData = array_merge($allData, $data['hydra:member'] ?? []);

    $hasMorePages = isset($data['hydra:view']['hydra:next']);
    $currentPage++;

    if ($currentPage > 1000) break; // Safety check
}
*/
