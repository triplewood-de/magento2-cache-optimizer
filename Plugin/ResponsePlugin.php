<?php

declare(strict_types=1);

namespace Triplewood\CacheOptimizer\Plugin;

use Magento\Customer\Model\Context as ContextModel;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Exception\NotFoundException;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\RouterListInterface;

class ResponsePlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Context $httpContext,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RouterListInterface $routerList,
        private readonly array $whitelistedUrls = [],
    ) {
    }

    private function getRouteInfo() : array
    {
        $moduleName = $this->request->getModuleName();
        $moduleAction = $this->request->getActionName();

        if ($moduleName && $moduleAction) {
            return [
                'moduleName'   => $moduleName,
                'moduleAction' => $moduleAction,
            ];
        }

        foreach ($this->routerList as $router) {
            try {
                $actionInstance = $router->match($this->request);
                if ($actionInstance) {
                    $actionRequest = $actionInstance->getRequest();
                    $moduleName = $actionRequest->getModuleName();
                    $moduleAction = $actionRequest->getActionName();

                    if (!$moduleName || !$moduleAction) {
                        $pathInfo = ltrim($actionRequest->getPathInfo(), '/').'/';
                        ;
                        $parts = explode('/', $pathInfo);
                        $moduleName = $parts[0] ?? '';
                        $moduleAction = $parts[2] ?? '';
                    }
                    return [
                        'moduleName'   => $moduleName,
                        'moduleAction' => $moduleAction,
                    ];
                }
            } catch (NotFoundException $e) {
                break;
            }
        }
        return [];
    }

    public function aroundSendResponse(Http $subject, callable $proceed)
    {
        $isEnabled = $this->scopeConfig->getValue(
            'guest_cache_optimization/settings/active',
            ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return $proceed();
        }

        $isLoggedIn = $this->httpContext->getValue(ContextModel::CONTEXT_AUTH);

        // ignore all but GET-requests and logged-in requests
        if ($isLoggedIn || !$this->request->isGet()) {
            return $proceed();
        }

        $routeInfo = $this->getRouteInfo();
        $moduleName = $routeInfo['moduleName'] ?? '';
        $moduleAction = $routeInfo['moduleAction'] ?? '';

        foreach ($this->getWhitelistedUrls() as $url) {
            if ($url['moduleName'] === $moduleName && $url['moduleAction'] === $moduleAction) {
                // is whitelisted
                $header = $this->scopeConfig->getValue(
                    'guest_cache_optimization/settings/cache_header',
                    ScopeInterface::SCOPE_STORE
                ) ?? 'public, max-age=600, s-maxage=3600';

                $subject->setHeader('Cache-Control', $header, true);
                $subject->clearHeader('Pragma');
                $subject->setHeader('Pragma', '', true);
                $subject->clearHeader('Expires');
                $subject->setHeader('Expires', '', true);
                break;
            }
        }
        return $proceed();
    }

    private function getWhitelistedUrls(): array
    {
        $urls = [];

        foreach ($this->whitelistedUrls as $moduleName => $config) {
            if (!is_array($config)
                || !array_key_exists('enabled', $config)
                || !array_key_exists('moduleAction', $config)
                || !$config['enabled']
            ) {
                continue;
            }

            $urls[] = [
                'moduleName' => $moduleName,
                'moduleAction' => $config['moduleAction'],
            ];
        }

        return $urls;
    }
}
