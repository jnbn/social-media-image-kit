<?php

namespace Stillat\SocialMediaImageKit\Actions;

use Statamic\Entries\Entry;

use Statamic\Actions\Action;
use Stillat\SocialMediaImageKit\Configuration;
use Stillat\SocialMediaImageKit\Jobs\GenerateSocialMediaImages;

class RecreateSocialMediaImages extends Action
{

    protected $confirm = false;

    public static function title()
    {
        return 'Re-create Social Media Images';
    }

    public function visibleTo($item)
    {
        if (! $item instanceof Entry) {
            return false;
        }

        $collection = $item->collection()?->handle();

        if ($collection === null || ! in_array($collection, Configuration::collections())) {
            return false;
        }

        return true;
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    /**
     * The run method
     *
     * @return mixed
     */
    public function run($items, $values)
    {
        foreach ($items as $item) {
            GenerateSocialMediaImages::createJob($item->id());
        }
    }
}
