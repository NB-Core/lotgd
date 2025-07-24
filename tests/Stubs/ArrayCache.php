<?php

declare(strict_types=1);

namespace Doctrine\Common\Cache;

class ArrayCache implements Cache
{
    private array $data = [];

    public function fetch($id)
    {
        return $this->data[$id] ?? false;
    }

    public function contains($id)
    {
        return array_key_exists($id, $this->data);
    }

    public function save($id, $data, $lifeTime = 0)
    {
        $this->data[$id] = $data;
        return true;
    }

    public function delete($id)
    {
        unset($this->data[$id]);
        return true;
    }

    public function getStats()
    {
        return null;
    }
}
