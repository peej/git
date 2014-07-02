<?php

namespace spec\Git;

use PhpSpec\ObjectBehavior;

date_default_timezone_set('UTC');

class RepoSpec extends ObjectBehavior
{
    private $useBare = false;

    public function let()
    {
        $repoPath = $this->useBare ? '/tmp/git.git' : '/tmp/git';
        $this->beConstructedWith($repoPath);
        $this->removeDir($repoPath);
        mkdir($repoPath);
        $cwd = getcwd();
        chdir($repoPath);

        $init = $this->useBare ? 'git init --bare' : 'git init';
        $refMaster = $this->useBare ? 'refs/heads/master' : '.git/refs/heads/master';

        foreach (array(
            $init,
            'echo "one" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 $1 numbers/one.txt',
            'echo "two" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 $1 numbers/two.txt',
            'echo "test content" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 $1 test.txt',
            'git write-tree',
            'echo "initial commit" | git commit-tree $1',
            'echo "$1" > '.$refMaster,
            'git branch feature',
            'git tag tag'
        ) as $command) {
            if (isset($output)) {
                $command = str_replace('$1', $output, $command);
            }
            $output = exec($command);
        }
        chdir($cwd);
    }

    public function letgo()
    {
        $this->removeDir($this->useBare ? '/tmp/git.git' : '/tmp/git');
    }

    private function removeDir($dir)
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $file = $dir.'/'.$file;
                    if (is_dir($file)) {
                        $this->removeDir($file);
                    } else {
                        unlink($file);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function getMatchers()
    {
        return [
            'beSha' => function($subject) {
                return preg_match('/^[0-9a-f]{40}$/', $subject);
            },
        ];
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType('Git\Repo');
    }

    //*trees and blobs

    public function it_can_list_a_tree()
    {
        $this->tree()['test.txt']->shouldBeAnInstanceOf('Git\Blob');
        $this->tree()['numbers']->shouldBeAnInstanceOf('Git\Tree');
        $this->tree('numbers')['one.txt']->shouldBeAnInstanceOf('Git\Blob');
        $this->tree('numbers')['doesnt_exist.txt']->shouldBe(null);
        $this->tree('numbers/one.txt')->shouldBe(null);
        $this->tree('numbers/others/something/nothing')->shouldBe(null);
    }

    public function it_can_return_a_files_contents()
    {
        $this->file('test.txt')->shouldBeLike('test content');
        $this->file('numbers/one.txt')->shouldBeLike('one');
    }

    public function it_can_list_the_history_of_a_file()
    {
        $this->add('dir/new.txt', 'new content', 'create a file');
        $this->update('dir/new.txt', 'newer content', 'update a file');
        $this->add('something-else.txt', 'something else', 'create another file');
        $this->remove('something-else.txt', 'delete another file');
        $this->add('anotherthing.txt', 'another thing', 'and another');
        $newFile = $this->file('dir/new.txt');
        $newFile->history[0]->shouldBeAnInstanceOf('Git\Commit');
        $newFile->history[0]->message->shouldBe('update a file');
        $newFile->history[1]->message->shouldBe('create a file');
        $newFile2 = $this->file('anotherthing.txt');
        $newFile2->history[0]->message->shouldBe('and another');
    }

    // commits

    public function it_can_list_commits()
    {
        $sha = $this->add('new.txt', 'new content', 'create a file');
        $this->commits()[0]->message->shouldBe('create a file');
        $this->commits()[1]->message->shouldBe('initial commit');
        $this->commits($sha)[0]->message->shouldBe('create a file');
    }

    public function it_can_list_a_commit()
    {
        $this->commit()->sha->shouldBeSha();
        $this->commit()->message->shouldBe('initial commit');
        $this->commit()->files->shouldContain('numbers/one.txt');
        $sha = $this->add('new.txt', 'new content', 'create a file');
        $sha2 = $this->add('new2.txt', 'more content', 'create another file');
        $this->commit($sha)->message->shouldBe('create a file');
        $this->commit($sha2)->message->shouldBe('create another file');
    }

    public function it_can_return_the_differences_a_commit_contains()
    {
        $this->update('test.txt', "new line\ntest content\nanother line\none more");
        $this->add('new.txt', 'new content');
        $sha = $this->save('new commit');
        $commit = $this->commit($sha);
        $commit->diff['test.txt'][0]->shouldBe("1+new line\n");
        $commit->diff['test.txt'][1]->shouldBe("2 test content\n");
        $commit->diff['test.txt'][2]->shouldBe("3+another line\n");
        $commit->diff['test.txt'][3]->shouldBe("4+one more\n");
        $commit->diff['new.txt'][0]->shouldBe("1+new content\n");
        $sha = $this->update('test.txt', "new line\ntest content\none more\nnew line", 'another update');
        $commit = $this->commit($sha);
        $commit->diff['test.txt'][0]->shouldBe("1 new line\n");
        $commit->diff['test.txt'][1]->shouldBe("2 test content\n");
        $commit->diff['test.txt'][2]->shouldBe("3-another line\n");
        $commit->diff['test.txt'][3]->shouldBe("3 one more\n");
        $commit->diff['test.txt'][4]->shouldBe("4+new line\n");
    }

    // the index

    public function it_can_create_a_file_in_the_index()
    {
        $this->add('new.txt', 'new content')->shouldBe(true);
        $this->index()->shouldBe(array('new.txt' => 'A'));
    }

    public function it_can_list_the_current_index()
    {
        $this->add('numbers/four.txt', 'four');
        $this->add('foo/bar', 'foobar');
        $this->remove('test.txt');
        $this->update('numbers/one.txt', "one\n\none one one");
        $this->index()['numbers/four.txt']->shouldBe('A');
        $this->index()['foo/bar']->shouldBe('A');
        $this->index()['test.txt']->shouldBe('D');
        $this->index()['numbers/one.txt']->shouldBe('M');
    }

    // modification

    public function it_can_create_a_file()
    {
        $this->add('new.txt', 'new content', 'create a file')->shouldBeSha();
        $this->file('new.txt')->shouldBeLike('new content');
        $this->add('numbers/three.txt', 'three', 'add another number')->shouldBeSha();
        $this->file('numbers/three.txt')->shouldBeLike('three');
    }

    public function it_can_update_a_file()
    {
        $this->update('numbers/one.txt', '1', 'update a file')->shouldBeSha();
        $this->file('numbers/one.txt')->shouldBeLike('1');
    }

    public function it_can_move_a_file()
    {
        $this->move('test.txt', 'new_dir/test.txt', 'move a file')->shouldBeSha();
        $this->shouldThrow('Git\Exception')->duringFile('test.txt');
        $this->file('new_dir/test.txt')->shouldBeLike('test content');
    }

    public function it_can_copy_a_file()
    {
        $this->copy('test.txt', 'copy_of_test.txt', 'copy a file')->shouldBeSha();
        $this->file('test.txt')->shouldBeLike('test content');
        $this->file('copy_of_test.txt')->shouldBeLike('test content');
    }

    public function it_can_remove_a_file()
    {
        $this->remove('numbers/one.txt', 'remove a file')->shouldBeSha();
        $this->shouldThrow('Git\Exception')->duringFile('numbers/one.txt');
    }

    public function it_creates_commits_using_the_given_user_details()
    {
        $this->setUser('John Doe', 'johndoe@example.com');
        $this->add('new.txt', 'new content', 'create a file')->shouldBeSha();
        $this->file('new.txt')->shouldBeLike('new content');
        $this->add('new2.txt', 'more content', 'create another file')->shouldBeSha();
        $this->file('new.txt')->user->shouldBe('John Doe');
        $this->file('new.txt')->email->shouldBe('johndoe@example.com');
        $this->file('new.txt')->date->shouldBeInteger();
    }

    public function it_creates_commits_on_the_given_branch()
    {
        $this->createBranch('other');
        $this->setBranch('other');
        $this->add('new.txt', 'new content', 'create a file')->shouldBeSha();
        $this->file('new.txt')->shouldBeLike('new content');
        $this->setBranch('master');
        $this->shouldThrow('Git\Exception')->duringFile('new.txt');
        $this->setBranch('other');
        $this->file('new.txt')->shouldBeLike('new content');
    }

    // branches

    public function it_should_list_branches()
    {
        $this->getBranches()->shouldBe(array(
            'feature',
            'master'
        ));
    }

    public function it_should_create_a_branch()
    {
        $this->createBranch('new');
        $this->getBranches()->shouldBe(array(
            'feature',
            'master',
            'new'
        ));
    }

    public function it_should_list_tags()
    {
        $this->getTags()->shouldBe(array(
            'tag'
        ));
    }
}