<?php

namespace Git;

class Metadata
{
    public $commit;
    public $parents = array();
    public $user;
    public $email;
    public $date;
    public $message;
    public $diff;

    const LOG_FORMAT = '%H,%P,%aN,%aE,%at,%s';

    public function __construct($commit, $parents, $user, $email, $date, $message, $diff)
    {
        $this->commit = $commit;
        $this->parents = $parents;
        $this->user = $user;
        $this->email = $email;
        $this->date = (int) $date;
        $this->message = $message;
        $this->diff = new Diff($diff);
    }
}