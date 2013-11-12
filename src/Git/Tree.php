<?php

namespace Git;

class Tree implements \Iterator, \ArrayAccess
{
    private $repo;
    private $position = 0;
    private $entries = array();

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
            $treeString = $this->repo->exec('git cat-file -p '.$this->sha);

            preg_match_all('/^[0-9]{6} (blob|tree) ([0-9a-f]{40})\t(.+)$/m', $treeString, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                switch ($match[1]) {
                    case 'blob':
                        $this->entries[$match[3]] = new Blob($this->repo, $match[2], $match[3]);
                        break;
                    case 'tree':
                        $this->entries[$match[3]] = new Tree($this->repo, $match[2], $match[3]);
                        break;
                }
            }
        }
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function current()
    {
        $this->loadEntries();
        return $this->entries[$this->position];
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
        return isset($this->entries[$this->position]);
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