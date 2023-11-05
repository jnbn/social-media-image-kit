<?php

namespace Stillat\SocialMediaImageKit;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Stillat\SocialMediaImageKit\Contracts\ImageGenerator as ImageGeneratorContract;
use Stillat\SocialMediaImageKit\Events\GeneratedImage;
use Stillat\SocialMediaImageKit\Events\GeneratingImage;
use Stillat\SocialMediaImageKit\Events\GenerationFailed;

class ImageGenerator extends AbstractImageGenerator implements ImageGeneratorContract
{
    public function generate(Entry $entry, string $collection, string $blueprint, array $data): void
    {
        $entryId = $entry->id();

        $html = $this->templateManager->render($collection, $blueprint, $data);

        if ($html === null) {
            return;
        }

        $updatedImages = [];
        $originalImages = $entry->get($this->fieldConfiguration->imagesFieldName) ?? [];
        $existingImages = collect($entry->get($this->fieldConfiguration->imagesFieldName) ?? [])
            ->keyBy($this->fieldConfiguration->assetFieldName)
            ->all();

        $filesToPreserve = collect($existingImages)->filter(function ($image) {
            return Arr::get($image, $this->fieldConfiguration->preservedFieldName, false) === true;
        })->map(function ($image) {
            return $image[$this->fieldConfiguration->assetFieldName];
        })->all();

        $changesMade = 0;

        $appPath = storage_path('app');

        foreach ($this->sizes as $size) {
            $assetPath = $this->nameFormatter->getImageName($entryId, $entry, $size).'.'.$this->imageExtension;

            GeneratingImage::dispatch($entry, $size);

            if (array_key_exists($assetPath, $filesToPreserve) && array_key_exists($assetPath, $existingImages)) {
                $generatedImage = $existingImages[$assetPath];

                $updatedImages[] = $generatedImage;
                GeneratedImage::dispatch($entry, $size, false);

                continue;
            }

            if ($this->skipExistingImages && array_key_exists($assetPath, $existingImages)) {
                $generatedImage = $existingImages[$assetPath];

                $updatedImages[] = $generatedImage;
                GeneratedImage::dispatch($entry, $size, false);

                continue;
            }

            $path = $this->tmpPath.'/'.$assetPath;

            $imageDetails = new ImageDetails();
            $imageDetails->width = $size['width'];
            $imageDetails->height = $size['height'];

            if (! $this->htmlRenderer->fromHtml($path, $html, $imageDetails)) {
                GenerationFailed::dispatch($entry, $size);

                continue;
            }

            $assetId = $this->fieldConfiguration->assetContainer.'::'.$assetPath;

            $asset = AssetUpdater::updateAsset(new AssetInfo(
                assetId: $assetId,
                imagePath: $path,
                assetContainer: $this->fieldConfiguration->assetContainer,
                fileName: $assetPath
            ), $this->cleanupFiles);

            $setId = 's'.Str::random(8);

            // Preserve existing identifiers to reduce the number of changes made.
            if (array_key_exists($assetPath, $existingImages)) {
                $setId = $existingImages[$assetPath]['id'];
            }

            $generatedImage = [
                'id' => $setId,
                $this->fieldConfiguration->assetFieldName => $asset->path(),
                $this->fieldConfiguration->socialMediaPlatformType => $size['handle'],
            ];

            $updatedImages[] = $generatedImage;

            GeneratedImage::dispatch($entry, $size, true);

            $changesMade++;
        }

        if ($changesMade == 0) {
            return;
        }

        if ($updatedImages == $originalImages) {
            return;
        }

        $entry->set($this->fieldConfiguration->imagesFieldName, $updatedImages);
        $entry->saveQuietly();
    }
}
