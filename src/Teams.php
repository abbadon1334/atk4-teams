<?php

namespace Atk4\Teams;

use Atk4\Container\AppContainer;
use Atk4\Core\AppScopeTrait;
use Atk4\Core\NameTrait;
use Atk4\Core\SessionTrait;
use Atk4\Data\Model;
use Atk4\Data\Persistence\Static_;
use Atk4\Teams\Data\UserTeams;
use League\OAuth2\Client\Token\AccessToken;
use TheNetworg\OAuth2\Client\Provider\Azure;

class Teams
{
    use AppScopeTrait;
    use NameTrait;
    use SessionTrait;

    private Azure        $provider;
    private ?AccessToken $token;
    private AppContainer $container;

    public function __construct(AppContainer $container)
    {
        $this->container = $container;
        $this->token = $this->getToken();
        $this->TryLoadUserTeamsModel();
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

    private function getToken(): ?AccessToken
    {
        $serialized = $this->recall("teams_token");

        return null == $serialized
            ? null
            : unserialize($serialized);
    }

    public function authenticate()
    {
        if(isset($_COOKIE["teams_state"])) {
            $this->callback();
        }

        if (null === $this->token) {
            $this->requestAuth();
        }
    }

    private function requestAuth()
    {
        $this->setProvider();

        $authorizationUrl = $this->provider->getAuthorizationUrl(['scope' => $this->provider->scope]);

        setcookie('teams_state', $this->provider->getState(), ['samesite' => 'None', 'secure' => true]);

        $this->redirect($authorizationUrl);
    }

    /**
     * @throws \League\OAuth2\Client\Provider\Exception\IdentityProviderException
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
        } catch(\Throwable $e) {
            if (null === $this->getApp()) {
                echo $e->getMessage();
            }
            throw new \Atk4\Ui\Exception($e->getMessage());
        } finally {
            unset($_COOKIE["teams_state"]);
        }
    }

    private function redirect(string $uri)
    {
        if (null === $this->getApp()) {
            header('Location: ' . $uri);
            return;
        }

        $this->getApp()->terminate("", ['Location' => $uri]);
    }

    public function getWhoAMI() : UserTeams {
        if($this->userTeams->loaded()) {
            return $this->userTeams;
        }

        $data = $this->provider->get($this->provider->getRootMicrosoftGraphUri($this->token) . '/v1.0/me', $this->token);
        $this->setTeamUser($data);
        return $this->userTeams;
    }

    private UserTeams $userTeams;

    private function TryLoadUserTeamsModel() : UserTeams {
        $persistence = new Static_($this->recall("teams_user", []));
        $this->userTeams = new UserTeams($persistence);
        return $this->userTeams->tryLoad(1);
    }

    private function setTeamUser(array $data) {

        unset($data["@odata.context"]); // remove useless data

        $data["Guid"] = $data["id"]; // switch id with Guid
        $data["id"] = 1; // hardcode id for session load / delete

        $this->userTeams->save($data);
    }

    private function logout() {
        session_destroy();
        $this->redirect($this->container->get("teams/app_redirect_uri_on_success"));
    }
}