<?php
declare(strict_types=1);
namespace App\Module\Accounts\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(private readonly UrlGeneratorInterface $urls) {}

    public function authenticate(Request $request): Passport
    {
        $username=trim((string)$request->request->get('_username',''));
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME,$username);

        return new Passport(
            new UserBadge($username),
            new PasswordCredentials((string)$request->request->get('_password','')),
            [new CsrfTokenBadge('authenticate',(string)$request->request->get('_csrf_token',''))]
        );
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?\Symfony\Component\HttpFoundation\Response
    {
        return new RedirectResponse($this->urls->generate('app_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_login');
    }
}
