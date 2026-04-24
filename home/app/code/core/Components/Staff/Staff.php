<?php

namespace App\Core\Components\Staff;

use App\Core\Components\Base;
use App\Core\Components\Staff\EmailHelper;
use \MongoDB\BSON\ObjectId;

class Staff extends Base
{
    public function createStaff(array $data)
    {

        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        $sellerUsername = $data['seller_username'] ?? null;
        $staffRole = $data['staff_role'] ?? "admin";

        if (empty($userId) || empty($sellerUsername)) {
            return ["success" => false, "message" => "Missing required fields: user_id, seller_username"];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $user = $collection->findOne(['user_id' => $userId], $options);
        if (empty($user)) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $seller = $collection->findOne(['username' => $sellerUsername, 'shops' => ['$exists' => true]], $options);
        if (empty($seller)) {
            return ['success' => false, 'message' => 'Seller not found'];
        } else {
            $sourceShop = [];
            foreach ($seller['shops'] as $shop) {
                if ($shop['marketplace'] == $this->di->getRequester()->getSourceName()) {
                    $sourceShop = $shop;
                    break;
                }
            }
            if (empty($sourceShop)) {
                return ['success' => false, 'message' => 'Source Shop not found'];
            }
        }

        // Check if staff request already exists
        if (isset($seller['staff'])) {
            foreach ($seller['staff'] as $staffMember) {
                if ((string) $staffMember['staff_user_id'] == $userId) {
                    return ['success' => false, 'message' => 'Staff already exists for this seller'];
                }
            }
        }

        // Fetching staff role ID from the staff_roles collection
        $staffRolesCollection = $mongo->getCollectionForTable('staff_roles');
        $staffRoleDoc = $staffRolesCollection->findOne(['code' => $staffRole], $options);
        if (empty($staffRoleDoc)) {
            return ['success' => false, 'message' => 'Invalid staff role'];
        }
        $staffRoleId = $staffRoleDoc['_id'];

        // Add staff info
        $staffInfo = [
            'staff_user_id' => new ObjectId($userId),
            'name' => $user['username'],
            'email' => $user['email'],
            'type' => ($user['user_id'] === $seller['user_id']) ? 'owner' : 'staff',
            'status' => ($user['user_id'] === $seller['user_id']) ? 'approved' : 'pending_approval',
            'created_at' => date('Y-m-d H:i:s'),
            'staff_role_id' => $staffRoleId
        ];

        $seller['staff'][] = $staffInfo;

        try {
            $updateResult = $collection->updateOne(
                ['username' => $sellerUsername],
                ['$set' => ['staff' => $seller['staff']]]
            );

            if ($updateResult->getModifiedCount() > 0) {

                // Sending Staff Account Request Mail to Seller
                if ($staffInfo['type'] === "staff") {
                    $emailHelper = $this->di->getObjectManager()->get(EmailHelper::class);
                    $sourceAppCode = $sourceShop['apps'][0]['code'] ?? 'default';
                    $appPath = $this->di->getConfig()->get('apiconnector')[$sourceShop['marketplace']][$sourceAppCode]['app_path'] ?? "";

                    $emailData = [
                        'email' => $seller['email'],
                        'name' => $seller['username'],
                        'staff_name' => $user['username'],
                        'staff_email' => $user['email'],
                        'appPath' => $appPath
                    ];

                    if (isset($data['templateSource'])) {
                        $emailData['templateSource'] = $data['templateSource'];
                    }
                    $emailHelper->sendStaffRequestMail($emailData);
                }
                return ['success' => true, 'message' => 'Staff request added successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to add staff request'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error updating seller: ' . $e->getMessage()];
        }

    }

    public function approveStaff(array $data)
    {
        $sellerId = $data['seller_id'] ?? $this->di->getUser()->id;
        $staffUserId = $data['staff_user_id'];

        if (empty($sellerId) || empty($staffUserId)) {
            return ["success" => false, "message" => "Missing required param : staff_user_id and seller_id"];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $seller = $collection->findOne(['user_id' => $sellerId], $options);
        if (empty($seller) || empty($seller['staff'])) {
            return ['success' => false, 'message' => 'You are not authorize to approve.'];
        }

        $staffIndex = -1;
        foreach ($seller['staff'] as $index => $staffMember) {
            if ((string) $staffMember['staff_user_id'] == $staffUserId) {
                $staffIndex = $index;
                break;
            }
        }

        if ($staffIndex === -1) {
            return ['success' => false, 'message' => 'Staff member not found for this seller to approve'];
        }

        try {
            $updateResult = $collection->updateOne(
                [
                    'user_id' => $sellerId,
                    'staff.staff_user_id' => new ObjectId($staffUserId)
                ],
                [
                    '$set' => ['staff.$.status' => 'approved', 'staff.$.approved_at' => date('Y-m-d H:i:s')]
                ]
            );

            if ($updateResult->getModifiedCount() > 0) {
                // Sending Staff Approval Email to Staff User
                $staffUser = $collection->findOne(['user_id' => $staffUserId], $options);
                $emailHelper = $this->di->getObjectManager()->get(EmailHelper::class);
                $emailData = [
                    'email' => $staffUser['email'],
                    'name' => $staffUser['username'],
                    'seller_name' => $seller['username']
                ];

                if (isset($data['templateSource'])) {
                    $emailData['templateSource'] = $data['templateSource'];
                }
                $emailHelper->sendStaffApprovalMail($emailData);

                return ['success' => true, 'message' => 'Staff member approved successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to approve staff member'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error updating seller: ' . $e->getMessage()];
        }
    }

    public function rejectStaff(array $data)
    {
        $sellerId = $data['seller_id'] ?? $this->di->getUser()->id;
        $staffUserId = $data['staff_user_id'];

        if (empty($sellerId) || empty($staffUserId)) {
            return ["success" => false, "message" => "Missing required param: staff_user_id and seller_id"];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $seller = $collection->findOne(['user_id' => $sellerId], $options);
        if (empty($seller) || empty($seller['staff'])) {
            return ['success' => false, 'message' => 'You are not authorized to reject staff members.'];
        }

        $staffIndex = -1;
        foreach ($seller['staff'] as $index => $staffMember) {
            if ((string) $staffMember['staff_user_id'] == $staffUserId) {
                $staffIndex = $index;
                break;
            }
        }

        if ($staffIndex === -1) {
            return ['success' => false, 'message' => 'Staff member not found for this seller to reject.'];
        }

        try {
            $updateResult = $collection->updateOne(
                [
                    'user_id' => $sellerId,
                    'staff.staff_user_id' => new ObjectId($staffUserId)
                ],
                [
                    '$set' => ['staff.$.status' => 'rejected', 'staff.$.rejected_at' => date('Y-m-d H:i:s')]
                ]
            );

            if ($updateResult->getModifiedCount() > 0) {
                // Sending Staff Rejection Email to Staff User
                $staffUser = $collection->findOne(['user_id' => $staffUserId], $options);
                $emailHelper = $this->di->getObjectManager()->get(EmailHelper::class);
                $emailData = [
                    'email' => $staffUser['email'],
                    'name' => $staffUser['username'],
                    'seller_name' => $seller['username']
                ];

                if (isset($data['templateSource'])) {
                    $emailData['templateSource'] = $data['templateSource'];
                }
                $emailHelper->sendStaffRejectionMail($emailData);

                return ['success' => true, 'message' => 'Staff member rejected successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to reject staff member'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error updating seller: ' . $e->getMessage()];
        }
    }

    public function deleteStaff(array $data)
    {
        $sellerId = $data['seller_id'] ?? $this->di->getUser()->id;
        $staffUserId = $data['staff_user_id'];

        if (empty($sellerId) || empty($staffUserId)) {
            return ["success" => false, "message" => "Missing required param: staff_user_id and seller_id"];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $seller = $collection->findOne(['user_id' => $sellerId], $options);
        if (empty($seller) || empty($seller['staff'])) {
            return ['success' => false, 'message' => 'You are not authorized to delete staff members.'];
        }

        $staffIndex = -1;
        foreach ($seller['staff'] as $index => $staffMember) {
            if ((string) $staffMember['staff_user_id'] == $staffUserId) {
                $staffIndex = $index;
                break;
            }
        }

        if ($staffIndex === -1) {
            return ['success' => false, 'message' => 'Staff member not found for this seller to delete.'];
        }

        try {
            $updateResult = $collection->updateOne(
                ['user_id' => $sellerId],
                ['$pull' => ['staff' => ['staff_user_id' => new ObjectId($staffUserId)]]]
            );

            if ($updateResult->getModifiedCount() > 0) {
                // Sending Staff Deletion Email to Staff User
                $staffUser = $collection->findOne(['user_id' => $staffUserId], $options);
                $emailHelper = $this->di->getObjectManager()->get(EmailHelper::class);
                $emailData = [
                    'email' => $staffUser['email'],
                    'name' => $staffUser['username'],
                    'seller_name' => $seller['username']
                ];

                if (isset($data['templateSource'])) {
                    $emailData['templateSource'] = $data['templateSource'];
                }
                $emailHelper->sendStaffDeletionMail($emailData);

                return ['success' => true, 'message' => 'Staff member deleted successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to delete staff member'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error updating seller: ' . $e->getMessage()];
        }
    }

    public function getStaffs(array $data)
    {
        $sellerId = $data['seller_id'] ?? $this->di->getUser()->id;
        $filter = $data['filter'] ?? [];
        $page = $data['page'] ?? 1;
        $limit = $data['limit'] ?? 10;
        $skip = ($page - 1) * $limit;

        if (empty($sellerId)) {
            return ["success" => false, "message" => "Missing required field: seller_id"];
        }

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');
        $options = ["typeMap" => ['root' => 'array', 'document' => 'array']];

        $seller = $collection->findOne(['user_id' => $sellerId], $options);
        if (empty($seller) || empty($seller['staff'])) {
            return ['success' => false, 'message' => 'No staff members found for this seller'];
        }

        $staffs = $seller['staff'];

        // filter
        if (!empty($filter)) {

            $staffs = array_filter($staffs, function ($staff) use ($filter) {
                foreach ($filter as $key => $value) {
                    if (!isset($staff[$key]) || $staff[$key] != $value) {
                        return false;
                    }
                }
                return true;
            });
        }

        // Convert Object IDs to strings
        $staffs = $this->convertObjectIdsToStrings($staffs);

        // Pagination
        $total = count($staffs);
        $staffs = array_slice($staffs, $skip, $limit);

        return [
            'success' => true,
            'data' => $staffs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ];
    }

    private function convertObjectIdsToStrings(array $data)
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->convertObjectIdsToStrings($value);
            } elseif ($value instanceof ObjectId) {
                $value = (string) $value;
            }
        }
        return $data;
    }

    public function getUserStaffAccounts(array $data)
    {
        $userId = $data['user_id'] ?? $this->di->getUser()->id;
        if (empty($userId)) {
            return ["success" => false, "message" => "Missing required field: user_id"];
        }

        $statusFilter = isset($data['status']) ? [$data['status']] : ['pending_approval', 'approved'];

        $mongo = $this->di->getObjectManager()->create('\App\Core\Models\BaseMongo');
        $collection = $mongo->getCollectionForTable('user_details');

        $pipeline = [
            [
                '$match' => [
                    'staff.staff_user_id' => new ObjectId($userId)
                ]
            ],
            [
                '$addFields' => [
                    'staff' => [
                        '$filter' => [
                            'input' => '$staff',
                            'as' => 'staff',
                            'cond' => [
                                '$and' => [
                                    ['$eq' => ['$$staff.staff_user_id', new ObjectId($userId)]],
                                    ['$in' => ['$$staff.status', $statusFilter]]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$match' => [
                    'staff' => ['$ne' => []]
                ]
            ]
        ];

        $staffAccounts = $collection->aggregate($pipeline, ['typeMap' => ['root' => 'array', 'document' => 'array']])->toArray();

        return [
            'success' => true,
            'data' => $staffAccounts
        ];
    }


}