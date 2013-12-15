<?php

namespace Git;

class Metadata
{
    public $commit;
    public $parents = array();
    public $user;
    public $email;
    public $date;
    public $message;
    public $diff;

    const LOG_FORMAT = '%H,%P,%aN,%aE,%at,%s';

    public function __construct($commit, $parents, $user, $email, $date, $message, $diff)
    {
        $this->commit = $commit;
        $this->parents = $parents;
        $this->user = $user;
        $this->email = $email;
        $this->date = (int) $date;
        $this->message = $message;

        $this->diff = array();
        foreach ($diff as $filename => $patch) {
            preg_match_all('#@ -([0-9]+)(,[0-9]+)? \+([0-9]+)(,[0-9]+)? @@\n(.+)(?:$|@)#s', $patch, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $fromA = $match[1];
                $toA = $match[2];
                $fromB = $match[3];
                $toB = $match[4];
                $index = 1;
                $lines = explode("\n", $match[5]);
                foreach ($lines as $lnum => $line) {
                    if ($line != '\ No newline at end of file') {
                        if (isset($lines[$lnum + 1]) && $lines[$lnum + 1] == '\ No newline at end of file') {
                            $nl = '';
                        } else {
                            $nl = "\n";
                        }
                        $operation = substr($line, 0, 1);
                        switch ($operation) {
                            case '+':
                                $this->diff[$filename][] = $fromB.'+'.substr($line, 1).$nl;
                                $index++;
                                $fromB++;
                                break;
                            case '-':
                                $this->diff[$filename][] = $fromA.'-'.substr($line, 1).$nl;
                                $fromA++;
                                break;
                            default:
                                $this->diff[$filename][] = $index.' '.substr($line, 1).$nl;
                                $index++;
                                $fromA++;
                                $fromB++;
                                break;
                        }
                    }
                }
            }
        }
    }
}