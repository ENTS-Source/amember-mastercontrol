<?php

/**
 * Class Am_Plugin_EntsMastercontrol
 */
class Am_Plugin_EntsMastercontrol extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = "1.0.0";

    private $depsLoaded = false;

    protected function loadDependencies()
    {
        if ($this->depsLoaded) return;

        include_once __DIR__ . "/vendor/autoload.php";
        $this->depsLoaded = true;
    }

    function isConfigured()
    {
        $this->loadDependencies();
        $hasMqHost = strlen(trim($this->getConfig('mq.host'))) > 0;
        $hasMqUsername = strlen(trim($this->getConfig('mq.username'))) > 0;
        return $hasMqHost && $hasMqUsername;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle("ENTS: MasterControl");

        $fs = $form->addFieldSet()->setLabel(___("Message Queue Settings"));
        $fs->addText("mq.host")->setLabel(___("Host"));
        $fs->addInteger("mq.port")->setLabel(___("Port"))->addRule('gte', 1);
        $fs->addText("mq.username")->setLabel(___("Username"));
        $fs->addPassword("mq.password")->setLabel(___("Password"));
        $fs->addText("mq.vhost")->setLabel(___("Vhost\nempty - default /"));
        $fs->addText("mq.exchange")->setLabel(___("Exchange\nempty - default 'ents-members'"));

        $fs = $form->addFieldSet()->setLabel(___("MasterControl Settings"));
        $fs->addInteger("buffer_days")->setLabel(___("Expired Subscription Buffer (days)"))->addRule('gte', 0.0);

        $form->addFieldsPrefix("misc.ents-mastercontrol.");
    }

    function onUserAfterInsert(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onUserAfterUpdate(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onAccessAfterInsert(Am_Event $event) {
        $this->sendMemberEvents(array($event->getAccess()->getUser()));
    }

    function onAccessAfterUpdate(Am_Event $event) {
        $this->sendMemberEvents(array($event->getAccess()->getUser()));
    }

    function onAccessAfterDelete(Am_Event $event) {
        $this->sendMemberEvents(array($event->getAccess()->getUser()));
    }

    function onSubscriptionAdded(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onSubscriptionChanged(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onSubscriptionUpdated(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onSubscriptionDeleted(Am_Event $event) {
        $this->sendMemberEvents(array($event->getUser()));
    }

    function onDaily(Am_Event $event) {
        $usersTable = $this->getDi()->userTable;
        $users = $usersTable->selectObjects("SELECT * FROM ?_user");
        $this->sendMemberEvents($users);
    }

    private function sendMemberEvents(array $members) {
        if(!$this->isConfigured()) return;
        $host = $this->getConfig("mq.host");
        $port = (int)$this->getConfig("mq.port");
        $username = $this->getConfig("mq.username");
        $password = $this->getConfig("mq.password", null);
        $vhost = $this->getConfig("mq.vhost", "/");
        $exchange = $this->getConfig("mq.exchange", "ents-members");

        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection($host,$port,$username,$password,$vhost);
        $channel = $connection->channel();

        foreach ($members as $member) {
            $message = $this->buildUserMessage($member);
            $channel->basic_publish($message, $exchange);
        }

        $channel->close();
        $connection->close();
    }

    private function buildUserMessage(User $member){
        // Build the base message first
        $obj = array(
            "type" => "MEMBER_UPDATED",
            "id" => $member->pk(),
            "first_name" => $member->name_f,
            "last_name" => $member->name_l,
            "email" => $member->email,
            "nickname" => $member->name_f,
            "fob_number" => "UNKNOWN",
            "door_access" => array(
                "announce" => false,
                "access_type" => "subscription",
                "access" => array()
            ),
            "is_director" => false
        );

        // Copy the custom fields into the object, if possible
        $userCustomFields = $this->getDi()->userTable->customFields();

        if($userCustomFields->get("fob"))
            $obj["fob_number"] = $member->fob;
        if($userCustomFields->get("nickname"))
            $obj["nickname"] = $member->nickname;
        if($userCustomFields->get("announce"))
            $obj["door_access"]["announce"] = in_array("announce", $member->announce) ? true : false;
        if($userCustomFields->get("fob_access"))
            $obj["door_access"]["access_type"] = $member->fob_access ? $member->fob_access : "subscription";
        if($userCustomFields->get("roles"))
            $obj["is_director"] = in_array("Director", $member->roles) ? true : false;

        // Populate the access records
        $accessTable = $this->getDi()->accessTable;
        $bufferDays = $this->getConfig("buffer_days");
        $accessRecords = $accessTable->findBy(array("user_id" => $member->user_id));
        foreach ($accessRecords as $record) {
            if($record->expire_date < date_add(time(), date_interval_create_from_date_string($bufferDays . " days")))
                continue; // out of date record - no longer applies
            $obj["door_access"]["access"][] = array(
                "start" => $record->begin_date,
                "end" => $record->expire_date,
                "buffer_days" => $bufferDays
            );
        }

        // Convert to message
        $json = json_encode($obj);
        return new \PhpAmqpLib\Message\AMQPMessage($json);
    }
}