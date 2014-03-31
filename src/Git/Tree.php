<?php

namespace Git;

class Tree implements \Iterator, \ArrayAccess
{
    private $repo;
    private $position = 0;
    private $entries = array();
    private $entriesArray = array();

    public $sha;
    public $filename;

    public function __construct($repo, $sha, $filename = '')
    {
        $this->repo = $repo;
        $this->sha = $sha;
        $this->filename = $filename;
    }

    private function loadEntries()
    {
        if (!$this->entries) {
            $this->entries = $this->repo->loadTree($this->sha, $this->filename);
            $this->entriesArray = array_values($this->entries);
        }
    }

    public function entries()
    {
        $this->loadEntries();
        return $this->entries;
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function current()
    {
        $this->loadEntries();
        return $this->entriesArray[$this->position];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        ++$this->position;
    }

    public function valid()
    {
        $this->loadEntries();
        return isset($this->entriesArray[$this->position]);
    }

    public function offsetSet($offset, $value)
    {
        $this->loadEntries();
        if (is_null($offset)) {
            $this->entries[] = $value;
        } else {
            $this->entries[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        $this->loadEntries();
        return isset($this->entries[$offset]);
    }

    public function offsetUnset($offset)
    {
        $this->loadEntries();
        unset($this->entries[$offset]);
    }

    public function offsetGet($offset)
    {
        $this->loadEntries();
        return isset($this->entries[$offset]) ? $this->entries[$offset] : null;
    }
}