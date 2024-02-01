<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\Sentry\Tracing\Aspect;

use FriendsOfHyperf\Sentry\Constants;
use FriendsOfHyperf\Sentry\Tracing\SpanStarter;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use longlang\phpkafka\Producer\ProduceMessage;
use longlang\phpkafka\Protocol\RecordBatch\RecordHeader;

use function Hyperf\Tappable\tap;

/**
 * @property array $headers
 */
class KafkaProducerAspect extends AbstractAspect
{
    use SpanStarter;

    public array $classes = [
        'Hyperf\Kafka\Producer::sendAsync',
        'Hyperf\Kafka\Producer::sendBatchAsync',
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        return match ($proceedingJoinPoint->methodName) {
            'sendAsync' => $this->sendAsync($proceedingJoinPoint),
            'sendBatchAsync' => $this->sendBatchAsync($proceedingJoinPoint),
            default => $proceedingJoinPoint->process(),
        };
    }

    protected function sendAsync(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $span = $this->startSpan(
            'kafka.send',
            sprintf('%s::%s', $proceedingJoinPoint->className, $proceedingJoinPoint->methodName)
        );
        $carrier = json_encode([
            'sentry-trace' => $span->toTraceparent(),
            'baggage' => $span->toBaggage(),
            'traceparent' => $span->toW3CTraceparent(),
        ]);

        $headers = $proceedingJoinPoint->arguments['keys']['headers'] ?? [];
        $headers[] = (new RecordHeader())
            ->setHeaderKey(Constants::JOB_CARRIER)
            ->setValue($carrier);
        $proceedingJoinPoint->arguments['keys']['headers'] = $headers;

        return tap($proceedingJoinPoint->process(), fn () => $span->finish());
    }

    protected function sendBatchAsync(ProceedingJoinPoint $proceedingJoinPoint)
    {
        /** @var ProduceMessage[] $messages */
        $messages = $proceedingJoinPoint->arguments['keys']['messages'] ?? [];
        $span = $this->startSpan(
            'kafka.send_batch',
            sprintf('%s::%s', $proceedingJoinPoint->className, $proceedingJoinPoint->methodName)
        );
        $carrier = json_encode([
            'sentry-trace' => $span->toTraceparent(),
            'baggage' => $span->toBaggage(),
            'traceparent' => $span->toW3CTraceparent(),
        ]);

        foreach ($messages as $message) {
            (
                fn () => $this->headers[] = (new RecordHeader())
                    ->setHeaderKey(Constants::JOB_CARRIER)
                    ->setValue($carrier)
            )->call($message);
        }

        return tap($proceedingJoinPoint->process(), fn () => $span->finish());
    }
}
