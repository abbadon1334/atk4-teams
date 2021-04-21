<?php

declare(strict_types=1);

use Atk4\Container\AppContainer;
use Atk4\Data\Model;
use Atk4\Data\Persistence;
use Atk4\Data\Persistence\Sql;
use Atk4\Schema\Migration;
use Atk4\Teams\Teams;
use Atk4\Ui\Header;
use Atk4\Ui\Layout;
use Atk4\Ui\View;

class Application extends \Atk4\Ui\App
{
    public AppContainer        $container;
    public Teams               $teams;
    private User               $user;

    /**
     * @var Sql
     */
    private $persistence;

    public function __construct($defaults = [])
    {
        parent::__construct($defaults);

        $this->initLayout([Layout::class]);

        // Authenticate
        // @TODO ctor arg must be removed and called in init with $this->getApp()->getContainer()
        $this->teams = Teams::addTo($this, [$this->container]);

        // Teams authentication OK!
        $this->user = new User($this->getPersistence());
        $this->user->updateFromUserTeams($this->teams->getUserTeams());

        // javascript needed
        $this->requireJs('https://statics.teams.cdn.office.net/sdk/v1.6.0/js/MicrosoftTeams.min.js');
        $this->html->template->dangerouslyAppendHtml('HEAD', '
            <script>microsoftTeams.initialize();</script>
        ');
    }

    protected function init(): void
    {
        parent::init();

        Header::addTo($this)->set('Connected to Teams');
        View::addTo($this)->set(print_r($this->getApplicationUser()->get(), true));
    }

    public function getPersistence(): Sql
    {
        if ($this->persistence !== null) {
            return $this->persistence;
        }

        $dsn = sprintf(
            'mysql:dbname=%s;host=%s:%s',
            $this->container->get('db/name'),
            $this->container->get('db/host'),
            $this->container->get('db/port')
        );

        $this->persistence = new Sql(
            $dsn,
            $this->container->get('db/user'),
            $this->container->get('db/pass')
        );

        $this->container->set('db/persistence', $this->persistence);

        $this->persistence->onHook(Persistence::HOOK_AFTER_ADD, function ($owner, $element) {
            if ($element instanceof Model) {
                if (!$this->container->get('db/schema/rebuild')) {
                    return;
                }

                $getMigrator = function (Model $model) {
                    return new Migration($model);
                };

                $getMigrator(new User($this->getPersistence()))->dropIfExists()->create();

                /*
                foreach(glob(__DIR__.'/Models/*.php') as $f) {
                    $pathinfo = pathinfo($f);
                    $fqcnModel = "\\App\\Models\\" .$pathinfo["filename"];

                    $reflectionClass = new \ReflectionClass($fqcnModel);
                    if ($reflectionClass->isAbstract()) {
                        continue;
                    }

                    $getMigrator(new $fqcnModel($this->getPersistence()))->dropIfExists()->create();
                }
                */
            }
        });

        return $this->persistence;
    }

    public function getApplicationUser(): User
    {
        return $this->user;
    }
}
