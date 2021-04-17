<?php

declare(strict_types=1);

namespace Atk4\Teams;

use Atk4\Container\AppContainer;
use Atk4\Core\AppScopeTrait;
use Atk4\Core\Exception;
use Atk4\Core\NameTrait;
use Atk4\Core\SessionTrait;
use atk4\data\Persistence\Array_;
use Atk4\Teams\Data\UserTeams;
use Delight\Cookie\Cookie;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use TheNetworg\OAuth2\Client\Provider\Azure;
use Throwable;

class Teams
{
    use AppScopeTrait;
    use NameTrait;
    use SessionTrait;

    private Azure        $provider;
    private ?AccessToken $token;
    private AppContainer $container;
    private UserTeams    $userTeams;

    public function __construct(AppContainer $container)
    {
        $this->container = $container;

        $this->setProvider();

        $this->token = $this->getToken();
        $this->tryLoadUserTeamsModel();
    }

    private function getToken(): ?AccessToken
    {
        $serialized = $this->recall('teams_token');

        return $serialized === null
            ? null
            : unserialize($serialized);
    }

    private function tryLoadUserTeamsModel(): UserTeams
    {
        $persistence = new Array_($this->recall('teams_user', []));
        $this->userTeams = new UserTeams($persistence);

        return $this->userTeams->tryLoad(1);
    }

    public function authenticate()
    {
        // cookie is created only during requestAuth()
        // if cookie exists we must call the callback()
        // in the callback the cookie will be deleted
        if (Cookie::get('TEAM_AUTH_STATE', '') !== '') {
            $this->callback(); // client will be redirect
        }

        // We have a token
        if ($this->token !== null) {
            // check if is expired and refresh if not
            if ($this->token->hasExpired()) {
                if ($this->token->getRefreshToken() !== null) {
                    $this->refreshToken();
                } else {
                    $this->forgetToken();
                    $this->authenticate(); // client will be redirect
                }
            }
        }

        // we don't have a token
        if ($this->token === null) {
            $this->requestAuth(); // client will be redirect
        }

        // client authentication : OK

        // call Graph api /me and refresh user
        $this->refreshWhoAMI();
    }

    public function callback()
    {
        try {
            if (Cookie::exists('TEAM_AUTH_STATE')) {
                throw new Exception('Something is wrong, cookie not exists');
            }

            $cookie_teams = (string) Cookie::get('TEAM_AUTH_STATE');

            // we already set the cookie with a really short expire
            // we try to delete it
            (new Cookie('TEAM_AUTH_STATE'))->deleteAndUnset();

            $teams_code = $_GET['code'] ?? null;
            $teams_state = $_GET['state'] ?? null;

            if ($teams_code && $cookie_teams && $teams_state) {
                if ($teams_state === $cookie_teams) {
                    // @var AccessToken $token
                    $this->token = $this->provider->getAccessToken('authorization_code', [
                        'scope' => $this->provider->scope,
                        'code' => $teams_code,
                    ]);

                    $this->serializeToken($this->token);
                    $this->redirect($this->container->get('teams/app_redirect_uri_on_success'));
                }
            }
        } catch (Throwable $e) {
            if ($this->getApp() === null) {
                echo $e->getMessage();
            }

            throw new Exception($e->getMessage());
        }
    }

    private function setProvider()
    {
        $this->provider = new Azure([
            'clientId' => $this->container->get('teams/app_id'),
            'clientSecret' => $this->container->get('teams/app_secret'),
            'redirectUri' => $this->container->get('teams/app_redirect_uri'),
            'scopes' => $this->container->get('teams/app_scopes'),
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);
    }

    private function serializeToken(AccessToken $token): void
    {
        $this->memorize('teams_token', serialize($token));
    }

    private function redirect(string $uri)
    {
        if ($this->getApp() === null) {
            header('Location: ' . $uri);

            exit;
        }

        $this->getApp()->redirect($uri);
    }

    private function requestAuth()
    {
        $authorizationUrl = $this->provider->getAuthorizationUrl(['scope' => $this->provider->scope]);

        $cookie = new Cookie('TEAM_AUTH_STATE');
        $cookie->setValue($this->provider->getState());
        $cookie->setMaxAge(5);
        $cookie->setSecureOnly(true);
        $cookie->setSameSiteRestriction('None');
        $cookie->saveAndSet();

        $this->redirect($authorizationUrl);
    }

    public function refreshWhoAMI(bool $force = false): void
    {
        if ($this->userTeams->loaded() && !$force) {
            return;
        }

        $data = $this->provider->get(
            $this->provider->getRootMicrosoftGraphUri($this->token) . '/v1.0/me',
            $this->token
        );

        $this->setTeamUser($data);
    }

    private function setTeamUser(array $data)
    {
        unset($data['@odata.context']); // remove useless data

        $data['guid'] = $data['id']; // switch id with Guid
        $data['id'] = 1;             // hardcode id for session load / delete

        $this->userTeams->save($data);
    }

    public function getUserTeams(): UserTeams
    {
        return $this->userTeams;
    }

    private function forgetToken()
    {
        $this->forget('teams_token');
        $this->token = null;
    }

    private function logout()
    {
        $this->forgetToken();
        $this->redirect($this->container->get('teams/app_redirect_uri_on_success'));
    }

    private function refreshToken()
    {
        try {
            $this->token = $this->provider->getAccessToken('refresh_token', [
                'scope' => $this->provider->scope,
                'refresh_token' => $this->token->getRefreshToken(),
            ]);

            $this->serializeToken($this->token);
        } catch (IdentityProviderException $e) {
            $this->forgetToken();
        }
    }
}
