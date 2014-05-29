<?php

namespace Git;

class Blob
{
    private $repo;

    private $metadata;
    private $content;
    private $history = array();

    public $sha;
    public $filename;

    public function __construct($repo, $sha, $filename)
    {
        $this->repo = $repo;
        $this->sha = $sha;
        $this->filename = $filename;
    }

    public function getContent()
    {
        if (!$this->content) {
            $this->content = $this->repo->catFile($this->sha);
        }
        return $this->content;
    }
    
    public function getHistory()
    {
        if (!$this->history) {
            $shas = $this->repo->log($this->filename);
            foreach ($shas as $sha) {
                $this->history[] = new Commit($this->repo, $sha);
            }
        }
        return $this->history;
    }

    public function __toString()
    {
        return $this->__get('content');
    }

    public function __get($key)
    {
        if ($key == 'content') {
            return $this->getContent();
        } elseif ($key == 'history') {
            return $this->getHistory();
        } else {
            return $this->getHistory()[0]->$key;
        }
    }

}