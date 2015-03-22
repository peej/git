<?php

namespace Git;

class Repo implements Gittable
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

    private function exec($command)
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

    public function dereference($reference)
    {
        if (preg_match('/^[a-f0-9]{40}$/', $reference)) {
            $sha = $reference;
        } else {
            $sha = $this->exec('git show-ref --heads -s '.escapeshellarg($reference));
            if (!$sha) {
                throw new Exception('Could not dereference '.$reference);
            }
        }
        return $sha;
    }

    public function setUser($name, $email)
    {
        $this->exec('git config --local user.name '.escapeshellarg($name));
        $this->exec('git config --local user.email '.escapeshellarg($email));
    }

    private function getRefNames($refString, $prefix)
    {
        $refs = array();
        $trimLength = 41 + strlen($prefix);
        foreach (explode("\n", $refString) as $ref) {
            $refs[] = substr($ref, $trimLength);
        }
        return $refs;
    }

    public function getBranches()
    {
        return $this->getRefNames($this->exec('git show-ref --heads'), 'refs/heads/');
    }

    public function getTags()
    {
        return $this->getRefNames($this->exec('git show-ref --tags'), 'refs/tags/');
    }

    public function setBranch($name = 'master')
    {
        $this->branch = $name;
    }

    public function createBranch($name)
    {
        $this->exec('git branch '.escapeshellarg($name));
    }

    public function renameBranch($oldName, $newName)
    {
        $this->exec('git branch -m '.escapeshellarg($oldName).' '.escapeshellarg($newName));
    }

    public function deleteBranch($name, $mustBeMerged = true)
    {
        if ($mustBeMerged) {
            $this->exec('git branch -d '.escapeshellarg($name));
        } else {
            $this->exec('git branch -D '.escapeshellarg($name));
        }
    }

    public function catFile($sha)
    {
        return $this->exec('git cat-file -p '.$sha);
    }

    public function loadTree($sha, $path = '')
    {
        $entries = array();
        $treeString = $this->catFile($sha);
        preg_match_all('/^[0-9]{6} (blob|tree) ([0-9a-f]{40})\t(.+)$/m', $treeString, $matches, PREG_SET_ORDER);
        $path = $path ? $path.'/' : '';
        foreach ($matches as $entry) {
            switch ($entry[1]) {
                case 'blob':
                    $entries[$entry[3]] = new Blob($this, $entry[2], $path.$entry[3]);
                    break;
                case 'tree':
                    $entries[$entry[3]] = new Tree($this, $entry[2], $path.$entry[3]);
                    break;
            }
        }
        return $entries;
    }

    public function log($filename)
    {
        $log = $this->exec('git log --format=format:"%H" refs/heads/'.escapeshellarg($this->branch).' -- '.escapeshellarg($filename));
        return explode("\n", $log);
    }

    public function files($sha)
    {
        $show = $this->exec('git show --pretty="format:" --name-only '.$sha);
        return explode("\n", trim($show));
    }

    public function commitMetadata($sha)
    {
        $commitString = $this->exec('git show -U5 --format=format:'.escapeshellarg(Metadata::LOG_FORMAT).' '.$sha);
        if (!$commitString) {
            throw new Exception('Log for commit "'.$sha.'"" not found');
        }
        $parts = explode("\n", $commitString);
        $metadata = explode(',', array_shift($parts));

        if ($parts) {
            $diffString = join("\n", $parts);
        } else {
            $diffString = $this->exec('git diff -p '.$sha.'^1 '.$sha);
        }

        $diff = array();
        foreach(explode('diff --git', $diffString) as $d) {
            if ($d) {
                preg_match('#^[^\n]+\n(?:[^\n]+\n)?[^\n]+\n--- (?:/dev/null|a/([^\n]+))\n\+\+\+ (?:/dev/null|b/([^\n]+))\n(@@.+)$#s', $d, $matches);
                if (count($matches) == 4) {
                    $diff[$matches[1] ?: $matches[2]] = $matches[3];
                }
            }
        }

        return new Metadata(
            $metadata[0], // commit
            $metadata[1] ? explode(' ', $metadata[1]) : array(), // parents
            $metadata[2], // user
            $metadata[3], // email
            $metadata[4], // date
            $metadata[5], // message
            $diff //diff
        );
    }


    # read

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
            if (!is_a($tree, 'Git\Tree')) {
                $tree = null;
            }
        }
        return $tree;
    }

    public function commits($sha = null, $number = 20)
    {
        $commits = array();
        if (!$sha) {
            $sha = 'refs/heads/'.$this->branch;
        }
        
        for ($foo = $number; $foo > 0; $foo--) {
            $commit = new Commit($this, $sha);
            $commits[] = $commit;

            if (!isset($commit->parents[0])) {
                break;
            }

            $sha = $commit->parents[0];
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

    public function resetIndex()
    {
        $this->exec('git read-tree refs/heads/'.escapeshellarg($this->branch));
    }

    # write

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
        try {
            $parentSha = $this->dereference($this->branch);
            $sha = $this->exec('echo '.escapeshellarg($commitMessage).' | git commit-tree -p '.$parentSha.' '.$sha);
        } catch (Exception $e) {
            $sha = $this->exec('echo '.escapeshellarg($commitMessage).' | git commit-tree '.$sha);
        }
        $this->exec('git update-ref '.escapeshellarg('refs/heads/'.$this->branch).' '.escapeshellarg($sha));
        $this->resetIndex();
        return $sha;
    }

    # merging

    public function canMerge($branch)
    {
        $sha = $this->exec('git merge-base '.escapeshellarg($this->branch).' '.escapeshellarg($branch));
        $merge = $this->exec('git merge-tree '.escapeshellarg($sha).' '.escapeshellarg($this->branch).' '.escapeshellarg($branch));
        if ($merge == '') {
            throw new Exception('Base branch already contains everything in head branch, nothing to merge');
        } elseif (preg_match_all('/in both\n *(?:base|our|their) +100644 [a-f0-9]+ ([^\n]+)/', $merge, $filenames)) {
            $e = new Exception('Can not merge branches without conflict, resolve conflicts first');
            if (isset($filenames[1]) && $filenames[1]) {
                $e->filenames = $filenames[1];
            }
            throw $e;
        }
        return true;
    }

    public function mergeConflicts($branch)
    {
        try {
            $this->canMerge($branch);
        } catch (Exception $e) {
            if (isset($e->filenames)) {
                $diffs = array();
                foreach ($e->filenames as $filename) {
                    $d = $this->exec('git diff '.escapeshellarg($this->branch).' '.escapeshellarg($branch).' -- '.$filename);
                    preg_match('#^[^\n]+\n(?:[^\n]+\n)?[^\n]+\n--- (?:/dev/null|a/([^\n]+))\n\+\+\+ (?:/dev/null|b/([^\n]+))\n(@@.+)$#s', $d, $matches);
                    if (count($matches) == 4) {
                        $diffs[$matches[1] ?: $matches[2]] = $matches[3];
                    }
                }
                $diff = new Diff($diffs);
                return $diff->diff;
            }
        }
        return null;
    }

    /**
     * Execute a merge
     */
    public function merge($branch, $commitMessage = null)
    {
        if ($this->index()) {
            throw new Exception('Can not merge with dirty index');
        }
        $parent1Sha = $this->dereference($this->branch);
        $parent2Sha = $this->dereference($branch);
        
        $this->canMerge($branch);

        if (!$commitMessage) {
            $commitMessage = 'Merge '.$branch.' into '.$this->branch;
        }

        $baseSha = $this->exec('git merge-base '.escapeshellarg($this->branch).' '.escapeshellarg($branch));
        $this->exec('git read-tree -m -i '.escapeshellarg($baseSha).' '.escapeshellarg($this->branch).' '.escapeshellarg($branch));
        $sha = $this->exec('git write-tree');
        
        $sha = $this->exec('echo '.escapeshellarg($commitMessage).' | git commit-tree -p '.$parent1Sha.' -p '.$parent2Sha.' '.$sha);

        $this->exec('git update-ref '.escapeshellarg('refs/heads/'.$this->branch).' '.escapeshellarg($sha));
        return $sha;
    }

    /**
     * Update the index to reverse a previous commit
     */
    public function revert($sha, $commitMessage = null)
    {
        if ($this->index()) {
            throw new Exception('Can not revert with dirty index');
        }

        try {
            $this->exec('git diff -R '.escapeshellarg($sha.'~1').' '.escapeshellarg($sha).' | git apply --index');
        } catch (Exception $e) {
            return false;
        }

        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    /**
     * Update the index to undo back to a previous commit
     */
    public function undo($sha, $commitMessage = null)
    {
        if ($this->index()) {
            throw new Exception('Can not revert with dirty index');
        }

        $this->exec('git diff -R --cached '.escapeshellarg($sha).' | git apply --index');

        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }
}
