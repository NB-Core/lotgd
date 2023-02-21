<?php

/**
 * @exclude(false)
 */
class Annotated
{
    /**
     * @exclude(true)
     */
    public function doNotBool()
    {
    }

    /**
     * @databag('name' => 'user.name')
     * @databag('name' => 'page.number')
     */
    public function withBags()
    {
    }

    /**
     * @upload('field' => 'user-files')
     */
    public function saveFiles()
    {
    }
}

/**
 * @exclude(true)
 */
class Excluded
{
    public function action()
    {
    }
}
