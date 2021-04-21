<?php

declare(strict_types=1);

namespace Atk4\Teams;

use Atk4\Container\AppContainer;
use Atk4\Core\Exception;
use Atk4\Core\SessionTrait;
use Atk4\Data\Persistence\Array_;
use Atk4\Teams\Data\UserTeams;
use Atk4\Ui\AbstractView;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use TheNetworg\OAuth2\Client\Token\AccessToken;
use TheNetworg\OAuth2\Client\Provider\Azure;
use Throwable;

class Teams extends AbstractView
{
    use SessionTrait;

    private Azure        $provider;
    private ?AccessToken $accessToken;
    private AppContainer $container;
    private UserTeams    $userTeams;

    public function __construct(AppContainer $container)
    {
        $this->container = $container;
        $this->provider = new Azure([
            'clientId'               => $this->container->get('teams/app_id'),
            'clientSecret'           => $this->container->get('teams/app_secret'),
            'redirectUri'            => $this->container->get('teams/app_redirect_uri'),
            'scopes'                 => $this->container->get('teams/app_scopes'),
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
        ]);

        // Fix : chrome80 + cross domain session check
        session_set_cookie_params($this->container->get('teams/session_cookie_params'));
    }

    /**
     * @throws Exception
     * @throws IdentityProviderException
     */
    protected function init(): void
    {
        parent::init();

        $this->initUserTeams();
        $this->initAccessToken();

        $this->getApp()->setResponseHeader('Access-Control-Allow-Credentials', 'true');
        $this->getApp()->setResponseHeader('Access-Control-Allow-Origin', "*");
        $this->getApp()->setResponseHeader('Access-Control-Allow-Headers', "*");

        // We have a token
        if ($this->accessToken !== null) {

            if (!$this->accessToken->hasExpired()) {
                $this->refreshWhoAMI();

                return;
            }

            if ($this->accessToken->getRefreshToken() !== null) {
                $this->refreshToken();
            } else {
                $this->forgetToken();
            }

            return;
        }

        $this->checkAuth(); // client will be redirect
    }

    private function initAccessToken()
    {
        $serialized = $this->recall('teams_token');

        $this->accessToken = $serialized === null
            ? null
            : unserialize($serialized);
    }

    private function initUserTeams()
    {
        $this->userTeams = new UserTeams(new Array_());
        $this->userTeams->data = unserialize($this->recall('teams_user', "a:0:{}"));
        $this->userTeams->setId($this->userTeams->data['id'] ?? null);
    }

    /**
     * @throws Exception|IdentityProviderException
     */
    public function checkAuth()
    {

        $teams_code = $_GET['code'] ?? null;
        if (null === $teams_code) {
            $this->requestAuth();
        } else {
            $this->callback();
        }
    }

    /**
     * @throws \Atk4\Ui\Exception
     * @throws IdentityProviderException
     */
    public function callback()
    {
        $teams_code = $_GET['code'] ?? null;
        $teams_state = $_GET['state'] ?? null;

        $saved_state = $this->recall('session_state');

        if($saved_state === null) {
            throw new \Atk4\Ui\Exception("Something wrong, initial state not found");
        }

        if($saved_state !== $teams_state) {
            throw new \Atk4\Ui\Exception("Something wrong, initial state not matching with returned state");
        }

        $this->forget('session_state');

        /** @var AccessToken $accessToken */
        $accessToken = $this->provider->getAccessToken('authorization_code', [
            'scope' => $this->provider->scope,
            'code'  => $teams_code,
        ]);

        $this->serializeToken($accessToken);

        $this->redirect($this->container->get('teams/app_redirect_uri_on_success'));
    }

    private function serializeToken(AccessToken $token): void
    {
        $this->memorize('teams_token', serialize($token));
    }

    private function redirect(string $uri)
    {
        $this->getApp()->redirect($uri);
    }

    private function requestAuth()
    {
        $authorizationUrl = $this->provider->getAuthorizationUrl(['scope' => $this->provider->scope]);

        $this->memorize('session_state', $this->provider->getState());

        $this->redirect($authorizationUrl);
    }

    /**
     * @throws \Atk4\Data\Exception
     */
    public function refreshWhoAMI(bool $force = false): void
    {
        if ($this->userTeams->loaded() && !$force) {
            return;
        }

        /** @var AccessToken $token */
        $token = $this->accessToken;
        $data = $this->provider->get(
            $this->provider->getRootMicrosoftGraphUri($token) . '/v1.0/me',
            $this->accessToken
        );

        $this->setTeamUser($data);
    }

    /**
     * @throws \Atk4\Data\Exception
     */
    private function setTeamUser(array $data)
    {
        unset($data['@odata.context']); // remove useless data

        $data['guid'] = $data['id']; // switch id with Guid
        $data['id'] = 1;             // hardcode id for session load / delete

        $this->memorize('teams_user', serialize($data));
        $this->userTeams->save($data);
    }

    public function getUserTeams(): UserTeams
    {
        return $this->userTeams;
    }

    private function forgetToken()
    {
        $this->forget('teams_user');
        $this->forget('teams_token');
        $this->forget('session_state');
        $this->accessToken = null;
    }

    public function logout()
    {
        $this->forgetToken();
        $this->redirect($this->container->get('teams/app_redirect_uri_on_logout'));
    }

    private function refreshToken()
    {
        try {
            /** @var AccessToken $accessToken */
            $accessToken = $this->provider->getAccessToken('refresh_token', [
                'scope'         => $this->provider->scope,
                'refresh_token' => $this->accessToken->getRefreshToken(),
            ]);
            $this->accessToken = $accessToken;
            $this->serializeToken($this->accessToken);
        } catch (IdentityProviderException $e) {
            $this->forgetToken();
        }
    }
}
