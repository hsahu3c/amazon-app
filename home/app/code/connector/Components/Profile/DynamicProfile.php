<?php
namespace App\Connector\Components\Profile;

use App\Connector\Models\Profile\ProfileHelper as ProfileModel;
use App\Core\Components\Base;
use \MongoDB\BSON\ObjectId;

/**
 * Intended to make a new class for dynamic profile but didn't have time to do it.
 * Still do not delete this class.
 */
class DynamicProfile extends Base
{
    public function enabled(): bool
    {
        return $this->di->getConfig()->path("profiles.dynamic", false);
    }

    public function getFilters(array $data, ObjectId $profileId): array
    {
        if (isset($data['source_product_id'])
            || isset($data['source_product_ids'])
            || isset($data['filter'])
            || isset($data['or_filter'])) {
            return [];
        }
        $currentProfile = ProfileModel::findFirst([
            '_id' => $profileId,
        ]);
        if (is_null($currentProfile)) {
            throw new \Exception('profile not found');
        }
        $currentProfile = $currentProfile->toArray();
        $profileHelper = new ProfileHelper;
        $profileHelper->setShopIds(
            [
                [
                    'source' => $data['source']['shopId'],
                    'target' => $data['target']['shopId'],
                ],
            ]
        );
        $extractedFilters = $profileHelper->getToUpdateQuery($currentProfile, [
            'sources' => $data['source']['shopId'],
            'targets' => $data['target']['shopId'],
        ], false);
        if ($extractedFilters === false) {
            throw new \Exception('Illegal query');
        }
        return $extractedFilters['filter'];
    }
}
