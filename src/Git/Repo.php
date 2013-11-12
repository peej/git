<?php

namespace Git;

class Repo
{
    private $path;
    private $bare = true;
    private $branch = 'master';

    public function __construct($repoPath)
    {
        $this->path = $repoPath;

        if (!file_exists($this->path)) {
            if (substr($this->path, -4) != '.git') {
                $this->bare = false;
            }
            mkdir($this->path);
            if ($this->bare) {
                $this->exec('git init --bare');
            } else {
                $this->exec('git init');
            }
        } elseif (!is_dir($this->path)) {
            throw new Exception('Repo path not a directory');
        } else {
            if (is_dir($this->path.'/.git')) {
                $this->bare = false;
            }
        }
    }

    private function refFilename($reference = null)
    {
        if (!$reference) {
            $reference = $this->branch;
        }
        if ($this->bare) {
            return 'refs/heads/'.$reference;
        } else {
            return '.git/refs/heads/'.$reference;
        }
    }

    public function exec($command)
    {
        $cwd = getcwd();
        chdir($this->path);
        $out = exec($command.' 2>&1', $output, $return);
        chdir($cwd);
        if ($return != 0) {
            throw new Exception('Git binary returned an error "'.$out.'"');
        }
        return join("\n", $output);
    }

    public function setUser($name, $email)
    {
        $this->exec('git config --local user.name '.escapeshellarg($name));
        $this->exec('git config --local user.email '.escapeshellarg($email));
        return true;
    }

    public function tree($path = '.')
    {
        if ($path == '.' || $path == '') {
            $commit = $this->exec('git cat-file -p refs/heads/'.$this->branch);
            preg_match('/^tree ([0-9a-f]{40})$/m', $commit, $match);
            if (!isset($match[1])) {
                throw new Exception('Could not find HEAD commit for '.$this->branch);
            }
            $tree = new Tree($this, $match[1]);
        } else {
            $path = explode('/', $path);
            $directory = array_pop($path);
            $parent = join('/', $path);
            
            $tree = $this->tree($parent)[$directory];
        }
        return $tree;
    }

    public function commits($sha = null, $number = 20)
    {
        $commits = array();
        if (!$sha) {
            $sha = 'refs/heads/'.$this->branch;
        }
        
        $commit = new Commit($this, $sha);
        $commits[] = $commit;

        foreach ($commit->parents as $parent) {
            if (count($commits) >= $number) break;
            $commits = array_merge($commits, $this->commits($parent, $number));
        }
        
        return $commits;
    }

    public function commit($sha = null)
    {
        if (!$sha) {
            $sha = 'refs/heads/'.$this->branch;
        }
        $commit = new Commit($this, $sha);
        return $commit;
    }

    public function file($filename)
    {
        $tree = $this->tree(dirname($filename));
        if (isset($tree[basename($filename)])) {
            return $tree[basename($filename)];
        }
        throw new Exception('File "'.$filename.'" not found');
    }

    public function index()
    {
        $index = $this->exec('git diff-index --cached refs/heads/'.$this->branch);
        preg_match_all('/^:[0-9]{6} [0-9]{6} [0-9a-f]{40} [0-9a-f]{40} ([ACDMRTUX])[0-9]{0,3}\t(.+)$/m', $index, $matches, PREG_SET_ORDER);
        $items = array();
        foreach ($matches as $match) {
            $items[$match[2]] = $match[1];
        }
        return $items;
    }

    /**
     * Add a new file
     * @param str $filename Name of the file to add
     * @param str $content Content to add
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function add($filename, $content, $commitMessage = null)
    {
        $sha = $this->exec('echo '.escapeshellarg($content).' | git hash-object -w --stdin');
        $this->exec('git update-index --add --cacheinfo 100644 '.$sha.' '.escapeshellarg($filename));
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function update($filename, $content, $commitMessage = null)
    {
        $sha = $this->exec('echo '.escapeshellarg($content).' | git hash-object -w --stdin');
        $this->exec('git update-index --cacheinfo 100644 '.$sha.' '.escapeshellarg($filename));
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function move($from, $to, $commitMessage = null)
    {
        $this->remove($from);
        return $this->copy($from, $to, $commitMessage);
    }

    public function copy($from, $to, $commitMessage = null)
    {
        $this->add($to, $this->file($from));
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function remove($filename, $commitMessage = null)
    {
        $this->exec('git rm --cached '.escapeshellarg($filename));
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    /**
     * Commit the index
     */
    public function save($commitMessage)
    {
        $sha = $this->exec('git write-tree');
        $headRefFilename = $this->path.'/'.$this->refFilename();
        if (file_exists($headRefFilename)) {
            $parentSha = trim(file_get_contents($headRefFilename));
            $sha = $this->exec('echo '.escapeshellarg($commitMessage).' | git commit-tree -p '.$parentSha.' '.$sha);
        } else {
            $sha = $this->exec('echo '.escapeshellarg($commitMessage).' | git commit-tree '.$sha);
        }
        $this->exec('echo "'.$sha.'" > '.escapeshellarg($this->refFilename()));
        return $sha;
    }

}
