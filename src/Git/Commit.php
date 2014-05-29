<?php

namespace Git;

class Commit
{
    private $repo;

    public $sha;
    private $tree;
    private $metadata;
    private $files;

    public function __construct($repo, $sha)
    {
        $this->repo = $repo;
        $this->sha = $repo->dereference($sha);
    }

    public function __toString()
    {
        return $this->sha;
    }

    public function setTree($tree)
    {
        $this->tree = $tree;
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'tree':
                $this->setTree($value);
                break;
        }
    }

    public function getTree()
    {
        if (!is_a($this->tree, 'Tree')) {
            $this->tree = new Tree($this->repo, $this->tree);
        }
        return $this->tree;
    }

    public function getFiles()
    {
        if (!$this->files) {
            $this->files = $this->repo->files($this->sha);
        }
        return $this->files;
    }

    public function getMetadata($key)
    {
        if (!$this->metadata) {
            $this->metadata = $this->repo->commitMetadata($this->sha);
        }
        return $this->metadata->$key;
    }

    public function __get($key)
    {
        switch ($key) {

            case 'tree':
                return $this->getTree();

            case 'files':
                return $this->getFiles();

            default:
                return $this->getMetadata($key);

        }
    }

}