<?php

declare(strict_types=1);

namespace Zeroseven\Countries\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Zeroseven\Countries\Utility\MenuUtility;

class Redirect implements MiddlewareInterface
{
    protected const REDIRECT_HEADER = 'X-z7country-redirect';

    /** @var ServerRequestInterface */
    private $request;

    /** @var RequestHandlerInterface */
    private $handler;

    protected function init(ServerRequestInterface $request, RequestHandlerInterface $handler): void
    {
        $this->request = $request;
        $this->handler = $handler;
    }

    protected function isRootPage(): bool
    {
        return $this->request->getUri()->getPath() === '/';
    }

    protected function isLocalReferer(): bool
    {
        if ($referer = $_SERVER['HTTP_REFERER'] ?? null) {
            return strtolower(parse_url($referer, PHP_URL_HOST)) === strtolower($this->request->getUri()->getHost());
        }

        return false;
    }

    protected function isDisabled(): bool
    {
        return !empty($this->request->getHeader(self::REDIRECT_HEADER)) || ($_COOKIE['disable-language-redirect'] ?? false);
    }

    protected function parseAcceptedLanguages(): ?array
    {
        if ($httpAcceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) {
            return array_map(static function ($v) {
                return preg_match('/^(\w{2})(-(\w{2}))?($|;)/', $v, $matches) ? [$matches[1], $matches[3] ?? null] : null;
            }, GeneralUtility::trimExplode(',', strtolower($httpAcceptLanguage)));
        }

        return null;
    }

    protected function getAcceptedLanguages(): ?array
    {
        if ($acceptedLanguages = $this->parseAcceptedLanguages()) {
            return array_unique(array_map(static fn($v) => $v[0], $acceptedLanguages));
        }

        return null;
    }

    protected function getAcceptedCountries(): ?array
    {
        if ($acceptedLanguages = $this->parseAcceptedLanguages()) {
            return array_filter(array_unique(array_map(static fn($v) => $v[1], $acceptedLanguages)));
        }

        return null;
    }

    protected function getRedirectUrl(array $languagePriority, array $countryPriority): ?string
    {
        if ($languageMenu = GeneralUtility::makeInstance(MenuUtility::class)->getLanguageMenu()) {
            foreach ($languagePriority as $languageCode) {
                foreach ($languageMenu as $language) {
                    if ($language['available'] && $language['object']->getTwoLetterIsoCode() === $languageCode) {
                        foreach ($countryPriority as $countryCode) {
                            foreach ($language['countries'] as $country) {
                                if ($country['available'] && strtolower($country['object']->getIsoCode()) === $countryCode) {
                                    return $country['link'];
                                }
                            }
                        }

                        return $language['link'];
                    }
                }
            }
        }

        return null;
    }

    protected function redirect(string $url): ResponseInterface
    {
        if ($url === (string)$this->request->getUri()) {
            return $this->handler->handle($this->request)->withHeader(self::REDIRECT_HEADER, 'same url');
        }

        return (new RedirectResponse($url, 307))->withHeader(self::REDIRECT_HEADER, 'true');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->init($request, $handler);

        if (
            $this->isRootPage()
            && !$this->isLocalReferer()
            && !$this->isDisabled()
            && ($languagePriority = $this->getAcceptedLanguages())
            && ($countryPriority = $this->getAcceptedCountries())
            && ($url = $this->getRedirectUrl($languagePriority, $countryPriority))
        ) {
            return $this->redirect($url);
        }

        return $handler->handle($request);
    }
}
