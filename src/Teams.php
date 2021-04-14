<?php

namespace Atk4\Teams;

use Atk4\Container\AppContainer;
use Atk4\Core\AppScopeTrait;
use Atk4\Core\SessionTrait;
use League\OAuth2\Client\Token\AccessToken;
use TheNetworg\OAuth2\Client\Provider\Azure;

class Teams
{
    use AppScopeTrait;
    use SessionTrait;

    private Azure        $provider;
    private ?AccessToken $token;
    private AppContainer $container;

    public function __construct(AppContainer $container)
    {
        $this->container = $container;
        $this->token = $this->getToken();
    }

    private function setProvider()
    {
        $this->provider = new Azure([
            'clientId'               => $this->container->get("TEAMS_APP_ID"),
            'clientSecret'           => $this->container->get("TEAMS_APP_SECRET"),
            'redirectUri'            => $this->container->get("TEAMS_APP_REDIRECT_URI"),
            'scopes'                 => $this->container->get("TEAMS_APP_SCOPES"),
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);
    }

    private function serializeToken(AccessToken $token): void
    {
        $this->memorize("teams_token", serialize($token));
    }

    private function getToken(): ?AccessToken
    {
        $serialized = $this->recall("teams_token");

        return null == $serialized
            ? null
            : unserialize($serialized);
    }

    public function authenticate()
    {
        if (null === $this->token) {
            $this->requestAuth();
        }
    }

    private function requestAuth()
    {
        $this->setProvider();

        $authorizationUrl = $this->provider->getAuthorizationUrl(['scope' => $this->provider->scope]);

        setcookie('teams_state', $this->provider->getState(), ['samesite' => 'None', 'secure' => true]);

        if (null === $this->getApp()) {
            header('Location: ' . $authorizationUrl);

            return;
        }

        $this->getApp()->terminate("", ['Location' => $authorizationUrl]);
    }

    /**
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
     */
    private function callback()
    {
        $this->setProvider();

        $cookie_teams = $_COOKIE["teams_state"] ?? null;
        $teams_code = $_GET['code'] ?? null;
        $teams_state = $_GET['state'] ?? null;

        if ($teams_code && $cookie_teams && $teams_state) {

            if ($teams_state == $cookie_teams) {

                unset($_COOKIE["teams_state"]);

                /** @var AccessToken $token */
                $this->token = $this->provider->getAccessToken('authorization_code', [
                    'scope' => $this->provider->scope,
                    'code'  => $teams_code,
                ]);

                $this->serializeToken($this->token);

                return true;
            }
        }

        return false;
    }
}