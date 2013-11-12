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
    private $diff;

    const LOG_FORMAT = '%H,%P,%aN,%aE,%at,%s';

    public function __construct($log)
    {
        $parts = explode("\n", $log);
        $metadata = explode(',', array_shift($parts));

        $this->commit = $metadata[0];
        $this->parents = $metadata[1] ? explode(' ', $metadata[1]) : array();
        $this->user = $metadata[2];
        $this->email = $metadata[3];
        $this->date = (int) $metadata[4];
        $this->message = $metadata[5];
        $this->diff = join("\n", $parts);
    }

    public function __get($key)
    {
         switch ($key) {

            case 'diff':
                if (!is_array($this->diff)) {
                    $diff = explode('diff --git ', $this->diff);
                    $this->diff = array();
                    foreach ($diff as $d) {
                        preg_match('#a/(.+?) #', $d, $filename);
                        if ($filename) {
                            preg_match_all('#@ -([0-9]+)(,[0-9]+)? \+([0-9]+)(,[0-9]+)? @@\n(.+)(?:$|@)#s', $d, $matches, PREG_SET_ORDER);
                            foreach ($matches as $match) {
                                $fromA = $match[1];
                                $toA = $match[2];
                                $fromB = $match[3];
                                $toB = $match[4];
                                $index = 1;
                                foreach (explode("\n", $match[5]) as $line) {
                                    $operation = substr($line, 0, 1);
                                    switch ($operation) {
                                        case '+':
                                            $this->diff[$filename[1]][] = $fromB.'+'.substr($line, 1);
                                            $index++;
                                            $fromB++;
                                            break;
                                        case '-':
                                            $this->diff[$filename[1]][] = $fromA.'-'.substr($line, 1);
                                            $fromA++;
                                            break;
                                        default:
                                            $this->diff[$filename[1]][] = $index.' '.substr($line, 1);
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
                return $this->diff;

        }
    }
}