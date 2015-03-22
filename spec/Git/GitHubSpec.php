<?php

namespace spec\Git;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

date_default_timezone_set('UTC');

class GitHubSpec extends ObjectBehavior
{
    /**
     * @param \Git\HttpClient $request
     */
    function let($request)
    {
        $mockApiData = json_decode(file_get_contents('spec/Git/github-api.json'), true);
        foreach ($mockApiData as $data) {
            $request->send($data['url'], $data['method'], unserialize($data['body']))->will(function ($args) use ($data, $request) {
                list($url, $method, $body) = $args;
                if ($method == $data['method'] && $body == unserialize($data['body'])) {
                    $response = unserialize($data['response']);
                    if ($method == 'PATCH' || $method == 'POST') {
                        foreach ($body as $field => $value) {
                            if ($field == 'tree') {
                                if (is_array($value)) {
                                    $response->$field = array();
                                    foreach ($value as $item) {
                                        $response->{$field}[] = (object)$item;
                                    }
                                } else {
                                    $response->$field = new \stdClass;
                                    $response->$field->sha = $value;
                                }
                            } else {
                                $response->$field = $value;
                            }
                        }
                        if ($method == 'PATCH') {
                            $request->send($data['url'], 'GET', null)->willReturn($response);
                        } else {
                            $request->send($response->url, 'GET', null)->willReturn($response);
                        }
                    }
                    return $response;
                }
            });
        }
        $request->send('https://api.github.com/repos/peej/test/contents/numbers/others/something', 'GET', null)->willThrow('\Git\Exception');

        /*
        $this->beConstructedWith('peej', 'test', $request);
        /*/
        $this->beConstructedWith('peej', 'test', new \Git\HttpClient(file_get_contents('token')));
        //*/
        $this->exec('/git/refs/heads/master', 'PATCH', array(
            'sha' => '7ec4adfd77fcdfce8c76a3b4e1f818a8e3e92ebe',
            'force' => true
        ));
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
        $this->shouldHaveType('Git\GitHub');
    }

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
        $this->file('test.txt')->getContent()->shouldBe("test content\n");
        $this->file('numbers/one.txt')->getContent()->shouldBe("one\n");
    }

    public function it_can_list_the_history_of_a_file()
    {
        $file = $this->file('history.txt');
        $file->history[0]->shouldBeAnInstanceOf('Git\Commit');
        $file->history[0]->message->shouldBe('Update the history file');
        $file->history[1]->message->shouldBe('Create the file history');
    }

    // commits

    public function it_can_list_commits()
    {
        $this->commits()[0]->message->shouldBe('Update the history file');
        $this->commits()[1]->message->shouldBe('Create the file history');
        $this->commits('7ec4adfd77fcdfce8c76a3b4e1f818a8e3e92ebe')[0]->message->shouldBe('Update the history file');
    }

    public function it_can_list_a_commit()
    {
        $this->commit()->sha->shouldBe('7ec4adfd77fcdfce8c76a3b4e1f818a8e3e92ebe');
        $this->commit()->message->shouldBe('Update the history file');
        $this->commit()->files->shouldContain('history.txt');
        $this->commit('7ec4adfd77fcdfce8c76a3b4e1f818a8e3e92ebe')->message->shouldBe('Update the history file');
    }

    public function it_can_return_the_differences_a_commit_contains()
    {
        $commit = $this->commit();
        $commit->diff['history.txt'][0]->shouldBe('-,1 Test the history of a file');
        $commit->diff['history.txt'][1]->shouldBe("1,+ Test the history of a file\n");
        $commit->diff['history.txt'][2]->shouldBe("2,+ \n");
        $commit->diff['history.txt'][3]->shouldBe('3,+ By adding new lines to the file');
    }

    // the index
    
    public function it_can_create_a_file_in_the_index()
    {
        $this->add('new.txt', 'new content')->shouldBe(true);
        $this->index()['new.txt']->operation->shouldBe('A');
    }

    public function it_can_list_the_current_index()
    {
        $this->add('new.txt', 'new content')->shouldBe(true);
        $this->update('numbers/one.txt', "one\n\none one one")->shouldBe(true);
        $this->remove('test.txt')->shouldBe(true);
        $this->index()['new.txt']->operation->shouldBe('A');
        $this->index()['numbers/one.txt']->operation->shouldBe('M');
        $this->index()['test.txt']->operation->shouldBe('D');
    }

    // modification

    public function it_can_create_a_file()
    {
        $this->add('new.txt', 'new content', 'create a file')->shouldBeSha();
        #$this->file('new.txt')->shouldBeLike('new content');
        #$this->add('numbers/three.txt', 'three', 'add another number')->shouldBeSha();
        #$this->file('numbers/three.txt')->shouldBeLike('three');
    }

    public function it_can_update_a_file()
    {
        $this->update('numbers/one.txt', '1', 'update a file')->shouldBeSha();
        $this->file('numbers/one.txt')->shouldBeLike('1');
    }

    public function it_can_remove_a_file()
    {
        $this->remove('numbers/one.txt', 'remove a file')->shouldBeSha();
        $this->shouldThrow('Git\Exception')->duringFile('numbers/one.txt');
    }

    public function it_can_copy_a_file()
    {
        $this->copy('test.txt', 'copy_of_test.txt', 'copy a file')->shouldBeSha();
        $this->file('test.txt')->getContent()->shouldBe("test content\n");
        $this->file('copy_of_test.txt')->getContent()->shouldBe("test content\n");
    }

    public function it_can_move_a_file()
    {
        $this->move('test.txt', 'new_dir/test.txt', 'move a file')->shouldBeSha();
        $this->shouldThrow('Git\Exception')->duringFile('test.txt');
        $this->file('new_dir/test.txt')->getContent()->shouldBe("test content\n");
    }

    public function it_can_move_a_file_into_a_subdir_that_exists_and_has_been_edited()
    {
        $this->remove('numbers/two.txt');
        $this->move('test.txt', 'numbers/test.txt');
        $this->save('move a file into a subdir that exists and has been edited');
        $this->shouldThrow('Git\Exception')->duringFile('numbers/two.txt');
        $this->shouldThrow('Git\Exception')->duringFile('test.txt');
        $this->file('numbers/test.txt')->getContent()->shouldBe("test content\n");
    }

    public function it_creates_commits_on_the_given_branch()
    {
        $this->deleteBranch('other');
        $this->createBranch('other');
        $this->setBranch('other');
        $this->add('new.txt', 'new content', 'create a file')->shouldBeSha();
        $this->file('new.txt')->getContent()->shouldBe('new content');
        $this->setBranch('master');
        $this->shouldThrow('Git\Exception')->duringFile('new.txt');
        $this->setBranch('other');
        $this->file('new.txt')->getContent()->shouldBe('new content');
    }
}
