<?php

namespace Atk4\Teams;

use Atk4\Container\AppContainer;
use Atk4\Core\AppScopeTrait;
use Atk4\Core\NameTrait;
use Atk4\Core\SessionTrait;
use Atk4\Data\Model;
use Atk4\Data\Persistence\Static_;
use Atk4\Teams\Data\UserTeams;
use Atk4\Ui\Exception;
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
        $this->token = $this->getToken();
        $this->tryLoadUserTeamsModel();
    }

    private function getToken(): ?AccessToken
    {
        $serialized = $this->recall("teams_token");

        return null == $serialized
            ? null
            : unserialize($serialized);
    }

    private function tryLoadUserTeamsModel(): UserTeams
    {
        $persistence = new Static_($this->recall("teams_user", []));
        $this->userTeams = new UserTeams($persistence);

        return $this->userTeams->tryLoad(1);
    }

    public function authenticate()
    {
        if (isset($_COOKIE["teams_state"])) {
            $this->callback(); // client will be redirect
        }

        if (null === $this->token) {
            $this->requestAuth(); // client will be redirect
        }

        // Token is valid
        // check if is expired and refresh if not
        if ($this->token->hasExpired()) {
            if (!is_null($this->token->getRefreshToken())) {
                $this->refreshToken();
            } else {
                $this->forgetToken();
                $this->authenticate(); // possibile loop but need to be tested
            }
        }

        // client authentication : OK

        $this->refreshWhoAMI(); // it will be reloaded from session
    }

    /**
     * @throws IdentityProviderException
     */
    public function callback()
    {
        $this->setProvider();

        $cookie_teams = $_COOKIE["teams_state"] ?? null;
        $teams_code = $_GET['code'] ?? null;
        $teams_state = $_GET['state'] ?? null;

        try {
            if ($teams_code && $cookie_teams && $teams_state) {

                if ($teams_state == $cookie_teams) {

                    /** @var AccessToken $token */
                    $this->token = $this->provider->getAccessToken('authorization_code', [
                        'scope' => $this->provider->scope,
                        'code'  => $teams_code,
                    ]);

                    $this->serializeToken($this->token);
                    $this->redirect($this->container->get("teams/app_redirect_uri_on_success"));
                }
            }
        } catch (Throwable $e) {
            if (null === $this->getApp()) {
                echo $e->getMessage();
            }
            throw new \Atk4\Core\Exception($e->getMessage());
        } finally {
            unset($_COOKIE["teams_state"]);
        }
    }

    private function setProvider()
    {
        $this->provider = new Azure([
            'clientId'               => $this->container->get("teams/app_id"),
            'clientSecret'           => $this->container->get("teams/app_secret"),
            'redirectUri'            => $this->container->get("teams/app_redirect_uri"),
            'scopes'                 => $this->container->get("teams/app_scopes"),
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);
    }

    private function serializeToken(AccessToken $token): void
    {
        $this->memorize("teams_token", serialize($token));
    }

    private function redirect(string $uri)
    {
        if (null === $this->getApp()) {
            header('Location: ' . $uri);
            return;
        }

        $this->getApp()->terminate("", ['Location' => $uri]);
    }

    private function requestAuth()
    {
        $this->setProvider();

        $authorizationUrl = $this->provider->getAuthorizationUrl(['scope' => $this->provider->scope]);

        setcookie('teams_state', $this->provider->getState(), ['samesite' => 'None', 'secure' => true]);

        $this->redirect($authorizationUrl);
    }

    public function refreshWhoAMI(bool $force = false): void
    {
        if ($this->userTeams->loaded() && !$force) {
            return;
        }

        $data = $this->provider->get(
            $this->provider->getRootMicrosoftGraphUri($this->token) . '/v1.0/me',$this->token
        );

        $this->setTeamUser($data);
    }

    private function setTeamUser(array $data)
    {
        unset($data["@odata.context"]); // remove useless data

        $data["guid"] = $data["id"]; // switch id with Guid
        $data["id"] = 1;             // hardcode id for session load / delete

        $this->userTeams->save($data);
    }

    /**
     * @return UserTeams
     */
    public function getUserTeams(): UserTeams
    {
        return $this->userTeams;
    }

    private function forgetToken() {
        $this->forget("teams_token");
        $this->token = null;
    }

    private function logout()
    {
        $this->forgetToken();
        $this->redirect($this->container->get("teams/app_redirect_uri_on_success"));
    }

    private function refreshToken()
    {
        $this->setProvider();

        try {
            $this->token = $this->provider->getAccessToken('refresh_token', [
                'scope'         => $this->provider->scope,
                'refresh_token' => $this->token->getRefreshToken()
            ]);

            $this->serializeToken($this->token);
        } catch(IdentityProviderException $e) {
            $this->forgetToken();
        }
    }
}