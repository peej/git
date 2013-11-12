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
        $this->sha = $sha;
    }

    public function __toString()
    {
        return $this->sha;
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'tree':
                $this->tree = $value;
                break;
        }
    }

    public function __get($key)
    {
        switch ($key) {

            case 'tree':
                if (!is_a($this->tree, 'Tree')) {
                    $this->tree = new Tree($this->repo, $this->tree);
                }
                return $this->tree;

            case 'files':
                if (!$this->files) {
                    $show = $this->repo->exec('git show --pretty="format:" --name-only '.$this->sha);
                    $this->files = explode("\n", trim($show));
                }
                return $this->files;

            default:
                $this->loadMetadata();
                return $this->metadata->$key;

        }
    }

    private function loadMetadata()
    {
        if (!$this->metadata) {
            $commitString = $this->repo->exec('git show -U5 --format=format:'.escapeshellarg(Metadata::LOG_FORMAT).' '.$this->sha);
            if (!$commitString) {
                throw new Exception('Log for commit "'.$this->sha.'"" not found');
            }
            $this->metadata = new Metadata($commitString);
        }
    }

}