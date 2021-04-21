<?php

declare(strict_types=1);

use Atk4\Data\Model;
use Atk4\Teams\Data\UserTeams;

class User extends Model
{
    public $table = 'user';

    protected function init(): void
    {
        parent::init();
        $this->addField('guid');
        $this->addField('userPrincipalName');
        $this->addField('displayName');
    }

    public function updateFromUserTeams(UserTeams $user)
    {
        $this->tryLoadBy('userPrincipalName', $user->get('userPrincipalName'));

        foreach ($this->getFields() as $field) {
            $name = $field->getPersistenceName();

            if ($name === $this->id_field) {
                continue;
            }

            if ($this->hasField($field->getPersistenceName())) {
                $this->set($name, $user->get($name));
            }
        }

        $this->save();
    }
}
