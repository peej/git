<?php

namespace spec\Git;

use PhpSpec\ObjectBehavior;

date_default_timezone_set('UTC');

class RepoSpec extends ObjectBehavior
{
    private $useBare = true;
    private $repoPath, $refHeads;
    private $output = array(); // command exec output

    private function execCommand($cmd)
    {
        return exec($cmd.' 2>/dev/null');
    }

    private function execCommands($cmds)
    {
        $cwd = getcwd();
        chdir($this->repoPath);
        $this->output = array();
        foreach ($cmds as $command) {
            foreach ($this->output as $key => $response) {
                $command = str_replace('{'.$key.'}', $response, $command);
            }
            $this->output[] = $this->execCommand($command);
        }
        chdir($cwd);
    }

    public function let()
    {
        $this->repoPath = $this->useBare ? '/tmp/git.git' : '/tmp/git';
        $this->beConstructedWith($this->repoPath);
        $this->removeDir($this->repoPath);
        mkdir($this->repoPath);
        
        $init = $this->useBare ? 'git init --bare' : 'git init';
        $this->refHeads = $this->useBare ? 'refs/heads/' : '.git/refs/heads/';

        $this->execCommands(array(
            $init,
            'git config user.name "John Doe"',
            'git config user.email johndoe@example.com',
            'echo "one" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {3} numbers/one.txt',
            'echo "two" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {5} numbers/two.txt',
            'echo "test content" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {7} test.txt',
            'git write-tree',
            'echo "initial commit" | git commit-tree {9}',
            'echo "{10}" > '.$this->refHeads.'master',
            'git branch feature',
            'git tag tag'
        ));
    }

    public function letgo()
    {
        $this->removeDir($this->repoPath);
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
        return array(
            'beSha' => function($subject) {
                return preg_match('/^[0-9a-f]{40}$/', $subject);
            },
        );
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
        $commit->diff['test.txt'][0]->shouldBe("1,+ new line\n");
        $commit->diff['test.txt'][1]->shouldBe("2,1 test content\n");
        $commit->diff['test.txt'][2]->shouldBe("3,+ another line\n");
        $commit->diff['test.txt'][3]->shouldBe("4,+ one more\n");
        $commit->diff['new.txt'][0]->shouldBe("1,+ new content\n");
        $sha = $this->update('test.txt', "new line\ntest content\none more\nnew line", 'another update');
        $commit = $this->commit($sha);
        $commit->diff['test.txt'][0]->shouldBe("1,1 new line\n");
        $commit->diff['test.txt'][1]->shouldBe("2,2 test content\n");
        $commit->diff['test.txt'][2]->shouldBe("-,3 another line\n");
        $commit->diff['test.txt'][3]->shouldBe("3,4 one more\n");
        $commit->diff['test.txt'][4]->shouldBe("4,+ new line\n");
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
        $this->resetIndex();
        $this->shouldThrow('Git\Exception')->duringFile('new.txt');
        $this->index()->shouldBe(array());
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

    public function it_should_rename_a_branch()
    {
        $this->renameBranch('feature', 'new');
        $this->getBranches()->shouldBe(array(
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

    public function it_should_know_when_a_branch_can_be_merged()
    {
        $this->execCommands(array(
            'cat '.$this->refHeads.'master', #0
            'echo "three" | git hash-object -w --stdin', #1
            'git update-index --add --cacheinfo 100644 {1} numbers/three.txt', #2
            'git write-tree', #3
            'echo "feature added three" | git commit-tree -p {0} {3}', #4
            'git update-ref refs/heads/feature {4}', #5
            'cat '.$this->refHeads.'feature', #6
            'echo "four" | git hash-object -w --stdin', #7
            'git update-index --add --cacheinfo 100644 {7} numbers/four.txt', #8
            'git write-tree', #9
            'echo "feature added four" | git commit-tree -p {6} {9}', #10
            'git update-ref refs/heads/feature {10}', #11
            'git read-tree refs/heads/master', #12
            'echo "three" | git hash-object -w --stdin', #13
            'git update-index --add --cacheinfo 100644 {13} numbers/three.txt', #14
            'git write-tree', #15
            'echo "master added three" | git commit-tree -p {0} {15}', #16
            'git update-ref refs/heads/master {16}' #17
        ));
        $this->setBranch('master');
        $this->canMerge('feature')->shouldBe(true);
        $this->mergeConflicts('feature')->shouldBe(null);
        $this->merge('feature');

        $mergeCommit = $this->commit();
        $mergeCommit->message->shouldBe('Merge feature into master');
        $mergeCommit->parents->shouldBe(array(
            $this->output[16], $this->output[10]
        ));
    }

    public function it_should_know_if_a_branch_can_not_be_merged()
    {
        $this->execCommands(array(
            'cat '.$this->refHeads.'master',
            'echo "three" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {1} numbers/three.txt',
            'echo "a new line at the start\ntest content" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {3} test.txt',
            'git write-tree',
            'echo "added three" | git commit-tree -p {0} {5}',
            'git update-ref refs/heads/master {6}',
            'git read-tree refs/heads/feature',
            'echo "3" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {9} numbers/three.txt',
            'echo "test content\na new line at the end" | git hash-object -w --stdin',
            'git update-index --add --cacheinfo 100644 {11} test.txt',
            'git write-tree',
            'echo "added 3" | git commit-tree -p {0} {13}',
            'git update-ref refs/heads/feature {14}',
            'git read-tree refs/heads/master'
        ));
        $this->setBranch('master');

        $this->shouldThrow('Exception')->duringCanMerge('feature');
        $this->mergeConflicts('feature')->shouldBe(array(
            'numbers/three.txt' => array(
                "-,1 three\n",
                "1,+ 3\n"
            ),
            'test.txt' => array(
                "-,1 a new line at the start\n",
                "1,2 test content\n",
                "2,+ a new line at the end\n"
            )
        ));
        $this->shouldThrow('Exception')->duringMerge('feature');
    }

    public function it_should_be_able_to_undo_back_to_a_previous_commit()
    {
        $this->add('new.txt', 'new content', 'create a file');
        $this->execCommands(array('git show-ref -s refs/heads/master'));
        $sha = $this->output[0];
        $this->update('new.txt', 'updated content', 'update a file');
        $this->add('something.else.txt', 'something else unrelated', 'Add something else');

        $this->file('new.txt')->shouldBeLike('updated content');
        $this->undo($sha, 'Revert commit '.$sha)->shouldBeSha();
        $this->file('new.txt')->shouldBeLike('new content');
        $this->shouldThrow('Git\Exception')->duringFile('something.else.txt');
    }

    public function it_should_be_able_to_revert_a_previous_commit()
    {
        $this->add('new.txt', 'new content', 'create a file');
        $this->update('new.txt', 'updated content', 'update a file');
        $this->execCommands(array('git show-ref -s refs/heads/master'));
        $sha = $this->output[0];
        $this->add('something.else.txt', 'something else unrelated', 'Add something else');

        $this->file('new.txt')->shouldBeLike('updated content');
        $this->revert($sha, 'Revert commit '.$sha)->shouldBeSha();
        $this->file('new.txt')->shouldBeLike('new content');
        $this->file('something.else.txt')->shouldBeLike('something else unrelated');
    }

}