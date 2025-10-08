<?php
//echo "<pre>";
//var_dump($_SERVER);
//exit;

$router = new Api($_SERVER);
$router->run();
exit;

class Api
{
    private ?string $apiMethod = null;
    private ?string $requestUri = null;
    private ?string $monolithUrl = null;
    private ?string $moviesServiceUrl = null;
    private ?string $gradualMigrationFlag = null;
    private ?string $moviesMigrationPercent = null;

    public function __construct(array $varServer)
    {
        $this->parseRequest($varServer);
    }

    public function run(): void
    {
        $this->validateAttributes();

        $proxyService = new ProxyService(
            new Curler(),
            $this->requestUri,
            $this->apiMethod,
            $this->monolithUrl,
            $this->moviesServiceUrl,
            ('true' === $this->gradualMigrationFlag),
            (int) $this->moviesMigrationPercent
        );
        $proxyService->handle();
    }

    private function parseRequest(array $varServer): void
    {
        $this->monolithUrl = $varServer['MONOLITH_URL'] ?? null;
        $this->moviesServiceUrl = $varServer['MOVIES_SERVICE_URL'] ?? null;
        $this->gradualMigrationFlag = $varServer['GRADUAL_MIGRATION'] ?? null;
        $this->moviesMigrationPercent = $varServer['MOVIES_MIGRATION_PERCENT'] ?? null;
        $this->requestUri = $varServer['REQUEST_URI'] ?? null;

        $pathInfo = $varServer['PATH_INFO'] ?: $this->preparePathInfo($varServer);
        preg_match('/^\/([A-Za-z]+)\/?.*$/', $pathInfo, $matches);
        $this->apiMethod = !empty($matches[1]) ? $matches[1] : null;
    }

    private function preparePathInfo(array $varServer): string
    {
        if (null === ($requestUri = $varServer['REQUEST_URI'])) {
            return '/';
        }

        if (false !== $pos = strpos($requestUri, '?')) {
            $requestUri = substr($requestUri, 0, $pos);
        }

        $requestUri = str_replace($varServer['PATH_PREFIX'], '', $requestUri);

        if ('' !== $requestUri && '/' !== $requestUri[0]) {
            $requestUri = '/' . $requestUri;
        }

        return rawurldecode($requestUri);
    }

    private function validateAttributes(): void
    {
        if (null === $this->apiMethod || null === $this->requestUri) {
            header('HTTP/1.1 400 Bad Request', true, 400);
            exit;
        }

        if (null === $this->monolithUrl || null === $this->gradualMigrationFlag || null === $this->moviesMigrationPercent || null === $this->moviesServiceUrl) {
            header('HTTP/1.1 500 ENV incorrect', true, 500);
            exit;
        }
    }
}

class ProxyService
{
    private const CMD_MOVIES = 'movies';

    public function __construct(
        private readonly Curler $curler,
        private readonly string $requestUri,
        private readonly string $apiMethod,
        private readonly string $monolithUrl,
        private readonly string $moviesServiceUrl,
        private readonly bool $gradualMigrationFlag,
        private readonly int $moviesMigrationPercent,
    )
    {}

    public function handle(): void
    {
        try {
            if (
                (self::CMD_MOVIES === $this->apiMethod)
                && $this->gradualMigrationFlag
                && (random_int(1, 100) <= $this->moviesMigrationPercent)
            ) {
                $result = $this->delegateMoviesMicrocervice();
            } else {
                $result = $this->delegateMonolith();
            }

        } catch (Exception $exception) {
            http_response_code($exception->getCode() ?: 500);
            echo $exception->getMessage();
            return;
        }

        if (json_validate($result)) {
            header('Content-type: application/json');
        }
        echo $result;
    }

    private function delegateMonolith(): string
    {
        $path = sprintf('%s%s', $this->monolithUrl, $this->requestUri);
        $curlResponseDto = $this->curler->requestGET($path);

        if (200 === $curlResponseDto->getStatusCode()) {
            return $curlResponseDto->getBody();
        }
        throw new Exception($curlResponseDto->getBody(), $curlResponseDto->getStatusCode());
    }

    private function delegateMoviesMicrocervice(): string
    {
        $path = sprintf('%s%s', $this->moviesServiceUrl, $this->requestUri);
        $curlResponseDto = $this->curler->requestGET($path);

        if (200 === $curlResponseDto->getStatusCode()) {
            return $curlResponseDto->getBody();
        }
        throw new Exception($curlResponseDto->getBody(), $curlResponseDto->getStatusCode());
    }
}

class Curler
{
    public function __construct()
    {}

    public function requestGET(string $path): CurlResponseDto
    {
        $ch = curl_init();
        if (!$ch) {
            throw new Exception('Unable to init curl');
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL => $path,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_VERBOSE => true,
                CURLOPT_TIMEOUT => 30,
            ]
        );
        $responseBody = (string) curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorMessage = curl_error($ch);
        $curlErrorCode = curl_errno($ch);
        curl_close($ch);

        if (CURLE_OK !== $curlErrorCode) {
            throw new Exception($curlErrorMessage, 500);
        }

        return new CurlResponseDto($responseBody, $httpCode);
    }
}

class CurlResponseDto {
    public function __construct(
        private readonly string $body,
        private readonly int $statusCode = 0,
    ) {}

    public function getBody(): string {
        return $this->body;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }
}