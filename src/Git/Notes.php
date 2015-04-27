<?php

namespace Git;

trait Notes
{
    private $note;

    public function getNote()
    {
        if (!$this->note) {
            try {
                $this->note = $this->repo->note($this->sha);
            } catch (Exception $e) {
                $this->note = '';
            }
        }
        return $this->note;
    }

    public function setNote($note)
    {
        $this->note = $this->repo->note($this->sha, $note);
    }
}