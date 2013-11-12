# Git

An OO wrapper for Git allowing use of a Git repo as if it were a filesystem or database.

Talks directly to a Git repo via the Git binary, does not require a working copy or writing files to disk so it also works with a bare repo.


## Installation

Install via [Composer](http://getcomposer.org/), add a dependency on `peej/git` to your project's `composer.json` file.

    {
        "require": {
            "peej/git": "1.0.*"
        }
    }


## Requirements

A system with [git](http://git-scm.com/) installed, it is expected to be in the command path.


## Usage

    $repo = new Git\Repo('/tmp/myrepo.git');

    // get head commit message
    echo $repo->commit()->message;

    // get a file from the head
    echo $repo->file('mydir/myfile.txt');

    // get the latest commit the file was mentioned in
    $commit = $repo->file('mydir/myfile.txt')->history[0];

    // get a tree for a given path
    $tree = $repo->tree('mydir');
    echo $tree['myfile.txt'];

    // stage a file edit
    $repo->update('mydir/myfile.txt', 'new content');

    // create a new commit
    $repo->save('commit message');

