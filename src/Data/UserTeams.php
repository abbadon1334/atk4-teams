<?php


namespace Atk4\Teams\Data;


use Atk4\Data\Field\Email;

class UserTeams extends \Atk4\Data\Model
{
    protected function init(): void
    {
        parent::init();

        $this->addField("guid");
        $this->addField("businessPhones", ['type'=>'array', 'serialize'=>'json']);
        $this->addField("displayName");
        $this->addField("givenName");
        $this->addField("jobTitle");
        $this->addField("mail", [Email::class]);
        $this->addField("mobilePhone");
        $this->addField("officeLocation");
        $this->addField("preferredLanguage");
        $this->addField("surname");
        $this->addField("userPrincipalName");
    }
}