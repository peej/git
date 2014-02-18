<?php

namespace Git;

interface Gittable
{
    /**
     * Dereference a reference into a SHA
     * @param str $reference
     * @return str SHA
     */
    public function dereference($reference);

    /**
     * Set the internal branch pointer to the given branch name
     * @param $name
     */
    public function setBranch($name);

    public function createBranch($name);

    public function deleteBranch($name, $mustBeMerged);

    # plumbing

    /**
     * @param str $sha
     * @return str
     */
    public function catFile($sha);

    /**
     * @param str $sha
     * @return str
     */
    public function loadTree($sha);

    /**
     * @param str $filename
     * @return str[] An array of SHAs of the commits the filename is in
     */
    public function log($filename);

    /**
     * @param str $sha
     * @return str[] An array of filenames in the given commit
     */
    public function files($sha);

    /**
     * @param str $sha
     * @return Git\Metadata
     */
    public function commitMetadata($sha);

    # read

    /**
     * Get a tree for a given path
     * @param str $path
     * @return Git\Tree
     */
    public function tree($path = '.');

    /**
     * Get a commit tree starting at the given SHA
     * @param str $sha
     * @return Git\Commit
     */
    public function commits($sha = null, $number = 20);

    /**
     * Get a commit
     * @param str $sha The SHA of the commit to fetch, if not given the HEAD of the current branch is fetched
     * @return Git\Commit
     */
    public function commit($sha = null);

    /**
     * Get a file from the head
     * @param str $filename
     * @return Git\Blob
     */
    public function file($filename);

    /**
     * Get the current index
     * @param str $sha The SHA of the commit to fetch, if not given the HEAD of the current branch is fetched
     * @return Git\Commit
     */
    public function index();

    # write

    /**
     * Add a new file
     * @param str $filename Name of the file to add
     * @param str $content Content to add
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function add($filename, $content, $commitMessage = null);

    /**
     * Update an existing file
     * @param str $filename Name of the file to update
     * @param str $content New content
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function update($filename, $content, $commitMessage = null);

    /**
     * Rename a file
     * @param str $from Name of the file to rename
     * @param str $to New name of the file
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function move($from, $to, $commitMessage = null);

    /**
     * Copy a file
     * @param str $from Name of the file to copy
     * @param str $to New name of the copy
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function copy($from, $to, $commitMessage = null);

    /**
     * Delete a file
     * @param str $filename Name of the file to delete
     * @param str $commitMessage Commit message to use. If not provided, addition will not be committed and must be comitted manually.
     */
    public function remove($filename, $commitMessage = null);

    /**
     * Commit the index
     */
    public function save($commitMessage);

}
