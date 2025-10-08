<?php
//echo "<pre/>";
//var_dump($_SERVER);
//use RdKafka;

$router = new Api($_SERVER);
$router->run();
exit;

class Api
{
    private const string CMD_CREATE_USER = 'user';
    private const string CMD_CREATE_MOVIE = 'movie';
    private const string CMD_CREATE_PAYMENT = 'payment';

    private const KNOWN_CMD = [
        self::CMD_CREATE_USER,
        self::CMD_CREATE_MOVIE,
        self::CMD_CREATE_PAYMENT,
    ];

    private ?string $apiMethod = null;
    private ?array $httpParsedBody = [];
    private ?string $kafkaUrl = null;
    private ?KafkaService $kafkaService = null;

    public function __construct(array $varServer)
    {
        $this->parseRequest($varServer);
    }

    public function run(): void
    {
        $this->validateAttributes();
        $this->kafkaService = new KafkaService($this->kafkaUrl);

        $handler = match ($this->apiMethod) {
            self::CMD_CREATE_USER => function () {
                return $this->kafkaService->createUser($this->httpParsedBody);
            },
            self::CMD_CREATE_MOVIE => function () {
                return $this->kafkaService->createMovie($this->httpParsedBody);
            },
            self::CMD_CREATE_PAYMENT => function () {
                return $this->kafkaService->createPayment($this->httpParsedBody);
            },
            default => function () {
                http_response_code(400);
                exit;
            }
        };
        try {
            $result = $handler();
            $result['status'] = "success";
        } catch (Exception $exception) {
            http_response_code($exception->getCode() ?? 500);
            echo $exception->getMessage();
            return;
        }

        header('HTTP/1.1 201', true, 201);
        $result = json_encode($result, JSON_PRETTY_PRINT);
        if (json_validate($result)) {
            header('Content-type: application/json');
        }
        echo $result;
    }

    private function parseRequest(array $varServer): void
    {
        $this->kafkaUrl = $varServer['KAFKA_BROKERS'] ?? null;

        $pathInfo = $varServer['PATH_INFO'] ?: $this->preparePathInfo($varServer);
        preg_match('/^\/([A-Za-z]+)\/?.*$/', $pathInfo, $matches);
        $this->apiMethod = !empty($matches[1]) ? $matches[1] : null;

        if (
            ('POST' === $_SERVER['REQUEST_METHOD'])
            && ('application/json' === $_SERVER['HTTP_CONTENT_TYPE'])
            && $_SERVER['CONTENT_LENGTH'] > 0
        ) {
            $content = file_get_contents('php://input');
            $this->httpParsedBody = json_decode($content, true, 512, \JSON_BIGINT_AS_STRING);
        }
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
        if (
            (null === $this->apiMethod)
            || (!is_array($this->httpParsedBody))
            || !in_array($this->apiMethod, self::KNOWN_CMD, true)
        ) {
            header('HTTP/1.1 400 Bad Request', true, 400);
            exit;
        }

        if (null === $this->kafkaUrl) {
            header('HTTP/1.1 500 ENV incorrect', true, 500);
            exit;
        }
    }
}

class KafkaService
{
    private const string TOPIC_USERS = 'user-events';
    private const string TOPIC_MOVIES = 'movie-events';
    private const string TOPIC_PAYMENTS = 'payment-events';

    private readonly KafkaClient $kafkaClient;
    public function __construct(string $kafkaUrl)
    {
        $this->kafkaClient = new KafkaClient($kafkaUrl);
    }

    public function createUser(array $data): array
    {
        $this->kafkaClient->produceMessage(self::TOPIC_USERS, json_encode($data, JSON_THROW_ON_ERROR));
        sleep(1);
        $payload = $this->kafkaClient->readOneMessageInTopic(self::TOPIC_USERS);

        return json_validate($payload) ? json_decode($payload, true) : [];
    }

    public function createMovie(array $data): array
    {
        $this->kafkaClient->produceMessage(self::TOPIC_MOVIES, json_encode($data, JSON_THROW_ON_ERROR));
        sleep(1);
        $payload = $this->kafkaClient->readOneMessageInTopic(self::TOPIC_MOVIES);

        return json_validate($payload) ? json_decode($payload, true) : [];
    }

    public function createPayment(array $data): array
    {
        $this->kafkaClient->produceMessage(self::TOPIC_PAYMENTS, json_encode($data, JSON_THROW_ON_ERROR));
        sleep(1);
        $payload = $this->kafkaClient->readOneMessageInTopic(self::TOPIC_PAYMENTS);

        return json_validate($payload) ? json_decode($payload, true) : [];
    }
}

class KafkaClient
{
    private static $conf;

    public function __construct(string $kafkaUrl)
    {
        $conf = new RdKafka\Conf();
        $conf->set('metadata.broker.list', $kafkaUrl);
        $conf->set('log_level', (string) LOG_DEBUG);
        $conf->set('debug', 'all');

        self::$conf = $conf;
    }

    public function produceMessage(string $topic, string $message): void
    {
        $producer = new RdKafka\Producer(self::$conf);
        $topic = $producer->newTopic($topic);

        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message);
        $producer->poll(0);

        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $producer->flush(10000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
//                echo "---Сообщение отправлено: $message\n";
                break;
            }
        }

        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new \RuntimeException('Was unable to flush, messages might be lost!');
        }
    }

    public function readOneMessageInTopic(string $topic): string
    {
        $conf = self::$conf;
        $conf->set('group.id', 'group-' . $topic);
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('enable.auto.offset.store', 'false');

        $consumer = new RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([$topic]);

        $message = $consumer->consume(5000);

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
//                echo "---Получено сообщение: {$message->payload}\n";
                $payload = $message->payload;
                $consumer->commit($message);
//                echo "---Offset сохранён: {$message->offset}\n";
                break;

            default:
                throw new Exception('Kafka consumer error: ' . $message->errstr());
//                echo "---Ошибка: " . $message->errstr() . "\n";
                break;
        }

        return $payload;
    }
}
