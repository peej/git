<?php

namespace Git;

class GitHub implements Gittable
{
    private $baseUrl = 'https://api.github.com';
    private $owner;
    private $repo;
    private $httpClient;
    private $branch = 'master';

    private $index = array();

    public function __construct($owner, $repo, $httpClient = null)
    {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->httpClient = $httpClient ? $httpClient : new HttpClient();
    }

    public function exec($url, $method = 'GET', $data = null)
    {
        $url = $this->baseUrl.'/repos/'.$this->owner.'/'.$this->repo.$url;
        #var_dump($url, $method, $data);

        $response = $this->httpClient->send($url, $method, $data);
        #var_dump($response);
        return $response;
    }

    public function dereference($reference)
    {
        if (substr($reference, 0, 5) == 'refs/') {
            $reference = $this->exec('/git/'.$reference, 'GET');
        }
        return $reference;
    }

    public function setBranch($name = 'master')
    {
        $this->branch = $name;
    }
    
    public function createBranch($name) {
        try {
            $this->exec('/git/refs/heads/'.$name, 'GET');
        } catch (Exception $e) {
            if ($e->getCode() == 404) {
                $ref = $this->exec('/git/refs/heads/'.$this->branch);
                $this->exec('/git/refs', 'POST', array(
                    'ref' => 'refs/heads/'.$name,
                    'sha' => $ref->object->sha
                ));
            }
        }
    }

    public function deleteBranch($name, $mustBeMerged = null) {
        $this->exec('/git/refs/heads/'.$name, 'DELETE');
    }

    # plumbing

    public function catFile($sha) {
        $blob = $this->exec('/git/blobs/'.$sha);
        if ($blob->encoding == 'base64') {
            return base64_decode($blob->content);
        }
        return $blob->content;
    }

    public function loadTree($sha)
    {
        $entries = array();
        $treeData = $this->exec('/git/trees/'.$sha);
        foreach ($treeData->tree as $entry) {
            switch ($entry->type) {
                case 'blob':
                    $entries[$entry->path] = new Blob($this, $entry->sha, $entry->path);
                    break;
                case 'tree':
                    $entries[$entry->path] = new Tree($this, $entry->sha, $entry->path);
                    break;
            }
        }
        return $entries;
    }

    public function log($filename)
    {
        $shas = array();
        foreach($this->exec('/commits?path='.urlencode($filename)) as $commit) {
            $shas[] = $commit->sha;
        }
        return $shas;
    }

    public function files($sha)
    {
        $commit = $this->exec('/commits/'.$sha);
        if (!$commit) {
            throw new Exception('Log for commit "'.$sha.'"" not found');
        }
        $files = array();
        foreach($commit->files as $file) {
            $files[] = $file->filename;
        }
        return $files;
    }
    
    public function commitMetadata($sha)
    {
        $commit = $this->exec('/commits/'.$sha);
        if (!$commit) {
            throw new Exception('Log for commit "'.$sha.'"" not found');
        }

        $parents = array_map(function ($parent) {
            return $parent->sha;
        }, $commit->parents);

        $diff = array();
        foreach($commit->files as $file) {
            $diff[$file->filename] = $file->patch;
        }

        return new Metadata(
            $commit->sha, // commit
            $parents, // parents
            $commit->commit->author->name, // user
            $commit->commit->author->email, // email
            strtotime($commit->commit->author->date), // date
            $commit->commit->message, // message
            $diff //diff
        );
    }

    # read

    public function tree($path = '.') {
        if ($path == '.' || $path == '') {
            $ref = $this->exec('/git/refs/heads/'.$this->branch);
            $headSha = $ref->object->sha;
            $commit = $this->exec('/git/commits/'.$headSha);
            $tree = new Tree($this, $commit->tree->sha);
        } else {
            $path = explode('/', $path);
            $directory = array_pop($path);
            $parent = join('/', $path);
            try {
                $contents = $this->exec('/contents/'.$parent);
            } catch (Exception $e) {
                $contents = array();
            }
            if (!is_array($contents)) {
                throw new Exception('The path "'.$parent.'" does not exist or is not to a directory');
            }
            $tree = null;
            foreach ($contents as $content) {
                if ($content->name == $directory && $content->type == 'dir') {
                    $tree = new Tree($this, $content->sha);
                    break;
                }
            }
        }
        return $tree;
    }

    public function commits($sha = null, $number = 20)
    {
        $commits = array();
        if (!$sha) {
            $ref = $this->exec('/git/refs/heads/'.$this->branch);
            $sha = $ref->object->sha;
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
            $ref = $this->exec('/git/refs/heads/'.$this->branch);
            $sha = $ref->object->sha;
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

    public function index() {
        return $this->index;
    }

    # write

    private function addToIndex($path, $sha = null)
    {
        if ($sha) {
            try {
                $this->file($path);
                $this->index[$path] = (object)array('operation' => 'M', 'sha' => $sha);
            } catch (Exception $e) {
                $this->index[$path] = (object)array('operation' => 'A', 'sha' => $sha);
            }
        } else {
            $this->index[$path] = (object)array('operation' => 'D');
        }
    }

    private function removeFromIndex($path)
    {
        unset($this->index[$path]);
    }

    private function emptyIndex()
    {
        $this->index = array();
    }

    public function add($filename, $content, $commitMessage = null)
    {
        $add = $this->exec('/git/blobs', 'POST', array(
            'content' => base64_encode($content),
            'encoding' => 'base64'
        ));
        $this->addToIndex($filename, $add->sha);
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function update($filename, $content, $commitMessage = null) {
        return $this->add($filename, $content, $commitMessage);
    }

    public function move($from, $to, $commitMessage = null) {
        if ($this->copy($from, $to) && $this->remove($from) && $commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function copy($from, $to, $commitMessage = null) {
        $this->add($to, $this->file($from));
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    public function remove($filename, $commitMessage = null) {
        $this->addToIndex($filename);
        if ($commitMessage) {
            return $this->save($commitMessage);
        }
        return true;
    }

    private function buildTree($path = '.')
    {
        $tree = $this->tree($path);
        $nodes = array();
        foreach ($tree as $node) {
            $include = true;
            $sha = $node->sha;
            foreach ($this->index() as $itemPath => $item) {
                if (basename($itemPath) == $node->filename) {
                    if ($item->operation == 'M') {
                        $sha = $item->sha;
                    } elseif ($item->operation == 'D') {
                        $include = false;
                    }
                }
            }
            if ($include) {
                if (is_a($node, '\Git\Tree')) {
                    $nodes[] = array(
                        'path' => $node->filename,
                        'mode' => '040000',
                        'type' => 'tree',
                        'sha' => $this->buildTree($node->filename)->sha
                    );
                } else {
                    $nodes[] = array(
                        'path' => $node->filename,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $sha
                    );
                }
            }
        }
        foreach ($this->index() as $itemPath => $item) {
            if ($path == dirname($itemPath)) {
                if ($item->operation == 'A') {
                    if ($path == '.') {
                        $filename = $itemPath;
                    } else {
                        $filename = substr($itemPath, strlen($path) + 1);
                    }
                    $nodes[] = array(
                        'path' => $filename,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $item->sha
                    );
                    $this->removeFromIndex($itemPath);
                }
            }
        }
        if ($path == '.') { // add anything not yet added within a sub-tree
            foreach ($this->index() as $itemPath => $item) {
                if ($item->operation == 'A') {
                    $nodes[] = array(
                        'path' => $itemPath,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $item->sha
                    );
                }
            }
        }
        return $this->exec('/git/trees', 'POST', array(
            'tree' => $nodes
        ));
    }
    
    public function save($commitMessage)
    {
        $tree = $this->buildTree();
        $head = $this->exec('/git/refs/heads/'.$this->branch);
        $commit = $this->exec('/git/commits', 'POST', array(
            'message' => $commitMessage,
            'tree' => $tree->sha,
            'parents' => array($head->object->sha)
        ));
        $this->exec('/git/refs/heads/'.$this->branch, 'PATCH', array(
            'sha' => $commit->sha
        ));
        $this->emptyIndex();
        return $commit->sha;
    }

}
