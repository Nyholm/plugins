<?php

namespace Http\Client\Plugin;

use Http\Client\Tools\Promise\FulfilledPromise;
use Http\Message\StreamFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Allow for caching a response.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class CachePlugin implements Plugin
{
    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var array
     */
    private $config;

    /**
     * @var OptionsResolver
     */
    private $optionsResolver;

    /**
     * Available options are
     *  - respect_cache_headers: Whether to look at the cache directives or ignore them.
     *  - default_ttl: If we do not respect cache headers or can't calculate a good ttl, use this value.
     *
     * @param CacheItemPoolInterface $pool
     * @param StreamFactory          $streamFactory
     * @param array                  $config
     */
    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory, array $config = [])
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;

        $this->optionsResolver = new OptionsResolver();
        $this->configureOptions($this->optionsResolver);

        $this->setConfig($config);
    }

    /**
     * {@inheritdoc}
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $method = strtoupper($request->getMethod());

        // if the request not is cachable, move to $next
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $next($request);
        }

        // If we can cache the request
        $key = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($key);

        if ($cacheItem->isHit()) {
            // return cached response
            $data = $cacheItem->get();
            $response = $data['response'];
            $response = $response->withBody($this->streamFactory->createStream($data['body']));

            return new FulfilledPromise($response);
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            if ($this->isCacheable($response)) {
                $cacheItem->set(['response' => $response, 'body' => $response->getBody()->__toString()])
                    ->expiresAfter($this->getMaxAge($response));
                $this->pool->save($cacheItem);
            }

            return $response;
        });
    }

    /**
     * Verify that we can cache this response.
     *
     * @param ResponseInterface $response
     *
     * @return bool
     */
    protected function isCacheable(ResponseInterface $response)
    {
        if (!in_array($response->getStatusCode(), [200, 203, 300, 301, 302, 404, 410])) {
            return false;
        }
        if (!$this->config['respect_cache_headers']) {
            return true;
        }
        if ($this->getCacheControlDirective($response, 'no-store') || $this->getCacheControlDirective($response, 'private')) {
            return false;
        }

        return true;
    }

    /**
     * Get the value of a parameter in the cache control header.
     *
     * @param ResponseInterface $response
     * @param string            $name     The field of Cache-Control to fetch
     *
     * @return bool|string The value of the directive, true if directive without value, false if directive not present.
     */
    private function getCacheControlDirective(ResponseInterface $response, $name)
    {
        $headers = $response->getHeader('Cache-Control');
        foreach ($headers as $header) {
            if (preg_match(sprintf('|%s=?([0-9]+)?|i', $name), $header, $matches)) {

                // return the value for $name if it exists
                if (isset($matches[1])) {
                    return $matches[1];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     *
     * @return string
     */
    private function createCacheKey(RequestInterface $request)
    {
        return md5($request->getMethod().' '.$request->getUri());
    }

    /**
     * Get a ttl in seconds. It could return null if we do not respect cache headers and got no defaultTtl.
     *
     * @param ResponseInterface $response
     *
     * @return int|null
     */
    private function getMaxAge(ResponseInterface $response)
    {
        if (!$this->config['respect_cache_headers']) {
            return $this->config['default_ttl'];
        }

        // check for max age in the Cache-Control header
        $maxAge = $this->getCacheControlDirective($response, 'max-age');
        if (!is_bool($maxAge)) {
            $ageHeaders = $response->getHeader('Age');
            foreach ($ageHeaders as $age) {
                return $maxAge - ((int) $age);
            }

            return $maxAge;
        }

        // check for ttl in the Expires header
        $headers = $response->getHeader('Expires');
        foreach ($headers as $header) {
            return (new \DateTime($header))->getTimestamp() - (new \DateTime())->getTimestamp();
        }

        return $this->config['default_ttl'];
    }

    /**
     * Configure an options resolver
     *
     * @param OptionsResolver $resolver
     *
     * @return array
     */
    private function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'default_ttl' => null,
            'respect_cache_headers' => true,
        ]);

        $resolver->setAllowedTypes('default_ttl', ['int', 'null']);
        $resolver->setAllowedTypes('respect_cache_headers', 'bool');
    }

    /**
     * Set config to the plugin. This will overwrite any previously set config values.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $this->optionsResolver->resolve($config);
    }
}
