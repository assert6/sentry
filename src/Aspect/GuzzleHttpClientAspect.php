<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\Sentry\Aspect;

use FriendsOfHyperf\Sentry\Integration;
use FriendsOfHyperf\Sentry\Switcher;
use GuzzleHttp\Client;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Http\Message\ResponseInterface;
use Sentry\Breadcrumb;

/**
 * @method array getConfig
 * @property array $config
 */
class GuzzleHttpClientAspect extends AbstractAspect
{
    public array $classes = [
        Client::class . '::request',
        Client::class . '::requestAsync',
    ];

    public function __construct(protected Switcher $switcher)
    {
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        if (! $this->switcher->isBreadcrumbEnable('guzzle')) {
            return $proceedingJoinPoint->process();
        }

        $startTime = microtime(true);
        $instance = $proceedingJoinPoint->getInstance();
        $arguments = $proceedingJoinPoint->arguments;
        $options = $arguments['keys']['options'] ?? [];
        $guzzleConfig = (function () {
            if (method_exists($this, 'getConfig')) { // @deprecated ClientInterface::getConfig will be removed in guzzlehttp/guzzle:8.0.
                return $this->getConfig();
            }

            return $this->config ?? [];
        })->call($instance);

        if (($options['no_sentry_aspect'] ?? null) === true || ($guzzleConfig['no_sentry_aspect'] ?? null) === true) {
            return $proceedingJoinPoint->process();
        }

        // Disable the aspect for the requestAsync method.
        if ($proceedingJoinPoint->methodName == 'request') {
            $proceedingJoinPoint->arguments['keys']['options']['no_sentry_aspect'] = true;
        }

        $uri = $arguments['keys']['uri'] ?? '';
        $data['config'] = $guzzleConfig;
        $data['request']['method'] = $arguments['keys']['method'] ?? 'GET';
        $data['request']['options'] = $arguments['keys']['options'] ?? [];

        $result = $proceedingJoinPoint->process();

        if ($result instanceof ResponseInterface) {
            $data['response']['status'] = $result->getStatusCode();
            $data['response']['reason'] = $result->getReasonPhrase();
            $data['response']['headers'] = $result->getHeaders();
        }
        $data['timeMs'] = (microtime(true) - $startTime) * 1000;

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'guzzle',
            $uri,
            $data
        ));

        return $result;
    }
}
